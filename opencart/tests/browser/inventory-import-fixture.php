<?php
declare(strict_types=1);

require '/var/www/html/config.php';
require_once '/var/www/html/extension/cmteh/system/Database.php';
require_once '/var/www/html/extension/cmteh/system/InventoryException.php';
require_once '/var/www/html/extension/cmteh/system/InventoryInput.php';
require_once '/var/www/html/extension/cmteh/system/InventorySchema.php';
require_once '/var/www/html/extension/cmteh/system/Inventory.php';
require_once '/var/www/html/extension/cmteh/system/InventoryImport.php';

use Cmteh\Database;
use Cmteh\Inventory;
use Cmteh\InventoryImport;

$pdo = Database::connectFromOpenCart();
$mode = $argv[1] ?? '';

if ($mode === '--migrate') {
    InventoryImport::migrate($pdo, DB_PREFIX);
    echo "{}";
    exit;
}

if ($mode === '--unpublish') {
    $nonce = $argv[2] ?? '';
    $productId = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $warehouseId = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!preg_match('/^[a-f0-9]{12}$/D', $nonce) || !$productId || !$warehouseId) throw new RuntimeException('Owned offer identifiers required.');
    $product = $pdo->prepare('SELECT p.model,cp.source_id FROM `' . DB_PREFIX . 'product` p JOIN `' . DB_PREFIX . 'cmteh_product` cp ON cp.product_id=p.product_id WHERE p.product_id=?');
    $product->execute([$productId]); $owner = $product->fetch(PDO::FETCH_ASSOC);
    if (!$owner || $owner['model'] !== 'warehouse-browser-' . $nonce || $owner['source_id'] !== 'warehouse-browser-' . $nonce) throw new RuntimeException('Synthetic product ownership check failed.');
    $inventory = new Inventory($pdo, DB_PREFIX, 0, 1);
    $state = $inventory->productState($productId);
    $balance = $inventory->balances(['product_id'=>$productId,'warehouse_id'=>$warehouseId,'limit'=>1])['rows'][0] ?? null;
    if (!$balance) throw new RuntimeException('Owned offer not found.');
    $inventory->command('offer.update', [
        'product_id'=>$productId,'warehouse_id'=>$warehouseId,'expected_product_version'=>$state['version'],
        'expected_balance_version'=>$balance['version'],'expected_warehouse_version'=>$balance['warehouse_version'],
        'price'=>$balance['price']===null?'':(string)$balance['price'],'delivery_term'=>$balance['delivery_term'],'published'=>false,
    ], ['id'=>1,'label'=>'Synthetic browser fixture'], 'wob-'.$nonce.'-unpublish');
    echo "{}";
    exit;
}

if ($mode === '--state') {
    $productId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $warehouseId = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$productId || !$warehouseId) throw new RuntimeException('Expected exact owned product and warehouse IDs.');
    $stmt = $pdo->prepare('SELECT p.model,p.quantity,cp.source_id,cp.sku FROM `' . DB_PREFIX . 'product` p JOIN `' . DB_PREFIX . 'cmteh_product` cp ON cp.product_id=p.product_id WHERE p.product_id=?');
    $stmt->execute([$productId]); $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product || !str_starts_with((string)$product['model'], 'warehouse-browser-')) throw new RuntimeException('Owned synthetic product not found.');
    $inventory = new Inventory($pdo, DB_PREFIX);
    $balance = $inventory->balances(['product_id'=>$productId,'warehouse_id'=>$warehouseId,'limit'=>1])['rows'][0] ?? null;
    if (!$balance) throw new RuntimeException('Owned product/warehouse balance not found.');
    echo json_encode([
        'product_id'=>(int)$productId,'product'=>$product,'native_quantity'=>(int)$product['quantity'],
        'balance'=>['quantity'=>(int)$balance['on_hand'],'reserved'=>(int)$balance['reserved'],'available'=>(int)$balance['available'],'price'=>$balance['price']===null?null:(string)$balance['price'],'published'=>(bool)$balance['published'],'term'=>(string)$balance['delivery_term']],
        'warehouse_count'=>(int)$pdo->query("SELECT COUNT(*) FROM `" . DB_PREFIX . "cmteh_warehouse`")->fetchColumn(),
        'product_count'=>(int)$pdo->query("SELECT COUNT(*) FROM `" . DB_PREFIX . "cmteh_product`")->fetchColumn(),
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($mode === '--warehouse-id') {
    $nonce = $argv[2] ?? '';
    if (!preg_match('/^[a-f0-9]{12}$/D', $nonce)) throw new RuntimeException('Owned warehouse nonce required.');
    $stmt = $pdo->prepare('SELECT warehouse_id,name FROM `' . DB_PREFIX . 'cmteh_warehouse` WHERE code=?');
    $stmt->execute(['WOB-C-' . $nonce]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['name'] !== 'Synthetic XLSX warehouse ' . $nonce) throw new RuntimeException('Synthetic UI warehouse not found.');
    echo json_encode(['warehouse_id'=>(int)$row['warehouse_id']], JSON_THROW_ON_ERROR);
    exit;
}

if ($mode === '--cleanup-import') {
    $nonce = $argv[2] ?? '';
    $username = $argv[3] ?? '';
    $productId = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!preg_match('/^[a-f0-9]{12}$/D', $nonce) || !preg_match('/^gate-[a-f0-9]{12}$/D', $username) || !$productId) {
        throw new RuntimeException('Owned browser cleanup identifiers required.');
    }
    $product = $pdo->prepare('SELECT p.model,cp.source_id FROM `' . DB_PREFIX . 'product` p JOIN `' . DB_PREFIX . 'cmteh_product` cp ON cp.product_id=p.product_id WHERE p.product_id=?');
    $product->execute([$productId]); $owner = $product->fetch(PDO::FETCH_ASSOC);
    if (!$owner || $owner['model'] !== 'warehouse-browser-' . $nonce || $owner['source_id'] !== 'warehouse-browser-' . $nonce) {
        throw new RuntimeException('Synthetic product ownership check failed.');
    }
    $user = $pdo->prepare('SELECT u.user_id,u.user_group_id,g.name FROM `' . DB_PREFIX . 'user` u JOIN `' . DB_PREFIX . 'user_group` g ON g.user_group_id=u.user_group_id WHERE u.username=?');
    $user->execute([$username]); $actor = $user->fetch(PDO::FETCH_ASSOC);
    if (!$actor || !str_starts_with((string)$actor['name'], 'Inventory XLSX happy ' . $nonce . ' gate ')) {
        throw new RuntimeException('Synthetic native user ownership check failed.');
    }
    $movement = $pdo->prepare('SELECT DISTINCT movement_id FROM `' . DB_PREFIX . 'cmteh_inventory_entry` WHERE product_id=?');
    $movement->execute([$productId]); $movementIds = array_map('intval', $movement->fetchAll(PDO::FETCH_COLUMN));
    $warehouse = $pdo->prepare('SELECT warehouse_id,name FROM `' . DB_PREFIX . 'cmteh_warehouse` WHERE code=?');
    $warehouse->execute(['WOB-C-' . $nonce]); $ownedWarehouse = $warehouse->fetch(PDO::FETCH_ASSOC);
    if ($ownedWarehouse && $ownedWarehouse['name'] !== 'Synthetic XLSX warehouse ' . $nonce) throw new RuntimeException('Synthetic UI warehouse ownership check failed.');
    $commandIds = [];
    if ($movementIds) {
        $marks = implode(',', array_fill(0, count($movementIds), '?'));
        $commands = $pdo->prepare('SELECT command_id FROM `' . DB_PREFIX . 'cmteh_inventory_movement` WHERE movement_id IN (' . $marks . ')');
        $commands->execute($movementIds); $commandIds = array_map('intval', $commands->fetchAll(PDO::FETCH_COLUMN));
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'cmteh_inventory_import_preview` WHERE actor_id=?')->execute([(int)$actor['user_id']]);
        $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'cmteh_inventory_entry` WHERE product_id=?')->execute([$productId]);
        if ($movementIds) {
            $marks = implode(',', array_fill(0, count($movementIds), '?'));
            $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'cmteh_inventory_movement` WHERE movement_id IN (' . $marks . ')')->execute($movementIds);
        }
        if ($commandIds) {
            $marks = implode(',', array_fill(0, count($commandIds), '?'));
            $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'cmteh_inventory_command` WHERE command_id IN (' . $marks . ')')->execute($commandIds);
        }
        if ($ownedWarehouse) {
            $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'cmteh_inventory_balance` WHERE product_id=? AND warehouse_id=?')->execute([$productId,(int)$ownedWarehouse['warehouse_id']]);
            $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'cmteh_warehouse` WHERE warehouse_id=? AND code=? AND name=?')->execute([(int)$ownedWarehouse['warehouse_id'],'WOB-C-'.$nonce,'Synthetic XLSX warehouse '.$nonce]);
        }
        $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'user_login` WHERE user_id=?')->execute([(int)$actor['user_id']]);
        $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'session` WHERE JSON_VALID(data) AND JSON_UNQUOTE(JSON_EXTRACT(data,\'$.user_id\'))=?')->execute([(string)$actor['user_id']]);
        $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'user` WHERE user_id=? AND username=?')->execute([(int)$actor['user_id'],$username]);
        $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'user_group` WHERE user_group_id=? AND name=? AND NOT EXISTS (SELECT 1 FROM `' . DB_PREFIX . 'user` WHERE user_group_id=?)')->execute([(int)$actor['user_group_id'],(string)$actor['name'],(int)$actor['user_group_id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    echo "{}";
    exit;
}

throw new RuntimeException('Unsupported import browser fixture operation.');
