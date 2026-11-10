<?php

declare(strict_types=1);

use Cmteh\Catalog;
use Cmteh\CatalogImport;
use Cmteh\Database;

$config = $argv[1] ?? '/var/www/html/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "Usage: php catalog-integration.php /path/to/isolated/opencart/config.php\n");
    exit(2);
}
require $config;
require __DIR__ . '/../extension/cmteh/system/Database.php';
$implementation = __DIR__ . '/../extension/cmteh/system/Catalog.php';
if (!is_file($implementation)) {
    throw new RuntimeException('RED: the OpenCart catalog cannot publish an imported product');
}
require $implementation;
require __DIR__ . '/../extension/cmteh/system/CatalogImport.php';

if (!preg_match('~^http://(?:127\.0\.0\.1|localhost)(?::[0-9]+)?$~D', Database::siteUrl())) {
    throw new RuntimeException('Integration tests require the isolated loopback site');
}
$pdo = Database::connectFromOpenCart();
$prefix = 'ctest_' . bin2hex(random_bytes(6)) . '_';
$private = sys_get_temp_dir() . '/' . $prefix;
mkdir($private, 0700);
$catalog = new Catalog($pdo, $prefix, 1, 0, $private);
$native = [
    'product', 'product_code', 'product_description', 'product_to_store', 'product_to_category', 'product_attribute',
    'category', 'category_description', 'category_to_store', 'category_path', 'manufacturer', 'manufacturer_to_store',
    'attribute', 'attribute_description', 'attribute_group', 'attribute_group_description',
];
$managed = array_map(static fn(string $suffix): string => 'cmteh_' . $suffix,
    ['schema', 'product', 'category', 'brand', 'attribute', 'content', 'route', 'media', 'media_link', 'import']);
$paths = [];

function expect(bool $condition, string $scenario): void
{
    if (!$condition) {
        throw new RuntimeException($scenario);
    }
    echo "PASS $scenario\n";
}

function ndjson(array $records): string
{
    $file = tempnam(sys_get_temp_dir(), 'cmteh-catalog-');
    $stream = fopen($file, 'wb');
    foreach ($records as [$type, $data]) {
        fwrite($stream, json_encode(compact('type', 'data'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }
    fclose($stream);
    return $file;
}

try {
    foreach ($native as $table) {
        $pdo->exec('CREATE TABLE `' . $prefix . $table . '` LIKE `' . DB_PREFIX . $table . '`');
    }
    $catalog->migrate();
    $catalog->migrate();
    $import = new CatalogImport($pdo, $catalog, $prefix);

    $fixture = __DIR__ . '/../fixtures/demo.ndjson';
    $preview = $import->preview($fixture);
    expect($preview['added'] === 49 && $preview['errors'] === 0 && $catalog->indexableCount() === 0,
        'Preview does not write and reports 49 valid rows');
    $first = $import->apply($fixture, $preview['sha256'], 'baseline', false, false, 12, 2);
    expect(!$first['finished'] && $first['next_line'] === 25 && $first['added'] === 24,
        'A partial run commits only completed batches');
    $done = $import->apply($fixture, $preview['sha256'], 'baseline', true);
    expect($done['finished'] && $done['added'] === 49 && $catalog->indexableCount() === 16,
        'Resume uses the last checkpoint and publishes all fixture products');
    $repeat = $import->apply($fixture, $preview['sha256'], 'repeat');
    expect($repeat['updated'] === 49 && $repeat['added'] === 0 && count($catalog->sitemap(0, 1000)) === 16,
        'Repeat updates the same IDs and sitemap contains no duplicates');

    $conflict = $paths[] = ndjson([
        ['product', $catalog->editRecord('product', 'mock-11')],
        ['product', array_replace($catalog->editRecord('product', 'mock-11'), ['id' => 'collision'])],
    ]);
    $invalid = $import->preview($conflict);
    try {
        $import->apply($conflict, $invalid['sha256'], 'conflict');
        throw new RuntimeException('Conflicting input was applied');
    } catch (InvalidArgumentException) {
        expect($invalid['errors'] === 1 && $catalog->indexableCount() === 16, 'A slug collision is reported before any write');
    }

    $claas = $catalog->products(['q' => '0000550380']);
    $tail = $catalog->products(['page' => 2]);
    expect($claas['total'] === 1 && $claas['products'][0]['sku'] === '0000550380' && count($tail['products']) === 4,
        'Exact search preserves leading zeros and pagination reaches the tail');
    $batch = $catalog->checkList(array_merge(
        [['article' => 'RE-568158', 'quantity' => 3], ['article' => '0000550380', 'quantity' => 2],
         ['article' => 'RE-56815', 'quantity' => 1], ['article' => 'ANOTHER000', 'quantity' => 1]],
        array_fill(0, 996, ['article' => '0000550380', 'quantity' => 7])
    ));
    expect(count($batch['rows']) === 1000 && !$batch['partial']
        && $batch['rows'][0]['status'] === 'matched' && $batch['rows'][0]['products'][0]['id'] === 'mock-11'
        && $batch['rows'][1]['article'] === '0000550380' && $batch['rows'][999]['quantity'] === 7
        && $batch['rows'][2]['status'] === 'not_found' && $batch['rows'][3]['status'] === 'not_found',
        'Exact batch checks 1000 ordered duplicate rows, preserves zeros and ignores prefix and MPN matches');
    try {
        $catalog->checkList(array_fill(0, 1001, ['article' => 'x', 'quantity' => 1]));
        throw new RuntimeException('Oversize batch accepted');
    } catch (InvalidArgumentException) {
        expect(true, 'More than 1000 rows is an explicit error');
    }
    $item = $catalog->editRecord('product', 'mock-11');
    $copy = array_replace($item, ['id' => 'ranking-mpn', 'slug' => 'ranking-mpn', 'sku' => 'ANOTHER-000', 'mpn' => 'RE-568158']);
    $catalog->save('product', $copy);
    $catalog->save('product', array_replace($copy, [
        'id' => 'ranking-prefix', 'slug' => 'ranking-prefix', 'sku' => 'RE-568158-EXTRA', 'mpn' => null,
    ]));
    $rank = array_column($catalog->products(['q' => 'RE-568158'])['products'], 'id');
    expect($rank === ['mock-11', 'ranking-mpn', 'ranking-prefix']
        && $catalog->products(['q' => 'масляный', 'brand' => 'john-deere'])['total'] === 3
        && count($catalog->explain(['q' => 'RE-568158', 'brand' => 'john-deere'])) > 0,
        'SKU, MPN and prefix rank without duplicates, Russian title filters and EXPLAIN run');
    $exactOnly = $catalog->checkList([['article' => 'RE568158', 'quantity' => 1]]);
    expect(array_column($exactOnly['rows'][0]['products'], 'id') === ['mock-11'],
        'Batch excludes prefix and MPN offers even when search finds them');
    $changed = array_replace($item, ['title' => 'Обновлённый фильтр', 'full_description' => '<p>Описание <strong>детали</strong></p><script>bad()</script>']);
    $catalog->save('product', $changed);
    expect($catalog->product('jd-re568158')['title'] === 'Обновлённый фильтр'
        && $catalog->product('jd-re568158')['full_description'] === '<p>Описание <strong>детали</strong></p>',
        'Editor updates native name and sanitizes description');

    $withdraw = $paths[] = ndjson([['unpublish', ['id' => 'ranking-mpn']]]);
    $lockedPreview = $import->preview($withdraw);
    $unpublish = $import->preview($withdraw, true);
    expect($lockedPreview['errors'] === 1 && $unpublish['updated'] === 1 && $catalog->product('ranking-mpn') !== null,
        'Bulk withdrawal requires explicit preview and does not write during preview');
    $import->apply($withdraw, $unpublish['sha256'], 'withdraw', false, true);
    expect($catalog->product('ranking-mpn') === null && $catalog->products(['q' => 'ANOTHER000'])['total'] === 0
        && !in_array('ranking-mpn', array_column($catalog->sitemap(0, 1000), 'slug'), true),
        'Withdrawal hides detail, search and sitemap without deleting the source record');

    $future = array_replace($item, [
        'id' => 'future-product', 'slug' => 'future-product', 'sku' => 'FUTURE123',
        'published_at' => '2999-01-01T12:00:00Z',
    ]);
    $catalog->save('product', $future);
    $catalog->save('article', ['id' => 'future-article', 'slug' => 'future-article', 'title' => 'Будущая статья',
        'status' => 'published', 'published_at' => '2999-01-01T12:00:00Z']);
    $catalog->save('article', ['id' => 'draft-article', 'slug' => 'draft-article', 'title' => 'Черновик', 'status' => 'draft']);
    expect($catalog->product('future-product') === null && $catalog->products(['q' => 'FUTURE123'])['total'] === 0
        && $catalog->content('article', 'future-article') === null && $catalog->content('articles')['total'] === 1,
        'Scheduled items and drafts are excluded from public projections');

    $mediaDir = $private . '/media';
    if (!is_dir($mediaDir)) {
        mkdir($mediaDir, 0750, true);
    }
    $imagePath = $prefix . 'pixel.png';
    $paths[] = $mediaDir . '/' . $imagePath;
    file_put_contents($mediaDir . '/' . $imagePath,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+XKZ0AAAAASUVORK5CYII=', true));
    $approved = '301bd413-9f6e-40f2-93a8-2f916e33450a';
    $draft = 'c978f504-4728-4c03-9d8a-de670f741a47';
    $unapproved = 'e41fba43-f946-4de9-9d59-f3a2ad38466b';
    $baseMedia = ['path' => $imagePath, 'mime' => 'image/png', 'status' => 'published'];
    $catalog->save('media', $baseMedia + ['uuid' => $approved, 'owners' => [['type' => 'product', 'id' => 'mock-11']]]);
    $catalog->save('media', $baseMedia + ['uuid' => $draft, 'owners' => [['type' => 'article', 'id' => 'draft-article']]]);
    $catalog->save('media', array_replace($baseMedia, ['status' => 'draft']) + [
        'uuid' => $unapproved, 'owners' => [['type' => 'product', 'id' => 'mock-11']],
    ]);
    $catalog->save('product', array_replace($item, [
        'main_image' => $unapproved,
        'images' => [['image' => $approved], ['image' => $draft], ['image' => $unapproved]],
        'full_description' => '<p><img src="/api/assets/' . $approved . '"><img src="/api/assets/' . $unapproved
            . '"><a href="/api/assets/' . $unapproved . '">Скрытое</a><a href="/catalog">Каталог</a></p>',
    ]));
    $mediaProduct = $catalog->product('jd-re568158');
    expect($catalog->asset($approved)['path'] === $mediaDir . '/' . $imagePath
        && $catalog->asset($draft) === null && $catalog->asset($unapproved) === null && $mediaProduct['main_image'] === null
        && array_column($mediaProduct['images'], 'image') === [$approved] && !str_contains($mediaProduct['full_description'], $unapproved)
        && str_contains($mediaProduct['full_description'], 'href="/catalog"'),
        'Only approved files of published owners appear in asset lookups, details and safe HTML');

    $catalog->save('route', ['path' => '/legacy/re568158', 'target' => '/product/jd-re568158', 'status' => 301]);
    $catalog->save('route', ['path' => '/legacy/removed', 'status' => 410]);
    expect($catalog->routes('/legacy/re568158') === ['status' => 301, 'target' => '/product/jd-re568158']
        && $catalog->routes('/legacy/removed') === ['status' => 410, 'target' => null]
        && $catalog->routes('/legacy/unmapped') === null, 'Legacy route lookup distinguishes redirects, removals and unknown paths');
    $uniqueRows = [];
    for ($i = 0; $i < 1000; $i++) {
        $sku = '000BATCH' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
        $catalog->save('product', array_replace($item, ['id' => 'batch-' . $i, 'slug' => 'batch-' . $i,
            'sku' => $sku, 'availability_status' => [null, 'in_stock', 'on_request', 'out_of_stock'][$i % 4]]));
        $uniqueRows[] = ['article' => $sku, 'quantity' => $i + 1];
    }
    $thousand = $catalog->checkList($uniqueRows);
    expect(count($thousand['rows']) === 1000 && !$thousand['partial']
        && $thousand['rows'][0]['products'][0]['id'] === 'batch-0'
        && $thousand['rows'][999]['products'][0]['id'] === 'batch-999'
        && array_column(array_map(static fn(array $r): array => $r['products'][0], array_slice($thousand['rows'], 0, 4)), 'availability_status')
            === [null, 'in_stock', 'on_request', 'out_of_stock'],
        '1000 distinct published SKUs return canonical offers and the actual four availability states');
    $catalog->save('product', array_replace($item, ['id' => 'second-brand', 'slug' => 'second-brand', 'brand_slug' => 'caterpillar']));
    $ambiguous = $catalog->checkList([['article' => 'RE568158', 'quantity' => 1], ['article' => 'FUTURE123', 'quantity' => 1]]);
    expect($ambiguous['rows'][0]['status'] === 'ambiguous' && count($ambiguous['rows'][0]['products']) === 2
        && $ambiguous['rows'][1]['status'] === 'not_found', 'Same SKU offers stay ambiguous and future publication is excluded');
    $pdo->exec("DELETE s FROM {$prefix}product_to_store s JOIN {$prefix}cmteh_product x USING (product_id) WHERE x.source_id = 'batch-999'");
    expect($catalog->checkList([['article' => '000BATCH0999', 'quantity' => 1]])['rows'][0]['status'] === 'not_found',
        'A product absent from this store is excluded from the exact batch');
    for ($i = 0; $i < 6; $i++) $catalog->save('product', array_replace($item,
        ['id' => 'many-' . $i, 'slug' => 'many-' . $i, 'sku' => 'MANY-OFFERS']));
    $bounded = $catalog->checkList([['article' => 'MANY-OFFERS', 'quantity' => 1]]);
    expect($bounded['partial'] && $bounded['rows'][0]['more_matches'] && count($bounded['rows'][0]['products']) === 5
        && $bounded['rows'][0]['status'] === 'ambiguous', 'Bounded matches report partial ambiguity instead of a false missing result');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ([...$managed, ...$native] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
    }
    foreach ($paths as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($private . '/media')) {
        rmdir($private . '/media');
    }
    rmdir($private);
}
