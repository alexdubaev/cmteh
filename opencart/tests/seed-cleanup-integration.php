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
 $pdo->exec("INSERT INTO `{$prefix}manufacturer` SET manufacturer_id=902,name='Owner unknown',sort_order=0");
 $pdo->exec("INSERT INTO `{$prefix}manufacturer` SET manufacturer_id=903,name='Owner mapped',sort_order=0");
 $pdo->exec("INSERT INTO `{$prefix}cmteh_brand` SET manufacturer_id=903,source_id='mock-owner',slug='owner',status=1,published_at=UTC_TIMESTAMP(),data='{}'");
 $protected=[];
 foreach(['setting','user','user_group','cmteh_brand','cmteh_lead','cmteh_quote'] as $t) $protected[$t]=$pdo->query("SELECT * FROM `$prefix$t`")->fetchAll();
 $initialProducts=(int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}product`")->fetchColumn();
 $tool=new Cmteh\SeedCleanup($pdo,$prefix);
 $preview=$tool->preview(); assertion($preview['counts']['product']===19 && $preview['counts']['category']===38 && $preview['counts']['manufacturer']===6,'Pinned exact upstream closure counts19/38/6');
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
 assertion($tool->preview()['state']==='already-clean','Repeated preview idempotent');
 $tool->apply($tool->preview()['guard'],fn($r)=>null); assertion(true,'Repeated apply idempotent');
 foreach($protected as $t=>$before) assertion($pdo->query("SELECT * FROM `$prefix$t`")->fetchAll()===$before,'Preserved exact '.$t.' rows');
 assertion((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}manufacturer` WHERE manufacturer_id IN (902,903)")->fetchColumn()===2,'Unknown and mapped native records preserved');
 $pdo->exec("INSERT INTO `{$prefix}cmteh_lead` SET public_id='00000000-0000-4000-8000-000000000001',request_key='00000000-0000-4000-8000-000000000002',fingerprint=REPEAT('a',64),name='Synthetic new request',created_at=UTC_TIMESTAMP()");
 $tool->recover($receipt); assertion((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}product`")->fetchColumn()===$initialProducts,'Narrow recovery restores exact seed');
 assertion($pdo->query("SELECT name FROM `{$prefix}cmteh_lead` WHERE public_id='00000000-0000-4000-8000-000000000001'")->fetchColumn()==='Synthetic new request','Recovery preserves newer submission');
 refused(fn()=>$tool->recover($receipt),'Recovery refuses occupied IDs');
 echo "PASS cleanup integration $n checks (before0)\n";
} finally {
 foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) if(str_starts_with($table,$prefix)) $pdo->exec("DROP TABLE `$table`");
}
