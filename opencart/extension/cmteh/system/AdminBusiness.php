<?php
declare(strict_types=1);
namespace Cmteh;

use PDO;
use InvalidArgumentException;

/** Read-only projections. Never migrates, sends mail, parses originals or infers customer identity. */
final class AdminBusiness
{
    private array $available = [];
    public function __construct(private readonly PDO $pdo, private readonly string $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]{0,40}$/D', $prefix)) throw new InvalidArgumentException('Invalid prefix.');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['lead'=>['','_outbox','_item'], 'quote'=>['','_outbox']] as $type=>$suffixes) {
            $this->available[$type] = true;
            foreach ($suffixes as $suffix) {
                if (!in_array($prefix.'cmteh_'.$type.$suffix, $tables, true)) $this->available[$type] = false;
            }
            if ($this->available[$type]) {
                $base=['public_id','created_at','name','phone','email','page_url',$type.'_id'];
                $required=[''=>$type==='lead' ? [...$base,'company'] : [...$base,'input_kind'], '_outbox'=>[$type.'_id','status']];
                if ($type==='lead') $required['_item']=['lead_id','quantity'];
                foreach ($required as $suffix=>$columns) {
                    $actual=$pdo->query('SHOW COLUMNS FROM '.$prefix.'cmteh_'.$type.$suffix)->fetchAll(PDO::FETCH_COLUMN);
                    if (array_diff($columns,$actual)) $this->available[$type]=false;
                }
            }
        }
    }

    private function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** All filters apply before summaries, grouping and pagination. Dates are UTC calendar days. */
    public static function filters(array $input): array
    {
        $r = ['search'=>'','from'=>'','to'=>'','type'=>'','status'=>''];
        foreach ($r as $key=>$_) {
            if (isset($input[$key]) && !is_string($input[$key])) throw new InvalidArgumentException('Некорректный фильтр.');
            $r[$key] = trim($input[$key] ?? '');
        }
        if (mb_strlen($r['search']) > 200) throw new InvalidArgumentException('Поиск — до 200 символов.');
        foreach (['from','to'] as $k) if ($r[$k] !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $r[$k]);
            if (!$date || $date->format('Y-m-d') !== $r[$k]) throw new InvalidArgumentException('Укажите действительную дату.');
        }
        if ($r['from'] && $r['to'] && $r['from'] > $r['to']) throw new InvalidArgumentException('Начало периода позже окончания.');
        if (!in_array($r['type'],['','lead','quote'],true) || !in_array($r['status'],['','pending','sending','retry','sent','failed','unknown'],true)) throw new InvalidArgumentException('Некорректный тип или состояние.');
        return $r;
    }

    private function projection(array $filters): array
    {
        $parts=[];
        if ($this->available['lead']) {
            $t=$this->prefix.'cmteh_lead';
            $parts[]="SELECT 'lead' type,l.public_id,l.created_at,l.name,l.phone,l.email,l.company,COALESCE(l.page_url,'') source,COALESCE(o.status,'unknown') status,
                COALESCE(i.items,0) items,COALESCE(i.quantity,0) quantity,'structured' input_kind
                FROM {$t} l LEFT JOIN {$t}_outbox o ON o.lead_id=l.lead_id
                LEFT JOIN (SELECT lead_id,COUNT(*) items,SUM(quantity) quantity FROM {$t}_item GROUP BY lead_id) i ON i.lead_id=l.lead_id";
        }
        if ($this->available['quote']) {
            $t=$this->prefix.'cmteh_quote';
            $parts[]="SELECT 'quote' type,q.public_id,q.created_at,q.name,q.phone,q.email,'' company,COALESCE(q.page_url,'') source,COALESCE(o.status,'unknown') status,
                NULL items,NULL quantity,q.input_kind FROM {$t} q LEFT JOIN {$t}_outbox o ON o.quote_id=q.quote_id";
        }
        if (!$parts) $parts[]="SELECT '' type,'' public_id,NULL created_at,'' name,'' phone,'' email,'' company,'' source,'' status,NULL items,NULL quantity,'' input_kind WHERE 1=0";
        $where=[]; $params=[];
        if ($filters['search'] !== '') {
            // Literal substring matching, including SQL wildcard characters supplied by user.
            $search='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filters['search']).'%';
            $where[]="(public_id LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR company LIKE ? ESCAPE '!')";
            array_push($params,$search,$search,$search,$search,$search);
        }
        if ($filters['from']) { $where[]='created_at >= ?'; $params[]=$filters['from'].' 00:00:00'; }
        if ($filters['to']) { $where[]='created_at < ?'; $params[]=(new \DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
        foreach (['type','status'] as $key) if ($filters[$key]) { $where[]="$key = ?"; $params[]=$filters[$key]; }
        return ['SELECT * FROM ('.implode(' UNION ALL ',$parts).') requests'.($where?' WHERE '.implode(' AND ',$where):''),$params];
    }

    private function context(array $filters): array
    {
        return ['filters'=>$filters,'unavailable'=>array_keys(array_filter($this->available,fn(bool $v):bool=>!$v))];
    }

    public function dashboard(array $filters = []): array
    {
        $f=self::filters($filters); [$sql,$p]=$this->projection($f);
        $row=$this->query("SELECT COUNT(*) total,COALESCE(SUM(type='lead'),0) `lead`,COALESCE(SUM(type='quote'),0) quote,
            COALESCE(SUM(items),0) items,COALESCE(SUM(quantity),0) quantity FROM ($sql) filtered",$p)[0];
        $row=array_map('intval',$row);
        $row['states']=$this->query("SELECT status,COUNT(*) requests FROM ($sql) filtered GROUP BY status ORDER BY status",$p);
        return $row+$this->context($f);
    }

    private function page(string $sql,array $p,array $f,int $page,int $size,string $order):array
    {
        $size=max(1,min(50,$size));
        $total=(int)$this->query("SELECT COUNT(*) n FROM ($sql) grouped",$p)[0]['n'];
        $pages=max(1,(int)ceil($total/$size)); $page=max(1,min($pages,$page)); $offset=($page-1)*$size;
        return ['rows'=>$this->query("$sql ORDER BY $order LIMIT $size OFFSET $offset",$p),'total'=>$total,'page'=>$page,'pages'=>$pages]+$this->context($f);
    }

    public function contacts(array $filters = [], int $page = 1, int $size = 50): array
    {
        $f=self::filters($filters); [$sql,$p]=$this->projection($f);
        return $this->page($sql,$p,$f,$page,$size,'created_at DESC,type,public_id');
    }

    public function reports(array $filters = [], int $page = 1, int $size = 50): array
    {
        $f=self::filters($filters); [$sql,$p]=$this->projection($f);
        return $this->page("SELECT DATE(created_at) day,type,status,COUNT(*) requests,COALESCE(SUM(items),0) items,COALESCE(SUM(quantity),0) quantity FROM ($sql) filtered GROUP BY DATE(created_at),type,status",$p,$f,$page,$size,'day DESC,type,status');
    }

    public function sources(array $filters = [], int $page = 1, int $size = 50): array
    {
        $f=self::filters($filters); [$sql,$p]=$this->projection($f);
        return $this->page("SELECT source,type,COUNT(*) requests FROM ($sql) filtered GROUP BY source,type",$p,$f,$page,$size,'requests DESC,source,type');
    }
}
