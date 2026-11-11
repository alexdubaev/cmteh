<?php
declare(strict_types=1);

require '/var/www/html/config.php';
require_once DIR_EXTENSION . 'cmteh/system/Database.php';
require_once DIR_EXTENSION . 'cmteh/system/Inventory.php';
require_once DIR_EXTENSION . 'cmteh/system/InventorySchema.php';
require_once DIR_EXTENSION . 'cmteh/system/Lead.php';

use Cmteh\{Database, Inventory, InventorySchema, Lead, LeadInput};

function recoveryCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$mode = $argv[1] ?? '';
$token = $argv[2] ?? '';
if (!in_array($mode, ['seed', 'advance', 'verify', 'cleanup'], true)
    || !preg_match('/^[a-f0-9]{12}$/D', $token)) throw new RuntimeException('Recovery fixture mode/token is invalid.');
$pdo = Database::connectFromOpenCart();
$statePath = Database::privateDir() . '/inventory-recovery-' . $token . '.json';
$loadState = static function() use ($statePath): array {
    if (!is_file($statePath) || is_link($statePath)) throw new RuntimeException('Recovery fixture state is unavailable.');
    $value = json_decode((string)file_get_contents($statePath), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('Recovery fixture state is invalid.');
    return $value;
};
$saveState = static function(array $value) use ($statePath): void {
    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($statePath, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Recovery fixture state could not be saved.');
    chmod($statePath, 0600);
};
$digest = static fn(array $value): string => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

if ($mode === 'seed') {
    if (is_file($statePath)) throw new RuntimeException('Recovery fixture token already exists.');
    InventorySchema::migrate($pdo, DB_PREFIX);
    $sourceId='warehouse-recovery-' . $token; $sku='000REC-' . $token; $quantity=6;
    $pdo->prepare('INSERT INTO `' . DB_PREFIX . 'product` (model,quantity,status,date_available,master_id,price,date_modified)
        VALUES(?,?,1,\'2020-01-01\',0,0,UTC_TIMESTAMP())')->execute([$sku,$quantity]);
    $productId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO `' . DB_PREFIX . 'product_to_store` (product_id,store_id) VALUES(?,0)')->execute([$productId]);
    $pdo->prepare('INSERT INTO `' . DB_PREFIX . 'product_description` (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword)
        VALUES(?,1,\'Synthetic recovery part\',\'\',\'\',\'\',\'\',\'\')')->execute([$productId]);
    $pdo->prepare('INSERT INTO `' . DB_PREFIX . 'cmteh_product` (product_id,source_id,slug,sku,sku_norm,mpn_norm,name_norm,brand_norm,manufacturer_id,category_id,published_at,indexable,availability,price_status,currency,data)
        VALUES(?,?,?, ?,? ,\'\',\'synthetic\',\'\',0,0,\'2020-01-01\',1,\'in_stock\',\'on_request\',\'RUB\',\'{}\')')
        ->execute([$productId,$sourceId,$sourceId,$sku,strtolower($sku)]);
    $candidate=['product_id'=>$productId,'quantity'=>$quantity,'source_id'=>$sourceId,'sku'=>$sku];
    $inventory = new Inventory($pdo, DB_PREFIX);
    $actor = ['id'=>1, 'label'=>'Synthetic recovery fixture'];
    $warehouseA = $inventory->command('warehouse.save', [
        'expected_warehouse_version'=>0, 'code'=>'REC-A-' . $token, 'name'=>'Synthetic recovery A',
        'city'=>'Synthetic test', 'delivery_term'=>'2 days',
    ], $actor, 'warehouse-recovery-' . $token . '-a')['warehouse'];
    $warehouseB = $inventory->command('warehouse.save', [
        'expected_warehouse_version'=>0, 'code'=>'REC-B-' . $token, 'name'=>'Synthetic recovery B',
        'city'=>'Synthetic test', 'delivery_term'=>'5 days',
    ], $actor, 'warehouse-recovery-' . $token . '-b')['warehouse'];
    $state = $inventory->productState((int)$candidate['product_id']);
    $inventory->command('product.activate', [
        'product_id'=>(int)$candidate['product_id'], 'expected_product_version'=>0,
        'expected_legacy_quantity'=>(int)$candidate['quantity'], 'expected_legacy_revision'=>$state['legacy_revision'],
        'confirmed'=>true, 'allocations'=>[
            ['warehouse_id'=>(int)$warehouseA['warehouse_id'], 'quantity'=>(int)$candidate['quantity'], 'expected_warehouse_version'=>1],
        ],
    ], $actor, 'warehouse-recovery-' . $token . '-activate');
    $saveState(['token'=>$token, 'product_id'=>(int)$candidate['product_id'], 'source_id'=>(string)$candidate['source_id'],
        'sku'=>(string)$candidate['sku'], 'legacy_quantity'=>(int)$candidate['quantity'],
        'warehouse_a'=>(int)$warehouseA['warehouse_id'], 'warehouse_b'=>(int)$warehouseB['warehouse_id'], 'owns_product'=>true]);
    echo json_encode(['seeded'=>true, 'token'=>$token], JSON_THROW_ON_ERROR) . "\n";
    exit;
}

$fixture = $loadState();
$inventory = new Inventory($pdo, DB_PREFIX);
$actor = ['id'=>1, 'label'=>'Synthetic recovery fixture'];
$current = static function(int $productId, int $warehouseId, bool $allowEmpty=false) use ($inventory): array {
    $state=$inventory->productState($productId);
    $balance=$inventory->balances(['product_id'=>$productId, 'warehouse_id'=>$warehouseId])['rows'][0] ?? null;
    if (!$balance && !$allowEmpty) throw new RuntimeException('Recovery fixture balance is missing.');
    return ['state'=>$state, 'balance'=>$balance ?? ['version'=>0]];
};
$run = static function(string $kind, array $input, string $suffix) use ($inventory,$actor,$token): array {
    return $inventory->command($kind, $input, $actor, 'warehouse-recovery-' . $token . '-' . $suffix);
};

if ($mode === 'advance') {
    $a=$current($fixture['product_id'],$fixture['warehouse_a']);
    $b=$current($fixture['product_id'],$fixture['warehouse_b'],true);
    $run('transfer.complete', [
        'product_id'=>$fixture['product_id'], 'warehouse_id'=>$fixture['warehouse_a'], 'to_warehouse_id'=>$fixture['warehouse_b'],
        'expected_product_version'=>$a['state']['version'], 'expected_balance_version'=>$a['balance']['version'],
        'expected_warehouse_version'=>1, 'expected_to_balance_version'=>$b['balance']['version'],
        'expected_to_warehouse_version'=>1, 'quantity'=>1, 'arrival_confirmed'=>true, 'reason'=>'Synthetic recovery movement',
    ], 'transfer');
    foreach ([[$fixture['warehouse_a'],'525.00','5 days'],[$fixture['warehouse_b'],'575.00','7 days']] as [$warehouseId,$price,$term]) {
        $entry=$current($fixture['product_id'],$warehouseId);
        $run('offer.update', [
            'product_id'=>$fixture['product_id'], 'warehouse_id'=>$warehouseId,
            'expected_product_version'=>$entry['state']['version'], 'expected_balance_version'=>$entry['balance']['version'],
            'expected_warehouse_version'=>1, 'price'=>$price, 'delivery_term'=>$term, 'published'=>true,
        ], 'offer-' . $warehouseId);
    }
    $entry=$current($fixture['product_id'],$fixture['warehouse_b']);
    $reservation=$run('reservation.create', [
        'product_id'=>$fixture['product_id'], 'warehouse_id'=>$fixture['warehouse_b'],
        'expected_product_version'=>$entry['state']['version'], 'expected_balance_version'=>$entry['balance']['version'],
        'expected_warehouse_version'=>1, 'quantity'=>1, 'reference'=>'Synthetic post-backup recovery reservation',
        'reason'=>'Synthetic recovery fixture',
    ], 'reserve')['reservation'];
    $offers=$inventory->publicOffers([$fixture['source_id']])[$fixture['source_id']]['offers'];
    $offer=null;
    foreach ($offers as $candidate) if ($candidate['warehouse']['code']==='REC-B-' . $token) $offer=$candidate;
    if (!$offer) throw new RuntimeException('Post-reservation public offer snapshot is unavailable.');
    $lead=new Lead($pdo,DB_PREFIX);
    $request=[
        'request_key'=>LeadInput::uuid(), 'name'=>'Synthetic recovery request', 'phone'=>'+70000000000',
        'page_url'=>'http://localhost/request', 'request_items'=>[['product_id'=>$fixture['source_id'],
            'article'=>$fixture['sku'], 'quantity'=>1, 'offer_id'=>$offer['offer_id'], 'offer_version'=>$offer['offer_version']]],
    ];
    $accepted=$lead->submitWarehouse(json_encode($request,JSON_THROW_ON_ERROR),'198.51.100.77',['content-type'=>'application/json']);
    if (($accepted['status']??0)!==200 || empty($accepted['body']['id'])) throw new RuntimeException('Synthetic selected warehouse request was not accepted.');
    $fixture['reservation_id']=(int)$reservation['reservation_id']; $fixture['lead_public_id']=(string)$accepted['body']['id'];
    $fixture['evidence_hash']=$digest([
        'product'=>$inventory->productState($fixture['product_id']),
        'balances'=>$inventory->balances(['product_id'=>$fixture['product_id']]),
        'history'=>$inventory->history(['product_id'=>$fixture['product_id']]),
        'reservations'=>$inventory->reservations(['product_id'=>$fixture['product_id']]),
        'lead'=>$lead->find($fixture['lead_public_id']),
    ]);
    $saveState($fixture);
    echo json_encode(['advanced'=>true,'token'=>$token,'lead_public_id'=>$fixture['lead_public_id']],JSON_THROW_ON_ERROR) . "\n";
    exit;
}

if ($mode === 'verify') {
    $lead=(new Lead($pdo,DB_PREFIX))->find($fixture['lead_public_id'] ?? '');
    recoveryCheck(is_array($lead) && count($lead['items'] ?? [])===1
        && $lead['items'][0]['warehouse_code']==='REC-B-' . $token
        && $lead['items'][0]['unit_price']==='575.00', 'Accepted warehouse snapshot survived compatible update.');
    $actual=$digest([
        'product'=>$inventory->productState($fixture['product_id']),
        'balances'=>$inventory->balances(['product_id'=>$fixture['product_id']]),
        'history'=>$inventory->history(['product_id'=>$fixture['product_id']]),
        'reservations'=>$inventory->reservations(['product_id'=>$fixture['product_id']]),
        'lead'=>$lead,
    ]);
    recoveryCheck(hash_equals((string)($fixture['evidence_hash'] ?? ''),$actual), 'Inventory movements, active reservation and accepted snapshot are byte-stable after compatible update.');
    $states=$inventory->reservations(['product_id'=>$fixture['product_id']])['rows'];
    recoveryCheck(count($states)===1 && $states[0]['state']==='active' && (int)$states[0]['quantity']===1,
        'Post-backup reservation remains active after compatible update.');
    echo json_encode(['verified'=>true,'token'=>$token],JSON_THROW_ON_ERROR) . "\n";
    exit;
}

if ($mode === 'cleanup') {
    $lead=(new Lead($pdo,DB_PREFIX))->find($fixture['lead_public_id'] ?? '');
    $leadTable=DB_PREFIX.'cmteh_lead';
    $leadRow=$pdo->prepare("SELECT lead_id FROM `{$leadTable}` WHERE public_id=?");
    $leadRow->execute([$fixture['lead_public_id'] ?? '']); $leadId=$leadRow->fetchColumn();
    if ($leadId) {
        foreach (['inventory','item','outbox'] as $table) $pdo->prepare("DELETE FROM `{$leadTable}_{$table}` WHERE lead_id=?")->execute([$leadId]);
        $pdo->prepare("DELETE FROM `{$leadTable}` WHERE lead_id=?")->execute([$leadId]);
    }
    $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'cmteh_inventory_entry` WHERE product_id=?')->execute([$fixture['product_id']]);
    $pdo->prepare('DELETE m FROM `' . DB_PREFIX . 'cmteh_inventory_movement` m JOIN `' . DB_PREFIX . 'cmteh_inventory_command` c ON c.command_id=m.command_id WHERE c.request_key LIKE ?')->execute(['warehouse-recovery-' . $token . '-%']);
    foreach (['reservation','balance','product'] as $table) $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'cmteh_inventory_' . $table . '` WHERE product_id=?')->execute([$fixture['product_id']]);
    $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'cmteh_inventory_command` WHERE request_key LIKE ?')->execute(['warehouse-recovery-' . $token . '-%']);
    $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'cmteh_warehouse` WHERE warehouse_id IN (?,?) AND code IN (?,?)')->execute([
        $fixture['warehouse_a'],$fixture['warehouse_b'],'REC-A-' . $token,'REC-B-' . $token]);
    $pdo->prepare('UPDATE `' . DB_PREFIX . 'product` SET quantity=? WHERE product_id=?')->execute([$fixture['legacy_quantity'],$fixture['product_id']]);
    if (!empty($fixture['owns_product'])) {
        foreach (['cmteh_product','product_to_store','product_description','product'] as $table) {
            $pdo->prepare('DELETE FROM `' . DB_PREFIX . $table . '` WHERE product_id=?')->execute([$fixture['product_id']]);
        }
    }
    if (!unlink($statePath)) throw new RuntimeException('Recovery fixture state could not be removed.');
    recoveryCheck($lead === null || $lead['items'][0]['warehouse_code']==='REC-B-' . $token, 'Only the fixture-owned lead snapshot was removed.');
    echo json_encode(['cleaned'=>true,'token'=>$token],JSON_THROW_ON_ERROR) . "\n";
}
