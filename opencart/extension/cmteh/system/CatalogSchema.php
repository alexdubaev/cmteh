<?php

declare(strict_types=1);

namespace Cmteh;

use PDO;

final class CatalogSchema
{
    public static function migrate(PDO $pdo, string $prefix): void
    {
        $tables = [
            'schema' => 'version SMALLINT NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL',
            'product' => "
                product_id INT NOT NULL PRIMARY KEY,
                source_id VARCHAR(160) COLLATE utf8mb4_bin NOT NULL UNIQUE,
                slug VARCHAR(160) COLLATE utf8mb4_bin NOT NULL UNIQUE,
                sku VARCHAR(128) NOT NULL,
                mpn VARCHAR(128) NULL,
                sku_norm VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
                mpn_norm VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
                name_norm VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
                brand_norm VARCHAR(120) COLLATE utf8mb4_bin NOT NULL,
                manufacturer_id INT NOT NULL,
                category_id INT NOT NULL,
                published_at DATETIME NOT NULL,
                indexable TINYINT NOT NULL,
                price_status VARCHAR(16) NOT NULL,
                currency CHAR(3) NULL,
                availability VARCHAR(16) NULL,
                part_type VARCHAR(16) NULL,
                main_image CHAR(36) CHARACTER SET ascii NULL,
                data JSON NOT NULL,
                KEY sku_lookup (sku_norm, product_id),
                KEY mpn_lookup (mpn_norm, product_id),
                KEY publish_lookup (published_at, product_id),
                KEY sitemap_lookup (indexable, product_id),
                KEY brand_lookup (manufacturer_id, published_at, product_id),
                KEY category_lookup (category_id, published_at, product_id),
                KEY facets_lookup (availability, part_type, product_id)",
            'category' => "
                category_id INT NOT NULL PRIMARY KEY,
                source_id VARCHAR(160) COLLATE utf8mb4_bin NOT NULL UNIQUE,
                slug VARCHAR(160) COLLATE utf8mb4_bin NOT NULL UNIQUE,
                published_at DATETIME NOT NULL,
                data JSON NOT NULL",
            'brand' => "
                manufacturer_id INT NOT NULL PRIMARY KEY,
                source_id VARCHAR(160) COLLATE utf8mb4_bin NOT NULL UNIQUE,
                slug VARCHAR(160) COLLATE utf8mb4_bin NOT NULL UNIQUE,
                status TINYINT NOT NULL,
                published_at DATETIME NOT NULL,
                data JSON NOT NULL",
            'attribute' => "
                attribute_id INT NOT NULL PRIMARY KEY,
                field_key CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
                group_name VARCHAR(128) NOT NULL",
            'content' => "
                type VARCHAR(16) CHARACTER SET ascii NOT NULL,
                source_id VARCHAR(160) COLLATE utf8mb4_bin NOT NULL,
                slug VARCHAR(160) COLLATE utf8mb4_bin NOT NULL,
                status TINYINT NOT NULL,
                published_at DATETIME NOT NULL,
                indexable TINYINT NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                data JSON NOT NULL,
                PRIMARY KEY (type, source_id),
                UNIQUE KEY type_slug (type, slug),
                KEY publication (type, status, published_at, source_id)",
            'route' => "
                path VARCHAR(512) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
                target VARCHAR(512) COLLATE utf8mb4_bin NULL,
                status SMALLINT NOT NULL",
            'media' => "
                uuid CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
                path VARCHAR(512) COLLATE utf8mb4_bin NOT NULL,
                filename VARCHAR(200) NOT NULL,
                mime VARCHAR(64) CHARACTER SET ascii NOT NULL,
                status TINYINT NOT NULL",
            'media_link' => "
                uuid CHAR(36) CHARACTER SET ascii NOT NULL,
                owner_type VARCHAR(16) CHARACTER SET ascii NOT NULL,
                owner_id VARCHAR(160) COLLATE utf8mb4_bin NOT NULL,
                PRIMARY KEY (uuid, owner_type, owner_id),
                KEY owner_lookup (owner_type, owner_id, uuid)",
            'import' => "
                run_key VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
                fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
                next_offset BIGINT UNSIGNED NOT NULL,
                next_line INT UNSIGNED NOT NULL,
                counts JSON NOT NULL,
                finished TINYINT NOT NULL",
        ];

        foreach ($tables as $name => $definition) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}cmteh_{$name}` ($definition)
                ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $hasDate = $pdo->prepare('SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = \'published_at\'');
        foreach (['category', 'brand'] as $type) {
            $table = $prefix . 'cmteh_' . $type;
            $hasDate->execute([$table]);
            if (!$hasDate->fetchColumn()) {
                $pdo->exec("ALTER TABLE `$table` ADD published_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'");
            }
            $hasDate->closeCursor();
        }
        $pdo->exec("INSERT IGNORE INTO `{$prefix}cmteh_schema` (version, applied_at) VALUES (1, UTC_TIMESTAMP()), (2, UTC_TIMESTAMP())");
    }
}
