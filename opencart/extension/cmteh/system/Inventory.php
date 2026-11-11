<?php
declare(strict_types=1);
namespace Cmteh;
use PDO;
require_once __DIR__ . '/InventoryInput.php';
require_once __DIR__ . '/InventorySchema.php';

/** All stock writers share the native product mutex before inventory/warehouse locks. */
final class Inventory
{
    public function __construct(private PDO $pdo, private string $prefix, private int $storeId=0, private int $languageId=0)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix) || strlen($prefix)>32) throw new \InvalidArgumentException('Invalid database prefix.');
        if($storeId<0||$languageId<0) throw new \InvalidArgumentException('Invalid storefront context.');
    }

    private function table(string $name): string { return '`' . $this->prefix . $name . '`'; }
    private function query(string $sql, array $params = []): \PDOStatement
    {
        $q=$this->pdo->prepare($sql); $q->execute($params); return $q;
    }
    private function fail(string $reason, string $message, int $status = 422): never { throw new InventoryException($reason,$message,$status); }
    private function version(array $row, mixed $expected): void
    {
        if ((int)$row['version'] !== InventoryInput::integer($expected,'version')) $this->fail('stale_version','Данные изменились. Обновите форму.',409);
    }
    private function atomic(callable $fn): mixed
    {
        $owns=!$this->pdo->inTransaction();
        if ($owns) $this->pdo->beginTransaction(); else $this->pdo->exec('SAVEPOINT cmteh_inventory_command');
        try {
            $result=$fn();
            if ($owns) $this->pdo->commit(); else $this->pdo->exec('RELEASE SAVEPOINT cmteh_inventory_command');
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($owns) $this->pdo->rollBack(); else { $this->pdo->exec('ROLLBACK TO SAVEPOINT cmteh_inventory_command'); $this->pdo->exec('RELEASE SAVEPOINT cmteh_inventory_command'); }
            }
            throw $e;
        }
    }

    public function command(string $kind, array $input, array $actor, string $requestKey): array
    {
        $kinds=['warehouse.save','warehouse.archive','product.activate','balance.count','balance.receive','balance.issue','transfer.complete','reservation.create','reservation.release','reservation.ship','offer.update'];
        if (!in_array($kind,$kinds,true)) $this->fail('invalid_input','Неизвестная складская команда.');
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D',$requestKey)) $this->fail('invalid_input','Проверьте идентификатор команды.');
        $actor=['id'=>InventoryInput::integer($actor['id']??null,'actor',1),'label'=>InventoryInput::text($actor['label']??null,'actor',160,true)];
        // Canonical ordering makes retries independent of JSON object property order.
        $fingerprint=hash('sha256',json_encode(InventoryInput::canonical([$kind,$input,$actor['id']]),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $prior=$this->query('SELECT fingerprint,result FROM '.$this->table('cmteh_inventory_command').' WHERE request_key=?',[$requestKey])->fetch();
        if ($prior && $prior['result']!==null) return $this->replay($prior,$fingerprint);
        $joining=$this->pdo->inTransaction();
        $execute=function() use($kind,$input,$actor,$requestKey,$fingerprint): array {
            $product=null;
            if (!str_starts_with($kind,'warehouse.')) {
                $pid=InventoryInput::integer($input['product_id']??null,'product_id',1);
                $product=$this->lockProduct($pid);
            }
            $prior=$this->query('SELECT fingerprint,result FROM '.$this->table('cmteh_inventory_command').' WHERE request_key=? FOR UPDATE',[$requestKey])->fetch();
            if ($prior) return $this->replay($prior,$fingerprint);
            $this->query('INSERT INTO '.$this->table('cmteh_inventory_command').' (request_key,fingerprint,kind,actor_id,actor_label,result,created_at) VALUES(?,?,?,?,?,NULL,UTC_TIMESTAMP())',[$requestKey,$fingerprint,$kind,$actor['id'],$actor['label']]);
            $command=(int)$this->pdo->lastInsertId();
            $result=str_starts_with($kind,'warehouse.') ? $this->warehouseCommand($kind,$input) : $this->stockCommand($kind,$input,$actor,$command,$product);
            $result=['command_id'=>(string)$command,'kind'=>$kind]+$result+['replayed'=>false];
            $this->query('UPDATE '.$this->table('cmteh_inventory_command').' SET result=? WHERE command_id=?',[json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$command]);
            return $result;
        };
        for($attempt=0;;$attempt++) {
            try { return $this->atomic($execute); }
            catch(\PDOException $e) {
                // MySQL deadlocks can roll back the whole owning transaction.
                // Never retry a caller-owned import/Lead transaction in isolation.
                if($joining||$attempt>=2||!in_array((int)($e->errorInfo[1]??0),[1213,1205],true)) throw $e;
            }
        }
    }

    private function replay(array $prior,string $fingerprint): array
    {
        if (!hash_equals($prior['fingerprint'],$fingerprint)) $this->fail('request_conflict','Ключ уже использован для другой команды.',409);
        if ($prior['result']===null) $this->fail('request_conflict','Команда ещё выполняется.',409);
        return array_replace(json_decode($prior['result'],true,32,JSON_THROW_ON_ERROR),['replayed'=>true]);
    }

    private function lockProduct(int $pid): array
    {
        $native=$this->query('SELECT * FROM '.$this->table('product').' WHERE product_id=? FOR UPDATE',[$pid])->fetch();
        if (!$native) $this->fail('not_found','Товар не найден.',404);
        $control=$this->query('SELECT * FROM '.$this->table('cmteh_inventory_product').' WHERE product_id=? FOR UPDATE',[$pid])->fetch();
        return ['native'=>$native,'control'=>$control?:null];
    }

    private function warehouse(int $id): array
    {
        $row=$this->query('SELECT * FROM '.$this->table('cmteh_warehouse').' WHERE warehouse_id=? FOR UPDATE',[$id])->fetch();
        if (!$row) $this->fail('not_found','Склад не найден.',404);
        return $row;
    }

    private function warehouseCommand(string $kind,array $in): array
    {
        $id=InventoryInput::integer($in['warehouse_id']??0,'warehouse_id');
        $old=$id ? $this->warehouse($id):null;
        $this->version($old?:['version'=>0],$in['expected_warehouse_version']??null);
        if ($kind==='warehouse.archive') {
            if (!$old) $this->fail('not_found','Склад не найден.',404);
            $stock=$this->query('SELECT offer_id,on_hand,reserved FROM '.$this->table('cmteh_inventory_balance').' WHERE warehouse_id=? ORDER BY offer_id FOR UPDATE',[$id])->fetchAll();
            foreach($stock as $row) if ((int)$row['on_hand']!==0 || (int)$row['reserved']!==0) $this->fail('warehouse_not_empty','Сначала переместите остатки и снимите резервы.');
            $this->query('UPDATE '.$this->table('cmteh_warehouse').' SET active=0,version=version+1,public_version=public_version+1,updated_at=UTC_TIMESTAMP() WHERE warehouse_id=?',[$id]);
        } else {
            $code=InventoryInput::text($in['code']??null,'code',64,true);
            if (!preg_match('/^[A-Za-z0-9_-]+$/D',$code)) $this->fail('invalid_input','Код склада: латинские буквы, цифры, дефис и подчёркивание.');
            $values=[$code,InventoryInput::text($in['name']??null,'name',160,true),InventoryInput::text($in['city']??'','city',160),InventoryInput::text($in['delivery_term']??'','delivery_term',255),InventoryInput::text($in['address']??'','address',1000),InventoryInput::text($in['note']??'','note',5000)];
            $duplicate=$this->query('SELECT warehouse_id FROM '.$this->table('cmteh_warehouse').' WHERE code=?',[$code])->fetchColumn();
            if ($duplicate && (int)$duplicate!==$id) $this->fail('duplicate_warehouse','Код склада уже существует.');
            if ($old) {
                $publicChanged=[$old['code'],$old['name'],$old['city'],$old['delivery_term']]!==array_slice($values,0,4);
                $this->query('UPDATE '.$this->table('cmteh_warehouse').' SET code=?,name=?,city=?,delivery_term=?,address=?,note=?,version=version+1,public_version=public_version+?,updated_at=UTC_TIMESTAMP() WHERE warehouse_id=?',[...$values,(int)$publicChanged,$id]);
            }
            else {
                $this->query('INSERT INTO '.$this->table('cmteh_warehouse').' (code,name,city,delivery_term,address,note,active,version,public_version,created_at,updated_at) VALUES(?,?,?,?,?,?,1,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())',$values);
                $id=(int)$this->pdo->lastInsertId();
            }
        }
        return ['warehouse'=>$this->warehouse($id)];
    }

    public static function legacyRevision(array $native): string
    {
        return hash('sha256',json_encode([(int)$native['product_id'],(int)$native['quantity'],(int)($native['master_id']??0),(string)($native['date_modified']??'')],JSON_THROW_ON_ERROR));
    }

    private function stockCommand(string $kind,array $in,array $actor,int $command,array $product): array
    {
        $native=$product['native']; $pid=(int)$native['product_id']; $control=$product['control'];
        $this->version($control?:['version'=>0],$in['expected_product_version']??null);
        if ($kind==='product.activate') {
            if ($control) $this->fail('already_managed','Товар уже распределён по складам.',409);
            $expected=InventoryInput::integer($in['expected_legacy_quantity']??null,'expected_legacy_quantity');
            if ($expected!==(int)$native['quantity'] || !is_string($in['expected_legacy_revision']??null) || !hash_equals(self::legacyRevision($native),$in['expected_legacy_revision'])) $this->fail('stale_version','Старый остаток изменился. Повторите распределение.',409);
            if ((int)($native['master_id']??0)!==0 || $this->query('SELECT product_id FROM '.$this->table('product').' WHERE master_id=? LIMIT 1',[$pid])->fetchColumn()) $this->fail('unsupported_stock','Общий остаток вариантов нельзя распределить автоматически.');
            $hasOptions=$this->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$this->prefix.'product_option_value'])->fetchColumn();
            if ($hasOptions && $this->query('SELECT product_option_value_id FROM '.$this->table('product_option_value').' WHERE product_id=? AND subtract=1 LIMIT 1',[$pid])->fetchColumn()) $this->fail('unsupported_stock','Остатки опций требуют отдельного учёта.');
            if (InventoryInput::flag($in['confirmed']??false)!==1) $this->fail('confirmation_required','Подтвердите распределение остатков.');
            $allocations=$in['allocations']??null;
            if (!is_array($allocations)||!array_is_list($allocations)||count($allocations)<1||count($allocations)>1000) $this->fail('invalid_input','Укажите распределение по складам.');
            $byWarehouse=[]; $total=0;
            foreach($allocations as $allocation) {
                if(!is_array($allocation)) $this->fail('invalid_input','Проверьте распределение.');
                $wid=InventoryInput::integer($allocation['warehouse_id']??null,'warehouse_id',1);
                if(isset($byWarehouse[$wid])) $this->fail('invalid_input','Склад повторяется.');
                $qty=InventoryInput::integer($allocation['quantity']??null,'quantity'); $total+=$qty;
                if($total>InventoryInput::MAX_QUANTITY) $this->fail('quantity_overflow','Общий остаток слишком велик.');
                $byWarehouse[$wid]=[$allocation,$qty];
            }
            $reason=InventoryInput::text($in['reason']??'','reason',1000,$total!==$expected);
            ksort($byWarehouse,SORT_NUMERIC);
            $this->query('INSERT INTO '.$this->table('cmteh_inventory_product').' (product_id,version,legacy_quantity,legacy_revision,activated_at) VALUES(?,0,?,?,UTC_TIMESTAMP())',[$pid,$expected,$in['expected_legacy_revision']]);
            $changes=[];
            foreach($byWarehouse as $wid=>[$allocation,$qty]) {
                $w=$this->warehouse((int)$wid); $this->version($w,$allocation['expected_warehouse_version']??null);
                if(!(int)$w['active']) $this->fail('warehouse_archived','Склад архивирован.');
                $b=$this->balance($pid,(int)$wid); $changes[]=$this->change($b,$qty,0,$w);
            }
            $movement=$this->movement($command,$kind,$reason?:'Начальное распределение',$actor,$pid,$changes);
        } else {
            if(!$control) $this->fail('unmanaged_product','Сначала подтвердите распределение старого остатка.');
            $wid=InventoryInput::integer($in['warehouse_id']??null,'warehouse_id',1);
            $ids=[$wid];
            if($kind==='transfer.complete') {
                $target=InventoryInput::integer($in['to_warehouse_id']??null,'to_warehouse_id',1);
                if($target===$wid) $this->fail('invalid_input','Выберите другой склад.');
                $ids[]=$target;
                if(InventoryInput::flag($in['arrival_confirmed']??false)!==1) $this->fail('confirmation_required','Подтвердите фактическое прибытие.');
            }
            sort($ids,SORT_NUMERIC); $warehouses=[]; $balances=[];
            foreach($ids as $id) $warehouses[$id]=$this->warehouse($id);
            foreach($ids as $id) $balances[$id]=$this->balance($pid,$id);
            $w=$warehouses[$wid]; $b=$balances[$wid];
            $this->version($w,$in['expected_warehouse_version']??null);
            $this->version($b,$in['expected_balance_version']??null);
            if(!(int)$w['active']) $this->fail('warehouse_archived','Склад архивирован.');
            if($kind==='offer.update') {
                $price=InventoryInput::price($in['price']??null); $term=InventoryInput::text($in['delivery_term']??'','delivery_term',255);
                $published=InventoryInput::flag($in['published']??false);
                $this->query('UPDATE '.$this->table('cmteh_inventory_balance').' SET price=?,delivery_term=?,published=?,version=version+1 WHERE offer_id=?',[$price,$term,$published,$b['offer_id']]);
                $movement=null;
            } else {
                $reason=InventoryInput::text($in['reason']??null,'reason',1000,true); $reservation=null; $changes=[];
                if(in_array($kind,['reservation.release','reservation.ship'],true)) {
                    $rid=InventoryInput::integer($in['reservation_id']??null,'reservation_id',1);
                    $reservation=$this->query('SELECT * FROM '.$this->table('cmteh_inventory_reservation').' WHERE reservation_id=? FOR UPDATE',[$rid])->fetch();
                    if(!$reservation || (int)$reservation['product_id']!==$pid || (int)$reservation['warehouse_id']!==$wid) $this->fail('not_found','Резерв не найден.',404);
                    $this->version($reservation,$in['expected_reservation_version']??null);
                    if($reservation['state']!=='active') $this->fail('reservation_closed','Резерв уже закрыт.',409);
                    if(array_key_exists('quantity',$in)) $this->fail('invalid_input','Резерв закрывается целиком.');
                    $qty=(int)$reservation['quantity'];
                    $changes[]=$this->change($b,$kind==='reservation.ship' ? -$qty:0,-$qty,$w);
                    $this->query('UPDATE '.$this->table('cmteh_inventory_reservation').' SET state=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE reservation_id=?',[$kind==='reservation.ship'?'shipped':'released',$rid]);
                    $reservation['state']=$kind==='reservation.ship'?'shipped':'released'; $reservation['version']=(int)$reservation['version']+1;
                } else {
                    $qty=InventoryInput::integer($in['quantity']??null,'quantity',$kind==='balance.count'?0:1);
                    $delta=match($kind){'balance.count'=>$qty-(int)$b['on_hand'],'balance.receive'=>$qty,'balance.issue','transfer.complete'=>-$qty,'reservation.create'=>0};
                    $changes[]=$this->change($b,$delta,$kind==='reservation.create'?$qty:0,$w);
                    if($kind==='transfer.complete') {
                        $tw=$warehouses[$target]; $tb=$balances[$target];
                        $this->version($tw,$in['expected_to_warehouse_version']??null); $this->version($tb,$in['expected_to_balance_version']??null);
                        if(!(int)$tw['active']) $this->fail('warehouse_archived','Склад архивирован.');
                        $changes[]=$this->change($tb,$qty,0,$tw);
                    }
                    if($kind==='reservation.create') {
                        $reference=InventoryInput::text($in['reference']??'','reference',255);
                        $this->query('INSERT INTO '.$this->table('cmteh_inventory_reservation').' (product_id,warehouse_id,quantity,state,version,reference,actor_id,actor_label,created_at,updated_at) VALUES(?,?,?,\'active\',1,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$pid,$wid,$qty,$reference,$actor['id'],$actor['label']]);
                        $rid=(int)$this->pdo->lastInsertId();
                        $reservation=$this->query('SELECT * FROM '.$this->table('cmteh_inventory_reservation').' WHERE reservation_id=?',[$rid])->fetch();
                    }
                }
                $movement=$this->movement($command,$kind,$reason,$actor,$pid,$changes);
            }
        }
        $this->query('UPDATE '.$this->table('cmteh_inventory_product').' SET version=version+1 WHERE product_id=?',[$pid]);
        $state=$this->productState($pid);
        if($state['on_hand']>InventoryInput::MAX_QUANTITY || $state['reserved']>InventoryInput::MAX_QUANTITY) $this->fail('quantity_overflow','Общий остаток слишком велик.');
        $this->query('UPDATE '.$this->table('product').' SET quantity=? WHERE product_id=?',[$state['available'],$pid]);
        $result=['product'=>$state,'movement_id'=>$movement===null?null:(string)$movement,'balances'=>$this->balanceRows($pid)];
        if(isset($reservation)) $result['reservation']=$reservation;
        return $result;
    }

    private function balance(int $pid,int $wid): array
    {
        $row=$this->query('SELECT * FROM '.$this->table('cmteh_inventory_balance').' WHERE product_id=? AND warehouse_id=? FOR UPDATE',[$pid,$wid])->fetch();
        if($row) return $row;
        $this->query('INSERT INTO '.$this->table('cmteh_inventory_balance').' (product_id,warehouse_id,on_hand,reserved,price,delivery_term,published,version) VALUES(?,?,0,0,NULL,\'\',0,0)',[$pid,$wid]);
        return $this->query('SELECT * FROM '.$this->table('cmteh_inventory_balance').' WHERE offer_id=?',[$this->pdo->lastInsertId()])->fetch();
    }

    private function change(array $b,int $onHand,int $reserved,array $w): array
    {
        $nextOn=(int)$b['on_hand']+$onHand; $nextReserved=(int)$b['reserved']+$reserved;
        if($nextOn<0||$nextReserved<0||$nextReserved>$nextOn) $this->fail('insufficient_stock','Недостаточно свободного остатка; пересчёт не может быть ниже резерва.');
        if($nextOn>InventoryInput::MAX_QUANTITY||$nextReserved>InventoryInput::MAX_QUANTITY) $this->fail('quantity_overflow','Остаток слишком велик.');
        $this->query('UPDATE '.$this->table('cmteh_inventory_balance').' SET on_hand=?,reserved=?,version=version+1 WHERE offer_id=?',[$nextOn,$nextReserved,$b['offer_id']]);
        return ['balance'=>$b,'warehouse'=>$w,'delta_on_hand'=>$onHand,'delta_reserved'=>$reserved,'on_hand'=>$nextOn,'reserved'=>$nextReserved,'version'=>(int)$b['version']+1];
    }

    private function movement(int $command,string $kind,string $reason,array $actor,int $pid,array $changes): int
    {
        $map=$this->query('SELECT source_id,sku FROM '.$this->table('cmteh_product').' WHERE product_id=?',[$pid])->fetch();
        $this->query('INSERT INTO '.$this->table('cmteh_inventory_movement').' (command_id,kind,reason,actor_id,actor_label,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$command,$kind,$reason,$actor['id'],$actor['label']]);
        $id=(int)$this->pdo->lastInsertId();
        foreach($changes as $position=>$c) $this->query('INSERT INTO '.$this->table('cmteh_inventory_entry').' (movement_id,position,product_id,warehouse_id,delta_on_hand,delta_reserved,on_hand,reserved,balance_version,source_id,article,warehouse_code) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$position,$pid,$c['warehouse']['warehouse_id'],$c['delta_on_hand'],$c['delta_reserved'],$c['on_hand'],$c['reserved'],$c['version'],$map['source_id']??'',$map['sku']??'',$c['warehouse']['code']]);
        return $id;
    }

    private function balanceRows(int $pid): array
    {
        return $this->query('SELECT b.*,b.on_hand-b.reserved available,w.code warehouse_code,w.name warehouse_name,w.version warehouse_version FROM '.$this->table('cmteh_inventory_balance').' b JOIN '.$this->table('cmteh_warehouse').' w ON w.warehouse_id=b.warehouse_id WHERE b.product_id=? ORDER BY b.warehouse_id',[$pid])->fetchAll();
    }

    public function productState(int $nativeProductId): ?array
    {
        $lock=$this->pdo->inTransaction()?' FOR UPDATE':'';
        $native=$this->query('SELECT * FROM '.$this->table('product').' WHERE product_id=?'.$lock,[$nativeProductId])->fetch();
        if(!$native) return null;
        $control=$this->query('SELECT * FROM '.$this->table('cmteh_inventory_product').' WHERE product_id=?'.$lock,[$nativeProductId])->fetch();
        $balances=$this->query('SELECT on_hand,reserved FROM '.$this->table('cmteh_inventory_balance').' WHERE product_id=? ORDER BY offer_id'.$lock,[$nativeProductId])->fetchAll();
        $onHand=0;$reserved=0; foreach($balances as $balance){$onHand+=(int)$balance['on_hand'];$reserved+=(int)$balance['reserved'];}
        return ['product_id'=>$nativeProductId,'managed'=>(bool)$control,'version'=>(int)($control['version']??0),'on_hand'=>$onHand,'reserved'=>$reserved,'available'=>$onHand-$reserved,'legacy_quantity'=>(int)($control['legacy_quantity']??$native['quantity']),'legacy_revision'=>$control['legacy_revision']??self::legacyRevision($native)];
    }

    private function page(string $from,array $where,array $params,string $order,array $filter): array
    {
        $page=InventoryInput::integer($filter['page']??1,'page',1); $limit=min(100,InventoryInput::integer($filter['limit']??50,'limit',1));
        if($page>100000) $this->fail('invalid_input','Номер страницы слишком велик.');
        $condition=$where?' WHERE '.implode(' AND ',$where):'';
        $count=(int)$this->query('SELECT COUNT(*) FROM '.$from.$condition,$params)->fetchColumn();
        $rows=$this->query('SELECT * FROM '.$from.$condition.' ORDER BY '.$order.' LIMIT '.$limit.' OFFSET '.(($page-1)*$limit),$params)->fetchAll();
        return ['rows'=>$rows,'total'=>$count,'page'=>$page,'limit'=>$limit];
    }
    public function warehouses(array $filter=[]): array
    {
        $where=[];$params=[];
        if(array_key_exists('active',$filter)){ $where[]='active=?'; $params[]=InventoryInput::flag($filter['active']); }
        if(isset($filter['warehouse_id'])){ $where[]='warehouse_id=?'; $params[]=InventoryInput::integer($filter['warehouse_id'],'warehouse_id',1); }
        return $this->page($this->table('cmteh_warehouse'),$where,$params,'warehouse_id',$filter);
    }
    public function balances(array $filter=[]): array
    {
        $where=[];$params=[];
        foreach(['product_id','warehouse_id'] as $key) if(isset($filter[$key])){ $where[]='b.'.$key.'=?'; $params[]=InventoryInput::integer($filter[$key],$key,1); }
        if(isset($filter['search'])&&$filter['search']!==''){ $needle=InventoryInput::text($filter['search'],'search',160); $where[]='(x.sku LIKE ? OR x.source_id LIKE ? OR w.code LIKE ?)'; foreach(range(1,3) as $_) $params[]='%'.$needle.'%'; }
        $from='(SELECT b.*,b.on_hand-b.reserved available,w.code warehouse_code,w.name warehouse_name,w.version warehouse_version,x.source_id,x.sku FROM '.$this->table('cmteh_inventory_balance').' b JOIN '.$this->table('cmteh_warehouse').' w ON w.warehouse_id=b.warehouse_id LEFT JOIN '.$this->table('cmteh_product').' x ON x.product_id=b.product_id'.($where?' WHERE '.implode(' AND ',$where):'').') inventory_rows';
        return $this->page($from,[],$params,'product_id,warehouse_id',$filter);
    }
    public function history(array $filter=[]): array
    {
        $where=[];$params=[];
        foreach(['product_id','warehouse_id'] as $key) if(isset($filter[$key])) { $where[]='EXISTS(SELECT 1 FROM '.$this->table('cmteh_inventory_entry').' e WHERE e.movement_id=m.movement_id AND e.'.$key.'=?)';$params[]=InventoryInput::integer($filter[$key],$key,1); }
        $result=$this->page($this->table('cmteh_inventory_movement').' m',$where,$params,'movement_id DESC',$filter);
        foreach($result['rows'] as &$row) $row['entries']=$this->query('SELECT * FROM '.$this->table('cmteh_inventory_entry').' WHERE movement_id=? ORDER BY position',[$row['movement_id']])->fetchAll();
        return $result;
    }
    public function reservations(array $filter=[]): array
    {
        $where=[];$params=[];
        foreach(['product_id','warehouse_id','reservation_id'] as $key) if(isset($filter[$key])){ $where[]=$key.'=?';$params[]=InventoryInput::integer($filter[$key],$key,1); }
        if(isset($filter['state'])){ $where[]='state=?';$params[]=InventoryInput::text($filter['state'],'state',16,true); }
        return $this->page($this->table('cmteh_inventory_reservation'),$where,$params,'reservation_id DESC',$filter);
    }

    public function publicOffers(array $sourceIds): array
    {
        return $this->offerProjection($sourceIds,false);
    }

    private function visibility(): string
    {
        $store=$this->storeId; $language=$this->languageId;
        return 'p.status=1 AND p.date_available<=UTC_DATE() AND x.published_at<=UTC_TIMESTAMP()
            AND EXISTS(SELECT 1 FROM '.$this->table('product_to_store').' s WHERE s.product_id=p.product_id AND s.store_id='.$store.')
            AND EXISTS(SELECT 1 FROM '.$this->table('product_description').' d WHERE d.product_id=p.product_id'.($language?' AND d.language_id='.$language:'').')
            AND (p.manufacturer_id=0 OR EXISTS(SELECT 1 FROM '.$this->table('manufacturer').' m
                JOIN '.$this->table('cmteh_brand').' brand ON brand.manufacturer_id=m.manufacturer_id AND brand.status=1 AND brand.published_at<=UTC_TIMESTAMP()
                JOIN '.$this->table('manufacturer_to_store').' ms ON ms.manufacturer_id=m.manufacturer_id AND ms.store_id='.$store.' WHERE m.manufacturer_id=p.manufacturer_id))';
    }

    private function offerProjection(array $sourceIds,bool $current): array
    {
        if(count($sourceIds)>1000) $this->fail('invalid_input','Слишком много товаров.');
        $ids=[]; foreach($sourceIds as $id) $ids[]=InventoryInput::text($id,'source_id',160,true);
        $ids=array_values(array_unique($ids)); $result=[];
        foreach($ids as $id) $result[$id]=['managed'=>false,'offers'=>[],'partial'=>false];
        if(!$ids) return $result;
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $from=$this->table('cmteh_product').' x JOIN '.$this->table('product').' p ON p.product_id=x.product_id LEFT JOIN '.$this->table('cmteh_inventory_product').' c ON c.product_id=p.product_id';
        $where='x.source_id IN('.$marks.') AND '.$this->visibility();
        $lock=$current?' FOR UPDATE':'';
        $managed=$this->query('SELECT x.source_id,c.product_id managed_id FROM '.$from.' WHERE '.$where.$lock,$ids)->fetchAll();
        foreach($managed as $row) $result[$row['source_id']]['managed']=$row['managed_id']!==null;
        $rows=$this->query('SELECT x.source_id,b.*,w.code,w.name,w.city,w.delivery_term warehouse_delivery_term,w.public_version warehouse_public_version FROM '.$from.' JOIN '.$this->table('cmteh_inventory_balance').' b ON b.product_id=c.product_id JOIN '.$this->table('cmteh_warehouse').' w ON w.warehouse_id=b.warehouse_id WHERE '.$where.' AND w.active=1 AND b.published=1 ORDER BY x.source_id,b.warehouse_id LIMIT 10001'.$lock,$ids)->fetchAll();
        if(count($rows)>10000){foreach($result as &$value) if($value['managed']) $value['partial']=true; unset($value); array_pop($rows);}
        foreach($rows as $row) {
            $id=$row['source_id'];
            if(count($result[$id]['offers'])>=100){$result[$id]['partial']=true;continue;}
            $result[$id]['offers'][]=$this->offerDto($row);
        }
        return $result;
    }

    private function offerDto(array $row): array
    {
        $dto=['offer_id'=>(string)$row['offer_id'],'offer_version'=>'','product_id'=>$row['source_id'],'warehouse'=>['code'=>$row['code'],'name'=>$row['name'],'city'=>$row['city']],
            'availability_status'=>(int)$row['on_hand']>(int)$row['reserved']?'in_stock':'out_of_stock','price'=>$row['price']===null?null:(string)$row['price'],'currency'=>'RUB','delivery_term'=>$row['delivery_term']!==''?$row['delivery_term']:$row['warehouse_delivery_term']];
        $dto['offer_version']=hash('sha256',json_encode([$dto,(int)$row['version'],(int)$row['warehouse_public_version']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return $dto;
    }

    /** Caller owns the first-insert Lead transaction. This method never reserves stock. */
    public function resolveSelectedOffers(array $items): array
    {
        if(!$this->pdo->inTransaction()) throw new \LogicException('Selected offers require the owning transaction.');
        if(count($items)>1000) $this->fail('invalid_input','Слишком много строк.');
        $pids=[]; $selected=[];
        foreach($items as $position=>$item) {
            if(!is_array($item)) $this->fail('invalid_input','Проверьте позицию заявки.');
            if(!array_key_exists('offer_id',$item)&&!array_key_exists('offer_version',$item)) continue;
            $source=InventoryInput::text($item['product_id']??null,'product_id',160,true);
            $offer=InventoryInput::text($item['offer_id']??null,'offer_id',40,true);
            $version=InventoryInput::text($item['offer_version']??null,'offer_version',64,true);
            if(!preg_match('/^[1-9][0-9]{0,19}$/D',$offer)||!preg_match('/^[a-f0-9]{64}$/D',$version)) $this->fail('invalid_input','Проверьте предложение.');
            $map=$this->query('SELECT product_id,sku FROM '.$this->table('cmteh_product').' WHERE source_id=?',[$source])->fetch();
            if(!$map||!is_string($item['article']??null)) $this->fail('stale_offer','Товар или артикул изменился. Проверьте выбор склада.',409);
            $pids[(int)$map['product_id']]=true; $selected[$position]=[$source,$offer,$version,$item['article'],(int)$map['product_id']];
        }
        ksort($pids,SORT_NUMERIC); foreach(array_keys($pids) as $pid) $this->lockProduct($pid);
        // The caller may already have a REPEATABLE READ snapshot. Recheck identity
        // using a current locking read after acquiring the native product mutex.
        foreach($selected as [$source,,$version,$article,$pid]) {
            $map=$this->query('SELECT product_id,sku FROM '.$this->table('cmteh_product').' WHERE source_id=? FOR UPDATE',[$source])->fetch();
            if(!$map||(int)$map['product_id']!==$pid||$map['sku']!==$article) $this->fail('stale_offer','Товар или артикул изменился. Проверьте выбор склада.',409);
        }
        // Warehouse label/term changes use the same row locks as commands.
        if($pids) {
            $marks=implode(',',array_fill(0,count($pids),'?'));
            $warehouseIds=$this->query('SELECT DISTINCT warehouse_id FROM '.$this->table('cmteh_inventory_balance').' WHERE product_id IN('.$marks.') ORDER BY warehouse_id',array_keys($pids))->fetchAll(PDO::FETCH_COLUMN);
            foreach($warehouseIds as $wid) $this->warehouse((int)$wid);
            $this->query('SELECT offer_id FROM '.$this->table('cmteh_inventory_balance').' WHERE product_id IN('.$marks.') ORDER BY offer_id FOR UPDATE',array_keys($pids))->fetchAll();
        }
        $offers=$this->offerProjection(array_values(array_unique(array_column($selected,0))),true); $snapshots=[];
        foreach($selected as $position=>[$source,$id,$version]) {
            $matched=null;
            foreach($offers[$source]['offers'] as $offer) if($offer['offer_id']===$id&&hash_equals($offer['offer_version'],$version)) $matched=$offer;
            if(!$matched) $this->fail('stale_offer','Предложение изменилось. Проверьте склад, цену и срок.',409);
            $snapshots[$position]=['offer_id'=>$matched['offer_id'],'offer_version'=>$matched['offer_version'],'warehouse_code'=>$matched['warehouse']['code'],'warehouse_name'=>$matched['warehouse']['name'],'warehouse_city'=>$matched['warehouse']['city'],'unit_price'=>$matched['price'],'currency'=>$matched['currency'],'delivery_term'=>$matched['delivery_term'],'availability_status'=>$matched['availability_status']];
        }
        return $snapshots;
    }
}
