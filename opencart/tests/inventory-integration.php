<?php
declare(strict_types=1);

require $argv[1] ?? '/var/www/html/config.php';
require_once __DIR__ . '/../extension/cmteh/system/Database.php';
require_once __DIR__ . '/../extension/cmteh/system/CatalogSchema.php';
foreach (['InventorySchema', 'Inventory'] as $class) {
    $path = __DIR__ . '/../extension/cmteh/system/' . $class . '.php';
    if (is_file($path)) require_once $path;
}
use Cmteh\Database;
use Cmteh\Inventory;
use Cmteh\InventorySchema;

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function rejects(callable $fn, string $reason): void {
    try { $fn(); } catch (Cmteh\InventoryException $e) {
        check($e->reason === $reason, 'Rejected: ' . $reason); return;
    }
    throw new RuntimeException('Expected rejection: ' . $reason);
}

if (($argv[2]??'')==='--race-child') {
    $prefix=$argv[3]??'';
    if(!preg_match('/^ivt_[a-f0-9]{12}_$/D',$prefix)) exit(2);
    $inventory=new Inventory(Database::connectFromOpenCart(),$prefix);
    echo "ready\n"; fflush(STDOUT);
    if(fgets(STDIN)!=="go\n") exit(2);
    $input=json_decode($argv[4],true,32,JSON_THROW_ON_ERROR);
    try { $inventory->command('reservation.create',$input,['id'=>1,'label'=>'Synthetic operator'],$argv[5]); echo "accepted\n"; }
    catch(Cmteh\InventoryException $e) { echo $e->reason."\n"; }
    exit;
}

$pdo = Database::connectFromOpenCart();
$prefix = 'ivt_' . bin2hex(random_bytes(6)) . '_';
$tables = [];
try {
    $pdo->exec("CREATE TABLE `{$prefix}product` LIKE `" . DB_PREFIX . "product`");
    $tables[] = 'product';
    foreach(['product_to_store','product_description','manufacturer','manufacturer_to_store','product_option_value'] as $table) $pdo->exec("CREATE TABLE `{$prefix}{$table}` LIKE `".DB_PREFIX.$table.'`');
    Cmteh\CatalogSchema::migrate($pdo, $prefix);
    $pdo->exec("INSERT INTO `{$prefix}product` (product_id,model,quantity,status,date_available,master_id) VALUES (1,'SYNTHETIC',10,1,'2020-01-01',0)");
    $pdo->exec("INSERT INTO `{$prefix}product_to_store` (product_id,store_id) VALUES(1,0)");
    $pdo->exec("INSERT INTO `{$prefix}product_description` (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES(1,1,'Synthetic','','','','','')");
    if (class_exists(InventorySchema::class)) InventorySchema::migrate($pdo, $prefix);
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$prefix . 'cmteh_inventory_product']);
    check((int)$q->fetchColumn() === 1, 'Inventory migration creates independent readiness without changing native quantity');
    check((int)$pdo->query("SELECT quantity FROM `{$prefix}product` WHERE product_id=1")->fetchColumn() === 10, 'Empty migration preserves legacy stock');
    InventorySchema::migrate($pdo,$prefix);
    $pdo->prepare("INSERT INTO `{$prefix}cmteh_product` (product_id,source_id,slug,sku,sku_norm,mpn_norm,name_norm,brand_norm,manufacturer_id,category_id,published_at,indexable,price_status,data) VALUES(1,?,?,?,'00001','','synthetic','',0,0,'2020-01-01',1,'on_request','{}')")->execute(['public-001','synthetic','00001']);
    $inventory=new Inventory($pdo,$prefix); $actor=['id'=>1,'label'=>'Synthetic operator']; $sequence=0;
    $run=function(string $kind,array $input,?string $key=null) use($inventory,$actor,&$sequence): array {
        return $inventory->command($kind,$input,$actor,$key??'synthetic-command-'.(++$sequence));
    };
    check($inventory->warehouses()['total']===0 && !$inventory->productState(1)['managed'], 'No guessed warehouse or automatic activation');
    $make=fn(string $code,string $term)=>$run('warehouse.save',['expected_warehouse_version'=>0,'code'=>$code,'name'=>'Synthetic '.$code,'city'=>'Synthetic city','delivery_term'=>$term,'address'=>'Private address','note'=>'Private note'])['warehouse'];
    $a=$make('SYN-A','2 days'); $b=$make('SYN-B','5 days');
    $state=$inventory->productState(1);
    $activation=['product_id'=>1,'expected_product_version'=>0,'expected_legacy_quantity'=>10,'expected_legacy_revision'=>$state['legacy_revision'],'confirmed'=>true,'allocations'=>[['warehouse_id'=>$a['warehouse_id'],'quantity'=>7,'expected_warehouse_version'=>1],['warehouse_id'=>$b['warehouse_id'],'quantity'=>3,'expected_warehouse_version'=>1]]];
    $wrong=$activation; $wrong['allocations'][0]['quantity']=6;
    rejects(fn()=>$run('product.activate',$wrong),'invalid_input');
    check(!$inventory->productState(1)['managed'], 'Unexplained cutover discrepancy rolls back entirely');
    $opened=$run('product.activate',$activation,'activation-exact-replay');
    check($opened['product']['available']===10 && $opened['product']['version']===1, 'Explicit opening allocation preserves ten physical units');
    $replayed=$run('product.activate',$activation,'activation-exact-replay');
    check($replayed['replayed'] && $replayed['command_id']===$opened['command_id'], 'Exact replay precedes now-stale activation versions');
    rejects(fn()=>$run('product.activate',$wrong,'activation-exact-replay'),'request_conflict');
    $input=function(int $wid,array $extra=[]) use($inventory): array {
        $state=$inventory->productState(1); $balance=$inventory->balances(['product_id'=>1,'warehouse_id'=>$wid])['rows'][0]??null;
        return array_replace(['product_id'=>1,'warehouse_id'=>$wid,'expected_product_version'=>$state['version'],'expected_balance_version'=>$balance['version']??0,'expected_warehouse_version'=>1,'reason'=>'Synthetic audited operation'],$extra);
    };
    $r=$run('reservation.create',$input((int)$a['warehouse_id'],['quantity'=>4,'reference'=>'SYNTHETIC-RFQ']));
    check($r['product']['on_hand']===10 && $r['product']['reserved']===4 && $r['product']['available']===6, 'Manual reservation changes available, preserving physical stock');
    rejects(fn()=>$run('balance.count',$input((int)$a['warehouse_id'],['quantity'=>3])),'insufficient_stock');
    rejects(fn()=>$run('balance.issue',$input((int)$a['warehouse_id'],['quantity'=>4])),'insufficient_stock');
    $rid=$r['reservation']['reservation_id'];
    $closed=$run('reservation.ship',$input((int)$a['warehouse_id'],['reservation_id'=>$rid,'expected_reservation_version'=>1]));
    check($closed['product']['on_hand']===6 && $closed['product']['reserved']===0, 'Full shipment reduces physical stock and reserve together');
    rejects(fn()=>$run('reservation.release',$input((int)$a['warehouse_id'],['reservation_id'=>$rid,'expected_reservation_version'=>2])),'reservation_closed');
    $bi=$inventory->balances(['product_id'=>1,'warehouse_id'=>$b['warehouse_id']])['rows'][0];
    $transfer=$input((int)$a['warehouse_id'],['quantity'=>2,'to_warehouse_id'=>$b['warehouse_id'],'expected_to_balance_version'=>$bi['version'],'expected_to_warehouse_version'=>1,'arrival_confirmed'=>true]);
    $moved=$run('transfer.complete',$transfer);
    check($moved['product']['on_hand']===6 && (int)$moved['balances'][0]['on_hand']===1 && (int)$moved['balances'][1]['on_hand']===5, 'Confirmed completed transfer has paired physical legs');
    rejects(fn()=>$run('warehouse.archive',['warehouse_id'=>$a['warehouse_id'],'expected_warehouse_version'=>1]),'warehouse_not_empty');
    rejects(fn()=>$run('offer.update',$input((int)$a['warehouse_id'],['price'=>1.5,'published'=>true])),'invalid_input');
    rejects(fn()=>$run('offer.update',$input((int)$a['warehouse_id'],['price'=>'0.00','published'=>true])),'invalid_input');
    $run('offer.update',$input((int)$a['warehouse_id'],['price'=>'1000.1','published'=>true]));
    $run('offer.update',$input((int)$b['warehouse_id'],['price'=>null,'published'=>true,'delivery_term'=>'Special term']));
    $offers=$inventory->publicOffers(['public-001']);
    check($offers['public-001']['managed'] && count($offers['public-001']['offers'])===2 && $offers['public-001']['offers'][0]['price']==='1000.10' && $offers['public-001']['offers'][1]['price']===null, 'Public source ID maps to two decimal/null warehouse offers');
    $offer=$offers['public-001']['offers'][0];
    check(array_keys($offer)===['offer_id','offer_version','product_id','warehouse','availability_status','price','currency','delivery_term'] && $offer['delivery_term']==='2 days' && $offers['public-001']['offers'][1]['delivery_term']==='Special term', 'Public DTO allowlist and default/override delivery are exact');
    $selection=[['product_id'=>'public-001','article'=>'00001','offer_id'=>$offer['offer_id'],'offer_version'=>$offer['offer_version'],'quantity'=>999]];
    $pdo->beginTransaction(); $snap=$inventory->resolveSelectedOffers($selection); $pdo->commit();
    check($snap[0]['unit_price']==='1000.10' && $inventory->productState(1)['reserved']===0, 'Server snapshot is exact and RFQ selection never reserves');
    $run('balance.receive',$input((int)$a['warehouse_id'],['quantity'=>1]));
    $pdo->beginTransaction(); rejects(fn()=>$inventory->resolveSelectedOffers($selection),'stale_offer'); $pdo->rollBack();
    $before=$inventory->productState(1); $history=$inventory->history()['total'];
    $pdo->beginTransaction();
    $run('balance.receive',$input((int)$a['warehouse_id'],['quantity'=>2]),'outer-transaction-rollback');
    check($pdo->inTransaction(), 'Inventory command joins the owning PDO transaction');
    $pdo->rollBack();
    check($inventory->productState(1)===$before && $inventory->history()['total']===$history, 'Outer rollback removes balance, command and ledger together');
    $run('balance.count',$input((int)$b['warehouse_id'],['quantity'=>10]));
    $raceInput=$input((int)$b['warehouse_id'],['quantity'=>6]); $children=[];
    try {
        $pdo->beginTransaction(); $pdo->query("SELECT product_id FROM `{$prefix}product` WHERE product_id=1 FOR UPDATE")->fetchAll();
        foreach([1,2] as $n) {
            $process=proc_open([PHP_BINARY,__FILE__,$argv[1]??'/var/www/html/config.php','--race-child',$prefix,json_encode($raceInput,JSON_THROW_ON_ERROR),'race-reserve-'.$n],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            if(!is_resource($process)) throw new RuntimeException('Race subprocess unavailable');
            $children[]=[$process,$pipes]; stream_set_timeout($pipes[1],10); stream_set_timeout($pipes[2],10);
            check(fgets($pipes[1])==="ready\n",'Independent reservation connection ready');
        }
        foreach($children as [, $pipes]) { fwrite($pipes[0],"go\n"); fflush($pipes[0]); }
        $deadline=microtime(true)+4; $waiting=0;
        do {
            $waiting=0; foreach($pdo->query('SHOW PROCESSLIST')->fetchAll() as $session) if(str_contains((string)$session['Info'],"SELECT * FROM `{$prefix}product` WHERE product_id=")) $waiting++;
            if($waiting>=2) break; usleep(20000);
        } while(microtime(true)<$deadline);
        check($waiting===2,'Two concurrent reservation commands wait on the real native product mutex'); $pdo->commit();
        $results=[]; foreach($children as [, $pipes]) $results[]=trim((string)fgets($pipes[1])); sort($results);
        check($results===['accepted','stale_version'] && $inventory->productState(1)['reserved']===6,'Concurrent reservations never overspend ten units');
    } finally {
        if($pdo->inTransaction()) $pdo->rollBack();
        foreach($children as [$process,$pipes]) { foreach($pipes as $pipe) fclose($pipe); if(proc_get_status($process)['running']) proc_terminate($process); proc_close($process); }
    }
    $sums=$pdo->query("SELECT warehouse_id,SUM(delta_on_hand) on_hand,SUM(delta_reserved) reserved FROM `{$prefix}cmteh_inventory_entry` GROUP BY warehouse_id ORDER BY warehouse_id")->fetchAll();
    $balances=$inventory->balances(['product_id'=>1])['rows'];
    foreach($sums as $n=>$sum) check((int)$sum['on_hand']===(int)$balances[$n]['on_hand'] && (int)$sum['reserved']===(int)$balances[$n]['reserved'],'Append-only ledger reconciles warehouse '.$sum['warehouse_id']);
    check((int)$pdo->query("SELECT quantity FROM `{$prefix}product` WHERE product_id=1")->fetchColumn()===$inventory->productState(1)['available'], 'Native quantity mirrors managed available aggregate');
    $pdo->exec("DELETE FROM `{$prefix}product_to_store` WHERE product_id=1");
    check($inventory->publicOffers(['public-001'])['public-001']['offers']===[], 'Public offers refuse products removed from the actual storefront');
    $pdo->exec("INSERT INTO `{$prefix}product_to_store` (product_id,store_id) VALUES(1,0)");
    $offer=$inventory->publicOffers(['public-001'])['public-001']['offers'][0];
    $selection=[['product_id'=>'public-001','article'=>'00001','offer_id'=>$offer['offer_id'],'offer_version'=>$offer['offer_version']]];
    $pdo->beginTransaction(); $pdo->query("SELECT sku FROM `{$prefix}cmteh_product` WHERE product_id=1")->fetchColumn();
    $other=Database::connectFromOpenCart(); $other->exec("UPDATE `{$prefix}cmteh_product` SET sku='00002' WHERE product_id=1");
    rejects(fn()=>$inventory->resolveSelectedOffers($selection),'stale_offer'); $pdo->rollBack();
    $other->exec("UPDATE `{$prefix}cmteh_product` SET sku='00001' WHERE product_id=1");
    $before=$inventory->productState(1); $history=$inventory->history()['total'];
    // A fixture-only FK rejects the ledger insert even without STRICT mode.
    // Existing entries stay NULL; new inserts default to the absent parent 0.
    $originalSqlMode=(string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $nativeBefore=(int)$pdo->query("SELECT quantity FROM `{$prefix}product` WHERE product_id=1")->fetchColumn();
    $failureConstraint=$prefix.'injected_failure'; $injected=false; $failed=false;
    try {
        $pdo->exec("ALTER TABLE `{$prefix}cmteh_inventory_entry` ADD COLUMN injected_failure INT NULL DEFAULT NULL, ADD CONSTRAINT `{$failureConstraint}` FOREIGN KEY (injected_failure) REFERENCES `{$prefix}cmteh_inventory_product` (product_id)");
        $injected=true;
        $pdo->exec("ALTER TABLE `{$prefix}cmteh_inventory_entry` ALTER COLUMN injected_failure SET DEFAULT 0");
        $pdo->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
        try { $run('balance.receive',$input((int)$a['warehouse_id'],['quantity'=>2]),'injected-ledger-failure'); }
        catch(PDOException $e) { $failed=(int)($e->errorInfo[1]??0)===1452; }
    }
    finally {
        try { if($injected) $pdo->exec("ALTER TABLE `{$prefix}cmteh_inventory_entry` DROP FOREIGN KEY `{$failureConstraint}`, DROP COLUMN injected_failure"); }
        finally { $pdo->exec('SET SESSION sql_mode='.$pdo->quote($originalSqlMode)); }
    }
    check($failed && $inventory->productState(1)===$before && $inventory->history()['total']===$history && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}cmteh_inventory_command` WHERE request_key='injected-ledger-failure'")->fetchColumn()===0 && (int)$pdo->query("SELECT quantity FROM `{$prefix}product` WHERE product_id=1")->fetchColumn()===$nativeBefore,'Injected ledger failure rolls back physical count, header, command and native mirror');
    $active=$inventory->reservations(['state'=>'active'])['rows'][0];
    $before=$inventory->productState(1)['on_hand'];
    $released=$run('reservation.release',$input((int)$b['warehouse_id'],['reservation_id'=>$active['reservation_id'],'expected_reservation_version'=>$active['version']]));
    check($released['product']['reserved']===0 && $released['product']['on_hand']===$before,'Full release preserves physical stock');
    rejects(fn()=>$run('balance.receive',$input((int)$a['warehouse_id'],['quantity'=>'1.1'])),'invalid_input');
    rejects(fn()=>$run('offer.update',$input((int)$a['warehouse_id'],['price'=>'10000000000000.00'])),'invalid_input');
    $before=$inventory->publicOffers(['public-001'])['public-001']['offers'][0];
    $run('warehouse.save',['warehouse_id'=>$a['warehouse_id'],'expected_warehouse_version'=>1,'code'=>'SYN-A','name'=>'Synthetic SYN-A','city'=>'Synthetic city','delivery_term'=>'2 days','address'=>'Changed private address','note'=>'Changed private note']);
    $after=$inventory->publicOffers(['public-001'])['public-001']['offers'][0];
    check($before===$after,'Private warehouse edits do not invalidate public offer terms');
    $run('warehouse.save',['warehouse_id'=>$a['warehouse_id'],'expected_warehouse_version'=>2,'code'=>'SYN-A','name'=>'Renamed synthetic warehouse','city'=>'Synthetic city','delivery_term'=>'3 days']);
    $after=$inventory->publicOffers(['public-001'])['public-001']['offers'][0];
    check($before['offer_version']!==$after['offer_version'] && $after['delivery_term']==='3 days','Public warehouse edits invalidate revision and update inherited term');
    $pdo->exec("UPDATE `{$prefix}product` SET manufacturer_id=7 WHERE product_id=1");
    check($inventory->publicOffers(['public-001'])['public-001']['offers']===[],'Unpublished brand cannot leak offers');
    $pdo->exec("UPDATE `{$prefix}product` SET manufacturer_id=0 WHERE product_id=1");
    check((new Inventory($pdo,$prefix,9,1))->publicOffers(['public-001'])['public-001']['offers']===[],'Store context does not expose another storefront product');
    check((new Inventory($pdo,$prefix,0,9))->publicOffers(['public-001'])['public-001']['offers']===[],'Language context requires the actual published description');
    $run('balance.count',$input((int)$a['warehouse_id'],['quantity'=>0,'expected_warehouse_version'=>3]));
    $archived=$run('warehouse.archive',['warehouse_id'=>$a['warehouse_id'],'expected_warehouse_version'=>3]);
    check((int)$archived['warehouse']['active']===0 && count($inventory->publicOffers(['public-001'])['public-001']['offers'])===1,'Empty warehouse archive hides offer without deleting history');
    rejects(fn()=>$run('balance.receive',$input((int)$a['warehouse_id'],['quantity'=>1,'expected_warehouse_version'=>4])),'warehouse_archived');
    $pdo->exec("INSERT INTO `{$prefix}product` (product_id,model,quantity,status,date_available,master_id) VALUES(2,'SYNTHETIC-VARIANT',5,1,'2020-01-01',1),(3,'SYNTHETIC-COUNT',5,1,'2020-01-01',0)");
    $activate=function(int $pid,array $extra=[]) use($inventory,$b):array { $state=$inventory->productState($pid); return array_replace(['product_id'=>$pid,'expected_product_version'=>0,'expected_legacy_quantity'=>5,'expected_legacy_revision'=>$state['legacy_revision'],'confirmed'=>true,'allocations'=>[['warehouse_id'=>$b['warehouse_id'],'quantity'=>4,'expected_warehouse_version'=>1]],'reason'=>'Confirmed physical discrepancy'],$extra); };
    rejects(fn()=>$run('product.activate',$activate(2)),'unsupported_stock');
    $physical=$run('product.activate',$activate(3));
    check($physical['product']['on_hand']===4 && $physical['product']['legacy_quantity']===5,'Explicit audited physical discrepancy changes only confirmed independent product');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $q = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND LEFT(table_name,?)=?');
    $q->execute([strlen($prefix),$prefix]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $table) $pdo->exec("DROP TABLE `$table`");
}
