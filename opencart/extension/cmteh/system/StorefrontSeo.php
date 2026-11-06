<?php

declare(strict_types=1);

namespace Cmteh;

final class StorefrontSeo
{
    private const CONTEXT = 'https://schema.org';
    private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const CHUNK = 1000;

    public function __construct(private readonly string $origin, private readonly Catalog $catalog)
    {
    }

    public static function path(string $raw): ?string
    {
        if (strlen($raw) > 6144 || preg_match('/%(?![0-9a-f]{2})|%(?:2f|5c)/i', $raw)) {
            return null;
        }
        $path = rawurldecode($raw);
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        return self::validPath($path) ? $path : null;
    }

    public static function validPath(string $path): bool
    {
        return strlen($path) <= 2048
            && preg_match('~^/(?:[\p{L}\p{N}_.-]+(?:/[\p{L}\p{N}_.-]+)*)?$~uD', $path) === 1
            && !preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path);
    }

    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public function url(string $path): string
    {
        return $this->origin . self::encodePath($path);
    }

    public function canonical(string $path, mixed $candidate = null): string
    {
        $fallback = $this->url($path);
        if (!is_string($candidate) || $candidate === '' || preg_match('~[\x00-\x20\x7f\\\\]~', $candidate)
            || str_starts_with($candidate, '//')) {
            return $fallback;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts) || isset($parts['fragment']) || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return $fallback;
        }
        $local = str_starts_with($candidate, '/');
        if (!$local && (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']))) {
            return $fallback;
        }
        $requested = self::path($parts['path'] ?? '/');
        if ($requested === null) {
            return $fallback;
        }
        $base = $local ? $this->origin : 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $base . self::encodePath($requested) . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public function assetUrl(mixed $uuid): ?string
    {
        if (!is_string($uuid) || CatalogInput::uuid($uuid) === null || $this->catalog->asset($uuid) === null) {
            return null;
        }

        return $this->url('/api/assets/' . strtolower($uuid));
    }

    public static function text(mixed $value): string
    {
        return is_string($value) ? trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    public function organization(array $site): array
    {
        if (empty($site['legal_name']) && empty($site['company_name'])) {
            return [];
        }
        $url = $this->url('/');

        return $this->entity('Organization', [
            '@id' => $url . '#organization', 'url' => $url,
            'name' => ($site['legal_name'] ?? '') ?: $site['company_name'],
            'logo' => !empty($site['logo_url']) ? $this->url($site['logo_url']) : null,
            'email' => $site['email'] ?? null, 'telephone' => $site['phone'] ?? null,
            'address' => ($site['legal_address'] ?? '') ?: ($site['address'] ?? null),
            'taxID' => $site['inn'] ?? null,
        ]);
    }

    public function breadcrumbs(array $parts): array
    {
        $items = [];
        foreach ($parts as [$name, $url]) {
            $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $name, 'item' => $url];
        }

        return $this->entity('BreadcrumbList', ['itemListElement' => $items]);
    }

    public function product(array $product, string $heading, string $url, ?string $image): array
    {
        $specifications = [];
        foreach ($product['specification_items'] ?? [] as $item) {
            $specifications[] = [
                '@type' => 'PropertyValue', 'name' => $item['name'],
                'value' => trim($item['value'] . ' ' . ($item['unit'] ?? '')),
            ];
        }
        if ($specifications === []) {
            foreach ($product['specifications'] ?? [] as $name => $value) {
                $specifications[] = ['@type' => 'PropertyValue', 'name' => $name, 'value' => $value];
            }
        }

        $entity = $this->entity('Product', [
            '@id' => $url . '#product', 'url' => $url, 'name' => $heading, 'sku' => $product['sku'],
            'mpn' => $product['mpn'] ?? null,
            'brand' => empty($product['brand_slug']) || empty($product['brand']) ? null
                : ['@type' => 'Brand', '@id' => $this->url('/brand/' . $product['brand_slug']) . '#brand', 'name' => $product['brand']],
            'description' => self::text(($product['short_description'] ?? '') ?: ($product['full_description'] ?? '')),
            'image' => $image === null ? null : [$image],
            'additionalProperty' => $specifications,
        ]);

        $price = $product['price'];
        $currency = $product['currency'] ?? '';
        if ($product['price_status'] === 'fixed' && (is_int($price) || is_float($price))
            && is_finite((float) $price) && $price >= 0 && preg_match('/^[A-Z]{3}$/D', $currency)) {
            $offer = [
                '@type' => 'Offer', '@id' => $url . '#offer', 'url' => $url,
                'priceCurrency' => $currency, 'price' => $price,
            ];
            $availability = match ($product['availability_status']) {
                'in_stock' => 'InStock',
                'out_of_stock' => 'OutOfStock',
                default => null,
            };
            if ($availability !== null) {
                $offer['availability'] = self::CONTEXT . '/' . $availability;
            }
            $entity['offers'] = $offer;
        }

        return $entity;
    }

    public function collection(array $record, array $products, string $type, string $url): array
    {
        $name = $record['h1'] ?? ($type === 'brand' ? $record['name'] : $record['title']);
        $description = self::text(($record['intro'] ?? '') ?: ($record['description'] ?? ''));
        $items = [];
        foreach ($products as $product) {
            $itemUrl = $this->canonical('/product/' . $product['slug'], $product['canonical_url']);
            $items[] = [
                '@type' => 'ListItem', 'position' => count($items) + 1,
                'item' => ['@id' => $itemUrl . '#product', 'name' => $product['title'], 'url' => $itemUrl],
            ];
        }
        $page = $this->entity('CollectionPage', [
            '@id' => $url . '#collection', 'url' => $url, 'name' => $name,
            'description' => $description,
            'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items],
        ]);
        if ($type !== 'brand') {
            return $page;
        }
        unset($page['@context']);

        return ['@context' => self::CONTEXT, '@graph' => [[
            '@type' => 'Brand', '@id' => $url . '#brand', 'name' => $name, 'description' => $description,
        ], $page]];
    }

    public function article(array $article, array $site, string $url): array
    {
        return $this->entity('Article', [
            '@id' => $url . '#article', 'url' => $url, 'mainEntityOfPage' => $url,
            'headline' => $article['title'], 'description' => self::text($article['excerpt'] ?? ''),
            'datePublished' => $article['published_at'] ?? null,
            'author' => empty($article['author']) ? null : ['@type' => 'Person', 'name' => $article['author']],
            'publisher' => $this->organization($site) === [] ? null : ['@id' => $this->url('/') . '#organization'],
            'image' => !empty($article['cover_url'])
                ? $this->url($article['cover_url']) : $this->assetUrl($article['cover_image'] ?? null),
        ]);
    }

    private function entity(string $type, array $fields): array
    {
        return ['@context' => self::CONTEXT, '@type' => $type] + array_filter(
            $fields, static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []
        );
    }

    public function sitemap(string $path): ?string
    {
        if ($path === '/sitemap.xml') {
            $nodes = [['/sitemaps/static.xml', null]];
            $count = $this->catalog->indexableCount();
            for ($i = 0; $i * self::CHUNK < $count; $i++) {
                $nodes[] = ["/sitemaps/products-$i.xml", null];
            }
            return $this->xml('sitemapindex', 'sitemap', $nodes);
        }
        if ($path === '/sitemaps/static.xml') {
            return $this->xml('urlset', 'url', $this->staticUrls());
        }
        if (!preg_match('~^/sitemaps/products-(0|[1-9][0-9]{0,8})\.xml$~D', $path, $matches)) {
            return null;
        }
        $offset = (int) $matches[1] * self::CHUNK;
        if ($offset >= $this->catalog->indexableCount()) {
            return null;
        }
        $nodes = [];
        foreach ($this->catalog->sitemap($offset, self::CHUNK) as $product) {
            $route = '/product/' . $product['slug'];
            if ($this->indexable($route, $product)) {
                $nodes[] = [$route, $product['date_updated']];
            }
        }

        return $this->xml('urlset', 'url', $nodes);
    }

    private function indexable(string $path, array $record): bool
    {
        return ($record['is_indexable'] ?? true)
            && $this->canonical($path, $record['canonical_url'] ?? null) === $this->url($path);
    }

    private function staticUrls(): array
    {
        $nodes = [];
        $home = $this->catalog->content('home') ?? [];
        if ($this->indexable('/', $home)) {
            $nodes[] = ['/', null];
        }
        foreach (['catalog', 'brands', 'articles', ...StorefrontPages::INFO] as $slug) {
            $path = '/' . $slug;
            $record = $this->catalog->content('page', $slug);
            if ($record !== null && $this->indexable($path, $record)) {
                $nodes[] = [$path, null];
            }
        }
        foreach (['brand' => $this->catalog->brands(), 'category' => $this->catalog->categories()] as $kind => $records) {
            foreach ($records as $record) {
                $path = '/' . $kind . '/' . $record['slug'];
                if ($this->indexable($path, $record)) {
                    $nodes[] = [$path, null];
                }
            }
        }
        foreach (['articles' => '/articles/', 'pages' => '/'] as $kind => $prefix) {
            $page = 1;
            do {
                $batch = $this->catalog->content($kind, null, $page);
                foreach ($batch['items'] as $item) {
                    $path = $prefix . $item['slug'];
                    if (($kind !== 'pages' || !StorefrontPages::reserved($item['slug'])) && $this->indexable($path, $item)) {
                        $nodes[] = [$path, $item['published_at']];
                    }
                }
            } while ($batch['items'] !== [] && $page * $batch['limit'] < $batch['total'] && ++$page <= 83334);
        }

        return $nodes;
    }

    private function xml(string $root, string $tag, array $nodes): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . '<' . $root . ' xmlns="' . self::SITEMAP_NS . '">';
        foreach ($nodes as [$path, $lastmod]) {
            $xml .= '<' . $tag . '><loc>' . htmlspecialchars($this->url($path), ENT_XML1, 'UTF-8') . '</loc>';
            if (is_string($lastmod) && preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}Z)?$/D', $lastmod)
                && !str_starts_with($lastmod, '0000-')) {
                $xml .= '<lastmod>' . $lastmod . '</lastmod>';
            }
            $xml .= '</' . $tag . '>';
        }

        return $xml . '</' . $root . '>';
    }
}
