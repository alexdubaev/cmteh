<?php
declare(strict_types=1);
namespace Cmteh;

use PDO;
use RuntimeException;

/** Strict server-side staging and atomic apply for explicitly listed warehouse rows. */
final class InventoryImport
{
    private const HEADERS = ['article','warehouse_code','quantity','price','delivery_term'];
    private const MAX_BYTES = 5_242_880;
    private const MAX_ROWS = 10_000;
    private const XLSX_RELATIONSHIPS = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];

    public function __construct(private PDO $pdo, private string $prefix, private Inventory $inventory)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix) || strlen($prefix) > 32) throw new RuntimeException('Invalid database prefix.');
    }

    /** Explicit additive migration; called only from the existing authorized module migrator. */
    public static function migrate(PDO $pdo,string $prefix): void
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D',$prefix)||strlen($prefix)>32) throw new RuntimeException('Invalid database prefix.');
        $table='`'.$prefix.'cmteh_inventory_import_preview`';
        $pdo->exec('CREATE TABLE IF NOT EXISTS '.$table." (preview_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, actor_id INT NOT NULL, digest CHAR(64) CHARACTER SET ascii NOT NULL, payload LONGTEXT NOT NULL, expires_at DATETIME NOT NULL, apply_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE, result JSON NULL, applied_at DATETIME NULL, created_at DATETIME NOT NULL, KEY actor_expiry(actor_id,expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** Decode a strict UTF-8/Windows-1251 semicolon or comma CSV without repairing values. */
    public static function parseCsv(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_BYTES) throw new InventoryException('invalid_input','Файл превышает 5 МБ.');
        if (str_starts_with($bytes,"\xEF\xBB\xBF")) $bytes=substr($bytes,3);
        if (!mb_check_encoding($bytes,'UTF-8')) {
            if (!function_exists('iconv')) throw new InventoryException('invalid_input','Не удалось прочитать UTF-8/Windows-1251 CSV.');
            $decoded=@iconv('Windows-1251','UTF-8//STRICT',$bytes);
            if ($decoded===false) throw new InventoryException('invalid_input','Не удалось прочитать UTF-8/Windows-1251 CSV.');
            $bytes=$decoded;
        }
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$bytes)) throw new InventoryException('invalid_input','CSV содержит управляющие символы.');
        $first=strtok($bytes,"\r\n");
        $delimiter=is_string($first)&&substr_count($first,';')>=substr_count($first,',')?';':',';
        $stream=fopen('php://temp','r+'); if(!$stream) throw new RuntimeException('CSV buffer unavailable.');
        fwrite($stream,$bytes); rewind($stream);
        $header=fgetcsv($stream,0,$delimiter,'"','\\');
        if (!is_array($header)) { fclose($stream); throw new InventoryException('invalid_input','В CSV нет строки заголовков.'); }
        $header=array_map(static fn($v)=>is_string($v)?trim($v):'', $header);
        if ($header!==self::HEADERS && $header!==[...self::HEADERS,'source_id']) { fclose($stream); throw new InventoryException('invalid_input','Заголовки должны быть: article;warehouse_code;quantity;price;delivery_term;source_id (последняя колонка необязательна).'); }
        $rows=[]; $line=1;
        while(($cells=fgetcsv($stream,0,$delimiter,'"','\\'))!==false) {
            $line++;
            if ($line>self::MAX_ROWS+1) { fclose($stream); throw new InventoryException('invalid_input','В файле больше 10 000 строк.'); }
            if ($cells===[null] || $cells===[]) continue;
            if(count($cells)!==count($header)) { $rows[]=['_row'=>$line,'_shape_error'=>'Число колонок не совпадает с шаблоном.']; continue; }
            $row=['_row'=>$line]; foreach($header as $index=>$key) $row[$key]=is_string($cells[$index])?$cells[$index]:'';
            $rows[]=$row;
        }
        fclose($stream); return $rows;
    }

    /** Inspect bounded XLSX XML only; formulas are rejected and never evaluated. */
    public static function validateXlsxFile(string $path,bool $hasSourceId=false): void
    {
        if(!class_exists(\ZipArchive::class)||!is_file($path)||filesize($path)>self::MAX_BYTES) throw new InventoryException('invalid_input','Не удалось безопасно прочитать XLSX до 5 МБ.');
        $zip=new \ZipArchive();
        if($zip->open($path,\ZipArchive::CHECKCONS)!==true) throw new InventoryException('invalid_input','Повреждённый или зашифрованный XLSX.');
        try {
            if($zip->numFiles>1024) throw new InventoryException('invalid_input','В XLSX слишком много файлов.');
            $total=0; $names=[];
            for($i=0;$i<$zip->numFiles;$i++) {
                $name=$zip->getNameIndex($i); $stat=$zip->statIndex($i);
                if(!is_string($name)||!is_array($stat)||str_contains($name,'..')||str_starts_with($name,'/')||isset($names[$name])) throw new InventoryException('invalid_input','В XLSX обнаружена небезопасная запись.');
                $names[$name]=true; $total+=(int)($stat['size']??0);
                if($total>32*1024*1024) throw new InventoryException('invalid_input','XLSX превышает допустимый распакованный размер.');
                if(preg_match('~(?:^|/)(?:externalLinks|vbaProject\.bin)~i',$name)) throw new InventoryException('invalid_input','Внешние ссылки и макросы XLSX не поддерживаются.');
            }
            $workbookSheets = self::workbookSheets($zip);
            $numberedSheets = preg_grep('~^xl/worksheets/sheet[0-9]+\.xml$~i', array_keys($names));
            foreach (array_values(array_unique([...$workbookSheets, ...$numberedSheets])) as $index => $name) {
                self::validateWorksheet(self::xlsxXml($zip, $name), $index === 0, $hasSourceId);
            }
        } finally { $zip->close(); }
    }

    private static function xlsxXml(\ZipArchive $zip, string $path): string
    {
        $xml = $zip->getFromName($path);
        if (!is_string($xml) || !mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0") || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new InventoryException('invalid_input', 'XLSX содержит неподдерживаемую XML-структуру.');
        }
        return $xml;
    }

    private static function workbookSheets(\ZipArchive $zip): array
    {
        $workbook = new \DOMDocument();
        $relationships = new \DOMDocument();
        $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
        if (!$workbook->loadXML(self::xlsxXml($zip, 'xl/workbook.xml'), $flags)
            || !$relationships->loadXML(self::xlsxXml($zip, 'xl/_rels/workbook.xml.rels'), $flags)) {
            throw new InventoryException('invalid_input', 'Не удалось прочитать структуру листов XLSX.');
        }
        $targets = [];
        $worksheetTypes = array_map(static fn(string $namespace) => $namespace . '/worksheet', self::XLSX_RELATIONSHIPS);
        foreach ($relationships->getElementsByTagNameNS('*', 'Relationship') as $relation) {
            if (!in_array($relation->getAttribute('Type'), $worksheetTypes, true)) continue;
            $id = $relation->getAttribute('Id');
            $target = $relation->getAttribute('Target');
            $path = str_starts_with($target, '/') ? substr($target, 1) : 'xl/' . $target;
            if ($id === '' || isset($targets[$id]) || $relation->getAttribute('TargetMode') === 'External'
                || !str_starts_with(strtolower($path), 'xl/') || !str_ends_with(strtolower($path), '.xml')
                || strpbrk($path, ':\\') !== false || str_contains($path, '..')) {
                throw new InventoryException('invalid_input', 'В XLSX обнаружена недопустимая ссылка на лист.');
            }
            $targets[$id] = $path;
        }
        $sheets = [];
        foreach ($workbook->getElementsByTagNameNS('*', 'sheet') as $sheet) {
            $id = $sheet->getAttributeNS(self::XLSX_RELATIONSHIPS[0], 'id')
                ?: $sheet->getAttributeNS(self::XLSX_RELATIONSHIPS[1], 'id');
            if (!isset($targets[$id])) throw new InventoryException('invalid_input', 'В XLSX не найден обычный лист.');
            $sheets[] = $targets[$id];
        }
        if (!$sheets) throw new InventoryException('invalid_input', 'В XLSX не найден лист.');
        return array_values(array_unique($sheets));
    }

    private static function validateWorksheet(string $xml, bool $checkColumns, bool $hasSourceId): void
    {
        $reader = new \XMLReader();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (!$reader->XML($xml, 'UTF-8', LIBXML_NONET)) throw new InventoryException('invalid_input', 'Не удалось прочитать лист XLSX.');
            $rowNumber = 0;
            $columns = [];
            $cellColumn = null;
            $hasWorksheet = false;
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'c') $cellColumn = null;
                if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'row' && $checkColumns && $rowNumber > 1 && $columns) {
                    if (!($columns['A']['text'] ?? false) || !($columns['B']['text'] ?? false)
                        || ($hasSourceId && ($columns['F']['value'] ?? false) && !$columns['F']['text'])) {
                        throw new InventoryException('invalid_input', 'Артикул, код склада и указанный source_id в XLSX должны быть текстовыми ячейками.');
                    }
                }
                if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
                switch ($reader->localName) {
                    case 'worksheet':
                        $hasWorksheet = $reader->depth === 0;
                        break;
                    case 'row':
                        $rowNumber = (int)($reader->getAttribute('r') ?? $rowNumber + 1);
                        $columns = [];
                        break;
                    case 'c':
                        $ref = $reader->getAttribute('r') ?? '';
                        $cellColumn = preg_replace('/[0-9]+$/D', '', $ref);
                        $columns[$cellColumn] = ['text' => in_array($reader->getAttribute('t'), ['s', 'inlineStr', 'str'], true), 'value' => false];
                        if ($reader->isEmptyElement) $cellColumn = null;
                        break;
                    case 'v':
                    case 'is':
                        if ($cellColumn === 'F') $columns['F']['value'] = trim($reader->readString()) !== '';
                        break;
                    case 'f':
                        throw new InventoryException('invalid_input', 'XLSX содержит формулу в строке '.($rowNumber ?: '?').'. Сначала замените формулу значением.');
                }
            }
            if (!$hasWorksheet || libxml_get_last_error()) throw new InventoryException('invalid_input', 'Не удалось прочитать лист XLSX.');
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array{preview_id:string,digest:string,expires_at:string,summary:array,errors:array,rows:array,can_apply:bool} */
    public function preview(array $rows,array $actor): array
    {
        $this->actor($actor);
        $this->pdo->exec('DELETE FROM '.$this->table('cmteh_inventory_import_preview').' WHERE expires_at<UTC_TIMESTAMP() AND apply_key IS NULL LIMIT 200');
        if (!array_is_list($rows)||count($rows)>self::MAX_ROWS) throw new InventoryException('invalid_input','В preview может быть до 10 000 строк.');
        $errors=[]; $normalized=[]; $seen=[]; $groups=[]; $display=[];
        foreach($rows as $index=>$row) {
            $rowNumber=is_array($row)&&isset($row['_row'])?(int)$row['_row']:$index+2;
            $problem=static function(string $message) use(&$errors,$rowNumber): void {$errors[]=['row'=>$rowNumber,'message'=>$message];};
            if(!is_array($row)||isset($row['_shape_error'])) { $problem(is_array($row)?(string)$row['_shape_error']:'Строка должна быть объектом.'); continue; }
            foreach(self::HEADERS as $key) if(!array_key_exists($key,$row)||!is_string($row[$key])) { $problem('Все ячейки должны быть текстом.'); continue 2; }
            try {
                $article=InventoryInput::text($row['article'],'article',128,true);
                $code=InventoryInput::text($row['warehouse_code'],'warehouse_code',64,true);
                $qty=InventoryInput::integer($row['quantity'],'quantity');
                $price=InventoryInput::price(trim($row['price']));
                $term=InventoryInput::text($row['delivery_term'],'delivery_term',255);
                if(preg_match('/^\s*=/',implode("\n",[$article,$code,$row['quantity'],$row['price'],$term]))) throw new InventoryException('invalid_input','Формулы и выражения не принимаются.');
            } catch(InventoryException $e) { $problem($e->getMessage()); continue; }
            $sourceId='';
            if(array_key_exists('source_id',$row)) { try {$sourceId=InventoryInput::text($row['source_id'],'source_id',160);} catch(InventoryException $e) {$problem($e->getMessage());continue;} }
            $matches=$this->resolveArticle($article,$sourceId);
            if(count($matches)!==1) { $problem(count($matches)===0?'Артикул не найден точно.':'Артикул неоднозначен; нужна уникальная связь с товаром.'); continue; }
            $native=$matches[0];
            $warehouse=$this->one('SELECT warehouse_id,code,name,city,delivery_term,version,active FROM '.$this->table('cmteh_warehouse').' WHERE BINARY code=BINARY ?',[$code]);
            if(!$warehouse) { $problem('Склад с таким кодом не найден.'); continue; }
            if(!(int)$warehouse['active']) { $problem('Склад архивирован.'); continue; }
            $pid=(int)$native['product_id']; $wid=(int)$warehouse['warehouse_id']; $pair=$pid.':'.$wid;
            if(isset($seen[$pair])) { $problem('Пара товар/склад повторяется в файле.'); continue; }
            $seen[$pair]=true;
            $state=$this->inventory->productState($pid);
            $balance=$this->one('SELECT * FROM '.$this->table('cmteh_inventory_balance').' WHERE product_id=? AND warehouse_id=?',[$pid,$wid]);
            if (!$state) { $problem('Товар изменился во время preview. Повторите загрузку.'); continue; }
            $entry=['row'=>$rowNumber,'product_id'=>$pid,'article'=>$article,'identity'=>self::productIdentity($native),'warehouse_id'=>$wid,'warehouse_code'=>$code,
                'warehouse_version'=>(int)$warehouse['version'],'expected_product_version'=>(int)$state['version'],
                'expected_legacy_quantity'=>(int)$state['legacy_quantity'],'expected_legacy_revision'=>$state['legacy_revision'],
                'expected_balance_version'=>(int)($balance['version']??0),'before_quantity'=>$balance?(int)$balance['on_hand']:0,
                'before_reserved'=>$balance?(int)$balance['reserved']:0,'before_price'=>$balance&&$balance['price']!==null?(string)$balance['price']:null,
                'before_term'=>$balance?(string)$balance['delivery_term']:'','quantity'=>$qty,'price'=>$price,'delivery_term'=>$term,
                'published'=>$balance?(bool)$balance['published']:false,'publish'=>($row['_publish']??false)===true||($row['_publish']??'')==='1',
                'confirm_cutover'=>($row['confirm_cutover']??false)===true||($row['confirm_cutover']??'')==='1',
                'cutover_reason'=>is_string($row['cutover_reason']??null)?trim($row['cutover_reason']):''];
            $normalized[]=$entry; $groups[$pid][]=$entry;
            $display[]=['row'=>$rowNumber,'_product_id'=>$pid,'article'=>$article,'warehouse_code'=>$code,'managed'=>$state['managed'],
                'before'=>['quantity'=>$state['managed']?$entry['before_quantity']:$state['legacy_quantity'],'reserved'=>$entry['before_reserved'],'price'=>$entry['before_price'],'delivery_term'=>$entry['before_term'],'label'=>$state['managed']?'до':'старый общий остаток'],
                'after'=>['quantity'=>$qty,'price'=>$price,'delivery_term'=>$term,'published'=>$entry['published']||$entry['publish']], 'cutover'=>!$state['managed']];
        }
        $cutoverProducts=[];
        foreach($groups as $pid=>$items) {
            $state=$this->inventory->productState((int)$pid);
            if($state['managed']) continue;
            $cutoverProducts[(int)$pid]=true;
            $sum=0; foreach($items as $item) $sum+=$item['quantity'];
            if($sum>InventoryInput::MAX_QUANTITY) { foreach($items as $item) $errors[]=['row'=>$item['row'],'message'=>'Сумма распределения превышает допустимый остаток.']; continue; }
            $reason=$items[0]['cutover_reason']; $confirmed=$items[0]['confirm_cutover'];
            foreach($items as $item) if($item['cutover_reason']!==$reason||$item['confirm_cutover']!==$confirmed) $errors[]=['row'=>$item['row'],'message'=>'Причина и подтверждение перехода должны совпадать для товара.'];
            if(!$confirmed) foreach($items as $item) $errors[]=['row'=>$item['row'],'message'=>'Явно подтвердите переход старого общего остатка на складской учёт.'];
            if($sum!==(int)$state['legacy_quantity']&&$reason==='') foreach($items as $item) $errors[]=['row'=>$item['row'],'message'=>'Распределение отличается от старого остатка; укажите причину расхождения.'];
            foreach($display as &$item) if($item['_product_id']===(int)$pid) $item['cutover_difference']=$sum-(int)$state['legacy_quantity']; unset($item);
        }
        foreach($display as &$item) unset($item['_product_id']); unset($item);
        $digest=hash('sha256',json_encode(InventoryInput::canonical($normalized),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $previewId=bin2hex(random_bytes(16)); $expires=(new \DateTimeImmutable('+30 minutes',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $canApply=$normalized!==[]&&$errors===[];
        $payload=['rows'=>$normalized,'display'=>$display,'errors'=>$errors,'summary'=>['rows'=>count($rows),'changed'=>count($normalized),'errors'=>count($errors),'cutovers'=>count($cutoverProducts)]];
        $stmt=$this->pdo->prepare('INSERT INTO '.$this->table('cmteh_inventory_import_preview').' (preview_id,actor_id,digest,payload,expires_at,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');
        $stmt->execute([$previewId,$actor['id'],$digest,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$expires]);
        return $this->pagePreview($payload,$previewId,$digest,$expires,$canApply,1);
    }

    /** Return a same-actor preview after a rejected apply so the operator can inspect it. */
    public function storedPreview(string $previewId,string $digest,array $actor,int $page=1,bool $retryAllowed=true): ?array
    {
        $actor=$this->actor($actor);
        $row=$this->one('SELECT actor_id,digest,payload,expires_at,apply_key FROM '.$this->table('cmteh_inventory_import_preview').' WHERE preview_id=?',[$previewId]);
        if(!$row||(int)$row['actor_id']!==$actor['id']||!hash_equals((string)$row['digest'],$digest)) return null;
        $payload=json_decode((string)$row['payload'],true,64,JSON_THROW_ON_ERROR);
        return $this->pagePreview($payload,$previewId,$digest,(string)$row['expires_at'],$retryAllowed&&$row['apply_key']===null&&strtotime((string)$row['expires_at'].' UTC')>=time()&&$payload['errors']===[]&&$payload['rows']!==[], $page);
    }

    public function apply(string $previewId,string $digest,array $actor,string $requestKey): array
    {
        $actor=$this->actor($actor);
        if(!preg_match('/^[a-f0-9]{32}$/D',$previewId)||!preg_match('/^[a-f0-9]{64}$/D',$digest)||!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D',$requestKey)) throw new InventoryException('invalid_input','Проверьте данные подтверждения импорта.');
        $external=$this->pdo->inTransaction();
        if(!$external) $this->pdo->beginTransaction(); else $this->pdo->exec('SAVEPOINT cmteh_import_apply');
        try {
            $preview=$this->one('SELECT * FROM '.$this->table('cmteh_inventory_import_preview').' WHERE preview_id=? FOR UPDATE',[$previewId]);
            if(!$preview||(int)$preview['actor_id']!==$actor['id']) throw new InventoryException('not_found','Preview не найден.',404);
            if(!hash_equals((string)$preview['digest'],$digest)) throw new InventoryException('request_conflict','Данные preview изменились.',409);
            if($preview['apply_key']!==null) {
                if(!hash_equals((string)$preview['apply_key'],$requestKey)||$preview['result']===null) throw new InventoryException('request_conflict','Preview уже применён с другим ключом.',409);
                $result=json_decode((string)$preview['result'],true,64,JSON_THROW_ON_ERROR); $result['replayed']=true;
                if($external) $this->pdo->exec('RELEASE SAVEPOINT cmteh_import_apply'); else $this->pdo->commit(); return $result;
            }
            if(strtotime((string)$preview['expires_at'].' UTC')<time()) throw new InventoryException('stale_version','Preview устарел. Загрузите файл и проверьте его снова.',409);
            $payload=json_decode((string)$preview['payload'],true,64,JSON_THROW_ON_ERROR);
            if(!$payload['errors']||count($payload['errors'])>0||!$payload['rows']) {
                if($payload['errors']||!$payload['rows']) throw new InventoryException('invalid_input','Исправьте ошибки preview перед применением.');
            }
            $rows=$payload['rows'];
            $identities=$this->lockAll($rows);
            foreach ($rows as $row) {
                $product = $identities[$row['product_id']] ?? null;
                if (!isset($row['identity'])) {
                    throw new InventoryException('stale_version', 'Preview создан до обновления. Повторите загрузку.', 409);
                }
                if (!$product || $row['identity'] !== self::productIdentity($product)) {
                    throw new InventoryException('stale_version', 'Артикул или связь с товаром изменились после preview. Повторите загрузку.', 409);
                }
            }
            $commandCount=0; $groups=[]; foreach($rows as $row) $groups[$row['product_id']][]=$row;
            $ordinal=0;
            foreach($groups as $pid=>$items) {
                $state=$this->inventory->productState((int)$pid);
                foreach($items as $row) $this->assertFresh($row,$state);
                if(!$state['managed']) {
                    $allocations=[]; foreach($items as $row) $allocations[]=['warehouse_id'=>$row['warehouse_id'],'quantity'=>$row['quantity'],'expected_warehouse_version'=>$row['warehouse_version']];
                    $sum=array_sum(array_column($items,'quantity')); $reason=$items[0]['cutover_reason'];
                    $this->inventory->command('product.activate',['product_id'=>(int)$pid,'expected_product_version'=>0,
                        'expected_legacy_quantity'=>$state['legacy_quantity'],'expected_legacy_revision'=>$state['legacy_revision'],
                        'confirmed'=>true,'reason'=>$sum===$state['legacy_quantity']?'':$reason,'allocations'=>$allocations],$actor,$this->commandKey($requestKey,'a',++$ordinal));
                    $commandCount++;
                }
                foreach($items as $row) {
                    $fresh=$this->inventory->productState((int)$pid);
                    $balance=$this->one('SELECT * FROM '.$this->table('cmteh_inventory_balance').' WHERE product_id=? AND warehouse_id=? FOR UPDATE',[$pid,$row['warehouse_id']]);
                    if((int)($balance['reserved']??0)>$row['quantity']) throw new InventoryException('insufficient_stock','Количество ниже активного резерва; весь импорт отменён.',409);
                    $this->inventory->command('balance.count',['product_id'=>(int)$pid,'warehouse_id'=>$row['warehouse_id'],
                        'expected_product_version'=>$fresh['version'],'expected_balance_version'=>(int)($balance['version']??0),'expected_warehouse_version'=>$row['warehouse_version'],
                        'quantity'=>$row['quantity'],'reason'=>'Импорт подтверждённого количества строки '.$row['row']],$actor,$this->commandKey($requestKey,'q',++$ordinal));
                    $commandCount++;
                    $fresh=$this->inventory->productState((int)$pid); $balance=$this->one('SELECT * FROM '.$this->table('cmteh_inventory_balance').' WHERE product_id=? AND warehouse_id=? FOR UPDATE',[$pid,$row['warehouse_id']]);
                    $this->inventory->command('offer.update',['product_id'=>(int)$pid,'warehouse_id'=>$row['warehouse_id'],
                        'expected_product_version'=>$fresh['version'],'expected_balance_version'=>$balance['version'],'expected_warehouse_version'=>$row['warehouse_version'],
                        'price'=>$row['price'],'published'=>$row['publish']?true:(bool)$balance['published'],'delivery_term'=>$row['delivery_term']],$actor,$this->commandKey($requestKey,'o',++$ordinal));
                    $commandCount++;
                }
            }
            $receipt=['command_id'=>substr(hash('sha256',$requestKey),0,24),'preview_id'=>$previewId,'rows'=>count($rows),'commands'=>$commandCount,'replayed'=>false];
            $this->pdo->prepare('UPDATE '.$this->table('cmteh_inventory_import_preview').' SET apply_key=?,result=?,applied_at=UTC_TIMESTAMP() WHERE preview_id=?')->execute([$requestKey,json_encode($receipt,JSON_THROW_ON_ERROR),$previewId]);
            if($external) $this->pdo->exec('RELEASE SAVEPOINT cmteh_import_apply'); else $this->pdo->commit();
            return $receipt;
        } catch(\Throwable $e) {
            if($external&&$this->pdo->inTransaction()) { $this->pdo->exec('ROLLBACK TO SAVEPOINT cmteh_import_apply'); $this->pdo->exec('RELEASE SAVEPOINT cmteh_import_apply'); }
            elseif($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function assertFresh(array $row,array $state): void
    {
        if((int)$state['version']!==$row['expected_product_version'] || !hash_equals($state['legacy_revision'],$row['expected_legacy_revision'])) throw new InventoryException('stale_version','Товар изменился после preview. Повторите загрузку.',409);
        $warehouse=$this->one('SELECT version,active FROM '.$this->table('cmteh_warehouse').' WHERE warehouse_id=? FOR UPDATE',[$row['warehouse_id']]);
        if(!$warehouse||(int)$warehouse['version']!==$row['warehouse_version']||!(int)$warehouse['active']) throw new InventoryException('stale_version','Склад изменился после preview.',409);
        $balance=$this->one('SELECT version,on_hand,reserved FROM '.$this->table('cmteh_inventory_balance').' WHERE product_id=? AND warehouse_id=? FOR UPDATE',[$row['product_id'],$row['warehouse_id']]);
        if((int)($balance['version']??0)!==$row['expected_balance_version']) throw new InventoryException('stale_version','Остаток/цена изменились после preview.',409);
        if($balance&&(int)$balance['reserved']>$row['quantity']) throw new InventoryException('insufficient_stock','Количество ниже активного резерва.',409);
    }

    private function lockAll(array $rows): array
    {
        $products=array_values(array_unique(array_column($rows,'product_id'))); sort($products,SORT_NUMERIC);
        $in=implode(',',array_fill(0,count($products),'?'));
        $productRows=$this->pdo->prepare('SELECT p.product_id,p.model,c.source_id,c.sku FROM '.$this->table('product').' p LEFT JOIN '.$this->table('cmteh_product').' c ON c.product_id=p.product_id'." WHERE p.product_id IN ($in) ORDER BY p.product_id FOR UPDATE");
        $productRows->execute($products);
        $identities=array_column($productRows->fetchAll(),null,'product_id');
        $this->pdo->prepare('SELECT product_id FROM '.$this->table('cmteh_inventory_product')." WHERE product_id IN ($in) ORDER BY product_id FOR UPDATE")->execute($products);
        $warehouses=array_values(array_unique(array_column($rows,'warehouse_id'))); sort($warehouses,SORT_NUMERIC);
        $wh=implode(',',array_fill(0,count($warehouses),'?'));
        $this->pdo->prepare('SELECT warehouse_id FROM '.$this->table('cmteh_warehouse')." WHERE warehouse_id IN ($wh) ORDER BY warehouse_id FOR UPDATE")->execute($warehouses);
        $pairs=$this->pdo->prepare('SELECT offer_id FROM '.$this->table('cmteh_inventory_balance').' WHERE product_id=? AND warehouse_id=? FOR UPDATE');
        foreach($rows as $row) $pairs->execute([$row['product_id'],$row['warehouse_id']]);
        return $identities;
    }

    private static function productIdentity(array $row): array
    {
        return ['source_id' => $row['source_id'], 'sku' => $row['sku'], 'model' => $row['model']];
    }

    private function resolveArticle(string $article,string $sourceId=''): array
    {
        $sql='SELECT p.product_id,p.model,c.source_id,c.sku FROM '.$this->table('product').' p LEFT JOIN '.$this->table('cmteh_product').' c ON c.product_id=p.product_id WHERE (BINARY c.sku=BINARY ? OR BINARY p.model=BINARY ?)';
        $params=[$article,$article];
        if($sourceId!=='') { $sql.=' AND BINARY c.source_id=BINARY ?'; $params[]=$sourceId; }
        $sql.=' ORDER BY p.product_id LIMIT 3'; $stmt=$this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    }
    private function commandKey(string $base,string $kind,int $ordinal): string { return substr($base,0,42).':'.substr(hash('sha256',$base),0,10).':'.$kind.$ordinal; }
    private function pagePreview(array $payload,string $previewId,string $digest,string $expires,bool $canApply,int $page): array
    {
        $perPage=100; $total=max(count($payload['display']),count($payload['errors'])); $pages=max(1,(int)ceil($total/$perPage)); $page=max(1,min($pages,$page)); $offset=($page-1)*$perPage;
        return ['preview_id'=>$previewId,'digest'=>$digest,'expires_at'=>$expires.' UTC','summary'=>$payload['summary'],
            'errors'=>array_slice($payload['errors'],$offset,$perPage),'rows'=>array_slice($payload['display'],$offset,$perPage),
            'page'=>$page,'pages'=>$pages,'shown_rows'=>min($perPage,max(0,count($payload['display'])-$offset)),
            'can_apply'=>$canApply];
    }
    private function actor(array $actor): array { return ['id'=>InventoryInput::integer($actor['id']??null,'actor',1),'label'=>InventoryInput::text($actor['label']??null,'actor',160,true)]; }
    private function table(string $name): string { return '`'.$this->prefix.$name.'`'; }
    private function one(string $sql,array $params=[]): ?array { $q=$this->pdo->prepare($sql); $q->execute($params); $r=$q->fetch(); return is_array($r)?$r:null; }
}
