<?php
declare(strict_types=1);
require $argv[1] ?? '/var/www/html/config.php';
require_once __DIR__ . '/../extension/cmteh/system/Lead.php';
require_once __DIR__ . '/../extension/cmteh/system/Inventory.php';
require_once __DIR__ . '/../extension/cmteh/system/InventorySchema.php';
use Cmteh\{Database,Catalog,Lead,Inventory,InventorySchema};
function check(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
$pdo=Database::connectFromOpenCart();
if(!preg_match('~^http://(?:127\.0\.0\.1|localhost)(?::[0-9]+)?$~D',Database::siteUrl())) throw new RuntimeException('Loopback only');
// Explicitly owned fixtures for the real loopback HTTP journey; never an installed site host.
$mode=$argv[2]??'';
if($mode==='--installed-proof') {
 $root='/var/www/html/extension/cmteh/system/'; $classes=[]; $members=[];
 foreach(array_merge(get_declared_classes(),get_declared_traits()) as $class) {
  if(!str_starts_with($class,'Cmteh\\')) continue;
  $path=realpath((new ReflectionClass($class))->getFileName());
  if(!is_string($path)||!str_starts_with($path,$root)||is_link($path)) throw new RuntimeException('Installed source required');
  $classes[$class]=$path; $members[basename($path)]=hash_file('sha256',$path);
 }
 echo json_encode(['test_path'=>__FILE__,'test_sha256'=>hash_file('sha256',__FILE__),'classes'=>$classes,'members'=>$members],JSON_THROW_ON_ERROR); exit;
}
if (str_starts_with($mode,'--browser-')) {
 $token=$argv[3]??'';
 if(!preg_match('/^[a-f0-9]{12}$/D',$token)) throw new RuntimeException('Owned browser token required');
 $prefix=DB_PREFIX; $source='warehouse-browser-'.$token; $marker='Warehouse browser '.$token;
 $stateFile=sys_get_temp_dir().'/cmteh-warehouse-browser-'.$token.'.json';
 if($mode==='--browser-setup') {
  InventorySchema::migrate($pdo,$prefix); (new Lead($pdo,$prefix))->migrate();
  if(is_file($stateFile)) throw new RuntimeException('Owned fixture already exists');
  $pdo->beginTransaction();
  try {
  $pdo->prepare("INSERT INTO `{$prefix}product` (model,quantity,status,date_available,master_id,price,date_modified) VALUES(?,10,1,'2020-01-01',0,9999,UTC_TIMESTAMP())")->execute([$source]); $pid=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO `{$prefix}product_to_store` (product_id,store_id) VALUES(?,0)")->execute([$pid]);
  $pdo->prepare("INSERT INTO `{$prefix}product_description` (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES(?,1,'Synthetic warehouse part','','','','','')")->execute([$pid]);
  $pdo->prepare("INSERT INTO `{$prefix}cmteh_product` (product_id,source_id,slug,sku,sku_norm,mpn_norm,name_norm,brand_norm,manufacturer_id,category_id,published_at,indexable,availability,price_status,currency,data) VALUES(?,?,?,'000-WOB-001','000WOB001','','synthetic','',0,0,'2020-01-01',0,'out_of_stock','on_request','RUB','{}')")->execute([$pid,$source,$source]);
  $inventory=new Inventory($pdo,$prefix,0,1); $n=0;
  $run=function($kind,$input) use($inventory,$token,&$n) { return $inventory->command($kind,$input,['id'=>1,'label'=>'Synthetic browser operator'],'wob-'.$token.'-'.(++$n)); };
  $a=$run('warehouse.save',['expected_warehouse_version'=>0,'code'=>'WOB-A-'.$token,'name'=>'Synthetic warehouse A','city'=>'Synthetic city A','delivery_term'=>'2 days','address'=>'Do not expose private address'])['warehouse'];
  $b=$run('warehouse.save',['expected_warehouse_version'=>0,'code'=>'WOB-B-'.$token,'name'=>'Synthetic warehouse B','city'=>'Synthetic city B','delivery_term'=>'5 days'])['warehouse'];
  $state=$inventory->productState($pid);
  $run('product.activate',['product_id'=>$pid,'expected_product_version'=>0,'expected_legacy_quantity'=>10,'expected_legacy_revision'=>$state['legacy_revision'],'confirmed'=>true,'allocations'=>[['warehouse_id'=>$a['warehouse_id'],'quantity'=>6,'expected_warehouse_version'=>1],['warehouse_id'=>$b['warehouse_id'],'quantity'=>4,'expected_warehouse_version'=>1]]]);
  foreach([[$a,'1000.10'],[$b,'850.25']] as [$w,$price]) {
   $bal=$inventory->balances(['product_id'=>$pid,'warehouse_id'=>$w['warehouse_id']])['rows'][0];
   $run('offer.update',['product_id'=>$pid,'warehouse_id'=>$w['warehouse_id'],'expected_product_version'=>$inventory->productState($pid)['version'],'expected_balance_version'=>$bal['version'],'expected_warehouse_version'=>1,'price'=>$price,'published'=>true]);
  }
  $fixture=['source'=>$source,'pid'=>$pid,'a'=>$a['warehouse_id'],'b'=>$b['warehouse_id'],'marker'=>$marker];
  if(file_put_contents($stateFile,json_encode($fixture,JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Fixture recovery record unavailable');
  $pdo->commit(); echo json_encode($fixture,JSON_THROW_ON_ERROR); exit;
  } catch(Throwable $error) { if($pdo->inTransaction()) { $pdo->rollBack(); if(is_file($stateFile)) unlink($stateFile); } throw $error; }
 }
 if(!is_file($stateFile)) throw new RuntimeException('Owned fixture unavailable');
 $fixture=json_decode(file_get_contents($stateFile),true,16,JSON_THROW_ON_ERROR); $pid=$fixture['pid'];
 if($mode==='--browser-mutate') {
  $inventory=new Inventory($pdo,$prefix,0,1); $bal=$inventory->balances(['product_id'=>$pid,'warehouse_id'=>$fixture['a']])['rows'][0];
  $inventory->command('offer.update',['product_id'=>$pid,'warehouse_id'=>$fixture['a'],'expected_product_version'=>$inventory->productState($pid)['version'],'expected_balance_version'=>$bal['version'],'expected_warehouse_version'=>1,'price'=>'1234.56','published'=>true],['id'=>1,'label'=>'Synthetic browser operator'],'wob-'.$token.'-mutation'); echo '{}'; exit;
 }
 if($mode==='--browser-state') {
  $lead=new Lead($pdo,$prefix); $q=$pdo->prepare("SELECT public_id FROM {$prefix}cmteh_lead WHERE company=?"); $q->execute([$marker]);
  echo json_encode(['leads'=>array_map(fn($id)=>$lead->find($id),$q->fetchAll(PDO::FETCH_COLUMN)),'stock'=>(new Inventory($pdo,$prefix))->productState($pid)],JSON_THROW_ON_ERROR); exit;
 }
 if($mode!=='--browser-cleanup') throw new RuntimeException('Unsupported fixture operation');
 $pdo->beginTransaction();
 try {
  $q=$pdo->prepare("SELECT lead_id FROM {$prefix}cmteh_lead WHERE company=?"); $q->execute([$marker]);
  foreach($q->fetchAll(PDO::FETCH_COLUMN) as $lid) {
   foreach(['inventory','item','outbox'] as $suffix) $pdo->prepare("DELETE FROM {$prefix}cmteh_lead_{$suffix} WHERE lead_id=?")->execute([$lid]);
   $pdo->prepare("DELETE FROM {$prefix}cmteh_lead WHERE lead_id=?")->execute([$lid]);
  }
  $pdo->prepare("DELETE FROM {$prefix}cmteh_inventory_entry WHERE product_id=?")->execute([$pid]);
  $pdo->prepare("DELETE m FROM {$prefix}cmteh_inventory_movement m JOIN {$prefix}cmteh_inventory_command c ON c.command_id=m.command_id WHERE c.request_key LIKE ?")->execute(['wob-'.$token.'-%']);
  foreach(['reservation','balance','product'] as $suffix) $pdo->prepare("DELETE FROM {$prefix}cmteh_inventory_{$suffix} WHERE product_id=?")->execute([$pid]);
  $pdo->prepare("DELETE FROM {$prefix}cmteh_inventory_command WHERE request_key LIKE ?")->execute(['wob-'.$token.'-%']);
  $pdo->prepare("DELETE FROM {$prefix}cmteh_warehouse WHERE warehouse_id IN (?,?) AND code IN (?,?)")->execute([$fixture['a'],$fixture['b'],'WOB-A-'.$token,'WOB-B-'.$token]);
  foreach(['cmteh_product','product_to_store','product_description','product'] as $table) $pdo->prepare("DELETE FROM {$prefix}{$table} WHERE product_id=?")->execute([$pid]);
  $pdo->commit(); unlink($stateFile); echo '{}';
 } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
 exit;
}
if($mode==='--selected-race') {
 $prefix=$argv[3]??''; if(!preg_match('/^wof_[a-f0-9]{12}_$/D',$prefix)) exit(2);
 $body=$argv[4]??''; echo "ready\n"; fflush(STDOUT); if(fgets(STDIN)!=="go\n") exit(2);
 $result=(new Lead($pdo,$prefix))->submitWarehouse($body,$argv[5]??'192.0.2.50',['content-type'=>'application/json']);
 echo json_encode($result,JSON_THROW_ON_ERROR)."\n"; exit;
}
$prefix='wof_'.bin2hex(random_bytes(6)).'_';
$native=['product','product_code','product_description','product_to_store','product_to_category','product_attribute','product_option_value','category','category_description','category_to_store','category_path','manufacturer','manufacturer_to_store','attribute','attribute_description','attribute_group','attribute_group_description'];
try {
 foreach($native as $table) $pdo->exec("CREATE TABLE `{$prefix}{$table}` LIKE `".DB_PREFIX.$table.'`');
 $catalog=new Catalog($pdo,$prefix,1,0); $catalog->migrate(); InventorySchema::migrate($pdo,$prefix);
 $lead=new Lead($pdo,$prefix); $lead->migrate();
 $pdo->exec("INSERT INTO `{$prefix}product` (product_id,model,quantity,status,date_available,master_id,price,date_modified) VALUES(1,'SYNTHETIC',10,1,'2020-01-01',0,9999,'2020-01-01')");
 $pdo->exec("INSERT INTO `{$prefix}product_to_store` (product_id,store_id) VALUES(1,0)");
 $pdo->exec("INSERT INTO `{$prefix}product_description` (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES(1,1,'Synthetic','','','','','')");
 $pdo->exec("INSERT INTO `{$prefix}cmteh_product` (product_id,source_id,slug,sku,sku_norm,mpn_norm,name_norm,brand_norm,manufacturer_id,category_id,published_at,indexable,availability,price_status,currency,data) VALUES(1,'public-001','synthetic','00001','00001','','synthetic','',0,0,'2020-01-01',1,'out_of_stock','fixed','RUB','{}')");
 $inventory=new Inventory($pdo,$prefix,0,1); $sequence=0;
 $run=fn(string $kind,array $input)=>$inventory->command($kind,$input,['id'=>1,'label'=>'Synthetic operator'],'warehouse-rfq-'.(++$GLOBALS['sequence']));
 $a=$run('warehouse.save',['expected_warehouse_version'=>0,'code'=>'SYN-A','name'=>'Synthetic A','city'=>'Synthetic city A','delivery_term'=>'2 days','address'=>'Private hidden'])['warehouse'];
 $b=$run('warehouse.save',['expected_warehouse_version'=>0,'code'=>'SYN-B','name'=>'Synthetic B','delivery_term'=>'5 days'])['warehouse'];
 $legacyBody=['request_key'=>Cmteh\LeadInput::uuid(),'name'=>'Synthetic legacy buyer','phone'=>'+70000000000','page_url'=>'http://localhost/request','request_items'=>[['product_id'=>'public-001','article'=>'00001','quantity'=>1]]];
 $legacySaved=$lead->submit(json_encode($legacyBody,JSON_THROW_ON_ERROR),'192.0.2.39',['content-type'=>'application/json']);
 check($legacySaved['status']===200,'Unmanaged canonical legacy submission remains supported before cutover');
 $state=$inventory->productState(1);
 $run('product.activate',['product_id'=>1,'expected_product_version'=>0,'expected_legacy_quantity'=>10,'expected_legacy_revision'=>$state['legacy_revision'],'confirmed'=>true,'allocations'=>[['warehouse_id'=>$a['warehouse_id'],'quantity'=>6,'expected_warehouse_version'=>1],['warehouse_id'=>$b['warehouse_id'],'quantity'=>4,'expected_warehouse_version'=>1]]]);
 $input=function(int $wid,array $extra=[]) use($inventory):array { $state=$inventory->productState(1); $bal=$inventory->balances(['product_id'=>1,'warehouse_id'=>$wid])['rows'][0]; return array_replace(['product_id'=>1,'warehouse_id'=>$wid,'expected_product_version'=>$state['version'],'expected_balance_version'=>$bal['version'],'expected_warehouse_version'=>1],$extra); };
 $run('offer.update',$input((int)$a['warehouse_id'],['price'=>'1000.10','published'=>true]));
 $run('offer.update',$input((int)$b['warehouse_id'],['price'=>'850.25','published'=>true]));
 $base=['request_key'=>Cmteh\LeadInput::uuid(),'name'=>'Synthetic buyer','phone'=>'+70000000000','page_url'=>'http://localhost/request','request_items'=>[['product_id'=>'public-001','article'=>'00001','quantity'=>2]]];
 $send=fn(array $body)=>$lead->submit(json_encode($body,JSON_THROW_ON_ERROR),'192.0.2.40',['content-type'=>'application/json']);
 $legacyReplay=$lead->submit(json_encode($legacyBody,JSON_THROW_ON_ERROR),'192.0.2.39',['content-type'=>'application/json']);
 check($legacyReplay['status']===200 && $legacyReplay['body']['replayed'] && $legacyReplay['body']['id']===$legacySaved['body']['id'],'Committed legacy replay precedes the new warehouse requirement after cutover');
 $catalog->selectedProducts(['public-001']);
 check($send($base)['status']===409,'A new canonical managed legacy submission requires an explicit warehouse');
 $offers=$inventory->publicOffers(['public-001'])['public-001']['offers'];
 $product=$catalog->product('synthetic');
 check(($product['inventory_managed']??false) && count($product['offers']??[])===2 && $product['price']===null && $product['availability_status']==='in_stock','Catalog projects two offers without a fake global price or legacy availability');
 $filtered=$catalog->products(['availability'=>'in_stock']);
 check($filtered['total']===1 && count($filtered['products'])===1 && count($catalog->checkList([['article'=>'00001','quantity'=>2]])['rows'][0]['products'][0]['offers'])===2,'Filter count and exact-SKU list agree without multiplying products');
 $selected=$base; $selected['request_key']=Cmteh\LeadInput::uuid();
 $selected['request_items']=array_map(fn($offer)=>['product_id'=>'public-001','article'=>'00001','quantity'=>2,'offer_id'=>$offer['offer_id'],'offer_version'=>$offer['offer_version'],'price'=>'0.01','warehouse_name'=>'Injected'],$offers);
 $warehouse=fn(array $body)=>$lead->submitWarehouse(json_encode($body,JSON_THROW_ON_ERROR),'192.0.2.41',['content-type'=>'application/json']);
 $state=$inventory->productState(1); $accepted=$warehouse($selected);
 check($accepted['status']===200 && !$accepted['body']['replayed'],'Explicit two-warehouse RFQ accepted');
 $saved=$lead->find($accepted['body']['id']);
 check(count($saved['items'])===2 && $saved['items'][0]['unit_price']==='1000.10' && $saved['items'][1]['unit_price']==='850.25' && $saved['items'][0]['warehouse_name']==='Synthetic A' && $inventory->productState(1)===$state,'Same product snapshots authoritative decimals and terms separately without reserving');
 $run('offer.update',$input((int)$a['warehouse_id'],['price'=>'1234.56','published'=>false]));
 $replay=$warehouse($selected);
 check($replay['status']===200 && $replay['body']['replayed'] && $replay['body']['id']===$accepted['body']['id'] && $lead->find($accepted['body']['id'])===$saved,'Lost acknowledgement replay remains immutable after offer price change and hide');
 $stale=$selected; $stale['request_key']=Cmteh\LeadInput::uuid(); $rejected=$warehouse($stale);
 check($rejected['status']===409 && (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}cmteh_lead")->fetchColumn()===2 && (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}cmteh_lead_outbox")->fetchColumn()===2 && (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}cmteh_lead_inventory")->fetchColumn()===2,'Stale first submission rolls back lead/items/job and snapshots');
 $tampered=$selected; $tampered['request_key']=Cmteh\LeadInput::uuid(); $tampered['request_items'][0]['article']='00002';
 check($warehouse($tampered)['status']===409,'Selected SKU identity is exact and cannot be injected');
 $malformed=$selected; unset($malformed['request_items'][0]['offer_version']);
 check($warehouse($malformed)['status']===400,'Partial offer selection rejects rather than degrading to generic SKU');
 $fresh=$base; $fresh['request_key']=Cmteh\LeadInput::uuid();
 $current=$inventory->publicOffers(['public-001'])['public-001']['offers'][0];
 $fresh['request_items']=[['product_id'=>'public-001','article'=>'00001','quantity'=>2,'offer_id'=>$current['offer_id'],'offer_version'=>$current['offer_version']]];
 // Keep the real snapshot INSERT failure independent of STRICT mode.
 if((int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn()!==1 || (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}cmteh_lead WHERE lead_id=0")->fetchColumn()!==0) throw new RuntimeException('Fault fixture requires checked foreign keys and absent parent zero');
 $originalSqlMode=(string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
 $failureConstraint=$prefix.'injected_failure'; $injected=false;
 try {
  $pdo->exec("ALTER TABLE {$prefix}cmteh_lead_inventory ADD COLUMN injected_failure BIGINT UNSIGNED NULL DEFAULT NULL, ADD CONSTRAINT `{$failureConstraint}` FOREIGN KEY (injected_failure) REFERENCES {$prefix}cmteh_lead (lead_id)");
  $injected=true;
  $pdo->exec("ALTER TABLE {$prefix}cmteh_lead_inventory ALTER COLUMN injected_failure SET DEFAULT 0");
  $pdo->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
  $failed=$warehouse($fresh);
 } finally {
  try {if($injected) $pdo->exec("ALTER TABLE {$prefix}cmteh_lead_inventory DROP FOREIGN KEY `{$failureConstraint}`, DROP COLUMN injected_failure");}
  finally {$pdo->exec('SET SESSION sql_mode='.$pdo->quote($originalSqlMode));}
 }
 check($failed['status']===503 && (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}cmteh_lead")->fetchColumn()===2 && (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}cmteh_lead_item")->fetchColumn()===3 && (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}cmteh_lead_outbox")->fetchColumn()===2 && (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}cmteh_lead_inventory")->fetchColumn()===2,'Snapshot SQL failure rolls back the first header and all item/outbox writes');
 $children=[];
 try {
  $pdo->beginTransaction(); $pdo->query("SELECT product_id FROM {$prefix}product WHERE product_id=1 FOR UPDATE")->fetchAll();
  foreach(['192.0.2.50','192.0.2.51'] as $ip) {
   $process=proc_open([PHP_BINARY,__FILE__,$argv[1]??'/var/www/html/config.php','--selected-race',$prefix,json_encode($fresh,JSON_THROW_ON_ERROR),$ip],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
   if(!is_resource($process)) throw new RuntimeException('Selected race process unavailable');
   $children[]=[$process,$pipes]; stream_set_timeout($pipes[1],10); stream_set_timeout($pipes[2],10);
   if(fgets($pipes[1])!=="ready\n") throw new RuntimeException('Selected race child not ready');
  }
  foreach($children as [, $pipes]) {fwrite($pipes[0],"go\n");fflush($pipes[0]);}
  $deadline=microtime(true)+4; $waiting=0;
  do { $waiting=0; foreach($pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $session) if(str_contains((string)$session['Info'],"INSERT INTO {$prefix}cmteh_lead") || str_contains((string)$session['Info'],"FROM {$prefix}product WHERE product_id=")) $waiting++; if($waiting>=2) break; usleep(20000); } while(microtime(true)<$deadline);
  check($waiting===2,'Two selected RFQ connections are concurrently held on real native/header mutexes'); $pdo->commit();
  $results=[]; foreach($children as [, $pipes]) $results[]=json_decode(trim((string)fgets($pipes[1])),true,32,JSON_THROW_ON_ERROR);
  check($results[0]['status']===200 && $results[1]['status']===200 && $results[0]['body']['id']===$results[1]['body']['id'] && $results[0]['body']['replayed']!==$results[1]['body']['replayed'] && (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}cmteh_lead_inventory")->fetchColumn()===3 && (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}cmteh_lead_outbox")->fetchColumn()===3 && $inventory->productState(1)['reserved']===0,'Concurrent selected duplicate stores one immutable warehouse snapshot/job without reservation');
 } finally {
  if($pdo->inTransaction()) $pdo->rollBack();
  foreach($children as [$process,$pipes]) {foreach($pipes as $pipe) fclose($pipe);if(proc_get_status($process)['running']) proc_terminate($process);proc_close($process);}
 }
 $run('offer.update',$input((int)$b['warehouse_id'],['price'=>'9999999999999.99','published'=>true]));
 $large=$fresh; $large['request_key']=Cmteh\LeadInput::uuid(); $largeOffer=$inventory->publicOffers(['public-001'])['public-001']['offers'][0]; $large['request_items'][0]['offer_version']=$largeOffer['offer_version']; $large['request_items'][0]['quantity']=100000;
 $largeAccepted=$warehouse($large); $largeSaved=$lead->find($largeAccepted['body']['id']);
 check($largeAccepted['status']===200 && $largeSaved['items'][0]['unit_price']==='9999999999999.99' && (int)$largeSaved['items'][0]['quantity']===100000,'Maximum decimal unit price and large nonbinding quantity persist without float multiplication');
 $run('offer.update',$input((int)$b['warehouse_id'],['price'=>'850.25','published'=>true]));
 $pdo->exec("INSERT INTO {$prefix}product (product_id,model,quantity,status,date_available,master_id,price,date_modified) VALUES(2,'SYNTHETIC-LEGACY',1,1,'2020-01-01',0,900,'2020-01-01')");
 $pdo->exec("INSERT INTO {$prefix}product_to_store (product_id,store_id) VALUES(2,0)");
 $pdo->exec("INSERT INTO {$prefix}product_description (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES(2,1,'Synthetic legacy part','','','','','')");
 $pdo->exec("INSERT INTO {$prefix}cmteh_product (product_id,source_id,slug,sku,sku_norm,mpn_norm,name_norm,brand_norm,manufacturer_id,category_id,published_at,indexable,availability,price_status,currency,data) VALUES(2,'public-002','synthetic-legacy','00002','00002','','synthetic','',0,0,'2020-01-01',1,'in_stock','fixed','RUB','{}')");
 check(array_column($catalog->products(['sort'=>'price_asc'])['products'],'id')===['public-001','public-002'],'Price sorting uses the visible warehouse price rather than a stale native global price');
 $run('offer.update',$input((int)$b['warehouse_id'],['price'=>null,'published'=>true]));
 check(array_column($catalog->products(['sort'=>'price_asc'])['products'],'id')===['public-002','public-001'] && $catalog->products(['availability'=>'in_stock'])['total']===2,'Null warehouse prices sort after known prices while availability remains independent');
 $pdo->beginTransaction();
 $nativeInsert=$pdo->prepare("INSERT INTO {$prefix}product (product_id,model,quantity,status,date_available,master_id,date_modified) VALUES(?, 'SYNTHETIC-BOUND',0,1,'2020-01-01',0,'2020-01-01')");
 $storeInsert=$pdo->prepare("INSERT INTO {$prefix}product_to_store (product_id,store_id) VALUES(?,0)");
 $descriptionInsert=$pdo->prepare("INSERT INTO {$prefix}product_description (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES(?,1,'Synthetic bound','','','','','')");
 $mapInsert=$pdo->prepare("INSERT INTO {$prefix}cmteh_product (product_id,source_id,slug,sku,sku_norm,mpn_norm,name_norm,brand_norm,manufacturer_id,category_id,published_at,indexable,price_status,data) VALUES(?,?,?,?,?,'','synthetic','',0,0,'2020-01-01',1,'on_request','{}')");
 $rows=[];
 for($n=0;$n<1005;$n++) { $pid=$n+100; $group=intdiv($n,5);$sku='BOUND'.$group; $nativeInsert->execute([$pid]);$storeInsert->execute([$pid]);$descriptionInsert->execute([$pid]);$mapInsert->execute([$pid,'bound-'.$n,'bound-'.$n,$sku,$sku]); if($n%5===0) $rows[]=['article'=>$sku,'quantity'=>1]; }
 $pdo->commit();
 $bounded=$catalog->checkList($rows);
 check(count($bounded['rows'])===201 && count($bounded['rows'][200]['products'])===5,'Managed-ready exact-SKU batch handles more than one thousand distinct product matches with bounded offer lookups');

} finally {
 if($pdo->inTransaction()) $pdo->rollBack();
 $query=$pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE ?'); $query->execute([$prefix.'%']);
 $tables=$query->fetchAll(PDO::FETCH_COLUMN); usort($tables,fn($a,$b)=>(str_contains($b,'lead_item')||str_contains($b,'lead_outbox'))<=>(str_contains($a,'lead_item')||str_contains($a,'lead_outbox')));
 foreach($tables as $table) $pdo->exec('DROP TABLE `'.$table.'`');
}
