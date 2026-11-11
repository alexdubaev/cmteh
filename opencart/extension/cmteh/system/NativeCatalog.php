<?php

declare(strict_types=1);

namespace Cmteh;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

require_once __DIR__ . '/CatalogInput.php';

final class NativeCatalog
{
    private const SOURCES = [
        'product' => ['product', 'product_id'],
        'category' => ['category', 'category_id'],
        'brand' => ['manufacturer', 'manufacturer_id'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly int $languageId,
        private readonly int $storeId,
        private readonly string $currency,
    ) {
        if (!preg_match('/^[a-zA-Z0-9_]{0,32}$/D', $prefix)) {
            throw new InvalidArgumentException('Invalid database prefix');
        }
    }

    public function synchronize(string $type, int $id, bool $deleted = false): void
    {
        if (!isset(self::SOURCES[$type]) || $id < 1) {
            throw new InvalidArgumentException('Invalid native catalog record');
        }

        [$native, $key] = self::SOURCES[$type];
        $table = $this->table('cmteh_' . $type);
        $this->pdo->beginTransaction();
        try {
            $previous = $this->run("SELECT * FROM $table WHERE $key = ? FOR UPDATE", [$id])->fetch(PDO::FETCH_ASSOC);

            if ($deleted) {
                if ($previous && !$this->run('SELECT 1 FROM ' . $this->table($native) . " WHERE $key = ?", [$id])->fetchColumn()) {
                    $this->retireDeleted($type, $id, $previous);
                }
                $this->run("DELETE FROM $table WHERE $key = ? AND NOT EXISTS (SELECT 1 FROM " . $this->table($native) .
                    " WHERE $key = ?)", [$id, $id]);
            } else {
                $row = $this->nativeRecord($native, $key, $id);
                if ($row) {
                    $data = $previous ? json_decode($previous['data'], true, 32, JSON_THROW_ON_ERROR) : [];
                    $data['native_edited'] = true;
                    $slug = $previous['slug'] ?? $this->initialSlug($table, $type, $id, $row['name']);
                    if ($type === 'product') {
                        $this->syncProduct($id, $row, $previous, $data, $slug);
                    } else {
                        $this->syncTaxonomy($type, $id, $row, $previous, $data, $slug);
                    }
                }
            }

            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    private function retireDeleted(string $type, int $id, array $previous): void
    {
        $path = '/' . $type . '/' . $previous['slug'];
        $routes = $this->table('cmteh_route');
        $this->run("UPDATE $routes SET status=410, target=NULL WHERE target=?", [$path]);
        $this->run("INSERT INTO $routes (path,status,target) VALUES (?,410,NULL) ON DUPLICATE KEY UPDATE status=410,target=NULL", [$path]);
        $this->run('DELETE FROM ' . $this->table('cmteh_media_link') . ' WHERE owner_type=? AND owner_id=?', [$type,$previous['source_id']]);
        if ($type === 'brand') {
            $this->run('UPDATE ' . $this->table('cmteh_product') . " SET brand_norm='', manufacturer_id=0 WHERE manufacturer_id=?", [$id]);
        }
        if ($type === 'product') {
            $table = $this->table('cmteh_product');
            foreach ($this->run("SELECT product_id,data FROM $table WHERE product_id<>? FOR UPDATE", [$id])->fetchAll(PDO::FETCH_ASSOC) as $record) {
                $data=json_decode($record['data'],true,32,JSON_THROW_ON_ERROR);
                $data['relations']=array_values(array_filter($data['relations']??[],static fn(array $r):bool=>($r['source_id']??null)!==$previous['source_id']));
                $data['native_related']=array_values(array_filter($data['native_related']??[],static fn($v):bool=>(int)$v!==$id));
                $this->run("UPDATE $table SET data=? WHERE product_id=?", [self::encode($data),$record['product_id']]);
            }
        }
        $content=$this->table('cmteh_content');
        foreach ($this->run("SELECT type,source_id,status,data FROM $content FOR UPDATE", [])->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $data=json_decode($record['data'],true,32,JSON_THROW_ON_ERROR);
            $status=(int)$record['status'];
            if ($record['type']==='navigation' && ($data['url']??null)===$path) {$status=0;$data['status']='draft';}
            $field=match($type){'brand'=>'brand_slugs','category'=>'category_slugs',default=>null};
            if ($field && isset($data[$field])) $data[$field]=array_values(array_filter($data[$field],static fn($slug):bool=>$slug!==$previous['slug']));
            $this->run("UPDATE $content SET data=?,status=? WHERE type=? AND source_id=?",[self::encode($data),$status,$record['type'],$record['source_id']]);
        }
    }

    private function nativeRecord(string $native, string $key, int $id): array|false
    {
        $table = $this->table($native);
        if ($native === 'manufacturer') {
            return $this->run("SELECT * FROM $table WHERE $key = ?", [$id])->fetch(PDO::FETCH_ASSOC);
        }

        $description = $this->table($native . '_description');
        return $this->run("SELECT n.*, d.name, d.description, d.meta_title, d.meta_description
            FROM $table n JOIN $description d USING ($key) WHERE n.$key = ? AND d.language_id = ?",
            [$id, $this->languageId])->fetch(PDO::FETCH_ASSOC);
    }

    private function initialSlug(string $table, string $type, int $id, string $name): string
    {
        [$key, $value] = match ($type) {
            'brand' => ['manufacturer_id', (string) $id],
            'product' => ['product_id', (string) $id],
            'category' => ['path', implode('_', $this->run('SELECT path_id FROM ' . $this->table('category_path') .
                ' WHERE category_id = ? ORDER BY level', [$id])->fetchAll(PDO::FETCH_COLUMN))],
        };
        $keyword = $this->run('SELECT s.keyword FROM ' . $this->table('seo_url') .
            ' s WHERE s.key = ? AND s.value = ? AND s.store_id = ? AND s.language_id = ? ORDER BY s.sort_order, s.seo_url_id LIMIT 1',
            [$key, $value, $this->storeId, $this->languageId])->fetchColumn();
        if ($type === 'category' && is_string($keyword)) {
            $segments = explode('/', trim($keyword, '/'));
            $keyword = end($segments);
        }

        $label = CatalogInput::slugify(self::decode($name));
        $generated = rtrim(mb_substr($label, 0, 135), '-') . '-' . $id;
        $fallback = "native-$type-$id";
        foreach ([$keyword, $label, $generated, $fallback] as $candidate) {
            if (is_string($candidate) && mb_strlen($candidate) <= 160
                && preg_match('/^[\p{L}\p{N}][\p{L}\p{N}_-]*$/uD', $candidate)
                && !$this->run("SELECT 1 FROM $table WHERE slug = ?", [$candidate])->fetchColumn()) {
                return $candidate;
            }
        }
        throw new InvalidArgumentException('Не удалось выбрать адрес для записи OpenCart');
    }

    private function syncTaxonomy(string $type, int $id, array $row, array|false $previous, array $data, string $slug): void
    {
        $sourceId = $previous['source_id'] ?? "native-$type-$id";
        $name = self::decode($row['name']);
        $data['id'] = $sourceId;
        $data['slug'] = $slug;
        $data['sort_order'] = (int) $row['sort_order'];
        $data['published_at'] ??= gmdate('Y-m-d\TH:i:s\Z');

        if ($type === 'category') {
            $data['title'] = $name;
            $data['description'] = CatalogInput::html(self::decode($row['description']));
            $data['seo_title'] = self::decode($row['meta_title']);
            $data['seo_description'] = self::decode($row['meta_description']);
            $data['status'] = (int) $row['status'] === 1 ? 'published' : 'draft';
            $table = $this->table('cmteh_category');
            if ($previous) {
                $this->run("UPDATE $table SET data = ? WHERE category_id = ?", [self::encode($data), $id]);
            } else {
                $this->run("INSERT INTO $table (category_id, source_id, slug, published_at, data)
                    VALUES (?, ?, ?, UTC_TIMESTAMP(), ?)", [$id, $sourceId, $slug, self::encode($data)]);
            }
            return;
        }

        $data['name'] = $name;
        $data['status'] ??= 'published';
        $table = $this->table('cmteh_brand');
        if ($previous) {
            $this->run("UPDATE $table SET data = ? WHERE manufacturer_id = ?", [self::encode($data), $id]);
        } else {
            $this->run("INSERT INTO $table (manufacturer_id, source_id, slug, status, published_at, data)
                VALUES (?, ?, ?, 1, UTC_TIMESTAMP(), ?)", [$id, $sourceId, $slug, self::encode($data)]);
        }
        $this->run('UPDATE ' . $this->table('cmteh_product') . ' SET brand_norm = ? WHERE manufacturer_id = ?',
            [CatalogInput::normalize($name), $id]);
    }

    private function syncProduct(int $id, array $row, array|false $previous, array $data, string $slug): void
    {
        $codes = $this->run('SELECT code, value FROM ' . $this->table('product_code') .
            " WHERE product_id = ? AND code IN ('sku', 'mpn') ORDER BY product_code_id", [$id])->fetchAll(PDO::FETCH_KEY_PAIR);
        $sku = trim(self::decode($codes['sku'] ?? ''));
        $sku = mb_substr($sku !== '' ? $sku : trim(self::decode($row['model'])), 0, 128);
        $mpn = trim(mb_substr(self::decode($codes['mpn'] ?? ''), 0, 128));

        $brandId = (int) $row['manufacturer_id'];
        $brand = $this->run('SELECT name FROM ' . $this->table('manufacturer') . ' WHERE manufacturer_id = ?',
            [$brandId])->fetchColumn();
        $categoryId = (int) $this->run('SELECT pc.category_id FROM ' . $this->table('product_to_category') .
            ' pc JOIN ' . $this->table('cmteh_category') . ' c USING (category_id) WHERE pc.product_id = ?
            ORDER BY pc.category_id <> ?, pc.category_id LIMIT 1', [$id, (int) ($previous['category_id'] ?? 0)])->fetchColumn();

        $data['native_gallery'] = array_values(array_filter(array_map(
            CatalogInput::publicImagePath(...),
            $this->run('SELECT image FROM ' . $this->table('product_image') .
                ' WHERE product_id = ? ORDER BY sort_order, product_image_id LIMIT 50', [$id])->fetchAll(PDO::FETCH_COLUMN),
        )));
        $data['native_related'] = array_map('intval', $this->run('SELECT related_id FROM ' . $this->table('product_related') .
            ' WHERE product_id = ? ORDER BY related_id LIMIT 50', [$id])->fetchAll(PDO::FETCH_COLUMN));

        $currency = $previous['currency'] ?? (preg_match('/^[A-Z]{3}$/D', $this->currency) ? $this->currency : null);
        $priceStatus = $previous['price_status'] ?? 'on_request';
        if (!$previous || ($data['native_price_status'] ?? null) === $priceStatus) {
            $priceStatus = $currency !== null && (float) $row['price'] > 0 ? 'fixed' : 'on_request';
            $data['native_price_status'] = $priceStatus;
        }
        $availability = $previous['availability'] ?? null;
        $ready=$this->run('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$this->prefix.'cmteh_inventory_product'])->fetchColumn();
        $managed=$ready && $this->run('SELECT 1 FROM '.$this->table('cmteh_inventory_product').' WHERE product_id=?',[$id])->fetchColumn();
        if (!$managed && (!$previous || (array_key_exists('native_availability', $data) && $data['native_availability'] === $availability))) {
            $availability = (int) $row['quantity'] > 0 ? 'in_stock' : null;
            $data['native_availability'] = $availability;
        }

        $columns = [$sku, $mpn !== '' ? $mpn : null, CatalogInput::normalize($sku), CatalogInput::normalize($mpn),
            CatalogInput::normalize(self::decode($row['name'])), CatalogInput::normalize(self::decode((string) $brand)),
            $brandId, $categoryId, self::encode($data)];
        $table = $this->table('cmteh_product');
        if ($previous) {
            $this->run("UPDATE $table SET sku = ?, mpn = ?, sku_norm = ?, mpn_norm = ?, name_norm = ?, brand_norm = ?,
                manufacturer_id = ?, category_id = ?, data = ?, price_status = ?, currency = ?, availability = ? WHERE product_id = ?",
                [...$columns, $priceStatus, $currency, $availability, $id]);
            return;
        }

        $this->run("INSERT INTO $table
            (product_id, source_id, slug, sku, mpn, sku_norm, mpn_norm, name_norm, brand_norm, manufacturer_id, category_id,
             data, published_at, indexable, price_status, currency, availability)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), 1, ?, ?, ?)",
            [$id, "native-product-$id", $slug, ...$columns, $priceStatus, $currency, $availability]);
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function table(string $name): string
    {
        return sprintf('%c%s%s%c', 96, $this->prefix, $name, 96);
    }

    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
}
