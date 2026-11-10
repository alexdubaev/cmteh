<?php

declare(strict_types=1);
use Cmteh\Catalog;
use Cmteh\Database;
require $argv[1] ?? '/var/www/html/config.php';
require __DIR__ . '/../extension/cmteh/system/Database.php';
require __DIR__ . '/../extension/cmteh/system/Catalog.php';
require __DIR__ . '/../extension/cmteh/system/NativeCatalog.php';
require __DIR__ . '/../extension/cmteh/system/StorefrontPages.php';
require __DIR__ . '/../extension/cmteh/system/StorefrontSeo.php';
if (!preg_match('~^http://(?:127\.0\.0\.1|localhost)(?::[0-9]+)?$~D', Database::siteUrl())) throw new RuntimeException('Loopback required');
$pdo = Database::connectFromOpenCart();
$prefix = 'atest_' . bin2hex(random_bytes(6)) . '_';
$native = ['product','product_code','product_description','product_to_store','product_to_category','product_attribute','product_image','product_related','category','category_description','category_to_store','category_path','manufacturer','manufacturer_to_store','attribute','attribute_description','attribute_group','attribute_group_description','seo_url'];
$managed = array_map(fn($s) => 'cmteh_' . $s, ['schema','product','category','brand','attribute','content','route','media','media_link','import']);
$c = new Catalog($pdo,$prefix,1,0);
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function rejects(callable $fn,string $message): void { try { $fn(); } catch (InvalidArgumentException $e) { check(true,$message); return; } throw new RuntimeException($message); }
try {
 foreach ($native as $t) $pdo->exec("CREATE TABLE `$prefix$t` LIKE `" . DB_PREFIX . "$t`");
 $c->migrate();
 $c->saveFromAdmin('brand',['id'=>'brand-a','slug'=>'brand-a','name'=>'Synthetic A','status'=>'published','seo_title'=>'SEO A','seo_description'=>'Brand meta','canonical_url'=>'/brand/brand-a','image_path'=>'catalog/test.png']);
 check($c->editRecord('brand','brand-a')['seo_title']==='SEO A','Brand SEO and named image survive save');
 $c->saveFromAdmin('category',['id'=>'root','slug'=>'root','title'=>'Root','status'=>'published']);
 $c->saveFromAdmin('category',['id'=>'child','slug'=>'child','title'=>'Child','parent_slug'=>'root','status'=>'published']);
 $c->saveFromAdmin('category',['id'=>'other','slug'=>'other','title'=>'Other','status'=>'draft']);
 rejects(fn()=> $c->saveFromAdmin('category',['id'=>'root','parent_slug'=>'child']), 'Category cycle rejected atomically');
 check($c->editRecord('category','child')['parent_slug']==='root','Parent is read from native hierarchy');
 $c->saveFromAdmin('product',['id'=>'product-a','slug'=>'product-a','title'=>'Synthetic part','sku'=>'000001','mpn'=>'000002','brand_slug'=>'brand-a','category_slug'=>'child','category_slugs'=>['root','child'],'status'=>'published','main_image_path'=>'catalog/test.png','specification_items'=>[['name'=>'Size','value'=>'42','group_name'=>'Technical']]]);
 $p=$c->editRecord('product','product-a'); $pid=$p['native_id'];
 $pdo->prepare("INSERT INTO `{$prefix}product_description` (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES (?,2,'Other language','','','','','')")->execute([$pid]);
 $pdo->prepare("UPDATE `{$prefix}product_description` SET name='Native changed' WHERE product_id=? AND language_id=1")->execute([$pid]);
 $pdo->prepare("UPDATE `{$prefix}product` SET location='Keep location' WHERE product_id=?")->execute([$pid]);
 (new Cmteh\NativeCatalog($pdo,$prefix,1,0,'RUB'))->synchronize('product',$pid);
 $pdo->exec("INSERT INTO `{$prefix}attribute_group` (sort_order) VALUES (0)"); $group=(int)$pdo->lastInsertId();
 $pdo->exec("INSERT INTO `{$prefix}attribute_group_description` (attribute_group_id,language_id,name) VALUES ($group,1,'Native group')");
 $pdo->exec("INSERT INTO `{$prefix}attribute` (attribute_group_id,sort_order) VALUES ($group,0)"); $attribute=(int)$pdo->lastInsertId();
 $pdo->exec("INSERT INTO `{$prefix}attribute_description` (attribute_id,language_id,name) VALUES ($attribute,1,'Native dimension')");
 $pdo->exec("INSERT INTO `{$prefix}product_attribute` (product_id,attribute_id,language_id,text) VALUES ($pid,$attribute,1,'Native value')");
 $c->saveFromAdmin('product',['id'=>'product-a','short_description'=>'Changed']);
 check((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}product_attribute` WHERE product_id=$pid")->fetchColumn()===2,'Editing existing native specification does not duplicate attributes');
 $p=$c->editRecord('product','product-a');
 check($p['title']==='Native changed' && $p['sku']==='000001' && count($p['category_slugs'])===2 && $p['main_image_path']==='catalog/test.png' && $p['specification_items'][0]['value']==='42','Native edits, multiple categories, image and specs survive partial admin save');
 check($pdo->query("SELECT name FROM `{$prefix}product_description` WHERE product_id=$pid AND language_id=2")->fetchColumn()==='Other language' && $pdo->query("SELECT location FROM `{$prefix}product` WHERE product_id=$pid")->fetchColumn()==='Keep location','Other languages and unmanaged native fields preserved');
 check(count($c->editRows('product',1,'000001','published'))===1 && count($c->editRows('product',1,'no match',''))===0 && count($c->editRows('category',1,'','draft'))===1,'Lists search native fields and publication status');
 rejects(fn()=> $c->saveFromAdmin('product',['id'=>'product-a','slug'=>'second']), 'Slug change needs explicit confirmation');
 $c->save('route',['path'=>'/legacy-part','status'=>301,'target'=>'/product/product-a']);
 $c->saveFromAdmin('product',['id'=>'product-a','slug'=>'second'],true);
 $c->saveFromAdmin('product',['id'=>'product-a','slug'=>'third'],true);
 check($c->routes('/product/product-a')['target']==='/product/third' && $c->routes('/legacy-part')['target']==='/product/third','Repeated renames flatten incoming redirects');
 rejects(fn()=> $c->saveFromAdmin('product',['id'=>'product-a','slug'=>'product-a'],true),'Existing redirect source cannot be reclaimed');
 rejects(fn()=> $c->deleteRecord('brand','brand-a'),'Brand dependencies prevent deletion');
 rejects(fn()=> $c->deleteRecord('category','root'),'Category dependencies prevent deletion');
 $c->saveFromAdmin('home',['id'=>'home','status'=>'published','home_blocks'=>[['key'=>'brands','enabled'=>false,'sort_order'=>1],['key'=>'categories','enabled'=>true,'sort_order'=>-1]],'brands_mode'=>'selected','brand_slugs'=>['brand-a'],'categories_mode'=>'selected','category_slugs'=>['child','root','other'],'categories_limit'=>0]);
 $home=$c->content('home');
 check($home['categories_limit']===0 && $home['category_slugs']===['child','root','other'],'Home selection settings persist in order');
 rejects(fn()=> $c->saveFromAdmin('home',['id'=>'home','categories_limit'=>301]),'Home category limit validated');
 rejects(fn()=> $c->saveFromAdmin('home',['id'=>'home','home_blocks'=>[['key'=>'unknown','enabled'=>true,'sort_order'=>0]]]),'Unknown home blocks rejected');
 $view=(new Cmteh\StorefrontPages($c,'http://127.0.0.1:3170'))->view('/',[]);
 check(array_column($view['model']['home_categories']??[],'slug')===['child','root'] && ($view['model']['home_blocks'][0]['key']??'')==='categories' && !in_array('brands',array_column($view['model']['home_blocks']??[],'key'),true),'Home view applies selected order, public filtering and enabled block order');
 $c->deleteRecord('product','product-a');
 check($c->product('third')===null && $c->routes('/product/third')['status']===410 && $c->routes('/legacy-part')['status']===410,'Deletion clears public index and retires incoming URLs');
 check($pdo->query("SELECT COUNT(*) FROM `{$prefix}product_to_category` WHERE product_id=$pid")->fetchColumn()==0,'Deletion cleans native relations');
 check(count($c->editorChoices('category'))===3,'Editor choices include existing draft relations');
 $other=$c->editRecord('category','other');
 $c->save('route',['path'=>'/legacy-other','target'=>'/category/other','status'=>301]);
 $pdo->exec('DELETE FROM `'.$prefix.'category` WHERE category_id=' . $other['native_id']);
 (new Cmteh\NativeCatalog($pdo,$prefix,1,0,'RUB'))->synchronize('category',$other['native_id'],true);
 check($c->editRecord('category','other')===null && ($c->routes('/category/other')['status']??null)===410 && ($c->routes('/legacy-other')['status']??null)===410,'Native deletion retires canonical and incoming legacy URLs');
} finally {
 if ($pdo->inTransaction()) $pdo->rollBack();
 foreach ([...$managed,...$native] as $t) $pdo->exec("DROP TABLE IF EXISTS `$prefix$t`");
}
