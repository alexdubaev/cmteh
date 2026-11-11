<?php
declare(strict_types=1);
require $argv[1] ?? '/var/www/html/config.php';
require __DIR__ . '/../extension/cmteh/system/Database.php';
require __DIR__ . '/../extension/cmteh/system/Catalog.php';
$pdo = Cmteh\Database::connectFromOpenCart();
if (!str_starts_with(Cmteh\Database::siteUrl(), 'http://127.0.0.1:')) throw new RuntimeException('Local fixture only');
$prefix = 'unmapped_' . bin2hex(random_bytes(4)) . '_';
$tables = ['product','product_description','product_code','category','category_description','manufacturer','seo_url'];
try {
    foreach ($tables as $table) $pdo->exec("CREATE TABLE `$prefix$table` LIKE `" . DB_PREFIX . "$table`");
    $c = new Cmteh\Catalog($pdo,$prefix,1,0,sys_get_temp_dir()); $c->migrate();
    $pdo->exec("INSERT INTO `{$prefix}manufacturer` SET manufacturer_id=902,name='Owner unknown',sort_order=0");
    $before = $pdo->query("SELECT * FROM `{$prefix}manufacturer`")->fetchAll();
    $rows = $c->editRows('brand',1,'Owner','',true);
    if (count($rows)!==1 || empty($rows[0]['unmapped']) || $rows[0]['native_id']!==902 || $rows[0]['path']!==null) throw new RuntimeException('Authorized unified list must include unknown native identity without public URL');
    if ($c->editRows('brand')!==[] || $c->editorChoices('brand')!==[]) throw new RuntimeException('Unknown records leaked into custom choices');
    if ($pdo->query("SELECT * FROM `{$prefix}manufacturer`")->fetchAll()!==$before || $pdo->query("SELECT COUNT(*) FROM `{$prefix}cmteh_brand`")->fetchColumn()!=0) throw new RuntimeException('Read mapped or published unknown data');
    echo "PASS unknown native identity/search; no mapping/publication; custom-only isolation (3 checks)\n";
} finally {
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) if (str_starts_with($table,$prefix)) $pdo->exec("DROP TABLE `$table`");
}
