<?php
declare(strict_types=1);
require $argv[1] ?? '/var/www/html/config.php';
require __DIR__ . '/../extension/cmteh/system/Database.php';
$service = __DIR__ . '/../extension/cmteh/system/SeedCleanup.php';
if (!is_file($service)) throw new RuntimeException('Guarded cleanup implementation is missing');
require $service;
$pdo=Cmteh\Database::connectFromOpenCart();
if (!str_starts_with(Cmteh\Database::siteUrl(),'http://127.0.0.1:')) throw new RuntimeException('Loopback fixture only');
$pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$prefix='cleanup_'.bin2hex(random_bytes(4)).'_';
$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$n=0;
function assertion(bool $ok,string $message): void { global $n; if(!$ok) throw new RuntimeException($message); $n++; echo "PASS $message\n"; }
function refused(callable $work,string $message): void {try{$work();}catch(RuntimeException $e){assertion(true,$message);return;} throw new RuntimeException($message);}
try {
 foreach($tables as $table) if(str_starts_with($table,DB_PREFIX)) { $name=substr($table,strlen(DB_PREFIX));$pdo->exec("CREATE TABLE `$prefix$name` LIKE `$table`");$pdo->exec("INSERT INTO `$prefix$name` SELECT * FROM `$table`"); }
 // Mirror the actual protected owner attribute surface without editing the source runtime.
 $pdo->exec("INSERT IGNORE INTO `{$prefix}attribute_group` SET attribute_group_id=7,sort_order=0");
 $pdo->exec("INSERT IGNORE INTO `{$prefix}attribute_group_description` SET attribute_group_id=7,language_id=1,name='Owner group'");
 foreach([12,13,14,15] as $id) {
     $pdo->exec("INSERT IGNORE INTO `{$prefix}attribute` SET attribute_id=$id,attribute_group_id=7,sort_order=0");
     $pdo->exec("INSERT IGNORE INTO `{$prefix}attribute_description` SET attribute_id=$id,language_id=1,name='Owner attribute $id'");
     $pdo->prepare("INSERT IGNORE INTO `{$prefix}cmteh_attribute` (attribute_id,field_key,group_name) VALUES(?,?,?)")->execute([$id,hash('sha256','owner'.$id),'Owner group']);
 }
 $template=$pdo->query("SELECT * FROM `{$prefix}product` WHERE product_id=28")->fetch(PDO::FETCH_ASSOC);
 foreach([990281,990282] as $id) {
     $row=$template; $row['product_id']=$id; $row['model']='Owner product'; $row['manufacturer_id']=0;
     $pdo->prepare("INSERT INTO `{$prefix}product` (`".implode('`,`',array_keys($row))."`) VALUES (".implode(',',array_fill(0,count($row),'?')).")")->execute(array_values($row));
 }
 foreach([[990281,12],[990281,13],[990281,14],[990281,15],[990282,12]] as [$product,$attribute]) $pdo->exec("INSERT INTO `{$prefix}product_attribute` SET product_id=$product,attribute_id=$attribute,language_id=1,text='Owner value'");
 $pdo->exec("INSERT INTO `{$prefix}cmteh_lead_rate` (window_minute,scope_key,hits) VALUES(1,UNHEX('80ff000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d'),1)");
 $pdo->exec("CREATE TABLE `{$prefix}binary_probe` (id INT PRIMARY KEY,payload BLOB NOT NULL) ENGINE=InnoDB");
 $pdo->exec("INSERT INTO `{$prefix}binary_probe` VALUES(1,UNHEX('00ff80c0c100f5287f'))");
 $binaryBefore=$pdo->query("SELECT HEX(payload) FROM `{$prefix}binary_probe` WHERE id=1")->fetchColumn();
 $protectedAttributeTables=[];
 foreach(['attribute','attribute_description','attribute_group','attribute_group_description','cmteh_attribute','product_attribute'] as $t) {
     $filter=match($t) {
         'attribute','attribute_description'=>' WHERE attribute_id IN (12,13,14,15)',
         'attribute_group','attribute_group_description'=>' WHERE attribute_group_id=7',
         'product_attribute'=>' WHERE product_id IN (990281,990282)',
         default=>'',
     };
     $protectedAttributeTables[$t]=[$filter,$pdo->query("SELECT * FROM `$prefix$t`".$filter)->fetchAll()];
 }
 $pdo->exec("INSERT INTO `{$prefix}manufacturer` SET manufacturer_id=902,name='Owner unknown',sort_order=0");
 $pdo->exec("INSERT INTO `{$prefix}manufacturer` SET manufacturer_id=903,name='Owner mapped',sort_order=0");
 $pdo->exec("INSERT INTO `{$prefix}cmteh_brand` SET manufacturer_id=903,source_id='mock-owner',slug='owner',status=1,published_at=UTC_TIMESTAMP(),data='{}'");
 $protected=[];
 foreach(['setting','user','user_group','cmteh_brand','cmteh_lead','cmteh_quote','cmteh_lead_rate'] as $t) $protected[$t]=$pdo->query("SELECT * FROM `$prefix$t`")->fetchAll();
 $initialProducts=(int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}product`")->fetchColumn();
 $tool=new Cmteh\SeedCleanup($pdo,$prefix);
 $preview=$tool->preview(); assertion($preview['counts']['product']===19 && $preview['counts']['category']===38 && $preview['counts']['manufacturer']===6,'Pinned exact upstream closure counts19/38/6');
 assertion(($preview['counts']['attribute']??0)===11 && ($preview['counts']['attribute_group']??0)===4 && ($preview['counts']['attribute_description']??0)===11 && ($preview['counts']['attribute_group_description']??0)===4,'Pinned attribute closure11/4 and30 exact rows');
 $pdo->exec("UPDATE `{$prefix}attribute` SET sort_order=sort_order+1 WHERE attribute_id=1");
 refused(fn()=>$tool->preview(),'Modified seed attribute refuses');
 $pdo->exec("UPDATE `{$prefix}attribute` SET sort_order=sort_order-1 WHERE attribute_id=1");
 $pdo->exec("INSERT INTO `{$prefix}attribute_description` SET attribute_id=1,language_id=999,name='Owner translation'");
 refused(fn()=>$tool->preview(),'New seed attribute description refuses');
 $pdo->exec("DELETE FROM `{$prefix}attribute_description` WHERE attribute_id=1 AND language_id=999");
 $pdo->exec("UPDATE `{$prefix}attribute_group` SET sort_order=sort_order+1 WHERE attribute_group_id=3");
 refused(fn()=>$tool->preview(),'Modified seed attribute group refuses');
 $pdo->exec("UPDATE `{$prefix}attribute_group` SET sort_order=sort_order-1 WHERE attribute_group_id=3");
 $pdo->exec("INSERT INTO `{$prefix}attribute` SET attribute_id=990283,attribute_group_id=3,sort_order=0");
 refused(fn()=>$tool->preview(),'New owner group member refuses');
 $pdo->exec("DELETE FROM `{$prefix}attribute` WHERE attribute_id=990283");
 $pdo->prepare("INSERT INTO `{$prefix}cmteh_attribute` (attribute_id,field_key,group_name) VALUES(1,?,?)")->execute([hash('sha256','new-owner-mapping'),'Owner map']);
 refused(fn()=>$tool->preview(),'New CMTEH mapping of seed attribute refuses');
 $pdo->exec("DELETE FROM `{$prefix}cmteh_attribute` WHERE attribute_id=1");
 $pdo->exec("INSERT INTO `{$prefix}product_attribute` SET product_id=990281,attribute_id=1,language_id=1,text='Owner usage'");
 refused(fn()=>$tool->preview(),'Retained product usage of seed attribute refuses');
 $pdo->exec("DELETE FROM `{$prefix}product_attribute` WHERE product_id=990281 AND attribute_id=1");
 $pdo->exec("ALTER TABLE `{$prefix}attribute` ADD owner_flag INT NOT NULL DEFAULT 0");
 refused(fn()=>$tool->preview(),'Unexpected attribute schema refuses');
 $pdo->exec("ALTER TABLE `{$prefix}attribute` DROP owner_flag");
 $pdo->exec("UPDATE `{$prefix}product` SET model='Owner edit' WHERE product_id=28");
 refused(fn()=>$tool->apply($preview['guard'],fn($r)=>null),'Changed row refuses atomically');
 // Restore exact original field from the pinned preimage, never infer based on name.
 $model=json_decode(file_get_contents(__DIR__.'/../extension/cmteh/system/seed-cleanup.json'),true)['rows']['product'];
 foreach($model as $r) if($r['product_id']==28) $pdo->prepare("UPDATE `{$prefix}product` SET model=? WHERE product_id=28")->execute([$r['model']]);
 $pdo->exec("CREATE TABLE `{$prefix}owner_reference` (id INT PRIMARY KEY, product_id INT) ENGINE=InnoDB");$pdo->exec("INSERT INTO `{$prefix}owner_reference` VALUES(1,28)");
 refused(fn()=>$tool->preview(),'New owner reference refuses closure'); $pdo->exec("DROP TABLE `{$prefix}owner_reference`");
 $pdo->exec("ALTER TABLE `{$prefix}product` ADD owner_flag INT NOT NULL DEFAULT 0");
 refused(fn()=>$tool->preview(),'Unexpected delete schema refuses'); $pdo->exec("ALTER TABLE `{$prefix}product` DROP owner_flag");
 $pdo->exec("INSERT INTO `{$prefix}module` SET module_id=902,name='Owner structured reference',code='custom',setting='{\"products\":[28]}'");
 refused(fn()=>$tool->preview(),'New structured owner reference refuses'); $pdo->exec("DELETE FROM `{$prefix}module` WHERE module_id=902");
 $preview=$tool->preview(); $receipt=null;
 refused(fn()=>$tool->apply($preview['guard'],function($r){throw new RuntimeException('Preimage persistence failed');}),'Unclosed backup refuses without deletion');
 assertion((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}product`")->fetchColumn()===$initialProducts,'Failed backup leaves complete seed');
 refused(fn()=>$tool->apply(str_repeat('0',64),fn($r)=>null),'Wrong snapshot hash refuses');
 $tool->apply($preview['guard'],function($r)use(&$receipt){$receipt=$r;});
 assertion((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}product`")->fetchColumn()===($initialProducts-19),'Exact primary seed deleted');
 assertion((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}attribute` WHERE attribute_id BETWEEN 1 AND 11")->fetchColumn()===0 && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}attribute_group` WHERE attribute_group_id IN (3,4,5,6)")->fetchColumn()===0,'Exact11 attributes/4 groups removed');
 foreach($protectedAttributeTables as $t=>[$filter,$before]) assertion($pdo->query("SELECT * FROM `$prefix$t`".$filter)->fetchAll()===$before,'Preserved exact owner '.$t.' rows');
 assertion($pdo->query("SELECT HEX(payload) FROM `{$prefix}binary_probe` WHERE id=1")->fetchColumn()===$binaryBefore,'Cleanup preserves exact BLOB bytes');
 assertion($tool->preview()['state']==='already-clean','Repeated preview idempotent');
 $tool->apply($tool->preview()['guard'],fn($r)=>null); assertion(true,'Repeated apply idempotent');
 foreach($protected as $t=>$before) assertion($pdo->query("SELECT * FROM `$prefix$t`")->fetchAll()===$before,'Preserved exact '.$t.' rows');
 assertion((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}manufacturer` WHERE manufacturer_id IN (902,903)")->fetchColumn()===2,'Unknown and mapped native records preserved');
 $pdo->exec("INSERT INTO `{$prefix}cmteh_lead` SET public_id='00000000-0000-4000-8000-000000000001',request_key='00000000-0000-4000-8000-000000000002',fingerprint=REPEAT('a',64),name='Synthetic new request',created_at=UTC_TIMESTAMP()");
 $tool->recover($receipt); assertion((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}product`")->fetchColumn()===$initialProducts,'Narrow recovery restores exact seed');
 assertion($pdo->query("SELECT name FROM `{$prefix}cmteh_lead` WHERE public_id='00000000-0000-4000-8000-000000000001'")->fetchColumn()==='Synthetic new request','Recovery preserves newer submission');
 foreach($protectedAttributeTables as $t=>[$filter,$before]) assertion($pdo->query("SELECT * FROM `$prefix$t`".$filter)->fetchAll()===$before,'Recovery preserves exact owner '.$t.' rows');
 assertion($tool->preview()['counts']['attribute']===11 && $tool->preview()['counts']['attribute_group']===4,'Recovery restores pinned attribute/group closure');
 assertion($pdo->query("SELECT HEX(payload) FROM `{$prefix}binary_probe` WHERE id=1")->fetchColumn()===$binaryBefore && $pdo->query("SELECT * FROM `{$prefix}cmteh_lead_rate`")->fetchAll()===$protected['cmteh_lead_rate'],'Narrow recovery preserves exact BLOB and BINARY32 bytes');
 refused(fn()=>$tool->recover($receipt),'Recovery refuses occupied IDs');
 echo "PASS cleanup integration $n checks (before0)\n";
} finally {
 foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) if(str_starts_with($table,$prefix)) $pdo->exec("DROP TABLE `$table`");
}
