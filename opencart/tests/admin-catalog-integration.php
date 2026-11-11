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
$private = sys_get_temp_dir() . '/' . $prefix;
mkdir($private,0700); mkdir($private . '/media',0700);
$c = new Catalog($pdo,$prefix,1,0,$private);
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function rejects(callable $fn,string $message): void { try { $fn(); } catch (InvalidArgumentException $e) { check(true,$message); return; } throw new RuntimeException($message); }
try {
 foreach ($native as $t) $pdo->exec("CREATE TABLE `$prefix$t` LIKE `" . DB_PREFIX . "$t`");
 $c->migrate();
 $c->saveFromAdmin('site',['id'=>'site','slug'=>'site','status'=>'published','phone'=>'+7 900 000-00-00','address'=>'Local test office']);
 $c->save('page',['id'=>'contacts','slug'=>'contacts','title'=>'Contacts','status'=>'published']);
 $mapView=fn()=> (new Cmteh\StorefrontPages($c,'http://127.0.0.1:3170'))->view('/contacts',[]);
 check($mapView()['site']['yandex_map_src']==='','No map is supplied by default');
 $widget='https://yandex.ru/map-widget/v1/?ll=37.617%2C55.755&z=12';
 $frame='https://api-maps.yandex.ru/frame/v1/-/CZxcTEST';
 foreach ([$widget, $frame, '<iframe src="'.$frame.'" width="560" height="400"></iframe>',
     '<div style="position:relative"><a href="https://yandex.ru/maps/">Яндекс Карты</a><iframe src="'.htmlspecialchars($widget,ENT_QUOTES,'UTF-8').'" onload="alert(1)" style="width:100%"></iframe></div>',
     'HTTPS://WWW.YANDEX.RU/map-widget/v1/?um=constructor%3Atest&source=constructor'] as $index=>$input) {
   $expected=$index===4 ? 'https://www.yandex.ru/map-widget/v1/?um=constructor%3Atest&source=constructor' : (($index===1 || $index===2) ? $frame : $widget);
   $c->saveFromAdmin('site',['id'=>'site','yandex_map_embed'=>$input]);
   $stored=$c->editRecord('site','site');
   check($stored['yandex_map_embed']===$expected && $c->content('site')['yandex_map_embed']===$expected && $mapView()['site']['yandex_map_src']===$expected,'Yandex map input '.$index.' persists only canonical URL and reaches public projection');
 }
 $before=$c->editRecord('site','site');
 foreach (['https://evil.test/map-widget/v1/', 'https://yandex.ru.evil.test/map-widget/v1/', 'https://evil.test@yandex.ru/map-widget/v1/',
     'http://yandex.ru/map-widget/v1/', '//yandex.ru/map-widget/v1/', 'javascript:alert(1)', 'https://yandex.ru/maps/',
     'https://api-maps.yandex.ru/map-widget/v1/', 'https://yandex.ru/map-widget/v1/%2e%2e/', 'https://yandex.ru/map-widget/v1/?x=%0aevil',
     'https://yandex.ru\\@evil.test/map-widget/v1/', 'https://yandex.ru:443/map-widget/v1/', "https://yandex.ru/map-widget/v1/?x=\tbad", "\0".$widget,
     '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>', '<iframe src="'.$widget.'" srcdoc="ignored"></iframe>',
     '<iframe src="'.$widget.'"></iframe><iframe src="'.$widget.'"></iframe>', '<script src="https://api-maps.yandex.ru/services/constructor/1.0/js/"></script>',
     '<iframe src="'.$widget.'"></iframe><script>alert(1)</script>', str_repeat('x',20001), ['src'=>$widget]] as $index=>$input) {
   rejects(fn()=> $c->saveFromAdmin('site',['id'=>'site','phone'=>'Must roll back','yandex_map_embed'=>$input]),'Unsafe map input '.$index.' rejected');
   check($c->editRecord('site','site')===$before,'Rejected map input '.$index.' leaves stored site unchanged');
 }
 $c->saveFromAdmin('site',['id'=>'site','yandex_map_embed'=>'']);
 check($c->editRecord('site','site')['yandex_map_embed']==='' && $mapView()['site']['yandex_map_src']==='' && $c->content('site')['address']==='Local test office','Clearing removes map and preserves other contacts');
 $legacy=$c->editRecord('site','site'); $legacy['yandex_map_embed']='https://evil.test/map-widget/v1/';
 $pdo->prepare('UPDATE `'.$prefix.'cmteh_content` SET data=? WHERE type=? AND source_id=?')->execute([json_encode($legacy,JSON_THROW_ON_ERROR),'site','site']);
 check($mapView()['site']['yandex_map_src']==='' && !isset($mapView()['site']['yandex_map_embed']),'Public projection hides invalid legacy map and raw input');
 check(!str_contains(Cmteh\CatalogInput::html('<p>Keep</p><iframe src="'.$widget.'"></iframe><script>alert(1)</script>'),'iframe'),'General rich text sanitizer still removes embedded frames');
 $c->saveFromAdmin('brand',['id'=>'brand-a','slug'=>'brand-a','name'=>'Synthetic A','status'=>'published','seo_title'=>'SEO A','seo_description'=>'Brand meta','canonical_url'=>'/brand/brand-a','image_path'=>'catalog/test.png']);
 check($c->editRecord('brand','brand-a')['seo_title']==='SEO A','Brand SEO and named image survive save');
 $c->saveFromAdmin('category',['id'=>'root','slug'=>'root','title'=>'Root','status'=>'published']);
 $c->saveFromAdmin('category',['id'=>'root','h1'=>'Synthetic category heading']);
 check(($c->editRecord('category','root')['h1']??null)==='Synthetic category heading' && ($c->categories()[0]['h1']??null)==='Synthetic category heading','Category H1 survives admin reload and public catalog read');
 $c->saveFromAdmin('article',['id'=>'article-a','slug'=>'article-a','title'=>'Synthetic article','status'=>'published']);
 $c->saveFromAdmin('article',['id'=>'article-a','excerpt'=>'Synthetic article summary']);
 check(($c->editRecord('article','article-a')['excerpt']??null)==='Synthetic article summary' && ($c->content('article','article-a')['excerpt']??null)==='Synthetic article summary','Article excerpt survives admin reload and public content read');
 $legacyImage='00000000-0000-4000-8000-000000000001';
 file_put_contents($private . '/media/legacy.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jMskAAAAASUVORK5CYII='));
 $c->save('category',array_replace($c->editRecord('category','root'),['image'=>$legacyImage,'description'=>'Preserve legacy description']));
 $c->save('media',['uuid'=>$legacyImage,'path'=>'legacy.png','filename'=>'legacy.png','mime'=>'image/png','status'=>'published','owners'=>[['type'=>'category','id'=>'root']]]);
 check(($c->categories()[0]['image']??null)===$legacyImage,'Fixture legacy category image is approved for its owner');
 $c->saveFromAdmin('category',['id'=>'root','image'=>null]);
 $cleared=$c->editRecord('category','root');
 check(array_key_exists('image',$cleared) && $cleared['image']===null && $cleared['description']==='Preserve legacy description','Explicit category image clear removes legacy fallback and preserves unrelated fields');
 $c->saveFromAdmin('category',['id'=>'root','image'=>$legacyImage]);
 check($c->editRecord('category','root')['image']===null,'Clear-only category image input cannot assign a UUID attachment');

 $c->saveFromAdmin('category',['id'=>'child','slug'=>'child','title'=>'Child','parent_slug'=>'root','status'=>'published']);
 $c->saveFromAdmin('category',['id'=>'other','slug'=>'other','title'=>'Other','status'=>'draft']);
 rejects(fn()=> $c->saveFromAdmin('category',['id'=>'root','parent_slug'=>'child']), 'Category cycle rejected atomically');
 check($c->editRecord('category','child')['parent_slug']==='root','Parent is read from native hierarchy');
 $c->saveFromAdmin('product',['id'=>'product-a','slug'=>'product-a','title'=>'Synthetic part','sku'=>'000001','mpn'=>'000002','brand_slug'=>'brand-a','category_slug'=>'child','category_slugs'=>['root','child'],'status'=>'published','main_image_path'=>'catalog/test.png','specification_items'=>[['name'=>'Size','value'=>'42','group_name'=>'Technical']]]);
 $p=$c->editRecord('product','product-a'); $pid=$p['native_id'];
 $pdo->prepare("INSERT INTO `{$prefix}product_description` (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES (?,2,'Other language','','','','','')")->execute([$pid]);
 $pdo->prepare("UPDATE `{$prefix}product_description` SET name='Native changed' WHERE product_id=? AND language_id=1")->execute([$pid]);
 $pdo->prepare("UPDATE `{$prefix}product` SET location='Keep location', model='Native model independent of SKU' WHERE product_id=?")->execute([$pid]);
 (new Cmteh\NativeCatalog($pdo,$prefix,1,0,'RUB'))->synchronize('product',$pid);
 $pdo->exec("INSERT INTO `{$prefix}attribute_group` (sort_order) VALUES (0)"); $group=(int)$pdo->lastInsertId();
 $pdo->exec("INSERT INTO `{$prefix}attribute_group_description` (attribute_group_id,language_id,name) VALUES ($group,1,'Native group')");
 $pdo->exec("INSERT INTO `{$prefix}attribute` (attribute_group_id,sort_order) VALUES ($group,0)"); $attribute=(int)$pdo->lastInsertId();
 $pdo->exec("INSERT INTO `{$prefix}attribute_description` (attribute_id,language_id,name) VALUES ($attribute,1,'Native dimension')");
 $pdo->exec("INSERT INTO `{$prefix}product_attribute` (product_id,attribute_id,language_id,text) VALUES ($pid,$attribute,1,'Native value')");
 $c->saveFromAdmin('product',['id'=>'product-a','short_description'=>'Changed']);
 check($pdo->query("SELECT model FROM `{$prefix}product` WHERE product_id=$pid")->fetchColumn()==='Native model independent of SKU' && $c->editRecord('product','product-a')['sku']==='000001','Unrelated admin save preserves native model independently of SKU after native edits');
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
 if (is_file($private . '/media/legacy.png')) unlink($private . '/media/legacy.png');
 rmdir($private . '/media'); rmdir($private);
}
