<?php

declare(strict_types=1);

namespace Cmteh;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

require_once __DIR__ . '/CatalogSchema.php';
require_once __DIR__ . '/CatalogInput.php';
require_once __DIR__ . '/CatalogWrites.php';

final class Catalog
{
    use CatalogWrites;

    public const AVAILABILITY = ['in_stock', 'on_request', 'out_of_stock'];
    public const PART_TYPES = ['original', 'oem', 'analog'];
    public const CONTENT_TYPES = ['site', 'home', 'navigation', 'page', 'article'];
    public const MEDIA_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
    ];

    /** @var array<string, PDOStatement> */
    private array $statements = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly int $languageId = 1,
        private readonly int $storeId = 0,
        private readonly ?string $privateDir = null,
    ) {
        if (!preg_match('/^[a-zA-Z0-9_]{0,32}$/D', $prefix)) {
            throw new InvalidArgumentException('Invalid database prefix');
        }
    }

    public function migrate(): void
    {
        CatalogSchema::migrate($this->pdo, $this->prefix);
    }

    public function products(array $query): array
    {
        $limit = max(1, min(100, (int) ($query['limit'] ?? 12)));
        $page = max(1, min(intdiv(1000000, $limit) + 1, (int) ($query['page'] ?? 1)));
        $result = ['products' => [], 'total' => 0, 'page' => $page, 'limit' => $limit, 'partial' => false];
        $q = is_string($query['q'] ?? null) ? trim($query['q']) : '';
        $needle = CatalogInput::normalize($q);
        if ($q !== '' && (mb_strlen($q) < 2 || mb_strlen($q) > 64 || mb_strlen($needle) < 2)) {
            return $result;
        }

        [$where, $params] = $this->productFilters($query, $needle);
        $rankParams = [];
        $order = match ($query['sort'] ?? '') {
            'price_asc' => "x.price_status <> 'fixed', p.price ASC",
            'price_desc' => "x.price_status <> 'fixed', p.price DESC",
            'title' => 'd.name ASC',
            default => 'public_image IS NULL, p.sort_order ASC',
        };
        if ($needle !== '' && empty($query['sort'])) {
            $order = 'CASE WHEN x.sku_norm = ? THEN 0 WHEN x.mpn_norm = ? THEN 1
                      WHEN x.sku_norm LIKE ? OR x.mpn_norm LIKE ? THEN 2 ELSE 3 END';
            $rankParams = [$needle, $needle, $needle . '%', $needle . '%'];
        }

        $offset = ($page - 1) * $limit;
        return $this->atomic(function () use ($result, $where, $params, $rankParams, $limit, $offset, $order): array {
            $base = $this->productFrom() . " WHERE $where";
            $result['total'] = (int) $this->run('SELECT COUNT(*) ' . $base, $params)->fetchColumn();
            $rows = $this->run(
                $this->productSelect() . $base . " ORDER BY $order, p.product_id LIMIT $limit OFFSET $offset",
                array_merge($params, $rankParams)
            )->fetchAll(PDO::FETCH_ASSOC);
            $result['products'] = array_map($this->productDto(...), $rows);
            return $result;
        });
    }

    public function explain(array $query): array
    {
        [$where, $params] = $this->productFilters($query, CatalogInput::normalize((string) ($query['q'] ?? '')));
        return $this->run('EXPLAIN SELECT x.product_id ' . $this->productFrom() . " WHERE $where LIMIT 12", $params)
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function product(string $slug): ?array
    {
        $row = $this->run(
            $this->productSelect() . $this->productFrom() . ' WHERE ' . $this->publicProduct() . ' AND x.slug = ?',
            [$slug]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $product = $this->productDto($row);
        $details = self::decode($row['data']);
        $media = $this->approvedMedia('product', $row['source_id']);
        $product['codes'] = $details['codes'] ?? [];
        $product['images'] = array_values(array_filter($details['images'] ?? [], static fn(array $item): bool =>
            isset($media[$item['image']]) && str_starts_with($media[$item['image']], 'image/')));
        $product['documents'] = array_values(array_filter($details['documents'] ?? [], static fn(array $item): bool =>
            isset($media[$item['file']]) && $media[$item['file']] === 'application/pdf'));
        $product['specification_items'] = $this->specifications((int) $row['product_id'], $details['specification_items'] ?? []);
        $product['specifications'] = array_column($product['specification_items'], 'value', 'name');
        $product['relations'] = [];
        foreach ($details['relations'] ?? [] as $relation) {
            $related = $this->run(
                $this->productSelect() . $this->productFrom() . ' WHERE ' . $this->publicProduct() . ' AND x.source_id = ?',
                [$relation['source_id']]
            )->fetch(PDO::FETCH_ASSOC);
            if ($related) {
                $product['relations'][] = ['relation_type' => $relation['relation_type'], 'product' => $this->productDto($related)];
            }
        }
        $product['full_description'] = $this->visibleHtml(CatalogInput::html($row['description']), $media);
        return $product;
    }

    public function brands(): array
    {
        $sql = 'SELECT b.slug, b.data, m.name FROM ' . $this->table('cmteh_brand') . ' b
            JOIN ' . $this->table('manufacturer') . ' m USING (manufacturer_id)
            JOIN ' . $this->table('manufacturer_to_store') . ' s USING (manufacturer_id)
            WHERE b.status = 1 AND b.published_at <= UTC_TIMESTAMP() AND s.store_id = ? ORDER BY m.sort_order, m.manufacturer_id';
        return array_map(static fn(array $row): array => array_replace(
            self::decode($row['data']), ['slug' => $row['slug'], 'name' => $row['name']]
        ), $this->run($sql, [$this->storeId])->fetchAll(PDO::FETCH_ASSOC));
    }

    public function categories(): array
    {
        $sql = 'SELECT c.category_id, x.source_id, x.slug, x.data, d.name, d.description, d.meta_title, d.meta_description
            FROM ' . $this->table('cmteh_category') . ' x JOIN ' . $this->table('category') . ' c USING (category_id)
            JOIN ' . $this->table('category_description') . ' d USING (category_id)
            JOIN ' . $this->table('category_to_store') . ' s USING (category_id)
            WHERE c.status = 1 AND x.published_at <= UTC_TIMESTAMP() AND d.language_id = ? AND s.store_id = ? ORDER BY c.sort_order, c.category_id';
        return array_map(function (array $row): array {
            $media = $this->approvedMedia('category', $row['source_id']);
            $data = self::decode($row['data']);
            return array_replace($data, [
                'id' => $row['source_id'], 'slug' => $row['slug'], 'title' => $row['name'],
                'description' => $this->visibleHtml(CatalogInput::html($row['description']), $media),
                'seo_title' => $row['meta_title'] ?: null, 'seo_description' => $row['meta_description'] ?: null,
                'image' => isset($media[$data['image'] ?? '']) ? $data['image'] : null,
            ]);
        }, $this->run($sql, [$this->languageId, $this->storeId])->fetchAll(PDO::FETCH_ASSOC));
    }

    public function content(string $type, ?string $slug = null, int $page = 1): ?array
    {
        $from = ' FROM ' . $this->table('cmteh_content')
            . ' WHERE type = ? AND status = 1 AND published_at <= UTC_TIMESTAMP()';
        if ($type === 'articles' || $type === 'pages') {
            $params = [$type === 'articles' ? 'article' : 'page'];
            if ($type === 'pages') {
                $from .= ' AND indexable = 1';
            }
            $page = max(1, min(83334, $page));
            $offset = ($page - 1) * 12;
            $rows = $this->run('SELECT *' . $from . " ORDER BY published_at DESC, source_id LIMIT 12 OFFSET $offset", $params)
                ->fetchAll(PDO::FETCH_ASSOC);
            return [
                'items' => array_map($this->contentDto(...), $rows),
                'total' => (int) $this->run('SELECT COUNT(*)' . $from, $params)->fetchColumn(),
                'page' => $page, 'limit' => 12,
            ];
        }
        if (!in_array($type, self::CONTENT_TYPES, true)) {
            return null;
        }
        if ($type === 'navigation') {
            $rows = $this->run('SELECT *' . $from . ' ORDER BY sort_order, source_id LIMIT 300', [$type])->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map($this->contentDto(...), $rows);
            return $slug === null ? $items : array_values(array_filter($items, static fn(array $item): bool => $item['location'] === $slug));
        }
        if ($slug === null) {
            $slug = in_array($type, ['site', 'home'], true) ? $type : '';
        }
        $row = $this->run('SELECT *' . $from . ' AND slug = ?', [$type, $slug])->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->contentDto($row) : null;
    }

    public function routes(string $path): ?array
    {
        $row = $this->run('SELECT status, target FROM ' . $this->table('cmteh_route') . ' WHERE path = ?', [$path])
            ->fetch(PDO::FETCH_ASSOC);
        return $row ? ['status' => (int) $row['status'], 'target' => $row['target']] : null;
    }

    public function sitemap(int $offset, int $limit): array
    {
        $offset = max(0, $offset);
        $limit = max(1, min(1000, $limit));
        $rows = $this->run(
            $this->productSelect() . $this->productFrom() . ' WHERE ' . $this->publicProduct() .
            " AND x.indexable = 1 ORDER BY p.product_id LIMIT $limit OFFSET $offset"
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->productDto(...), $rows);
    }

    public function indexableCount(): int
    {
        return (int) $this->run('SELECT COUNT(*) ' . $this->productFrom() . ' WHERE ' . $this->publicProduct() . ' AND x.indexable = 1')
            ->fetchColumn();
    }

    public function asset(string $uuid): ?array
    {
        if (CatalogInput::uuid($uuid) === null) {
            return null;
        }
        $uuid = strtolower($uuid);
        $media = $this->run('SELECT path, filename, mime FROM ' . $this->table('cmteh_media') .
            ' WHERE uuid = ? AND status = 1', [$uuid])->fetch(PDO::FETCH_ASSOC);
        if (!$media) {
            return null;
        }
        $links = $this->run('SELECT owner_type, owner_id FROM ' . $this->table('cmteh_media_link') .
            ' WHERE uuid = ?', [$uuid])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($links as $link) {
            if ($this->isPublicOwner($link['owner_type'], $link['owner_id'])) {
                $path = $this->resolveMedia($media['path'], $media['mime']);
                return $path === null ? null : array_replace($media, ['path' => $path]);
            }
        }
        return null;
    }

    private function productFilters(array $query, string $needle): array
    {
        $where = [$this->publicProduct()];
        $params = [];
        foreach (['brand' => 'b.slug', 'category' => 'c.slug', 'availability' => 'x.availability', 'partType' => 'x.part_type'] as $key => $column) {
            if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                $where[] = "$column = ?";
                $params[] = $query[$key];
            }
        }
        if ($needle !== '') {
            $where[] = '(x.product_id IN (
                SELECT sku_hits.product_id FROM ' . $this->table('cmteh_product') . ' sku_hits WHERE sku_hits.sku_norm LIKE ?
                UNION DISTINCT SELECT mpn_hits.product_id FROM ' . $this->table('cmteh_product') . ' mpn_hits WHERE mpn_hits.mpn_norm LIKE ?
            ) OR x.name_norm LIKE ? OR x.brand_norm LIKE ?)';
            array_push($params, $needle . '%', $needle . '%', '%' . $needle . '%', '%' . $needle . '%');
        }
        return [implode(' AND ', $where), $params];
    }

    private function productSelect(): string
    {
        return 'SELECT x.*, p.price AS native_price, p.date_modified, d.name, d.description,
            d.meta_title, d.meta_description, m.name AS brand_name, b.slug AS brand_slug,
            c.source_id AS category_source_id, c.slug AS category_slug, cd.name AS category_title,
            image.uuid AS public_image ';
    }

    private function productFrom(): string
    {
        $store = $this->storeId;
        $language = $this->languageId;
        return 'FROM ' . $this->table('cmteh_product') . ' x
            JOIN ' . $this->table('product') . ' p ON p.product_id = x.product_id
            JOIN ' . $this->table('product_description') . " d ON d.product_id = p.product_id AND d.language_id = $language
            JOIN " . $this->table('product_to_store') . " s ON s.product_id = p.product_id AND s.store_id = $store
            JOIN " . $this->table('manufacturer') . ' m ON m.manufacturer_id = p.manufacturer_id
            JOIN ' . $this->table('cmteh_brand') . ' b ON b.manufacturer_id = m.manufacturer_id AND b.status = 1 AND b.published_at <= UTC_TIMESTAMP()
            JOIN ' . $this->table('manufacturer_to_store') . " ms ON ms.manufacturer_id = m.manufacturer_id AND ms.store_id = $store
            LEFT JOIN " . $this->table('product_to_category') . ' pc ON pc.product_id = p.product_id AND pc.category_id = x.category_id
            LEFT JOIN ' . $this->table('category') . ' nc ON nc.category_id = pc.category_id AND nc.status = 1
            LEFT JOIN ' . $this->table('category_to_store') . " cs ON cs.category_id = nc.category_id AND cs.store_id = $store
            LEFT JOIN " . $this->table('cmteh_category') . ' c ON c.category_id = cs.category_id AND c.published_at <= UTC_TIMESTAMP()
            LEFT JOIN ' . $this->table('category_description') . " cd ON cd.category_id = c.category_id AND cd.language_id = $language
            LEFT JOIN " . $this->table('cmteh_media_link') . " imlink
                ON imlink.uuid = x.main_image AND imlink.owner_type = 'product' AND imlink.owner_id = x.source_id
            LEFT JOIN " . $this->table('cmteh_media') . " image
                ON image.uuid = imlink.uuid AND image.status = 1 AND image.mime LIKE 'image/%'";
    }

    private function publicProduct(): string
    {
        return 'p.status = 1 AND p.date_available <= UTC_DATE() AND x.published_at <= UTC_TIMESTAMP()';
    }

    private function productDto(array $row): array
    {
        $data = self::decode($row['data']);
        $price = (float) $row['native_price'];
        $priceStatus = $row['price_status'];
        if ($priceStatus === 'fixed' && (!is_finite($price) || $price < 0
            || ($price == 0 && ($data['confirm_zero_price'] ?? false) !== true)
            || !preg_match('/^[A-Z]{3}$/D', $row['currency'] ?? ''))) {
            $priceStatus = 'on_request';
        }
        return [
            'id' => $row['source_id'], 'slug' => $row['slug'], 'title' => $row['name'],
            'sku' => $row['sku'], 'mpn' => $row['mpn'], 'brand' => $row['brand_name'], 'brand_slug' => $row['brand_slug'],
            'short_description' => $data['short_description'] ?? null, 'full_description' => null,
            'price' => $priceStatus === 'fixed' ? $price : null,
            'currency' => $row['currency'], 'price_status' => $priceStatus,
            'availability_status' => $row['availability'], 'part_type' => $row['part_type'],
            'main_image' => $row['public_image'],
            'category' => $row['category_slug'] !== null ? [
                'id' => $row['category_source_id'], 'slug' => $row['category_slug'], 'title' => $row['category_title'],
            ] : null,
            'specifications' => $data['specifications'] ?? null, 'delivery_status' => $data['delivery_status'] ?? null,
            'seo_title' => $row['meta_title'] ?: null, 'seo_description' => $row['meta_description'] ?: null,
            'canonical_url' => $data['canonical_url'] ?? null,
            'is_indexable' => (bool) $row['indexable'], 'date_updated' => str_replace(' ', 'T', $row['date_modified']) . 'Z',
        ];
    }

    private function contentDto(array $row): array
    {
        $data = self::decode($row['data']);
        $media = $this->approvedMedia($row['type'], $row['source_id']);
        $data = $this->visibleContent($data, $media);
        return array_replace($data, [
            'id' => $row['source_id'], 'slug' => $row['slug'],
            'is_indexable' => (bool) $row['indexable'],
            'published_at' => str_replace(' ', 'T', $row['published_at']) . 'Z',
        ]);
    }

    private function visibleContent(array $data, array $media): array
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = $this->visibleContent($value, $media);
            } elseif (in_array($key, ['image', 'cover_image', 'og_image', 'hero_image'], true)) {
                $value = is_string($value) && isset($media[$value]) && str_starts_with($media[$value], 'image/') ? $value : null;
            } elseif (is_string($value) && in_array($key, ['text', 'content', 'seo_text', 'description'], true)) {
                $value = $this->visibleHtml($value, $media);
            }
        }
        return $data;
    }

    private function visibleHtml(string $html, array $media): string
    {
        $html = preg_replace_callback('~ href="([^"]*)"~i', static function (array $match) use ($media): string {
            $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
            if (preg_match('~^/api/assets/([0-9a-f-]{36})/?$~iD', $path, $asset)
                && !isset($media[strtolower($asset[1])])) {
                return '';
            }
            return $match[0];
        }, $html) ?? '';
        return preg_replace_callback('~<img\b[^>]*\bsrc="/api/assets/([0-9a-f-]{36})"[^>]*>~i',
            static fn(array $match): string => isset($media[strtolower($match[1])]) && str_starts_with($media[strtolower($match[1])], 'image/') ? $match[0] : '',
            $html
        ) ?? '';
    }

    private function approvedMedia(string $type, string $id): array
    {
        $sql = 'SELECT m.uuid, m.mime FROM ' . $this->table('cmteh_media_link') . ' l
            JOIN ' . $this->table('cmteh_media') . ' m USING (uuid)
            WHERE l.owner_type = ? AND l.owner_id = ? AND m.status = 1';
        return $this->run($sql, [$type, $id])->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private function isPublicOwner(string $type, string $id): bool
    {
        if ($type === 'product') {
            return (bool) $this->run('SELECT 1 ' . $this->productFrom() . ' WHERE ' . $this->publicProduct() . ' AND x.source_id = ?', [$id])->fetchColumn();
        }
        if ($type === 'category') {
            return (bool) $this->run('SELECT 1 FROM ' . $this->table('cmteh_category') . ' x
                JOIN ' . $this->table('category') . ' c USING (category_id)
                JOIN ' . $this->table('category_to_store') . ' s USING (category_id)
                WHERE x.source_id = ? AND c.status = 1 AND x.published_at <= UTC_TIMESTAMP() AND s.store_id = ?', [$id, $this->storeId])->fetchColumn();
        }
        return (bool) $this->run('SELECT 1 FROM ' . $this->table('cmteh_content') .
            ' WHERE type = ? AND source_id = ? AND status = 1 AND published_at <= UTC_TIMESTAMP()', [$type, $id])->fetchColumn();
    }

    private function resolveMedia(string $relative, string $mime): ?string
    {
        if ($this->privateDir === null || !in_array($mime, self::MEDIA_TYPES, true)) {
            return null;
        }
        $root = realpath($this->privateDir . '/media');
        $path = $root === false ? false : realpath($root . '/' . $relative);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }
        return (new \finfo(FILEINFO_MIME_TYPE))->file($path) === $mime ? $path : null;
    }

    private function table(string $name): string
    {
        return "`{$this->prefix}{$name}`";
    }

    private function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->statements[$sql] ??= $this->pdo->prepare($sql);
        $statement->closeCursor();
        $statement->execute($params);
        return $statement;
    }

    private function atomic(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    private static function decode(string $value): array
    {
        return json_decode($value, true, 32, JSON_THROW_ON_ERROR);
    }
}
