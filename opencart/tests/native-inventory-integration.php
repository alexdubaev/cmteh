<?php
declare(strict_types=1);

// Actual installed native Loader/Factory/models; only isolated fixture-prefix data is touched.
$installed = '/var/www/html/';
$child=in_array($argv[1]??'',['--race-child','--before-failure'],true);
if (!$child) ob_start();
$prefix = $child ? ($argv[2]??'') : 'ngt_' . bin2hex(random_bytes(6)) . '_';
if (!preg_match('/^ngt_[a-f0-9]{12}_$/D',$prefix)) throw new RuntimeException('Invalid fixture prefix');
require $installed . 'config.php';
$originalPrefix = DB_PREFIX;
// PHP resolves an unqualified model constant in its namespace before the global constant.
// This leaves the sealed installed config untouched while all native SQL targets task data.
foreach (['Admin\\Model\\Catalog','Admin\\Model\\Design','Admin\\Controller\\Catalog','Catalog\\Model\\Catalog','Catalog\\Model\\Checkout',
 'Admin\\Controller\\Extension\\Cmteh\\Event','Admin\\Model\\Extension\\Cmteh\\Catalog',
 'Catalog\\Controller\\Extension\\Cmteh\\Event','Catalog\\Model\\Extension\\Cmteh\\Catalog','Catalog\\Model\\Extension\\Cmteh\\Checkout'] as $namespace) {
 define('Opencart\\'.$namespace.'\\DB_PREFIX',$prefix);
}
define('Opencart\\System\\Library\\Cart\\DB_PREFIX',$prefix);
require_once DIR_SYSTEM.'helper/general.php';
require_once DIR_SYSTEM . 'engine/autoloader.php';
$autoloader = new Opencart\System\Engine\Autoloader();
$autoloader->register('Opencart', $installed);
$autoloader->register('Opencart\\Admin\\Controller\\Extension\\Cmteh', DIR_EXTENSION . 'cmteh/admin/controller/');
$autoloader->register('Opencart\\Admin\\Model\\Extension\\Cmteh', DIR_EXTENSION . 'cmteh/admin/model/');
$autoloader->register('Opencart\\Catalog\\Controller\\Extension\\Cmteh', DIR_EXTENSION . 'cmteh/catalog/controller/');
$autoloader->register('Opencart\\Catalog\\Model\\Extension\\Cmteh', DIR_EXTENSION . 'cmteh/catalog/model/');
foreach (['Database','CatalogSchema','InventorySchema','Inventory'] as $class) require_once DIR_EXTENSION . 'cmteh/system/' . $class . '.php';
foreach (['system/InventoryNativeGuard.php','system/Inventory.php','system/InventoryInput.php','system/InventorySchema.php','system/InventoryException.php','system/CatalogInput.php','system/CatalogWrites.php','system/CatalogImport.php','system/CatalogAdmin.php','system/NativeCatalog.php',
 'admin/controller/event/cmteh_catalog.php','admin/controller/module/cmteh.php','admin/model/catalog/inventory_guard.php','admin/view/javascript/cmteh_inventory_guard.js',
 'catalog/controller/event/cmteh_inventory.php','catalog/model/catalog/inventory_guard.php','catalog/model/checkout/inventory_guard.php'] as $dependency) {
 $artifact=realpath(DIR_EXTENSION.'cmteh/'.$dependency);
 if (!$artifact || !str_starts_with($artifact,$installed) || hash_file('sha256',$artifact)!==hash_file('sha256',__DIR__.'/../extension/cmteh/'.$dependency)) throw new RuntimeException('Installed native dependency mismatch: '.$dependency);
}
foreach ([Cmteh\Inventory::class,Opencart\System\Engine\Loader::class,Opencart\Admin\Model\Catalog\Product::class] as $class) {
 if (!str_starts_with((new ReflectionClass($class))->getFileName(),$installed)) throw new RuntimeException('Expected installed native class');
}
if (!preg_match('~^http://(?:127\.0\.0\.1|localhost)(?::[0-9]+)?$~D', Cmteh\Database::siteUrl())) throw new RuntimeException('Loopback required');
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function denied(callable $action,string $message): void { try { $action(); } catch (Cmteh\InventoryException $e) { check(true,$message); return; } throw new RuntimeException($message); }
$pdo = Cmteh\Database::connectFromOpenCart();
$registry = new Opencart\System\Engine\Registry();
$config = new Opencart\System\Engine\Config(); $config->set('application','Admin'); $config->set('config_language_id',1); $config->set('config_currency','RUB');
$registry->set('config',$config);
$registry->set('db',new Opencart\System\Library\DB(DB_DRIVER,DB_HOSTNAME,DB_USERNAME,DB_PASSWORD,DB_DATABASE,DB_PORT));
$registry->set('event',new Opencart\System\Engine\Event($registry));
$registry->set('factory',new Opencart\System\Engine\Factory($registry));
$registry->set('load',new Opencart\System\Engine\Loader($registry));
$registry->set('cache',new class { public function delete(string $key): void {} });
$registry->set('log',new class { public function write(string $text): void { throw new RuntimeException('Unexpected native projection warning'); } });
$nativeUser=function(int $id=1) use($registry): void {
 $registry->set('session',(object)['data'=>['user_id'=>$id,'user_token'=>'synthetic-local-token']]);
 $registry->set('user',new Opencart\System\Library\Cart\User($registry));
};
$registry->set('request',(object)['post'=>[],'get'=>[],'server'=>['REQUEST_METHOD'=>'POST']]);
$registry->set('response',new Opencart\System\Library\Response());
$registry->set('language',new class { public function load(...$args): array {return [];} public function get(string $key): string {return $key;} });
$hooks=function(string $application) use($registry): void {
 $module=new Opencart\Admin\Controller\Extension\Cmteh\Module\Cmteh($registry);
 foreach (['nativeCatalogEvents','inventoryEvents'] as $method) {
  if (!method_exists($module,$method)) continue;
  foreach ((new ReflectionMethod($module,$method))->invoke($module) as $definition) {
   [$trigger,$action]=$definition;
   if (!str_starts_with($trigger,$application.'/')) continue;
   $registry->get('event')->register(substr($trigger,strlen($application)+1),new Opencart\System\Engine\Action($action),$definition[2]??0);
  }
 }
};
if ($child) {
 $nativeUser();
 if (($argv[1]??'')==='--before-failure') {
  $config->set('application','Catalog'); $hooks('catalog');
  $spy=new class($registry) extends Opencart\System\Engine\Controller { public string $fixturePrefix=''; public function fail(string &$route,array &$args): void { $this->db->query('UPDATE `'.$this->fixturePrefix.'product` SET quantity=111 WHERE product_id=2'); throw new RuntimeException('Synthetic later before-event failure'); } };
  $spy->fixturePrefix=$prefix;
  $registry->set('fallback_controller_test_inventory_failure',$spy);
  $registry->get('event')->register('model/checkout/order.addHistory/before',new Opencart\System\Engine\Action('test/inventory_failure.fail'),0);
  $registry->get('load')->model('checkout/order');
  try { $registry->get('model_checkout_order')->addHistory(102,1); }
  catch (RuntimeException $error) { if ($error->getMessage()!=='Synthetic later before-event failure') throw $error; echo "failure-caught\n"; exit; }
  throw new RuntimeException('Expected later before-event failure');
 }
 $hooks('admin'); $load=$registry->get('load'); $load->model('design/seo_url'); $load->model('catalog/product');
 echo "ready\n"; fflush(STDOUT);
 $payload=json_decode(trim(fgets(STDIN)),true,64,JSON_THROW_ON_ERROR);
 $registry->get('model_catalog_product')->editProduct(1,$payload);
 echo "saved\n"; exit;
}
$tables=[];
try {
 $names=$pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
 foreach ($names as $name) {
  if (!str_starts_with($name,$originalPrefix)) continue;
  $suffix=substr($name,strlen($originalPrefix));
  if (!str_starts_with($suffix,'product') && !in_array($suffix,['user','user_group','seo_url','order','order_product','order_history','order_status','category','category_description','category_path','manufacturer','manufacturer_to_store','download','download_description','option','option_description','option_value','attribute','attribute_description','attribute_group','attribute_group_description','subscription_plan','subscription_plan_description'],true)) continue;
  $pdo->exec("CREATE TABLE `{$prefix}{$suffix}` LIKE `{$name}`"); $tables[]=$suffix;
 }
 Cmteh\CatalogSchema::migrate($pdo,$prefix); Cmteh\InventorySchema::migrate($pdo,$prefix);
 $q=$pdo->prepare('INSERT INTO `'.$prefix.'user_group` (user_group_id,name,permission) VALUES(?,?,?)');
 $q->execute([1,'Synthetic editor',json_encode(['access'=>['catalog/product','extension/cmteh/module/cmteh_catalog'],'modify'=>['catalog/product']],JSON_THROW_ON_ERROR)]);
 $q->execute([2,'Synthetic read only',json_encode(['access'=>['catalog/product'],'modify'=>[]],JSON_THROW_ON_ERROR)]);
 $pdo->exec("INSERT INTO `{$prefix}user` (user_id,user_group_id,username,password,firstname,lastname,email,image,ip,status,date_added) VALUES(1,1,'synthetic-editor','disabled-synthetic-password','Synthetic','Editor','synthetic@example.invalid','','127.0.0.1',1,UTC_TIMESTAMP()),(2,2,'synthetic-read','disabled-synthetic-password','Synthetic','Reader','synthetic@example.invalid','','127.0.0.1',1,UTC_TIMESTAMP())");
 $permissionsBefore=$pdo->query("SELECT permission FROM `{$prefix}user_group` ORDER BY user_group_id")->fetchAll(PDO::FETCH_COLUMN);
 $nativeUser();
 $pdo->exec("INSERT INTO `{$prefix}product` (product_id,model,quantity,status,date_available) VALUES(1,'SYNTHETIC',10,1,'2020-01-01'),(2,'LEGACY',5,1,'2020-01-01')");
 $pdo->exec("INSERT INTO `{$prefix}product_description` (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES(1,1,'Synthetic','','','Synthetic','',''),(2,1,'Legacy','','','Legacy','','')");
 $pdo->exec("INSERT INTO `{$prefix}product_to_store` VALUES(1,0),(2,0)");
 $pdo->exec("INSERT INTO `{$prefix}cmteh_product` (product_id,source_id,slug,sku,sku_norm,mpn_norm,name_norm,brand_norm,manufacturer_id,category_id,published_at,indexable,price_status,data) VALUES(1,'synthetic','synthetic','001','001','','synthetic','',0,0,'2020-01-01',1,'on_request','{}')");
 $inventory=new Cmteh\Inventory($pdo,$prefix); $actor=['id'=>1,'label'=>'Synthetic operator'];
 $warehouse=$inventory->command('warehouse.save',['expected_warehouse_version'=>0,'code'=>'SYN','name'=>'Synthetic'],$actor,'native-test-warehouse')['warehouse'];
 $state=$inventory->productState(1);
 $inventory->command('product.activate',['product_id'=>1,'expected_product_version'=>0,'expected_legacy_quantity'=>10,'expected_legacy_revision'=>$state['legacy_revision'],'confirmed'=>true,'allocations'=>[['warehouse_id'=>$warehouse['warehouse_id'],'quantity'=>10,'expected_warehouse_version'=>1]]],$actor,'native-test-activate');
 $hooks('admin');
 if (($argv[1]??'')==='--form-only') {
  $registry->set('url',new Opencart\System\Library\Url(rtrim(HTTP_SERVER,'/').'/admin/'));
  $registry->get('session')->data['user_token']='synthetic-form&+token';
  $registry->get('request')->get=['product_id'=>1];
  $formRoute='catalog/product_form'; $formData=['footer'=>''];
  $registry->get('event')->trigger('view/catalog/product_form/before',[&$formRoute,&$formData]);
  if (!preg_match('~<script type="application/json" id="cmteh-inventory-guard">(.*?)</script>~s',$formData['footer'],$match)) throw new RuntimeException('Installed form event did not emit stock summary');
  $summary=json_decode($match[1],true,32,JSON_THROW_ON_ERROR);
  $parts=parse_url($summary['url']); parse_str($parts['query']??'',$query);
  check(($query['route']??null)==='extension/cmteh/module/cmteh_catalog.inventory' && ($query['product_id']??null)==='1','Installed native form summary opens existing inventory method for native product');
  check(($query['user_token']??null)===$registry->get('session')->data['user_token'] && !str_contains($summary['url'],'&amp;') && str_contains($match[1],'\\u0026'),'Native form URL helper encodes token once and JSON ampersands reach DOM href correctly');
  $nativeUser(2); $formData=['footer'=>''];
  $registry->get('event')->trigger('view/catalog/product_form/before',[&$formRoute,&$formData]);
  if (!preg_match('~<script type="application/json" id="cmteh-inventory-guard">(.*?)</script>~s',$formData['footer'],$match)) throw new RuntimeException('Installed readonly stock summary missing');
  check(json_decode($match[1],true,32,JSON_THROW_ON_ERROR)['url']==='','Native form hides inventory link when existing catalog access is absent');
  return;
 }
 $load=$registry->get('load'); $load->model('design/seo_url'); $load->model('catalog/product'); $model=$registry->get('model_catalog_product');
 $data=$model->getProduct(1); $data['product_description']=$model->getDescriptions(1); $data['product_description'][1]['name']='Description changed'; $data['quantity']=999; $data['product_store']=[0];
 $model->editProduct(1,$data);
 check((int)$pdo->query("SELECT quantity FROM `{$prefix}product` WHERE product_id=1")->fetchColumn()===10,'Stale native full form preserves warehouse available');
 check($pdo->query("SELECT name FROM `{$prefix}product_description` WHERE product_id=1")->fetchColumn()==='Description changed','Descriptive native edit is retained');
 check($pdo->query("SELECT name_norm FROM `{$prefix}cmteh_product` WHERE product_id=1")->fetchColumn()==='DESCRIPTIONCHANGED','Redirected loader explicitly synchronizes projection after commit');
 $copy=$model->copyProduct(1);
 check($copy>2 && (int)$pdo->query("SELECT quantity FROM `{$prefix}product` WHERE product_id=$copy")->fetchColumn()===0 && !$inventory->productState($copy)['managed'],'Managed native copy has zero unassigned stock and no inherited inventory');
 denied(fn()=>$model->deleteProduct(1),'Direct native managed deletion is rejected before writes');
 $registry->get('request')->post=['selected'=>[2,1]];
 $load->controller('catalog/product.delete');
 check((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}product` WHERE product_id IN(1,2)")->fetchColumn()===2 && isset(json_decode($registry->get('response')->getOutput(),true)['error']),'Mixed native delete batch preflights all rows before deleting legacy first');
 $nativeUser(2);
 $registry->get('request')->post=['selected'=>[2]];
 $load->controller('catalog/product.delete');
 check((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}product` WHERE product_id=2")->fetchColumn()===1 && isset(json_decode($registry->get('response')->getOutput(),true)['error']),'Native modify denial remains enforced through original controller');
 $nativeUser();
 $legacy=$model->getProduct(2); $legacy['product_description']=$model->getDescriptions(2); $legacy['quantity']=7;
 $model->editProduct(2,$legacy);
 check((int)$pdo->query("SELECT quantity FROM `{$prefix}product` WHERE product_id=2")->fetchColumn()===7,'Unmanaged native full form still changes legacy quantity');
 // Competing connection actually blocks at the native product row, then reads the newly committed aggregate.
 $pdo->beginTransaction(); $pdo->query("SELECT product_id FROM `{$prefix}product` WHERE product_id=1 FOR UPDATE")->fetchAll();
 $process=proc_open([PHP_BINARY,__FILE__,'--race-child',$prefix],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if (!is_resource($process)) throw new RuntimeException('Cannot start native competing process');
 check(trim(fgets($pipes[1]))==='ready','Competing installed native Loader process is ready');
 fwrite($pipes[0],json_encode($data,JSON_THROW_ON_ERROR)."\n"); fflush($pipes[0]);
 stream_set_blocking($pipes[1],false); usleep(150000);
 check(proc_get_status($process)['running'] && stream_get_contents($pipes[1])==='','Native full form waits on same product mutex while inventory transaction owns it');
 $inventory->command('reservation.create',['product_id'=>1,'warehouse_id'=>$warehouse['warehouse_id'],'expected_product_version'=>1,'expected_balance_version'=>1,'expected_warehouse_version'=>1,'quantity'=>4,'reason'=>'Synthetic concurrent reserve'],$actor,'native-test-reserve');
 $pdo->commit(); fclose($pipes[0]); stream_set_blocking($pipes[1],true);
 $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
 check($exit===0 && trim($out)==='saved' && $err==='' && (int)$pdo->query("SELECT quantity FROM `{$prefix}product` WHERE product_id=1")->fetchColumn()===6,'Concurrent native stale save preserves newly reserved aggregate available');
 require_once DIR_EXTENSION.'cmteh/system/Catalog.php';
 $catalog=new Cmteh\Catalog($pdo,$prefix,1,0);
 $stateBefore=$inventory->productState(1); $balancesBefore=$inventory->balances(['product_id'=>1]);
 $catalog->saveFromAdmin('product',['id'=>'synthetic','short_description'=>'Metadata only','availability_status'=>'out_of_stock']);
 check($inventory->productState(1)===$stateBefore && $inventory->balances(['product_id'=>1])===$balancesBefore,'CMTEH metadata save does not reset managed stock, reserves, or versions');
 require_once DIR_EXTENSION.'cmteh/system/CatalogImport.php';
 $importPath=tempnam(sys_get_temp_dir(),'ngt_');
 try {
  $importRow=$catalog->editRecord('product','synthetic'); $importRow['title']='Metadata import'; $importRow['quantity']=999;
  file_put_contents($importPath,json_encode(['type'=>'product','data'=>$importRow],JSON_THROW_ON_ERROR)."\n");
  $import=new Cmteh\CatalogImport($pdo,$catalog,$prefix); $preview=$import->preview($importPath);
  $result=$import->apply($importPath,$preview['sha256'],'synthetic-metadata');
  check($result['finished'] && $inventory->productState(1)===$stateBefore && $inventory->balances(['product_id'=>1])===$balancesBefore,'Existing CMTEH metadata import cannot overwrite warehouse source or native available');
 } finally { if (is_file($importPath)) unlink($importPath); }
 try { (new Cmteh\Catalog($pdo,$prefix,1,0))->deleteRecord('product','synthetic'); throw new RuntimeException('CMTEH deletion should reject'); }
 catch (InvalidArgumentException $e) { check((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}cmteh_inventory_entry` WHERE product_id=1")->fetchColumn()===2,'CMTEH delete preserves native identity and inventory history'); }
 $registry->get('db')->query('START TRANSACTION');
 try {
  $foreign=$data; $foreign['product_description'][1]['name']='Must not write';
  try { $model->editProduct(1,$foreign); throw new RuntimeException('Expected foreign native transaction rejection'); }
  catch (Cmteh\InventoryException $error) { check($error->reason==='unsupported_native_transaction','Managed foreign native transaction rejected before writes'); }
  check($registry->get('db')->query('SELECT name FROM `'.$prefix.'product_description` WHERE product_id=1')->row['name']==='Metadata import','Foreign native owner retains unchanged data and its transaction');
 } finally { $registry->get('db')->query('ROLLBACK'); }
 $config->set('application','Catalog'); $registry->unset('model_catalog_product'); $registry->unset('fallback_model_catalog_product');
 $registry->unset('fallback_model_extension_cmteh_catalog_inventory_guard');
 $registry->set('event',new Opencart\System\Engine\Event($registry)); $hooks('catalog');
 $load->model('catalog/product'); $publicModel=$registry->get('model_catalog_product');
 denied(fn()=>$publicModel->editQuantity(1,999),'Native direct quantity setter cannot bypass managed inventory');
 denied(fn()=>$publicModel->editOptionQuantity(1,1,1,999),'Native direct option quantity setter cannot bypass managed inventory');
 $publicModel->editQuantity(2,8);
 check((int)$pdo->query("SELECT quantity FROM `{$prefix}product` WHERE product_id=2")->fetchColumn()===8,'Unmanaged direct native quantity setter is unchanged');
 $pdo->exec("INSERT INTO `{$prefix}order_product` (order_id,product_id,name,model,quantity,price,total,tax,reward) VALUES(101,1,'Synthetic','Synthetic',1,0,0,0,0),(102,2,'Legacy','Legacy',1,0,0,0,0)");
 $spy=new class($registry) extends Opencart\System\Engine\Controller { public int $calls=0; public function before(string &$route,array &$args): void {$this->calls++;} };
 $registry->set('fallback_controller_test_inventory_spy',$spy);
 $registry->get('event')->register('model/checkout/order.addHistory/before',new Opencart\System\Engine\Action('test/inventory_spy.before'),0);
 $load->model('checkout/order'); $order=$registry->get('model_checkout_order');
 denied(fn()=>$order->addHistory(101,1,'Synthetic',true),'Managed native order rejected before mail/activity/statistics hooks');
 check($spy->calls===0 && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}order_history`")->fetchColumn()===0 && $inventory->productState(1)['available']===6,'Managed native order has no before-hook, history, or stock side effect');
 check($order->addHistory(999999,1)===0 && $spy->calls===1,'Unmanaged missing-order native behavior and later hooks remain unchanged');
 $process=proc_open([PHP_BINARY,__FILE__,'--before-failure',$prefix],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if (!is_resource($process)) throw new RuntimeException('Cannot start failing before-event process');
 fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
 check($exit===0 && trim($out)==='failure-caught' && $err==='' && (int)$pdo->query("SELECT quantity FROM `{$prefix}product` WHERE product_id=2")->fetchColumn()===8,'Later before-event exception is propagated and shutdown rolls back its native transaction');
 check($pdo->query("SELECT permission FROM `{$prefix}user_group` ORDER BY user_group_id")->fetchAll(PDO::FETCH_COLUMN)===$permissionsBefore,'Native guards preserve all synthetic user-group ACL bytes');
} finally {
 if ($pdo->inTransaction()) $pdo->rollBack();
 $registry->get('db')->query('ROLLBACK');
 $names=$pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
 foreach ($names as $name) if (str_starts_with($name,$prefix)) $pdo->exec('DROP TABLE `'.$name.'`');
}
