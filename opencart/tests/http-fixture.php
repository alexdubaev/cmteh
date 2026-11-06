<?php

declare(strict_types=1);

use Cmteh\Catalog;
use Cmteh\Database;

if (PHP_SAPI !== 'cli' || getenv('CMTEH_LOCAL_SMOKE') !== '1' || !isset($argv[1])
    || !in_array($argv[1], ['seed', 'clean'], true)) {
    throw new RuntimeException('Run seed/clean only inside the isolated OpenCart test container.');
}
require '/var/www/html/config.php';
require __DIR__ . '/../extension/cmteh/system/Database.php';
require __DIR__ . '/../extension/cmteh/system/Catalog.php';
if (!preg_match('~^http://127\.0\.0\.1:[0-9]+$~D', Database::siteUrl()) || DB_PREFIX !== 'oc_') {
    throw new RuntimeException('The HTTP fixture needs an isolated local OpenCart database.');
}

$pdo = Database::connectFromOpenCart();
$private = Database::privateDir();
$catalog = new Catalog($pdo, DB_PREFIX, 1, 0, $private);
$media = $private . '/media/cmteh-http-fixture.png';
$public = '11111111-1111-4111-8111-111111111111';
$draft = '22222222-2222-4222-8222-222222222222';
$old = '33333333-3333-4333-8333-333333333333';
$zeroConfirmed = 'cmteh-http-zero-confirmed';
$zeroUnconfirmed = 'cmteh-http-zero-unconfirmed';
$productId = 'cmteh-http-fixture-product';
$articleId = 'cmteh-http-fixture-article';

$routes = [
    '/http-test-old' => '/product/jd-re568158', '/http-test-gone' => null,
    '/http-test-chain' => '/http-test-old', '/http-test-loop' => '/http-test-loop-2',
    '/http-test-loop-2' => '/http-test-loop', "/api/assets/$old" => "/api/assets/$public",
    '/catalog/CATERPILLAR' => '/brand/caterpillar',
    '/catalog/CATERPILLAR?page=2' => '/category/filters',
    '/catalog/Новинки' => '/articles',
    '/?page=3' => '/catalog', '/news?sid=2' => '/articles',
];
if ($argv[1] === 'clean') {
    $pdo->beginTransaction();
    try {
        foreach (array_keys($routes) as $path) {
            $pdo->prepare('DELETE FROM oc_cmteh_route WHERE path = ?')->execute([$path]);
        }
        foreach ([$public, $draft] as $id) {
            $pdo->prepare('DELETE FROM oc_cmteh_media_link WHERE uuid = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM oc_cmteh_media WHERE uuid = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM oc_cmteh_content WHERE source_id = ?')->execute([$articleId]);
        foreach ([$productId, $zeroConfirmed, $zeroUnconfirmed] as $id) {
            $statement = $pdo->prepare('SELECT product_id FROM oc_cmteh_product WHERE source_id = ?');
            $statement->execute([$id]);
            $product = $statement->fetchColumn();
            if ($product) {
                foreach (['cmteh_product', 'product_description', 'product_to_store', 'product_to_category',
                    'product_code', 'product_attribute', 'product'] as $table) {
                    $pdo->prepare("DELETE FROM oc_$table WHERE product_id = ?")->execute([$product]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    if (is_file($media)) {
        unlink($media);
    }
    exit;
}

foreach ($routes as $path => $target) {
    $catalog->save('route', ['path' => $path, 'target' => $target, 'status' => $target === null ? 410 : 301]);
}
$row = ['id' => $productId, 'slug' => 'см-техно', 'title' => 'Локальная HTTP-фикстура', 'sku' => 'HTTP-FIXTURE',
    'brand' => 'Caterpillar', 'status' => 'published', 'is_indexable' => false, 'main_image' => $public];
$catalog->save('product', $row);
$catalog->save('article', ['id' => $articleId, 'slug' => 'см-техно', 'title' => 'Локальная статья',
    'status' => 'published', 'is_indexable' => false]);
foreach ([$zeroConfirmed, $zeroUnconfirmed] as $id) {
    $confirmation = $id === $zeroConfirmed;
    $catalog->save('product', array_replace($row, ['id' => $id, 'slug' => $id, 'sku' => $id, 'main_image' => null,
        'currency' => 'RUB', 'price_status' => 'fixed', 'price' => $confirmation ? 0 : 50,
        'confirm_zero_price' => $confirmation]));
}
// Reproduce a native OpenCart change that bypasses the extension's zero-price input validator.
$pdo->prepare('UPDATE oc_product p JOIN oc_cmteh_product x USING (product_id) SET p.price = 0 WHERE x.source_id = ?')
    ->execute([$zeroUnconfirmed]);

if (!is_dir(dirname($media))) {
    mkdir(dirname($media), 0750);
}
if (!is_file($media)) {
    file_put_contents($media, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4ioAAAAASUVORK5CYII='));
}
foreach ([$public => 'published', $draft => 'draft'] as $uuid => $status) {
    $catalog->save('media', ['uuid' => $uuid, 'path' => basename($media), 'filename' => 'Тест.png', 'mime' => 'image/png',
        'status' => $status, 'owners' => [['type' => 'product', 'id' => $productId]]]);
}
