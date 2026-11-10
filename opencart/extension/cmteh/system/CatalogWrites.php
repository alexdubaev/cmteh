<?php

declare(strict_types=1);

namespace Cmteh;

use InvalidArgumentException;
use PDO;

trait CatalogWrites
{
    public function save(string $type, array $input): string
    {
        $row = $this->validate($type, $input);
        return $this->atomic(fn(): string => match ($type) {
            'product' => $this->writeProduct($row),
            'category' => $this->writeCategory($row),
            'brand' => $this->writeBrand($row),
            'route' => $this->writeRoute($row),
            'media' => $this->writeMedia($row),
            'unpublish' => $this->unpublish($row['id']),
            default => $this->writeContent($type, $row),
        });
    }

    public function validate(string $type, array $row): array
    {
        if ($type === 'route') {
            $path = CatalogInput::routeSource($row['path'] ?? null);
            $status = $row['status'] ?? null;
            if (!in_array($status, [301, 410], true)) {
                throw new InvalidArgumentException('status: требуется 301 или 410');
            }
            $target = $status === 301 ? CatalogInput::path($row['target'] ?? null) : null;
            if ($path === $target) {
                throw new InvalidArgumentException('Перенаправление должно вести на другой путь');
            }
            return ['path' => $path, 'target' => $target, 'status' => $status];
        }
        if ($type === 'media') {
            return $this->validateMedia($row);
        }
        if (!in_array($type, ['product', 'category', 'brand', 'unpublish', ...self::CONTENT_TYPES], true)) {
            throw new InvalidArgumentException('Неизвестный тип записи');
        }

        $id = CatalogInput::text($row['id'] ?? null, 160, 'id', true);
        if ($type === 'unpublish') {
            return ['id' => $id];
        }
        $slug = CatalogInput::slug(in_array($type, ['site', 'home'], true) ? $type : ($row['slug'] ?? null));
        $row['id'] = $id;
        $row['slug'] = $slug;
        CatalogInput::published($row);
        $row['status'] = $row['status'] ?? 'draft';
        $row['published_at'] = str_replace(' ', 'T', CatalogInput::date($row['published_at'] ?? null)) . 'Z';
        if (isset($row['is_indexable']) && !is_bool($row['is_indexable'])) {
            throw new InvalidArgumentException('is_indexable: требуется логическое значение');
        }
        $row['is_indexable'] = $row['is_indexable'] ?? true;
        $row['sort_order'] = (int) ($row['sort_order'] ?? 0);

        if ($type === 'product') {
            return $this->validateProduct($row);
        }
        if ($type === 'brand') {
            return array_replace(array_intersect_key($row, array_flip(['id', 'slug', 'status', 'is_indexable', 'sort_order', 'published_at', 'seo_title', 'seo_description', 'canonical_url', 'image_path'])), [
                'name' => CatalogInput::text($row['name'] ?? null, 64, 'name', true),
                'description' => CatalogInput::text($row['description'] ?? null, 2000, 'description'),
                'seo_title' => CatalogInput::text($row['seo_title'] ?? null, 255, 'seo_title'),
                'seo_description' => CatalogInput::text($row['seo_description'] ?? null, 255, 'seo_description'),
                'canonical_url' => CatalogInput::url($row['canonical_url'] ?? null),
                'accent' => is_string($row['accent'] ?? null) && preg_match('/^#[a-f0-9]{6}$/iD', $row['accent']) ? $row['accent'] : '#f7c400',
            ]);
        }
        if (in_array($type, ['category', 'page', 'article'], true)) {
            $row['title'] = CatalogInput::text($row['title'] ?? null, 255, 'title', true);
        }
        if ($type === 'navigation') {
            $row['label'] = CatalogInput::text($row['label'] ?? null, 128, 'label', true);
            $row['location'] = CatalogInput::enum($row['location'] ?? null, ['header', 'footer', 'legal'], 'location')
                ?? throw new InvalidArgumentException('location: обязательное поле');
            if (CatalogInput::url($row['url'] ?? null) === null) {
                throw new InvalidArgumentException('url: небезопасный адрес');
            }
        }
        if ($type === 'page' && in_array($slug, ['admin', 'api', 'account', 'brand', 'cart', 'category', 'checkout', 'extension', 'image', 'information', 'install', 'product', 'sitemaps', 'system', '_cmteh_platform'], true)) {
            throw new InvalidArgumentException('slug занят системным маршрутом');
        }
        if ($type === 'category') {
            $row['seo_title'] = CatalogInput::text($row['seo_title'] ?? null, 255, 'seo_title');
            $row['seo_description'] = CatalogInput::text($row['seo_description'] ?? null, 255, 'seo_description');
        }
        if ($type === 'home') { $row = $this->validateHome($row); }
        if ($type === 'site' && isset($row['social_links'])) {
            foreach ($this->objectList($row['social_links'], 30, 'social_links') as $link) {
                CatalogInput::text($link['label'] ?? null, 80, 'label', true);
                if (CatalogInput::url($link['url'] ?? null) === null) throw new InvalidArgumentException('Соцссылка: небезопасный URL');
            }
        }
        return $this->sanitizeContent($row);
    }

    public function existing(string $type, array $row): bool
    {
        [$table, $key, $value] = match ($type) {
            'product', 'unpublish' => ['cmteh_product', 'source_id', $row['id']],
            'category', 'brand' => ['cmteh_' . $type, 'source_id', $row['id']],
            'route' => ['cmteh_route', 'path', $row['path']],
            'media' => ['cmteh_media', 'uuid', $row['uuid']],
            default => ['cmteh_content', 'source_id', $row['id']],
        };
        if ($table === 'cmteh_content') {
            return (bool) $this->run('SELECT 1 FROM ' . $this->table($table) . ' WHERE type = ? AND source_id = ?', [$type, $value])->fetchColumn();
        }
        return (bool) $this->run('SELECT 1 FROM ' . $this->table($table) . " WHERE `$key` = ?", [$value])->fetchColumn();
    }

    public function migrateEditorialContent(): void
    {
        $this->atomic(function (): void {
            $schema = $this->table('cmteh_schema');
            require_once __DIR__ . '/EditorialDefaults.php';
            $migration = $this->run("INSERT IGNORE INTO $schema (version, applied_at) VALUES (3, UTC_TIMESTAMP())");
            if ($migration->rowCount() === 1) {
                EditorialDefaults::install($this);
            }
            $migration = $this->run("INSERT IGNORE INTO $schema (version, applied_at) VALUES (4, UTC_TIMESTAMP())");
            if ($migration->rowCount() === 1) {
                EditorialDefaults::installSearchContent($this);
            }
        });
    }

    public function editorialBySlug(string $type, string $slug): ?array
    {
        $table = $this->table('cmteh_content');
        $data = $this->run("SELECT data FROM $table WHERE type = ? AND slug = ?", [$type, $slug])->fetchColumn();
        return $data === false ? null : self::decode($data);
    }

    public function hasEditorialNavigation(string $location): bool
    {
        $table = $this->table('cmteh_content');
        return (bool) $this->run("SELECT 1 FROM $table WHERE type = 'navigation'
            AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.location')) = ? LIMIT 1", [$location])->fetchColumn();
    }

    public function hasNavigationTarget(string $location, string $url): bool
    {
        $table = $this->table('cmteh_content');
        return (bool) $this->run("SELECT 1 FROM $table WHERE type = 'navigation'
            AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.location')) = ?
            AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.url')) = ? LIMIT 1", [$location, $url])->fetchColumn();
    }

    public function insertEditorialDefault(string $type, array $input): void
    {
        if (!in_array($type, self::CONTENT_TYPES, true)) {
            throw new InvalidArgumentException('Неизвестный тип редакционного содержимого');
        }
        $row = $this->validate($type, $input);
        $table = $this->table('cmteh_content');
        $this->run("INSERT INTO $table (type, source_id, slug, status, published_at, indexable, sort_order, data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE source_id = source_id", [
                $type, $row['id'], $row['slug'], (int) CatalogInput::published($row), CatalogInput::date($row['published_at']),
                (int) $row['is_indexable'], $row['sort_order'], self::encode($row),
            ]);
    }

    public function backfillEditorialFields(string $type, string $slug, array $fields): void
    {
        $this->atomic(function () use ($type, $slug, $fields): void {
            $table = $this->table('cmteh_content');
            $data = $this->run("SELECT data FROM $table WHERE type = ? AND slug = ? FOR UPDATE", [$type, $slug])->fetchColumn();
            if ($data === false) {
                return;
            }
            $existing = self::decode($data);
            $missing = array_diff_key($fields, $existing);
            if ($missing !== []) {
                $this->run("UPDATE $table SET data = ? WHERE type = ? AND slug = ?", [
                    self::encode($existing + $this->sanitizeContent($missing)), $type, $slug,
                ]);
            }
        });
    }

    public function reviseEditorialDefaults(string $type, string $slug, array $changes, ?string $sourceId = null): void
    {
        $table = $this->table('cmteh_content');
        $row = $this->run("SELECT source_id, data, indexable FROM $table WHERE type = ? AND slug = ? FOR UPDATE", [$type, $slug])
            ->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($sourceId !== null && $row['source_id'] !== $sourceId)) {
            return;
        }
        $data = self::decode($row['data']);
        $updated = [];
        foreach ($changes as $field => [$previous, $next]) {
            if (($data[$field] ?? null) === $previous) {
                $updated[$field] = $next;
            }
        }
        if ($updated !== []) {
            $this->run("UPDATE $table SET data = ?, indexable = ? WHERE type = ? AND slug = ?", [
                self::encode(array_replace($data, $this->sanitizeContent($updated))),
                (int) ($updated['is_indexable'] ?? $row['indexable']), $type, $slug,
            ]);
        }
    }

    public function taxonomyExists(string $type, string $slug): bool
    {
        if (!in_array($type, ['brand', 'category'], true)) {
            return false;
        }
        return (bool) $this->run('SELECT 1 FROM ' . $this->table('cmteh_' . $type) . ' WHERE slug = ?', [$slug])->fetchColumn();
    }

    public function assertSlugAvailable(string $type, array $row): void
    {
        if (!isset($row['slug'])) {
            return;
        }
        if (in_array($type, ['product', 'category', 'brand'], true)) {
            $sql = 'SELECT source_id FROM ' . $this->table('cmteh_' . $type) . ' WHERE slug = ?';
            $params = [$row['slug']];
        } else {
            $sql = 'SELECT source_id FROM ' . $this->table('cmteh_content') . ' WHERE type = ? AND slug = ?';
            $params = [$type, $row['slug']];
        }
        $owner = $this->run($sql, $params)->fetchColumn();
        if ($owner !== false && $owner !== $row['id']) {
            throw new InvalidArgumentException('slug уже принадлежит другой записи');
        }
    }

    private function storedEditRecord(string $type, string $id): ?array
    {
        if ($type === 'product') {
            $row = $this->run('SELECT x.*, p.status, p.price, p.sort_order, d.name, d.description, d.meta_title, d.meta_description,
                b.slug AS brand_slug, m.name AS brand_name, c.slug AS category_slug
                FROM ' . $this->table('cmteh_product') . ' x
                JOIN ' . $this->table('product') . ' p USING (product_id)
                JOIN ' . $this->table('product_description') . ' d ON d.product_id = p.product_id AND d.language_id = ?
                LEFT JOIN ' . $this->table('manufacturer') . ' m ON p.manufacturer_id = m.manufacturer_id
                LEFT JOIN ' . $this->table('cmteh_brand') . ' b ON m.manufacturer_id = b.manufacturer_id
                LEFT JOIN ' . $this->table('cmteh_category') . ' c ON c.category_id = x.category_id
                WHERE x.source_id = ?', [$this->languageId, $id])->fetch(PDO::FETCH_ASSOC);
            return $row ? array_replace(self::decode($row['data']), [
                'id' => $id, 'slug' => $row['slug'], 'title' => $row['name'], 'full_description' => $row['description'],
                'sku' => $row['sku'], 'mpn' => $row['mpn'], 'brand' => $row['brand_name'], 'brand_slug' => $row['brand_slug'],
                'category_slug' => $row['category_slug'], 'main_image' => $row['main_image'], 'sort_order' => (int) $row['sort_order'],
                'seo_title' => $row['meta_title'], 'seo_description' => $row['meta_description'],
                'price' => $row['price_status'] === 'fixed' ? (float) $row['price'] : null,
                'currency' => $row['currency'], 'price_status' => $row['price_status'],
                'availability_status' => $row['availability'], 'part_type' => $row['part_type'],
                'status' => $row['status'] ? 'published' : 'draft', 'is_indexable' => (bool) $row['indexable'],
                'published_at' => str_replace(' ', 'T', $row['published_at']) . 'Z',
            ]) : null;
        }
        if (in_array($type, ['category', 'brand'], true)) {
            $value = $this->run('SELECT data FROM ' . $this->table('cmteh_' . $type) . ' WHERE source_id = ?', [$id])->fetchColumn();
            return $value === false ? null : self::decode($value);
        }
        if (!in_array($type, self::CONTENT_TYPES, true)) {
            return null;
        }
        $value = $this->run('SELECT data FROM ' . $this->table('cmteh_content') . ' WHERE type = ? AND source_id = ?', [$type, $id])->fetchColumn();
        return $value === false ? null : self::decode($value);
    }

    private function validateProduct(array $row): array
    {
        $row['title'] = CatalogInput::text($row['title'] ?? null, 255, 'title', true);
        $row['sku'] = CatalogInput::text($row['sku'] ?? null, 128, 'sku', true);
        $row['mpn'] = CatalogInput::text($row['mpn'] ?? null, 128, 'mpn') ?: null;
        $row['brand'] = CatalogInput::text($row['brand'] ?? null, 64, 'brand');
        $brandSlug = ($row['brand_slug'] ?? '') !== '' ? $row['brand_slug'] : CatalogInput::slugify($row['brand']);
        if ($brandSlug === '' && $row['brand'] === '' && $this->productMayHaveNoManufacturer($row['id'])) {
            $row['brand_slug'] = null;
        } else {
            $row['brand_slug'] = CatalogInput::slug($brandSlug);
        }
        $category = is_array($row['category'] ?? null) ? $row['category'] : [];
        $categorySlug = $row['category_slug'] ?? $category['slug'] ?? '';
        $row['category_slug'] = $categorySlug !== '' ? CatalogInput::slug($categorySlug) : null;
        $row['category_title'] = CatalogInput::text($row['category_title'] ?? $category['title'] ?? null, 255, 'category_title');
        $row['availability_status'] = CatalogInput::enum($row['availability_status'] ?? null, self::AVAILABILITY, 'availability_status');
        $row['part_type'] = CatalogInput::enum($row['part_type'] ?? null, self::PART_TYPES, 'part_type');
        $row['price_status'] = CatalogInput::enum($row['price_status'] ?? null, ['fixed', 'on_request', 'hidden'], 'price_status') ?? 'on_request';
        $row['currency'] = $this->currency($row['currency'] ?? null);
        if ($row['price_status'] === 'fixed') {
            $price = $row['price'] ?? null;
            if ((!is_int($price) && !is_float($price)) || !is_finite((float) $price) || $price < 0 || $price > 99999999999
                || ($price == 0 && ($row['confirm_zero_price'] ?? null) !== true) || $row['currency'] === null) {
                throw new InvalidArgumentException('price: фиксированная цена требует суммы, валюты и явного подтверждения нулевой цены');
            }
        } else {
            $row['price'] = null;
        }
        $row['short_description'] = CatalogInput::text($row['short_description'] ?? null, 2000, 'short_description') ?: null;
        $row['full_description'] = CatalogInput::html($row['full_description'] ?? '');
        $row['seo_title'] = CatalogInput::text($row['seo_title'] ?? null, 255, 'seo_title');
        $row['seo_description'] = CatalogInput::text($row['seo_description'] ?? null, 255, 'seo_description');
        $row['delivery_status'] = CatalogInput::text($row['delivery_status'] ?? null, 255, 'delivery_status') ?: null;
        $row['canonical_url'] = CatalogInput::url($row['canonical_url'] ?? null);
        $row['main_image'] = CatalogInput::uuid($row['main_image'] ?? null);

        $row['codes'] = array_map(static fn(array $item): array => [
            'code' => CatalogInput::text($item['code'] ?? null, 128, 'code', true),
            'code_type' => CatalogInput::enum($item['code_type'] ?? null,
                ['oem', 'mpn', 'supplier', 'previous', 'superseded', 'external', 'barcode'], 'code_type') ?? 'external',
            'source_name' => CatalogInput::text($item['source_name'] ?? null, 255, 'source_name') ?: null,
        ], $this->objectList($row['codes'] ?? [], 100, 'codes'));
        foreach (['images' => ['image', 'alt_text'], 'documents' => ['file', 'title']] as $field => [$uuidKey, $label]) {
            $row[$field] = array_map(static fn(array $item): array => [
                $uuidKey => CatalogInput::uuid($item[$uuidKey] ?? null) ?? throw new InvalidArgumentException('Медиа: требуется UUID'),
                $label => CatalogInput::text($item[$label] ?? null, 200, $label) ?: null,
            ], $this->objectList($row[$field] ?? [], 50, $field));
        }
        $row['specification_items'] = array_map(static fn(array $item): array => [
            'group_name' => CatalogInput::text($item['group_name'] ?? $item['group'] ?? null, 64, 'group_name') ?: 'Основные',
            'name' => CatalogInput::text($item['name'] ?? null, 64, 'name', true),
            'value' => CatalogInput::text($item['value'] ?? null, 1000, 'value', true),
            'unit' => CatalogInput::text($item['unit'] ?? null, 40, 'unit') ?: null,
        ], $this->objectList($row['specification_items'] ?? [], 100, 'specification_items'));
        $row['relations'] = array_map(static fn(array $item): array => [
            'source_id' => CatalogInput::text($item['source_id'] ?? $item['product']['id'] ?? null, 160, 'source_id', true),
            'relation_type' => CatalogInput::enum($item['relation_type'] ?? null,
                ['analog', 'oem_cross', 'compatible', 'superseded_by'], 'relation_type')
                ?? throw new InvalidArgumentException('relation_type: обязательное поле'),
        ], $this->objectList($row['relations'] ?? [], 50, 'relations'));
        return $row;
    }

    private function productMayHaveNoManufacturer(string $id): bool
    {
        return (bool) $this->run('SELECT 1 FROM ' . $this->table('cmteh_product') . ' x
            JOIN ' . $this->table('product') . ' p USING (product_id)
            WHERE x.source_id = ? AND p.manufacturer_id = 0', [$id])->fetchColumn();
    }

    private function currency(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z]{3}$/D', $value) ? $value : null;
    }

    private function validateMedia(array $row): array
    {
        $uuid = CatalogInput::uuid($row['uuid'] ?? null) ?? throw new InvalidArgumentException('uuid: неверный идентификатор');
        $path = CatalogInput::text($row['path'] ?? null, 512, 'path', true);
        if (!preg_match('~^[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*$~D', $path)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new InvalidArgumentException('path: нужен относительный путь в закрытом хранилище');
        }
        $mime = CatalogInput::enum($row['mime'] ?? null, self::MEDIA_TYPES, 'mime')
            ?? throw new InvalidArgumentException('mime: обязательное поле');
        $filename = CatalogInput::text($row['filename'] ?? basename($path), 200, 'filename', true);
        if (strpbrk($filename, "/\\\r\n") !== false) {
            throw new InvalidArgumentException('filename: недопустимое имя');
        }
        $status = CatalogInput::published($row) ? 1 : 0;
        if ($status && $this->resolveMedia($path, $mime) === null) {
            throw new InvalidArgumentException('Файл отсутствует, находится вне хранилища или тип не совпадает');
        }
        $owners = array_map(static fn(array $owner): array => [
            'type' => CatalogInput::enum($owner['type'] ?? null, ['product', 'category', ...self::CONTENT_TYPES], 'owner.type')
                ?? throw new InvalidArgumentException('owner.type: обязательное поле'),
            'id' => CatalogInput::text($owner['id'] ?? null, 160, 'owner.id', true),
        ], $this->objectList($row['owners'] ?? [], 100, 'owners'));
        if ($status && !$owners) {
            throw new InvalidArgumentException('Для публикации требуется явная связь с записью');
        }
        return compact('uuid', 'path', 'mime', 'filename', 'status', 'owners');
    }

    private function objectList(mixed $value, int $max, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            throw new InvalidArgumentException("$field: неверный список");
        }
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException("$field: ожидается объект");
            }
        }
        return $value;
    }

    private function sanitizeContent(array $row, int $depth = 0): array
    {
        if ($depth >= 8 || count($row) > 300) {
            throw new InvalidArgumentException('Слишком сложная структура контента');
        }
        foreach ($row as $key => &$value) {
            if (is_array($value)) {
                $value = $this->sanitizeContent($value, $depth + 1);
            } elseif (in_array($key, ['image', 'cover_image', 'og_image', 'hero_image'], true)) {
                $value = CatalogInput::uuid($value);
            } elseif (is_string($key) && ($key === 'href' || $key === 'url' || str_ends_with($key, '_url'))) {
                $value = CatalogInput::url($value);
            } elseif (in_array($key, ['text', 'content', 'description', 'seo_text'], true)) {
                $value = CatalogInput::html($value);
            } elseif (is_string($value)) {
                $value = CatalogInput::text($value, 5000, (string) $key);
            }
        }
        return $row;
    }

    private function writeProduct(array $row): string
    {
        $this->assertSlugAvailable('product', $row);
        $previous = $this->run('SELECT product_id FROM ' . $this->table('cmteh_product') . ' WHERE source_id = ? FOR UPDATE', [$row['id']])->fetchColumn();
        $manufacturer = ['manufacturer_id' => 0, 'name' => ''];
        if ($row['brand_slug'] !== null) {
            $manufacturer = $this->run('SELECT m.manufacturer_id, m.name FROM ' . $this->table('cmteh_brand') . ' b JOIN ' .
                $this->table('manufacturer') . ' m USING (manufacturer_id) WHERE b.slug = ?', [$row['brand_slug']])->fetch(PDO::FETCH_ASSOC);
            if (!$manufacturer) {
                throw new InvalidArgumentException('Не найден бренд ' . $row['brand_slug']);
            }
        }
        $brand = $manufacturer['manufacturer_id'];
        $category = 0;
        if ($row['category_slug'] !== null) {
            $category = $this->run('SELECT category_id FROM ' . $this->table('cmteh_category') . ' WHERE slug = ?', [$row['category_slug']])->fetchColumn();
            if ($category === false) {
                throw new InvalidArgumentException('Не найдена категория ' . $row['category_slug']);
            }
        }
        $published = CatalogInput::published($row);
        $availableDate = substr($row['published_at'], 0, 10);
        $native = [
            mb_substr($row['sku'], 0, 64), (int) $brand, $row['price'] ?? 0,
            $availableDate, $row['sort_order'], (int) $published,
        ];
        if ($previous === false) {
            $this->run('INSERT INTO ' . $this->table('product') . '
                (model, manufacturer_id, price, date_available, sort_order, status, location, image, shipping, subtract, date_added, date_modified)
                VALUES (?, ?, ?, ?, ?, ?, \'\', \'\', 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', $native);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $id = (int) $previous;
            $this->run('UPDATE ' . $this->table('product') . ' SET manufacturer_id = ?, price = ?,
                date_available = ?, sort_order = ?, status = ?, date_modified = UTC_TIMESTAMP() WHERE product_id = ?',
                [...array_slice($native, 1), $id]);
        }
        $this->run('INSERT INTO ' . $this->table('product_description') . '
            (product_id, language_id, name, description, tag, meta_title, meta_description, meta_keyword)
            VALUES (?, ?, ?, ?, \'\', ?, ?, \'\')
            ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), meta_title = VALUES(meta_title), meta_description = VALUES(meta_description)',
            [$id, $this->languageId, $row['title'], $row['full_description'], $row['seo_title'], $row['seo_description']]);
        $codeTable = $this->table('product_code');
        $this->run("DELETE FROM $codeTable WHERE product_id = ? AND code IN ('sku', 'mpn')", [$id]);
        foreach (['sku', 'mpn'] as $code) {
            if ($row[$code] !== null) {
                $this->run("INSERT INTO $codeTable (product_id, code, value) VALUES (?, ?, ?)", [$id, $code, $row[$code]]);
            }
        }
        $this->run('INSERT IGNORE INTO ' . $this->table('product_to_store') . ' (product_id, store_id) VALUES (?, ?)', [$id, $this->storeId]);
        $this->run('DELETE pc FROM ' . $this->table('product_to_category') . ' pc
            JOIN ' . $this->table('cmteh_product') . ' x ON x.product_id = pc.product_id AND x.category_id = pc.category_id
            WHERE pc.product_id = ?', [$id]);
        if ($category) {
            $this->run('INSERT INTO ' . $this->table('product_to_category') . ' (product_id, category_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)', [$id, $category]);
        }
        $this->writeSpecifications($id, $row['specification_items']);
        $details = array_intersect_key($row, array_flip([
            'short_description', 'canonical_url', 'delivery_status', 'codes', 'images', 'documents', 'specification_items', 'relations',
            'confirm_zero_price',
        ]));
        $oldDetails = $previous === false ? [] : self::decode((string) $this->run('SELECT data FROM ' . $this->table('cmteh_product') . ' WHERE product_id = ?', [$id])->fetchColumn());
        $details = array_replace($oldDetails, $details);
        $details['status'] = $row['status'];
        $details['specifications'] = array_column($row['specification_items'], 'value', 'name');
        $meta = [
            $row['slug'], $row['sku'], $row['mpn'], CatalogInput::normalize($row['sku']), CatalogInput::normalize($row['mpn'] ?? ''),
            CatalogInput::normalize($row['title']), CatalogInput::normalize($manufacturer['name']), $brand, $category, CatalogInput::date($row['published_at']),
            (int) $row['is_indexable'], $row['price_status'], $row['currency'], $row['availability_status'], $row['part_type'], $row['main_image'],
            self::encode($details),
        ];
        $table = $this->table('cmteh_product');
        if ($previous === false) {
            $this->run("INSERT INTO $table
                (product_id, source_id, slug, sku, mpn, sku_norm, mpn_norm, name_norm, brand_norm, manufacturer_id, category_id,
                 published_at, indexable, price_status, currency, availability, part_type, main_image, data)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$id, $row['id'], ...$meta]);
        } else {
            $this->run("UPDATE $table SET slug = ?, sku = ?, mpn = ?, sku_norm = ?, mpn_norm = ?, name_norm = ?, brand_norm = ?,
                manufacturer_id = ?, category_id = ?, published_at = ?, indexable = ?, price_status = ?, currency = ?,
                availability = ?, part_type = ?, main_image = ?, data = ? WHERE product_id = ?", [...$meta, $id]);
        }
        $this->writeAdminNativeFields('product', $id, $row);
        return $previous === false ? 'added' : 'updated';
    }

    private function writeCategory(array $row): string
    {
        $this->assertSlugAvailable('category', $row);
        $map = $this->table('cmteh_category');
        $previous = $this->run("SELECT category_id FROM $map WHERE source_id = ? FOR UPDATE", [$row['id']])->fetchColumn();
        $status = (int) CatalogInput::published($row);
        if ($previous === false) {
            $this->run('INSERT INTO ' . $this->table('category') . ' (image, sort_order, status) VALUES (\'\', ?, ?)', [$row['sort_order'], $status]);
            $id = (int) $this->pdo->lastInsertId();
            $this->run('INSERT INTO ' . $this->table('category_path') . ' (category_id, path_id, level) VALUES (?, ?, 0)', [$id, $id]);
        } else {
            $id = (int) $previous;
            $this->run('UPDATE ' . $this->table('category') . ' SET sort_order = ?, status = ? WHERE category_id = ?', [$row['sort_order'], $status, $id]);
        }
        $this->run('INSERT INTO ' . $this->table('category_description') . '
            (category_id, language_id, name, description, meta_title, meta_description, meta_keyword) VALUES (?, ?, ?, ?, ?, ?, \'\')
            ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), meta_title = VALUES(meta_title), meta_description = VALUES(meta_description)',
            [$id, $this->languageId, $row['title'], $row['description'] ?? '', $row['seo_title'], $row['seo_description']]);
        $this->run('INSERT IGNORE INTO ' . $this->table('category_to_store') . ' (category_id, store_id) VALUES (?, ?)', [$id, $this->storeId]);
        if ($previous === false) {
            $this->run("INSERT INTO $map (category_id, source_id, slug, published_at, data) VALUES (?, ?, ?, ?, ?)", [$id, $row['id'], $row['slug'], CatalogInput::date($row['published_at']), self::encode($row)]);
        } else {
            $this->run("UPDATE $map SET slug = ?, published_at = ?, data = ? WHERE category_id = ?", [$row['slug'], CatalogInput::date($row['published_at']), self::encode($row), $id]);
        }
        $this->writeAdminNativeFields('category', $id, $row);
        return $previous === false ? 'added' : 'updated';
    }

    private function writeBrand(array $row): string
    {
        $this->assertSlugAvailable('brand', $row);
        $map = $this->table('cmteh_brand');
        $previous = $this->run("SELECT manufacturer_id FROM $map WHERE source_id = ? FOR UPDATE", [$row['id']])->fetchColumn();
        if ($previous === false) {
            $this->run('INSERT INTO ' . $this->table('manufacturer') . ' (name, image, sort_order) VALUES (?, \'\', ?)', [$row['name'], $row['sort_order']]);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $id = (int) $previous;
            $this->run('UPDATE ' . $this->table('manufacturer') . ' SET name = ?, sort_order = ? WHERE manufacturer_id = ?', [$row['name'], $row['sort_order'], $id]);
            $this->run('UPDATE ' . $this->table('cmteh_product') . ' SET brand_norm = ? WHERE manufacturer_id = ?',
                [CatalogInput::normalize($row['name']), $id]);
        }
        $this->run('INSERT IGNORE INTO ' . $this->table('manufacturer_to_store') . ' (manufacturer_id, store_id) VALUES (?, ?)', [$id, $this->storeId]);
        $status = (int) CatalogInput::published($row);
        if ($previous === false) {
            $this->run("INSERT INTO $map (manufacturer_id, source_id, slug, status, published_at, data) VALUES (?, ?, ?, ?, ?, ?)",
                [$id, $row['id'], $row['slug'], $status, CatalogInput::date($row['published_at']), self::encode($row)]);
        } else {
            $this->run("UPDATE $map SET slug = ?, status = ?, data = ?, published_at = ? WHERE manufacturer_id = ?",
                [$row['slug'], $status, self::encode($row), CatalogInput::date($row['published_at']), $id]);
        }
        $this->writeAdminNativeFields('brand', $id, $row);
        return $previous === false ? 'added' : 'updated';
    }

    private function writeSpecifications(int $productId, array $items): void
    {
        $attributes = $this->table('cmteh_attribute');
        $native = $this->table('product_attribute');
        $this->run("DELETE FROM $native WHERE product_id = ? AND language_id = ? AND attribute_id IN (SELECT attribute_id FROM $attributes)",
            [$productId, $this->languageId]);
        foreach ($items as $sort => $item) {
            $key = hash('sha256', $item['group_name'] . "\0" . $item['name'] . "\0" . ($item['unit'] ?? ''));
            $id = $this->run("SELECT attribute_id FROM $attributes WHERE field_key = ?", [$key])->fetchColumn();
            if ($id === false) {
                // Reuse an existing native attribute by its language/group/name identity.
                $id = $this->run('SELECT a.attribute_id FROM ' . $this->table('attribute') . ' a
                    JOIN ' . $this->table('attribute_description') . ' d ON d.attribute_id=a.attribute_id AND d.language_id=?
                    JOIN ' . $this->table('attribute_group_description') . ' g ON g.attribute_group_id=a.attribute_group_id AND g.language_id=d.language_id
                    WHERE d.name=? AND g.name=? ORDER BY a.attribute_id LIMIT 1',
                    [$this->languageId, $item['name'], $item['group_name']])->fetchColumn();
                if ($id !== false) {
                    $this->run("INSERT INTO $attributes (attribute_id,field_key,group_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE field_key=field_key", [$id,$key,$item['group_name']]);
                }
            }
            if ($id === false) {
                $group = $this->run('SELECT attribute_group_id FROM ' . $this->table('attribute_group_description') .
                    ' WHERE language_id = ? AND name = ? ORDER BY attribute_group_id LIMIT 1', [$this->languageId, $item['group_name']])->fetchColumn();
                if ($group === false) {
                    $this->run('INSERT INTO ' . $this->table('attribute_group') . ' (sort_order) VALUES (0)');
                    $group = (int) $this->pdo->lastInsertId();
                    $this->run('INSERT INTO ' . $this->table('attribute_group_description') . ' (attribute_group_id, language_id, name) VALUES (?, ?, ?)',
                        [$group, $this->languageId, $item['group_name']]);
                }
                $this->run('INSERT INTO ' . $this->table('attribute') . ' (attribute_group_id, sort_order) VALUES (?, ?)', [$group, $sort]);
                $id = (int) $this->pdo->lastInsertId();
                $this->run('INSERT INTO ' . $this->table('attribute_description') . ' (attribute_id, language_id, name) VALUES (?, ?, ?)',
                    [$id, $this->languageId, $item['name']]);
                $this->run("INSERT INTO $attributes (attribute_id, field_key, group_name) VALUES (?, ?, ?)", [$id, $key, $item['group_name']]);
            }
            $this->run("INSERT INTO $native (product_id, attribute_id, language_id, text) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE text = VALUES(text)", [$productId, $id, $this->languageId, $item['value']]);
        }
    }

    private function specifications(int $id, array $metadata): array
    {
        $values = $this->run('SELECT x.field_key, p.text FROM ' . $this->table('product_attribute') . ' p
            JOIN ' . $this->table('cmteh_attribute') . ' x USING (attribute_id)
            WHERE p.product_id = ? AND p.language_id = ?', [$id, $this->languageId])->fetchAll(PDO::FETCH_KEY_PAIR);
        $result = [];
        foreach ($metadata as $item) {
            $key = hash('sha256', $item['group_name'] . "\0" . $item['name'] . "\0" . ($item['unit'] ?? ''));
            if (isset($values[$key])) {
                $item['value'] = $values[$key];
                $result[] = $item;
            }
        }
        return $result;
    }

    private function writeContent(string $type, array $row): string
    {
        $this->assertSlugAvailable($type, $row);
        $exists = $this->existing($type, $row);
        $table = $this->table('cmteh_content');
        $values = [
            $row['slug'], (int) CatalogInput::published($row), CatalogInput::date($row['published_at']),
            (int) $row['is_indexable'], $row['sort_order'], self::encode($row),
        ];
        if ($exists) {
            $this->run("UPDATE $table SET slug = ?, status = ?, published_at = ?, indexable = ?, sort_order = ?, data = ?
                WHERE type = ? AND source_id = ?", [...$values, $type, $row['id']]);
        } else {
            $this->run("INSERT INTO $table (type, source_id, slug, status, published_at, indexable, sort_order, data)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [$type, $row['id'], ...$values]);
        }
        return $exists ? 'updated' : 'added';
    }

    private function writeRoute(array $row): string
    {
        $exists = $this->existing('route', $row);
        $this->run('INSERT INTO ' . $this->table('cmteh_route') . ' (path, target, status) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE target = VALUES(target), status = VALUES(status)', [$row['path'], $row['target'], $row['status']]);
        return $exists ? 'updated' : 'added';
    }

    private function writeMedia(array $row): string
    {
        $exists = $this->existing('media', $row);
        $this->run('INSERT INTO ' . $this->table('cmteh_media') . ' (uuid, path, filename, mime, status) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE path = VALUES(path), filename = VALUES(filename), mime = VALUES(mime), status = VALUES(status)',
            [$row['uuid'], $row['path'], $row['filename'], $row['mime'], $row['status']]);
        $table = $this->table('cmteh_media_link');
        $this->run("DELETE FROM $table WHERE uuid = ?", [$row['uuid']]);
        foreach ($row['owners'] as $owner) {
            $this->run("INSERT IGNORE INTO $table (uuid, owner_type, owner_id) VALUES (?, ?, ?)", [$row['uuid'], $owner['type'], $owner['id']]);
        }
        return $exists ? 'updated' : 'added';
    }

    private function unpublish(string $id): string
    {
        $count = $this->run('UPDATE ' . $this->table('product') . ' p JOIN ' . $this->table('cmteh_product') .
            ' x USING (product_id) SET p.status = 0, p.date_modified = UTC_TIMESTAMP() WHERE x.source_id = ? AND p.status = 1', [$id])->rowCount();
        return $count ? 'updated' : 'skipped';
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, 32);
    }
}
