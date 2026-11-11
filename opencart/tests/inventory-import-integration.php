<?php
declare(strict_types=1);

require $argv[1] ?? '/var/www/html/config.php';
require_once __DIR__ . '/../extension/cmteh/system/Database.php';
require_once __DIR__ . '/../extension/cmteh/system/CatalogSchema.php';
foreach (['InventoryException', 'InventoryInput', 'InventorySchema', 'Inventory', 'InventoryImport'] as $class) {
    require_once __DIR__ . '/../extension/cmteh/system/' . $class . '.php';
}

use Cmteh\Database;
use Cmteh\Inventory;
use Cmteh\InventoryImport;
use Cmteh\InventorySchema;
use Cmteh\CatalogSchema;

function checkImport(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function rejectImport(callable $fn,string $reason,string $message): void {
    try { $fn(); } catch (Cmteh\InventoryException $e) { checkImport($e->reason===$reason,$message); return; }
    throw new RuntimeException('Expected import rejection: '.$message);
}

$pdo = Database::connectFromOpenCart();
$prefix = 'imi_' . bin2hex(random_bytes(6)) . '_';
$owned = [];
try {
    $pdo->exec("CREATE TABLE `{$prefix}product` LIKE `" . DB_PREFIX . "product`"); $owned[] = 'product';
    foreach (['product_to_store','product_description','manufacturer','manufacturer_to_store','product_option_value'] as $name) {
        $pdo->exec("CREATE TABLE `{$prefix}{$name}` LIKE `" . DB_PREFIX . $name . '`'); $owned[] = $name;
    }
    CatalogSchema::migrate($pdo, $prefix);
    InventorySchema::migrate($pdo, $prefix);
    $stageBefore=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$prefix}cmteh_inventory_import_preview'")->fetchColumn();
    new InventoryImport($pdo,$prefix,new Inventory($pdo,$prefix));
    checkImport($stageBefore===0,'Constructing the import service does not migrate or write preview state');
    InventoryImport::migrate($pdo,$prefix);
    InventoryImport::migrate($pdo,$prefix);
    checkImport((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$prefix}cmteh_inventory_import_preview'")->fetchColumn()===1,'Explicit import migration is idempotent');
    $pdo->exec("INSERT INTO `{$prefix}product` (product_id,model,quantity,status,date_available,master_id) VALUES (1,'0000123',8,1,'2020-01-01',0),(2,'OTHER',5,1,'2020-01-01',0)");
    $pdo->exec("INSERT INTO `{$prefix}product_to_store` (product_id,store_id) VALUES(1,0),(2,0)");
    $pdo->exec("INSERT INTO `{$prefix}product_description` (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES(1,1,'First','','','','',''),(2,1,'Second','','','','','')");
    $pdo->exec("INSERT INTO `{$prefix}cmteh_product` (product_id,source_id,slug,sku,sku_norm,mpn_norm,name_norm,brand_norm,manufacturer_id,category_id,published_at,indexable,price_status,data) VALUES(1,'public-first','first','0000123','0000123','','first','',0,0,'2020-01-01',1,'on_request','{}'),(2,'public-second','second','OTHER','other','','second','',0,0,'2020-01-01',1,'on_request','{}')");
    $inventory = new Inventory($pdo, $prefix);
    $actor = ['id'=>7,'label'=>'Synthetic operator'];
    $seq = 0;
    $command = static function(string $kind,array $input) use($inventory,$actor,&$seq): array {
        return $inventory->command($kind,$input,$actor,'import-fixture-'.(++$seq));
    };
    $warehouse = $command('warehouse.save',['expected_warehouse_version'=>0,'code'=>'WH-A','name'=>'Warehouse A','city'=>'Synthetic','delivery_term'=>'2 days'])['warehouse'];
    foreach ([1,2] as $id) {
        $state = $inventory->productState($id);
        $command('product.activate',['product_id'=>$id,'expected_product_version'=>0,'expected_legacy_quantity'=>$state['legacy_quantity'],'expected_legacy_revision'=>$state['legacy_revision'],'confirmed'=>true,'allocations'=>[['warehouse_id'=>$warehouse['warehouse_id'],'quantity'=>$state['legacy_quantity'],'expected_warehouse_version'=>1]]]);
    }
    $import = new InventoryImport($pdo,$prefix,$inventory);
    $utf8=InventoryImport::parseCsv("\xEF\xBB\xBFarticle;warehouse_code;quantity;price;delivery_term\r\n0000123;WH-A;9;125.50;Срок\r\n");
    checkImport($utf8[0]['article']==='0000123' && $utf8[0]['delivery_term']==='Срок', 'UTF-8 BOM CSV keeps a leading-zero text SKU');
    $cp1251=iconv('UTF-8','Windows-1251//STRICT',"article;warehouse_code;quantity;price;delivery_term\r\n0000123;WH-A;9;;Склад\r\n");
    $legacy=InventoryImport::parseCsv($cp1251);
    checkImport($legacy[0]['article']==='0000123' && $legacy[0]['delivery_term']==='Склад', 'Windows-1251 CSV decodes to strict UTF-8 rows');
    if(class_exists(ZipArchive::class)) {
        $xlsx=tempnam(sys_get_temp_dir(),'inventory-xlsx-');
        $zip=new ZipArchive(); $zip->open($xlsx,ZipArchive::CREATE|ZipArchive::OVERWRITE);
        $sheet='<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>article</t></is></c><c r="B1" t="inlineStr"><is><t>warehouse_code</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>0000123</t></is></c><c r="B2" t="inlineStr"><is><t>WH-A</t></is></c></row></sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml',$sheet); $zip->close();
        InventoryImport::validateXlsxFile($xlsx);
        checkImport(true, 'Bounded XLSX inspection accepts text typed leading-zero article and warehouse code');
        $zip->open($xlsx,ZipArchive::OVERWRITE); $zip->addFromString('xl/worksheets/sheet1.xml',str_replace('</c></row></sheetData>','</c><c r="C2" t="n"><f>1+1</f><v>2</v></c></row></sheetData>',$sheet)); $zip->close();
        try { InventoryImport::validateXlsxFile($xlsx); throw new RuntimeException('formula XLSX unexpectedly accepted'); }
        catch(Cmteh\InventoryException $e) { checkImport($e->reason==='invalid_input'&&str_contains($e->getMessage(),'строке 2'),'XLSX formula cells are rejected with their row number before import preview'); }
        $zip->open($xlsx,ZipArchive::OVERWRITE); $zip->addFromString('xl/worksheets/sheet1.xml',str_replace('t="inlineStr"><is><t>0000123','t="n"><v>123',$sheet)); $zip->close();
        try { InventoryImport::validateXlsxFile($xlsx); throw new RuntimeException('numeric SKU XLSX unexpectedly accepted'); }
        catch(Cmteh\InventoryException $e) { checkImport($e->reason==='invalid_input','Numeric typed XLSX article cannot lose leading zeros'); }
        unlink($xlsx);
    }
    $rows = [
        ['article'=>'0000123','warehouse_code'=>'WH-A','quantity'=>'9','price'=>'125.50','delivery_term'=>'Next day'],
    ];
    $preview = $import->preview($rows,$actor);
    checkImport($preview['can_apply'] && $preview['summary']['changed']===1 && $preview['rows'][0]['before']['quantity']===8 && $preview['rows'][0]['after']['quantity']===9, 'Preview resolves exact text SKU and shows before/after without changing stock');
    rejectImport(fn()=>$import->apply($preview['preview_id'],$preview['digest'],['id'=>8,'label'=>'Another operator'],'wrong-owner-apply-01'),'not_found','Preview cannot be applied by another actor');
    rejectImport(fn()=>$import->apply($preview['preview_id'],str_repeat('0',64),$actor,'wrong-digest-apply-01'),'request_conflict','Preview digest is required to match stored normalized rows');
    checkImport($inventory->productState(1)['on_hand']===8 && $inventory->productState(2)['on_hand']===5, 'Preview leaves every balance unchanged');
    $receipt = $import->apply($preview['preview_id'],$preview['digest'],$actor,'inventory-import-apply-01');
    checkImport(!$receipt['replayed'] && $inventory->productState(1)['on_hand']===9 && $inventory->productState(2)['on_hand']===5, 'Apply changes only listed pair and leaves omitted products untouched');
    $importedBalance=$inventory->balances(['product_id'=>1,'warehouse_id'=>$warehouse['warehouse_id']])['rows'][0];
    checkImport($importedBalance['price']==='125.50' && (int)$importedBalance['published']===0,'Imported price remains unpublished unless the operator explicitly selected publication');
    $publishRows=[['article'=>'0000123','warehouse_code'=>'WH-A','quantity'=>'9','price'=>'125.50','delivery_term'=>'Next day','_publish'=>true]];
    $publishPreview=$import->preview($publishRows,$actor); $import->apply($publishPreview['preview_id'],$publishPreview['digest'],$actor,'inventory-import-publish-01');
    $importedBalance=$inventory->balances(['product_id'=>1,'warehouse_id'=>$warehouse['warehouse_id']])['rows'][0];
    checkImport((int)$importedBalance['published']===1,'Explicit preview publication checkbox publishes the imported offer atomically');
    $replay = $import->apply($preview['preview_id'],$preview['digest'],$actor,'inventory-import-apply-01');
    checkImport($replay['replayed'] && $replay['command_id']===$receipt['command_id'], 'Exact apply replay returns same receipt without double movement');
    $outerPreview=$import->preview([['article'=>'0000123','warehouse_code'=>'WH-A','quantity'=>'9','price'=>'125.50','delivery_term'=>'Next day']],$actor);
    $pdo->beginTransaction(); $outerReceipt=$import->apply($outerPreview['preview_id'],$outerPreview['digest'],$actor,'inventory-import-outer-rollback');
    checkImport($pdo->inTransaction()&&!$outerReceipt['replayed'],'Import apply uses a savepoint inside an existing PDO transaction');
    $pdo->rollBack();
    checkImport($inventory->productState(1)['on_hand']===9,'Outer caller rollback removes the complete import');
    $import->apply($outerPreview['preview_id'],$outerPreview['digest'],$actor,'inventory-import-outer-rollback');
    checkImport($inventory->productState(1)['on_hand']===9,'Rolled-back import can be safely retried with the same key');
    $bad = $import->preview([
        ['article'=>'0000123','warehouse_code'=>'WH-A','quantity'=>'1.5','price'=>'10','delivery_term'=>''],
        ['article'=>'0000123','warehouse_code'=>'WH-A','quantity'=>'4','price'=>'-3','delivery_term'=>''],
    ],$actor);
    checkImport(!$bad['can_apply'] && count($bad['errors'])===2, 'Fractional quantity, invalid price, and duplicate key reject preview with row errors');
    $stale = $import->preview([
        ['article'=>'0000123','warehouse_code'=>'WH-A','quantity'=>'10','price'=>'125.50','delivery_term'=>''],
        ['article'=>'OTHER','warehouse_code'=>'WH-A','quantity'=>'5','price'=>'20','delivery_term'=>''],
    ],$actor);
    $state=$inventory->productState(2); $balance=$inventory->balances(['product_id'=>2])['rows'][0];
    $command('reservation.create',['product_id'=>2,'warehouse_id'=>$warehouse['warehouse_id'],'expected_product_version'=>$state['version'],'expected_balance_version'=>$balance['version'],'expected_warehouse_version'=>1,'quantity'=>1,'reason'=>'Synthetic conflict']);
    try { $import->apply($stale['preview_id'],$stale['digest'],$actor,'inventory-import-stale-01'); throw new RuntimeException('stale import unexpectedly applied'); }
    catch (Cmteh\InventoryException $e) { checkImport($e->reason==='stale_version' || $e->reason==='insufficient_stock','Concurrent reservation rejects the whole preview after an earlier product was ready'); }
    $failedReview=$import->storedPreview($stale['preview_id'],$stale['digest'],$actor,1,false);
    checkImport($failedReview!==null&&!$failedReview['can_apply']&&count($failedReview['rows'])===2,'Failed apply preserves review rows but disables retry on stale versions');
    checkImport($inventory->productState(1)['on_hand']===9 && $inventory->productState(2)['on_hand']===5, 'Rejected multi-product preview rolls back earlier rows');

    $pdo->exec("UPDATE `{$prefix}cmteh_product` SET sku='0000123' WHERE product_id=2");
    $ambiguous=$import->preview([['article'=>'0000123','warehouse_code'=>'WH-A','quantity'=>'5','price'=>'20','delivery_term'=>'']],$actor);
    checkImport(!$ambiguous['can_apply'] && str_contains($ambiguous['errors'][0]['message'],'неоднозначен'), 'Duplicate exact SKU fails closed without product substitution');
    $disambiguated=$import->preview([['article'=>'0000123','warehouse_code'=>'WH-A','quantity'=>'5','price'=>'20','delivery_term'=>'','source_id'=>'public-second']],$actor);
    checkImport($disambiguated['can_apply'] && $disambiguated['rows'][0]['article']==='0000123', 'Optional public source ID disambiguates exact SKU while checking the pair');

    $pdo->exec("INSERT INTO `{$prefix}product` (product_id,model,quantity,status,date_available,master_id) VALUES (4,'CUTOVER-SKU',10,1,'2020-01-01',0)");
    $pdo->exec("INSERT INTO `{$prefix}product_to_store` (product_id,store_id) VALUES(4,0)");
    $pdo->exec("INSERT INTO `{$prefix}product_description` (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES(4,1,'Cutover','','','','','')");
    $pdo->exec("INSERT INTO `{$prefix}cmteh_product` (product_id,source_id,slug,sku,sku_norm,mpn_norm,name_norm,brand_norm,manufacturer_id,category_id,published_at,indexable,price_status,data) VALUES(4,'public-cutover','cutover','CUTOVER-SKU','cutover-sku','','cutover','',0,0,'2020-01-01',1,'on_request','{}')");
    $cutoverRow=['article'=>'CUTOVER-SKU','warehouse_code'=>'WH-A','quantity'=>'8','price'=>'','delivery_term'=>''];
    $needsConfirm=$import->preview([$cutoverRow],$actor);
    checkImport(!$needsConfirm['can_apply'] && $needsConfirm['rows'][0]['before']['quantity']===10 && $needsConfirm['rows'][0]['cutover_difference']===-2, 'First import shows legacy total and explicit cutover difference before confirmation');
    $cutoverRow['confirm_cutover']=true; $cutoverRow['cutover_reason']='Фактический пересчёт на складе';
    $confirmed=$import->preview([$cutoverRow],$actor);
    checkImport($confirmed['can_apply'] && $confirmed['summary']['cutovers']===1, 'Confirmed cutover with an audit reason can be applied');
    $import->apply($confirmed['preview_id'],$confirmed['digest'],$actor,'inventory-import-cutover-01');
    checkImport($inventory->productState(4)['managed'] && $inventory->productState(4)['on_hand']===8, 'Cutover activates only the explicitly listed warehouse allocation');

    $warehouseIds=[]; $allocations=[]; $bulkRows=[];
    for($i=0;$i<1000;$i++) {
        $code='B'.str_pad((string)$i,4,'0',STR_PAD_LEFT);
        $w=$command('warehouse.save',['expected_warehouse_version'=>0,'code'=>$code,'name'=>'Bulk '.$i,'city'=>'','delivery_term'=>''])['warehouse'];
        $warehouseIds[]=(int)$w['warehouse_id']; $allocations[]=['warehouse_id'=>(int)$w['warehouse_id'],'quantity'=>1,'expected_warehouse_version'=>1];
        $bulkRows[]=['article'=>'BULK-SKU','warehouse_code'=>$code,'quantity'=>'1','price'=>'7.25','delivery_term'=>''];
    }
    $pdo->exec("INSERT INTO `{$prefix}product` (product_id,model,quantity,status,date_available,master_id) VALUES (3,'BULK-SKU',1000,1,'2020-01-01',0)");
    $pdo->exec("INSERT INTO `{$prefix}product_to_store` (product_id,store_id) VALUES(3,0)");
    $pdo->exec("INSERT INTO `{$prefix}product_description` (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES(3,1,'Bulk','','','','','')");
    $pdo->exec("INSERT INTO `{$prefix}cmteh_product` (product_id,source_id,slug,sku,sku_norm,mpn_norm,name_norm,brand_norm,manufacturer_id,category_id,published_at,indexable,price_status,data) VALUES(3,'public-bulk','bulk','BULK-SKU','bulk-sku','','bulk','',0,0,'2020-01-01',1,'on_request','{}')");
    $state=$inventory->productState(3);
    $command('product.activate',['product_id'=>3,'expected_product_version'=>0,'expected_legacy_quantity'=>1000,'expected_legacy_revision'=>$state['legacy_revision'],'confirmed'=>true,'allocations'=>$allocations]);
    $bulkPreview=$import->preview($bulkRows,$actor);
    checkImport($bulkPreview['can_apply'] && $bulkPreview['summary']['rows']===1000, 'Preview accepts an actual bounded 1000-row import');
    $bulkLastPage=$import->storedPreview($bulkPreview['preview_id'],$bulkPreview['digest'],$actor,10);
    checkImport(count($bulkPreview['rows'])===100 && $bulkPreview['pages']===10 && count($bulkLastPage['rows'])===100 && $bulkLastPage['page']===10, 'Large preview returns bounded 100-row pages while retaining all 1,000 rows');
    $bulkReceipt=$import->apply($bulkPreview['preview_id'],$bulkPreview['digest'],$actor,'inventory-import-1000rows');
    checkImport($bulkReceipt['rows']===1000 && $inventory->productState(3)['on_hand']===1000, 'Atomic apply commits all 1000 listed warehouse pairs');
} finally {
    foreach (array_reverse($owned) as $name) $pdo->exec("DROP TABLE IF EXISTS `{$prefix}{$name}`");
    foreach (['cmteh_inventory_import_preview','cmteh_lead_inventory','cmteh_inventory_reservation','cmteh_inventory_entry','cmteh_inventory_movement','cmteh_inventory_command','cmteh_inventory_balance','cmteh_inventory_product','cmteh_warehouse','cmteh_inventory_schema','cmteh_product','cmteh_category','cmteh_brand','cmteh_attribute','cmteh_content','cmteh_route','cmteh_media','cmteh_media_link','cmteh_import','cmteh_schema','cmteh_article','cmteh_page','cmteh_site','cmteh_navigation','cmteh_home_block','cmteh_content_section','cmteh_content_item','cmteh_product_specification','cmteh_product_code','cmteh_product_relation','cmteh_product_social','cmteh_product_category','cmteh_product_brand','cmteh_product_to_store','cmteh_product_description','cmteh_category_description','cmteh_manufacturer','cmteh_manufacturer_to_store'] as $name) $pdo->exec("DROP TABLE IF EXISTS `{$prefix}{$name}`");
}
