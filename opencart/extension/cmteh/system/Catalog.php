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
require_once __DIR__ . '/CatalogAdmin.php';
require_once __DIR__ . '/Inventory.php';

final class Catalog
{
    use CatalogWrites;
    use CatalogAdmin;

    public const AVAILABILITY = ['in_stock', 'on_request', 'out_of_stock'];
    public const PART_TYPES = ['original', 'oem', 'analog'];
    public const CONTENT_TYPES = ['site', 'home', 'navigation', 'page', 'article'];
    public const MEDIA_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
    ];

    /** @var array<string, PDOStatement> */
    private array $statements = [];
    private ?bool $inventoryReady = null;
    private array $offerProjection = [];

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
        $effectivePrice = $this->effectivePrice();
        $order = match ($query['sort'] ?? '') {
            'price_asc' => "($effectivePrice) IS NULL, ($effectivePrice) ASC",
            'price_desc' => "($effectivePrice) IS NULL, ($effectivePrice) DESC",
            'title' => 'd.name ASC',
            default => "public_image IS NULL AND p.image = '', p.sort_order ASC",
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
            $result['products'] = $this->productDtos($rows);
            return $result;
        });
    }

    /** Ordered exact-SKU batch. A bounded response never labels an unexamined row missing. */
    public function checkList(array $rows): array
    {
        if (!array_is_list($rows) || count($rows) < 1 || count($rows) > 1000
            || strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new InvalidArgumentException('Для проверки нужен список от 1 до 1000 строк, до 1 МБ.');
        }
        $needles = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['article'] ?? null)
                || trim($row['article']) === '' || mb_strlen($row['article']) > 128
                || preg_match('/[\x00-\x1F\x7F]/', $row['article'])
                || !is_int($row['quantity'] ?? null) || $row['quantity'] < 1 || $row['quantity'] > 100000) {
                throw new InvalidArgumentException('Проверьте артикулы и целое количество от 1 до 100 000.');
            }
            $needles[] = CatalogInput::normalize(trim($row['article']));
        }
        $keys = array_values(array_unique($needles));
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        // sku_lookup supports one indexed batch, independent of input row count and duplicates.
        $hits = $this->run($this->productSelect() . $this->productFrom()
            . ' WHERE ' . $this->publicProduct() . " AND x.sku_norm IN ($placeholders)"
            . ' ORDER BY x.sku_norm, x.product_id LIMIT 5001', $keys)->fetchAll(PDO::FETCH_ASSOC);
        $cut = count($hits) > 5000;
        if ($cut) array_pop($hits);
        $last = $cut ? $hits[array_key_last($hits)]['sku_norm'] : null;
        $groups = [];
        $projected = []; $perSku = [];
        foreach ($hits as $hit) {
            if (($perSku[$hit['sku_norm']] ?? 0) >= 5) continue;
            $perSku[$hit['sku_norm']] = ($perSku[$hit['sku_norm']] ?? 0) + 1;
            $projected[] = $hit['source_id'];
        }
        $this->offerProjection = $this->publicWarehouseOffers($projected);
        $counts = [];
        foreach ($hits as $hit) {
            $key = $hit['sku_norm'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            if (count($groups[$key] ?? []) >= 5) continue;
            $dto = $this->productDto($hit);
            $groups[$key][] = array_intersect_key($dto, array_flip([
                'id', 'slug', 'sku', 'title', 'brand', 'availability_status', 'inventory_managed', 'offers', 'offers_partial',
            ]));
        }
        $result = [];
        $partial = $cut;
        foreach ($rows as $index => $row) {
            $key = $needles[$index];
            $products = $groups[$key] ?? [];
            $more = ($counts[$key] ?? 0) > 5 || ($cut && strcmp($key, $last) >= 0);
            $partial = $partial || $more;
            $result[] = ['index' => $index, 'article' => trim($row['article']), 'quantity' => $row['quantity'],
                'status' => $more || count($products) > 1 ? 'ambiguous' : ($products ? 'matched' : 'not_found'),
                'products' => $products, 'more_matches' => $more];
        }
        return ['rows' => $result, 'partial' => $partial];
    }

    /** Canonical published snapshots for at most 100 explicitly chosen offers. */
    public function selectedProducts(array $ids): array
    {
        if (!$ids || count($ids) > 100) return [];
        $ids = array_values(array_unique($ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->run($this->productSelect() . $this->productFrom()
            . ' WHERE ' . $this->publicProduct() . " AND x.source_id IN ($placeholders) LIMIT 100", $ids)
            ->fetchAll(PDO::FETCH_ASSOC);
        return array_column($this->productDtos($rows), null, 'id');
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

        $this->offerProjection = $this->publicWarehouseOffers([$row['source_id']]);
        $product = $this->productDto($row);
        $details = self::decode($row['data']);
        $media = $this->approvedMedia('product', $row['source_id']);
        $product['codes'] = $details['codes'] ?? [];
        $product['images'] = array_values(array_filter($details['images'] ?? [], static fn(array $item): bool =>
            isset($media[$item['image']]) && str_starts_with($media[$item['image']], 'image/')));
        foreach ($details['native_gallery'] ?? [] as $image) {
            $url = self::publicImageUrl($image);
            if ($url !== null) {
                $product['images'][] = ['image_url' => $url, 'alt_text' => ''];
            }
        }
        $product['documents'] = array_values(array_filter($details['documents'] ?? [], static fn(array $item): bool =>
            isset($media[$item['file']]) && $media[$item['file']] === 'application/pdf'));
        $product['specification_items'] = $this->specifications((int) $row['product_id'], $details['specification_items'] ?? []);
        if (!empty($details['native_edited'])) {
            $product['specification_items'] = $this->mergeNativeSpecifications(
                (int) $row['product_id'], $product['specification_items'],
            );
        }
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
        $existingRelations = array_column(array_column($product['relations'], 'product'), 'id');
        foreach ($details['native_related'] ?? [] as $nativeId) {
            $related = $this->run(
                $this->productSelect() . $this->productFrom() . ' WHERE ' . $this->publicProduct() . ' AND x.product_id = ?',
                [(int) $nativeId],
            )->fetch(PDO::FETCH_ASSOC);
            if ($related && !in_array($related['source_id'], $existingRelations, true)) {
                $product['relations'][] = ['relation_type' => 'related', 'product' => $this->productDto($related)];
                $existingRelations[] = $related['source_id'];
            }
        }
        $description = self::nativeText($row['description'], $details);
        $product['full_description'] = $this->visibleHtml(CatalogInput::html($description), $media);
        return $product;
    }

    public function brands(): array
    {
        $sql = 'SELECT b.slug, b.data, m.name, m.image FROM ' . $this->table('cmteh_brand') . ' b
            JOIN ' . $this->table('manufacturer') . ' m USING (manufacturer_id)
            JOIN ' . $this->table('manufacturer_to_store') . ' s USING (manufacturer_id)
            WHERE b.status = 1 AND b.published_at <= UTC_TIMESTAMP() AND s.store_id = ? ORDER BY m.sort_order, m.manufacturer_id';
        return array_map(static function (array $row): array {
            $data = self::decode($row['data']);
            return array_replace($data, [
                'slug' => $row['slug'],
                'name' => self::nativeText($row['name'], $data),
                'image_url' => self::publicImageUrl($row['image']),
            ]);
        }, $this->run($sql, [$this->storeId])->fetchAll(PDO::FETCH_ASSOC));
    }

    public function categories(): array
    {
        $sql = 'SELECT c.category_id, c.image, x.source_id, x.slug, x.data, d.name, d.description, d.meta_title, d.meta_description,
                parent.slug AS parent_slug
            FROM ' . $this->table('cmteh_category') . ' x JOIN ' . $this->table('category') . ' c USING (category_id)
            JOIN ' . $this->table('category_description') . ' d USING (category_id)
            JOIN ' . $this->table('category_to_store') . ' s USING (category_id)
            LEFT JOIN ' . $this->table('cmteh_category') . ' parent ON parent.category_id = c.parent_id
            WHERE c.status = 1 AND x.published_at <= UTC_TIMESTAMP() AND d.language_id = ? AND s.store_id = ?
                AND ' . $this->publicCategoryAncestors('c') . '
            ORDER BY c.sort_order, c.category_id';
        return array_map(function (array $row): array {
            $media = $this->approvedMedia('category', $row['source_id']);
            $data = self::decode($row['data']);
            return array_replace($data, [
                'id' => $row['source_id'], 'slug' => $row['slug'], 'title' => self::nativeText($row['name'], $data),
                'parent_slug' => $row['parent_slug'],
                'description' => $this->visibleHtml(CatalogInput::html(self::nativeText($row['description'], $data)), $media),
                'seo_title' => self::nativeText($row['meta_title'], $data) ?: null,
                'seo_description' => self::nativeText($row['meta_description'], $data) ?: null,
                'image' => isset($media[$data['image'] ?? '']) ? $data['image'] : null,
                'image_url' => self::publicImageUrl($row['image']),
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
        return $this->productDtos($rows);
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
        foreach (['brand' => 'b.slug', 'availability' => $this->effectiveAvailability(), 'partType' => 'x.part_type'] as $key => $column) {
            if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                $where[] = "$column = ?";
                $params[] = $query[$key];
            }
        }
        if (is_string($query['category'] ?? null) && $query['category'] !== '') {
            $store = $this->storeId;
            $where[] = 'EXISTS (
                SELECT 1 FROM ' . $this->table('product_to_category') . ' member
                JOIN ' . $this->table('category') . ' assigned ON assigned.category_id = member.category_id AND assigned.status = 1
                JOIN ' . $this->table('category_to_store') . " assigned_store ON assigned_store.category_id = assigned.category_id
                    AND assigned_store.store_id = $store
                JOIN " . $this->table('cmteh_category') . ' assigned_map ON assigned_map.category_id = assigned.category_id
                    AND assigned_map.published_at <= UTC_TIMESTAMP()
                JOIN ' . $this->table('category_path') . ' assigned_path ON assigned_path.category_id = assigned.category_id
                JOIN ' . $this->table('cmteh_category') . ' selected ON selected.category_id = assigned_path.path_id
                WHERE member.product_id = p.product_id AND selected.slug = ? AND ' . $this->publicCategoryAncestors('assigned') . '
            )';
            $params[] = $query['category'];
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
        return 'SELECT x.*, p.price AS native_price, p.image AS native_image, p.date_modified, d.name, d.description,
            d.meta_title, d.meta_description, m.name AS brand_name, b.slug AS brand_slug, b.data AS brand_data,
            c.source_id AS category_source_id, c.slug AS category_slug, cd.name AS category_title,
            image.uuid AS public_image, ' . $this->effectiveAvailability() . ' AS effective_availability ';
    }

    private function productFrom(): string
    {
        $store = $this->storeId;
        $language = $this->languageId;
        return 'FROM ' . $this->table('cmteh_product') . ' x
            JOIN ' . $this->table('product') . ' p ON p.product_id = x.product_id
            JOIN ' . $this->table('product_description') . " d ON d.product_id = p.product_id AND d.language_id = $language
            JOIN " . $this->table('product_to_store') . " s ON s.product_id = p.product_id AND s.store_id = $store
            LEFT JOIN " . $this->table('manufacturer') . ' m ON m.manufacturer_id = p.manufacturer_id
            LEFT JOIN ' . $this->table('cmteh_brand') . ' b ON b.manufacturer_id = m.manufacturer_id AND b.status = 1 AND b.published_at <= UTC_TIMESTAMP()
            LEFT JOIN ' . $this->table('manufacturer_to_store') . " ms ON ms.manufacturer_id = m.manufacturer_id AND ms.store_id = $store
            LEFT JOIN " . $this->table('product_to_category') . ' pc ON pc.product_id = p.product_id AND pc.category_id = x.category_id
            LEFT JOIN ' . $this->table('category') . ' nc ON nc.category_id = pc.category_id AND nc.status = 1
            LEFT JOIN ' . $this->table('category_to_store') . " cs ON cs.category_id = nc.category_id AND cs.store_id = $store
            LEFT JOIN " . $this->table('cmteh_category') . ' c ON c.category_id = cs.category_id AND c.published_at <= UTC_TIMESTAMP()
                AND ' . $this->publicCategoryAncestors('nc') . '
            LEFT JOIN ' . $this->table('category_description') . " cd ON cd.category_id = c.category_id AND cd.language_id = $language
            LEFT JOIN " . $this->table('cmteh_media_link') . " imlink
                ON imlink.uuid = x.main_image AND imlink.owner_type = 'product' AND imlink.owner_id = x.source_id
            LEFT JOIN " . $this->table('cmteh_media') . " image
                ON image.uuid = imlink.uuid AND image.status = 1 AND image.mime LIKE 'image/%'";
    }

    private function publicProduct(): string
    {
        return 'p.status = 1 AND p.date_available <= UTC_DATE() AND x.published_at <= UTC_TIMESTAMP()
            AND (p.manufacturer_id = 0 OR (b.manufacturer_id IS NOT NULL AND ms.manufacturer_id IS NOT NULL))';
    }

    private function publicCategoryAncestors(string $categoryAlias): string
    {
        $store = $this->storeId;
        return 'NOT EXISTS (
            SELECT 1 FROM ' . $this->table('category_path') . ' ancestors
            LEFT JOIN ' . $this->table('category') . ' ancestor ON ancestor.category_id = ancestors.path_id
            LEFT JOIN ' . $this->table('category_to_store') . " ancestor_store ON ancestor_store.category_id = ancestors.path_id
                AND ancestor_store.store_id = $store
            LEFT JOIN " . $this->table('cmteh_category') . ' ancestor_map ON ancestor_map.category_id = ancestors.path_id
                AND ancestor_map.published_at <= UTC_TIMESTAMP()
            WHERE ancestors.category_id = ' . $categoryAlias . '.category_id
                AND (ancestor.status IS NULL OR ancestor.status <> 1 OR ancestor_store.category_id IS NULL
                    OR ancestor_map.category_id IS NULL)
        )';
    }

    private function productDto(array $row): array
    {
        $data = self::decode($row['data']);
        $price = (float) $row['native_price'];
        $projection = $this->offerProjection[$row['source_id']] ?? $this->publicWarehouseOffers([$row['source_id']])[$row['source_id']] ?? ['managed'=>false,'offers'=>[],'partial'=>false];
        $managed = $projection['managed'];
        $priceStatus = $managed ? 'warehouse' : $row['price_status'];
        $availability = $row['effective_availability'];
        if ($priceStatus === 'fixed' && (!is_finite($price) || $price < 0
            || ($price == 0 && ($data['confirm_zero_price'] ?? false) !== true)
            || !preg_match('/^[A-Z]{3}$/D', $row['currency'] ?? ''))) {
            $priceStatus = 'on_request';
        }
        return [
            'id' => $row['source_id'], 'slug' => $row['slug'], 'title' => self::nativeText($row['name'], $data),
            'sku' => $row['sku'], 'mpn' => $row['mpn'],
            'brand' => self::nativeText($row['brand_name'] ?? '', isset($row['brand_data']) ? self::decode($row['brand_data']) : []),
            'brand_slug' => $row['brand_slug'] ?? '',
            'short_description' => $data['short_description'] ?? null, 'full_description' => null,
            'price' => $priceStatus === 'fixed' ? $price : null,
            'currency' => $row['currency'], 'price_status' => $priceStatus,
            'inventory_managed' => $managed, 'offers' => $projection['offers'], 'offers_partial' => $projection['partial'],
            'availability_status' => $availability, 'part_type' => $row['part_type'],
            'main_image' => $row['public_image'],
            'main_image_url' => self::publicImageUrl($row['native_image']),
            'category' => $row['category_slug'] !== null ? [
                'id' => $row['category_source_id'], 'slug' => $row['category_slug'], 'title' => $row['category_title'],
            ] : null,
            'specifications' => $data['specifications'] ?? null, 'delivery_status' => $data['delivery_status'] ?? null,
            'seo_title' => self::nativeText($row['meta_title'], $data) ?: null,
            'seo_description' => self::nativeText($row['meta_description'], $data) ?: null,
            'canonical_url' => $data['canonical_url'] ?? null,
            'is_indexable' => (bool) $row['indexable'], 'date_updated' => str_replace(' ', 'T', $row['date_modified']) . 'Z',
        ];
    }

    /** Bounded public source IDs; Inventory owns the DTO allowlist and publication rules. */
    public function publicWarehouseOffers(array $ids): array
    {
        if (!$ids || !$this->hasInventory()) return [];
        $inventory = new Inventory($this->pdo, $this->prefix, $this->storeId, $this->languageId);
        $result = []; $remaining = 10000;
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $batch) {
            foreach ($inventory->publicOffers($batch) as $id => $projection) {
                if (count($projection['offers']) > $remaining) {
                    $projection['offers'] = array_slice($projection['offers'], 0, $remaining);
                    $projection['partial'] = true;
                }
                $remaining -= count($projection['offers']);
                $result[$id] = $projection;
            }
        }
        return $result;
    }

    private function productDtos(array $rows): array
    {
        $this->offerProjection = $this->publicWarehouseOffers(array_column($rows, 'source_id'));
        return array_map($this->productDto(...), $rows);
    }

    private function hasInventory(): bool
    {
        if ($this->inventoryReady !== null) return $this->inventoryReady;
        $query = $this->run('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$this->prefix . 'cmteh_inventory_product']);
        return $this->inventoryReady = (int) $query->fetchColumn() === 1;
    }

    private function effectiveAvailability(): string
    {
        if (!$this->hasInventory()) return 'x.availability';
        return "(CASE WHEN EXISTS (SELECT 1 FROM " . $this->table('cmteh_inventory_product') . " ip WHERE ip.product_id=p.product_id)
            THEN CASE WHEN EXISTS (SELECT 1 FROM " . $this->table('cmteh_inventory_balance') . " ib JOIN " . $this->table('cmteh_warehouse') . " iw ON iw.warehouse_id=ib.warehouse_id
                WHERE ib.product_id=p.product_id AND ib.published=1 AND iw.active=1 AND ib.on_hand>ib.reserved)
                THEN 'in_stock' ELSE 'out_of_stock' END ELSE x.availability END)";
    }

    private function effectivePrice(): string
    {
        $legacy = "CASE WHEN x.price_status='fixed' THEN p.price ELSE NULL END";
        if (!$this->hasInventory()) return $legacy;
        return "CASE WHEN EXISTS (SELECT 1 FROM " . $this->table('cmteh_inventory_product') . " ip WHERE ip.product_id=p.product_id)
            THEN (SELECT MIN(ib.price) FROM " . $this->table('cmteh_inventory_balance') . " ib JOIN " . $this->table('cmteh_warehouse') . " iw ON iw.warehouse_id=ib.warehouse_id
                WHERE ib.product_id=p.product_id AND ib.published=1 AND iw.active=1) ELSE ($legacy) END";
    }

    private function mergeNativeSpecifications(int $productId, array $items): array
    {
        $known = [];
        foreach ($items as $item) {
            $known[$item['group_name'] . "\\0" . $item['name']] = true;
        }
        $query = 'SELECT ad.name, gd.name AS group_name, p.text AS value
            FROM ' . $this->table('product_attribute') . ' p
            JOIN ' . $this->table('attribute') . ' a USING (attribute_id)
            JOIN ' . $this->table('attribute_description') . ' ad ON ad.attribute_id = a.attribute_id AND ad.language_id = p.language_id
            LEFT JOIN ' . $this->table('attribute_group_description') . ' gd
                ON gd.attribute_group_id = a.attribute_group_id AND gd.language_id = p.language_id
            WHERE p.product_id = ? AND p.language_id = ? ORDER BY a.sort_order, a.attribute_id LIMIT 100';
        foreach ($this->run($query, [$productId, $this->languageId])->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $name = html_entity_decode($item['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $group = html_entity_decode($item['group_name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!isset($known[$group . "\\0" . $name])) {
                $items[] = [
                    'name' => $name, 'group_name' => $group,
                    'value' => html_entity_decode($item['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
        }
        return $items;
    }

    private static function nativeText(string $value, array $data): string
    {
        return !empty($data['native_edited']) ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
    }

    private static function publicImageUrl(mixed $value): ?string
    {
        $relative = CatalogInput::publicImagePath($value);
        if ($relative === null || !defined('DIR_IMAGE')) {
            return null;
        }
        $imageRoot = realpath(DIR_IMAGE);
        $catalog = realpath(DIR_IMAGE . 'catalog');
        $file = realpath(DIR_IMAGE . $relative);
        if ($imageRoot === false || $catalog === false || $file === false
            || !str_starts_with($catalog, $imageRoot . DIRECTORY_SEPARATOR)
            || !str_starts_with($file, $catalog . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        return in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) ? '/image/' . $relative : null;
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
                WHERE x.source_id = ? AND c.status = 1 AND x.published_at <= UTC_TIMESTAMP() AND s.store_id = ?
                    AND ' . $this->publicCategoryAncestors('c'), [$id, $this->storeId])->fetchColumn();
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
