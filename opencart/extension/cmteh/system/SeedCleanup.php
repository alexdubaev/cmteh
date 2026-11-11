<?php
declare(strict_types=1);
namespace Cmteh;

use PDO;
use RuntimeException;

/** Offline exact-seed removal. No schema writes, inferred mappings, media or global restore. */
final class SeedCleanup
{
    public const TASK = 'admin-unification-20261111';
    public const MANIFEST_SHA256 = 'cb85e816b60619469242145760577a8757093dade1201ad29a550d0529757c08';
    private array $manifest;
    private array $columns = [];

    public function __construct(private PDO $pdo, private string $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new RuntimeException('Invalid table prefix');
        $file = __DIR__ . '/seed-cleanup.json';
        if (!hash_equals(self::MANIFEST_SHA256, hash_file('sha256', $file))) throw new RuntimeException('Pinned manifest mismatch');
        $this->manifest = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    public function preview(): array
    {
        return $this->transaction(function (): array {
            [$image, $schema] = $this->image();
            return $this->plan($image, $schema);
        });
    }

    /** Persist the closed private preimage successfully before any delete statement. */
    public function apply(string $guard, callable $persist): array
    {
        return $this->transaction(function () use ($guard, $persist): array {
            [$image, $schema] = $this->image();
            $plan = $this->plan($image, $schema);
            if (!hash_equals($plan['guard'], $guard)) throw new RuntimeException('Snapshot changed; obtain a new dry-run');
            if ($plan['state'] === 'already-clean') return $plan;
            $rows = $this->selected($image);
            $receipt = ['format'=>1, 'task'=>self::TASK, 'manifest'=>self::MANIFEST_SHA256,
                'guard'=>$guard, 'schema'=>array_intersect_key($schema, $rows), 'rows'=>$rows];
            $persist($receipt);
            foreach (array_reverse(array_keys($rows)) as $table) {
                foreach ($rows[$table] as $row) {
                    [$where, $params] = $this->predicate($row);
                    $statement = $this->pdo->prepare('DELETE FROM ' . $this->table($table) . ' WHERE ' . $where . ' LIMIT 1');
                    $statement->execute($params);
                    if ($statement->rowCount() !== 1) throw new RuntimeException('Delete identity changed');
                }
            }
            // Verification occurs within the same locked transaction.
            [$after, $afterSchema] = $this->image();
            if ($afterSchema !== $schema) throw new RuntimeException('Schema changed during cleanup');
            if ($this->plan($after, $afterSchema)['state'] !== 'already-clean') throw new RuntimeException('Cleanup postcondition failed');
            foreach ($image as $table=>$before) {
                $expected = array_values(array_filter($before, fn($row)=>!$this->contains($rows[$table] ?? [], $row, $table)));
                if ($this->canonical($after[$table], $table) !== $this->canonical($expected, $table)) throw new RuntimeException('Unrelated data changed');
            }
            $plan['state'] = 'applied';
            return $plan;
        });
    }

    public function recover(array $receipt): void
    {
        $this->transaction(function () use ($receipt): void {
            if (($receipt['task'] ?? '') !== self::TASK || ($receipt['manifest'] ?? '') !== self::MANIFEST_SHA256
                || ($receipt['format'] ?? 0) !== 1) throw new RuntimeException('Recovery task/manifest mismatch');
            [$image, $schema] = $this->image();
            if ($this->plan($image, $schema)['state'] !== 'already-clean') throw new RuntimeException('Recovery IDs are occupied');
            if (array_intersect_key($schema, $this->manifest['rows']) !== ($receipt['schema'] ?? null)) throw new RuntimeException('Recovery schema changed');
            foreach ($this->manifest['rows'] as $table=>$rows) {
                if ($this->canonical($receipt['rows'][$table] ?? [], $table) !== $this->canonical($rows, $table)) throw new RuntimeException('Recovery preimage is not pinned seed');
            }
            if (array_keys($receipt['rows']) !== array_keys($this->manifest['rows'])) throw new RuntimeException('Recovery rowsets changed');
            foreach ($receipt['rows'] as $table=>$rows) foreach ($rows as $row) {
                $keys = array_keys($row);
                $query = 'INSERT INTO ' . $this->table($table) . ' (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0,count($keys),'?')) . ')';
                $this->pdo->prepare($query)->execute(array_values($row));
            }
            [$after, $afterSchema] = $this->image();
            if ($this->plan($after, $afterSchema)['state'] !== 'ready') throw new RuntimeException('Recovery postcondition failed');
        });
    }

    private function transaction(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('Cleanup requires its own transaction');
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $this->pdo->beginTransaction();
        try { $result=$work(); $this->pdo->commit(); return $result; }
        catch (\Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    private function image(): array
    {
        $query = $this->pdo->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME');
        $query->execute(); $tables=$query->fetchAll(PDO::FETCH_ASSOC); $image=[]; $schema=[]; $this->columns=[];
        foreach ($tables as $meta) {
            if (!str_starts_with($meta['TABLE_NAME'],$this->prefix)) continue;
            if ($meta['ENGINE'] !== 'InnoDB') throw new RuntimeException('Only transactional base tables are supported');
            $table=substr($meta['TABLE_NAME'],strlen($this->prefix));
            $columns=$this->pdo->query('SHOW FULL COLUMNS FROM ' . $this->table($table))->fetchAll(PDO::FETCH_ASSOC);
            $schema[$table]=$columns;
            foreach ($columns as $column) $this->columns[$table][$column['Field']]=$column['Type'];
            // Full scans lock records and gaps, including currently empty tables, until commit.
            $image[$table]=$this->pdo->query('SELECT * FROM ' . $this->table($table) . ' FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        }
        // Native catalog has no upstream FK/trigger cascades. Refuse introduced write paths,
        // including aliases whose names cannot reveal their semantic target.
        foreach ($this->pdo->query('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $target) {
            if (str_starts_with($target,$this->prefix) && array_key_exists(substr($target,strlen($this->prefix)),$this->manifest['rows'])) throw new RuntimeException('Unexpected foreign key into seed closure');
        }
        foreach ($this->pdo->query('SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $target) {
            if (str_starts_with($target,$this->prefix) && array_key_exists(substr($target,strlen($this->prefix)),$this->manifest['rows'])) throw new RuntimeException('Unexpected trigger on seed closure');
        }
        return [$image,$schema];
    }

    private function plan(array $image,array $schema): array
    {
        $selected=$this->selected($image); $any=false; $allEmpty=true;
        foreach ($this->manifest['rows'] as $table=>$expected) {
            if (!isset($schema[$table])) {
                if ($table === 'product_special' && !$expected) continue;
                throw new RuntimeException('Required seed table missing: ' . $table);
            }
            $actualShape=[];
            foreach ($schema[$table] as $field) $actualShape[$field['Field']] = [
                'type'=>preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/i','$1',$field['Type']),
                'null'=>$field['Null'],'key'=>$field['Key'],'extra'=>$field['Extra']];
            if ($actualShape !== $this->manifest['schema'][$table]) throw new RuntimeException('Unexpected seed schema types: '.$table);
            $expectedKeys = $expected ? array_keys($expected[0]) : null;
            if ($expectedKeys) {
                $actualKeys=array_keys($this->columns[$table]); sort($actualKeys); sort($expectedKeys);
                if ($actualKeys!==$expectedKeys) throw new RuntimeException('Unexpected seed schema: ' . $table);
            }
            if ($selected[$table]) $allEmpty=false;
            if ($this->canonical($selected[$table],$table)!==$this->canonical($expected,$table)) $any=true;
        }
        if ($any && !$allEmpty) throw new RuntimeException('Changed/missing seed core or owned rowset; preserve and investigate');
        $this->references($image,$selected);
        $hashes=[];
        foreach ($image as $table=>$rows) $hashes[$table]=hash('sha256',$this->canonical($rows,$table));
        $guard=hash('sha256',json_encode([self::TASK,self::MANIFEST_SHA256,$this->prefix,$schema,$hashes],JSON_THROW_ON_ERROR));
        return ['task'=>self::TASK,'manifest'=>self::MANIFEST_SHA256,'guard'=>$guard,'state'=>$allEmpty?'already-clean':'ready',
            'counts'=>array_map('count',$selected),'entities'=>$this->manifest['entities'],
            'rowset_hashes'=>array_combine(array_keys($selected),array_map(fn($rows,$table)=>hash('sha256',$this->canonical($rows,$table)),$selected,array_keys($selected))),
            'reason'=>$this->manifest['provenance'],
            'retained'=>$this->manifest['retained']];
    }

    private function selected(array $image): array
    {
        $result=[];
        foreach ($this->manifest['rows'] as $table=>$expected) {
            $result[$table]=array_values(array_filter($image[$table] ?? [], fn($row)=>$this->owned($table,$row)));
        }
        return $result;
    }

    private function owned(string $table,array $row): bool
    {
        if ($table==='seo_url') {
            $type=['product_id'=>'product','category_id'=>'category','manufacturer_id'=>'manufacturer'][$row['key']] ?? '';
            return $type!=='' && in_array((int)$row['value'],$this->manifest['entities'][$type],true);
        }
        if ($table==='layout_module') return in_array((int)$row['layout_module_id'],[3,4,5,6],true);
        foreach (['product','category','manufacturer','banner','coupon','module','attribute_group','attribute'] as $type) {
            if ($table===$type || str_starts_with($table,$type.'_')) {
                return in_array((int)($row[$type.'_id'] ?? 0),$this->manifest['entities'][$type],true);
            }
        }
        throw new RuntimeException('Unknown owned rowset');
    }

    private function references(array $image,array $selected): void
    {
        foreach ($image as $table=>$rows) foreach ($rows as $row) {
            if ($this->contains($selected[$table] ?? [],$row,$table)) continue;
            foreach ($row as $column=>$value) {
                $type = $this->target($table,$column);
                if ($type && in_array((int)$value,$this->manifest['entities'][$type],true)) throw new RuntimeException('Live incoming reference: '.$table.'.'.$column);
                if (is_string($value)) $this->structured($value,$table,$column);
            }
        }
    }

    private function target(string $table,string $key): string
    {
        if (in_array($key,['product_id','category_id','manufacturer_id','banner_id','coupon_id','module_id','attribute_id','attribute_group_id'],true)) return substr($key,0,-3);
        if ($key==='related_id' && $table==='product_related') return 'product';
        if ($key==='master_id' && $table==='product') return 'product';
        if (($key==='parent_id' && $table==='category') || ($key==='path_id' && $table==='category_path')) return 'category';
        return '';
    }

    private function structured(string $value,string $table,string $column): void
    {
        $json=json_decode($value,true);
        if (is_array($json)) $this->jsonReferences($json,$table);
        $plain=html_entity_decode(rawurldecode($value),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if (preg_match_all('~(?:^|[?&;/\\s])((?:product|category|manufacturer|banner|coupon|module|attribute(?:_group)?)_id|path)=([0-9_]+)~',$plain,$matches,PREG_SET_ORDER)) {
            foreach($matches as $match) {
                $type=$match[1]==='path'?'category':substr($match[1],0,-3);
                foreach(explode('_',$match[2]) as $id) if(in_array((int)$id,$this->manifest['entities'][$type],true)) throw new RuntimeException('Live URL reference: '.$table.'.'.$column);
            }
        }
        if ($table==='layout_module' && $column==='code' && preg_match('~\\.([0-9]+)$~',$value,$match)
            && in_array((int)$match[1],$this->manifest['entities']['module'],true)) throw new RuntimeException('Live module placement');
        foreach ($this->manifest['rows']['seo_url'] as $seo) {
            $keyword=preg_quote($seo['keyword'],'~');
            if ($keyword!=='' && preg_match('~(?:^|[/"\\s])'.$keyword.'(?:$|[/?#"\\s])~u',$plain)) throw new RuntimeException('Live seed SEO link: '.$table.'.'.$column);
        }
    }

    private function jsonReferences(array $data,string $table,string $context=''): void
    {
        foreach($data as $key=>$value) {
            $name=is_string($key)?$key:$context;
            $type=$this->target($table,$name);
            $type=$type ?: (['product'=>'product','products'=>'product','category'=>'category','categories'=>'category','manufacturer'=>'manufacturer','manufacturers'=>'manufacturer','banner'=>'banner','coupon'=>'coupon','module'=>'module','attribute'=>'attribute','attributes'=>'attribute','attribute_group'=>'attribute_group','attribute_groups'=>'attribute_group'][$name] ?? '');
            if (is_array($value)) $this->jsonReferences($value,$table,$name);
            elseif ($type && is_numeric($value) && in_array((int)$value,$this->manifest['entities'][$type],true)) throw new RuntimeException('Live structured reference: '.$table.'.'.$name);
        }
    }

    private function canonical(array $rows,string $table): string
    {
        $values=[];
        foreach($rows as $row) {
            ksort($row);
            foreach($row as $key=>&$value) if($value!==null) {
                $value=(string)$value;
                if(preg_match('/^(?:binary|varbinary|bit|tinyblob|blob|mediumblob|longblob)/i',$this->columns[$table][$key] ?? '')) {
                    $value=['binary_hex'=>bin2hex($value)];
                    continue;
                }
                if(preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|float|double)/i',$this->columns[$table][$key] ?? '')) {
                    if (str_contains($value,'.')) $value=rtrim(rtrim($value,'0'),'.');
                    if ($value==='-0') $value='0';
                }
            }
            unset($value); $values[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        sort($values,SORT_STRING); return '['.implode(',',$values).']';
    }

    private function contains(array $rows,array $needle,string $table): bool
    {
        if (!$rows) return false;
        $match=$this->canonical([$needle],$table);
        foreach($rows as $row) if($this->canonical([$row],$table)===$match) return true;
        return false;
    }

    private function predicate(array $row): array
    {
        $parts=[]; $params=[];
        foreach($row as $key=>$value) { $parts[]='`'.$key.'` <=> ?'; $params[]=$value; }
        return [implode(' AND ',$parts),$params];
    }

    private function table(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D',$name)) throw new RuntimeException('Invalid table name');
        return '`'.$this->prefix.$name.'`';
    }
}
