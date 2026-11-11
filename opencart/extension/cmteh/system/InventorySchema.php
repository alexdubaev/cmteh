<?php
declare(strict_types=1);
namespace Cmteh;
use PDO;

final class InventorySchema
{
    public static function migrate(PDO $pdo, string $prefix): void
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix) || strlen($prefix) > 32) throw new \InvalidArgumentException('Invalid database prefix.');
        $tables = [
            'inventory_schema' => 'version SMALLINT NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL',
            'warehouse' => "warehouse_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
                name VARCHAR(160) NOT NULL, city VARCHAR(160) NOT NULL, delivery_term VARCHAR(255) NOT NULL,
                address VARCHAR(1000) NOT NULL, note TEXT NOT NULL, active TINYINT NOT NULL, version INT NOT NULL, public_version INT NOT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL",
            'inventory_product' => "product_id INT NOT NULL PRIMARY KEY, version INT NOT NULL, legacy_quantity INT NOT NULL,
                legacy_revision CHAR(64) CHARACTER SET ascii NOT NULL, activated_at DATETIME NOT NULL",
            'inventory_balance' => "offer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, warehouse_id INT NOT NULL,
                on_hand INT NOT NULL, reserved INT NOT NULL, price DECIMAL(15,2) NULL, delivery_term VARCHAR(255) NOT NULL,
                published TINYINT NOT NULL, version INT NOT NULL, UNIQUE KEY product_warehouse(product_id,warehouse_id),
                KEY warehouse_stock(warehouse_id,product_id)",
            'inventory_command' => "command_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
                fingerprint CHAR(64) CHARACTER SET ascii NOT NULL, kind VARCHAR(40) NOT NULL, actor_id INT NOT NULL,
                actor_label VARCHAR(160) NOT NULL, result JSON NULL, created_at DATETIME NOT NULL",
            'inventory_movement' => "movement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, command_id BIGINT UNSIGNED NOT NULL UNIQUE,
                kind VARCHAR(40) NOT NULL, reason VARCHAR(1000) NOT NULL, actor_id INT NOT NULL, actor_label VARCHAR(160) NOT NULL,
                created_at DATETIME NOT NULL",
            'inventory_entry' => "movement_id BIGINT UNSIGNED NOT NULL, position INT NOT NULL, product_id INT NOT NULL, warehouse_id INT NOT NULL,
                delta_on_hand INT NOT NULL, delta_reserved INT NOT NULL, on_hand INT NOT NULL, reserved INT NOT NULL,
                balance_version INT NOT NULL, source_id VARCHAR(160) NOT NULL, article VARCHAR(128) NOT NULL, warehouse_code VARCHAR(64) NOT NULL,
                PRIMARY KEY(movement_id,position), KEY product_history(product_id,warehouse_id,movement_id)",
            'inventory_reservation' => "reservation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, warehouse_id INT NOT NULL,
                quantity INT NOT NULL, state VARCHAR(16) NOT NULL, version INT NOT NULL, reference VARCHAR(255) NOT NULL,
                actor_id INT NOT NULL, actor_label VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                KEY product_reservations(product_id,warehouse_id,state,reservation_id)",
            'lead_inventory' => "lead_id BIGINT UNSIGNED NOT NULL, position INT UNSIGNED NOT NULL, offer_id VARCHAR(40) CHARACTER SET ascii NOT NULL,
                offer_version CHAR(64) CHARACTER SET ascii NOT NULL, warehouse_code VARCHAR(64) NOT NULL, warehouse_name VARCHAR(160) NOT NULL,
                warehouse_city VARCHAR(160) NOT NULL, unit_price DECIMAL(15,2) NULL, currency CHAR(3) CHARACTER SET ascii NOT NULL,
                delivery_term VARCHAR(255) NOT NULL, availability_status VARCHAR(16) CHARACTER SET ascii NOT NULL, PRIMARY KEY(lead_id,position)",
        ];
        foreach ($tables as $name => $definition) $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}cmteh_{$name}` ($definition) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT IGNORE INTO `{$prefix}cmteh_inventory_schema` (version,applied_at) VALUES(1,UTC_TIMESTAMP())");
    }
}
