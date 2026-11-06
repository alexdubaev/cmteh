<?php

declare(strict_types=1);

namespace Cmteh;

use DOMDocument;

require_once __DIR__ . '/EditorialDefaults.php';

final class StorefrontPages
{
    public const INFO = ['about', 'contacts', 'delivery', 'payment'];
    public const SYSTEM = ['catalog', 'brands', 'articles', 'request'];

    private const RESERVED = ['admin', 'api', 'account', 'brand', 'cart', 'category', 'checkout', 'extension', 'image',
        'information', 'install', 'product', 'sitemap', 'sitemaps', 'system', '_cmteh_platform'];

    private readonly StorefrontSeo $seo;
    private ?array $brands = null;
    private ?array $categories = null;

    public function __construct(private readonly Catalog $catalog, string $origin)
    {
        $this->seo = new StorefrontSeo($origin, $catalog);
    }

    public static function reserved(string $slug): bool
    {
        return in_array($slug, self::INFO, true) || in_array($slug, self::SYSTEM, true) || in_array($slug, self::RESERVED, true);
    }

    public function record(string $path): ?array
    {
        if ($path === '/') {
            return ['home', $this->catalog->content('home') ?? []];
        }
        $parts = explode('/', substr($path, 1));
        if (count($parts) === 1) {
            [$slug] = $parts;
            if (in_array($slug, self::SYSTEM, true)) {
                return [$slug, $this->catalog->content('page', $slug) ?? ['is_indexable' => false]];
            }
            if ((!in_array($slug, self::INFO, true) && self::reserved($slug)) || !self::slug($slug)) {
                return null;
            }
            $page = $this->catalog->content('page', $slug);
            return $page === null ? null : ['page', $page];
        }
        if (count($parts) !== 2 || !self::slug($parts[1])) {
            return null;
        }
        [$prefix, $slug] = $parts;
        $record = match ($prefix) {
            'brand' => $this->find($this->brands(), $slug),
            'category' => $this->find($this->categories(), $slug),
            'product' => $this->catalog->product($slug),
            'articles' => $this->catalog->content('article', $slug),
            default => null,
        };

        return $record === null ? null : [$prefix === 'articles' ? 'article' : $prefix, $record];
    }

    public function view(string $path, array $rawParams): ?array
    {
        $route = $this->record($path);
        if ($route === null) {
            return null;
        }

        [$page, $record] = $route;
        $params = $this->params($rawParams);
        $site = self::htmlFields($this->catalog->content('site') ?? []);
        $site['logo_url'] = EditorialDefaults::imageUrl($site['logo'] ?? null);
        $home = self::htmlFields($page === 'home' ? $record : ($this->catalog->content('home') ?? []));
        $home['hero_image_url'] = EditorialDefaults::imageUrl($home['hero_image_path'] ?? null)
            ?? $this->seo->assetUrl($home['hero_image'] ?? null);
        $home['hero_mobile_image_url'] = EditorialDefaults::imageUrl($home['hero_mobile_image_path'] ?? null);
        $navigation = $this->catalog->content('navigation') ?? [];
        $model = [
            'brands' => $this->brands(), 'categories' => $this->categories(),
            'home' => $home,
            'landing' => in_array($page, self::SYSTEM, true) ? self::htmlFields($record) : [],
        ];
        $meta = [
            'canonical' => $this->seo->canonical($path, $record['canonical_url'] ?? null),
            'robots' => ($record['is_indexable'] ?? true) ? null : 'noindex,follow',
            'title' => ($record['seo_title'] ?? '') ?: (($record['h1'] ?? '') ?: ($record['title'] ?? '')),
            'description' => ($record['seo_description'] ?? '') ?: ($record['intro'] ?? ''),
            'image' => $this->editorialImage($record['og_image'] ?? null, $record['og_image_path'] ?? null),
        ];
        $graphs = [$this->seo->organization($site)];
        $homeCrumb = ['Главная', $this->seo->url('/')];
        $catalogCrumb = ['Каталог', $this->seo->url('/catalog')];

        switch ($page) {
            case 'home':
                $model += $this->catalog->products(['limit' => 6]);
                $model['entries'] = array_map(self::htmlFields(...), $this->catalog->content('articles')['items']);
                $meta['title'] = $meta['title'] ?: ($record['hero_title'] ?? '');
                $meta['description'] = $meta['description'] ?: ($record['hero_text'] ?? '');
                $meta['image'] ??= $home['hero_image_url'] ? (str_starts_with($home['hero_image_url'], '/') ? $this->seo->url($home['hero_image_url']) : $home['hero_image_url']) : null;
                break;
            case 'catalog':
                $model += $this->catalog->products($params + ['limit' => 12]);
                if ($this->filtered($rawParams)) {
                    $meta['robots'] = 'noindex,follow';
                } elseif (!$this->paginate($path, $params['page'], $model, $meta)) {
                    return null;
                }
                break;
            case 'brands':
                break;
            case 'brand':
            case 'category':
                $model += $this->catalog->products([
                    $page => $record['slug'], 'page' => $params['page'], 'limit' => $page === 'brand' ? 12 : 24,
                ]);
                $model[$page] = $record;
                $model['collection_path'] = StorefrontSeo::encodePath($path);
                $name = $record['name'] ?? $record['title'];
                if ($page === 'brand') {
                    $meta['title'] = $meta['title'] ?: 'Запчасти ' . $name;
                    $meta['description'] = $meta['description'] ?: ($record['description'] ?? '');
                } else {
                    $meta['title'] = ($record['seo_title'] ?? '') ?: (($record['h1'] ?? '') ?: $name);
                    $meta['description'] = ($record['seo_description'] ?? '') ?: ($record['description'] ?? '');
                    $meta['image'] ??= !empty($record['image_url'])
                        ? $this->seo->url($record['image_url']) : $this->seo->assetUrl($record['image'] ?? null);
                }
                if (!$this->paginate($path, $params['page'], $model, $meta)) {
                    return null;
                }
                $graphs[] = $this->seo->collection($record, $model['products'], $page, $meta['canonical']);
                $graphs[] = $this->seo->breadcrumbs([$homeCrumb, $catalogCrumb, [$name, $meta['canonical']]]);
                break;
            case 'product':
                $record['full_description_html'] = $record['full_description'];
                $model['product'] = $record;
                $model['heading'] = self::heading($record);
                $model['related'] = $this->relatedProducts($record);
                $meta['title'] = ($record['seo_title'] ?? '') ?: $model['heading'];
                $meta['description'] = ($record['seo_description'] ?? '') ?: (($record['short_description'] ?? '') ?:
                    mb_strimwidth(StorefrontSeo::text($record['full_description']), 0, 170, '…'));
                $meta['image'] = !empty($record['main_image_url'])
                    ? $this->seo->url($record['main_image_url']) : $this->seo->assetUrl($record['main_image'] ?? null);
                if ($meta['image'] === null) {
                    foreach ($record['images'] as $image) {
                        $meta['image'] = !empty($image['image_url'])
                            ? $this->seo->url($image['image_url']) : $this->seo->assetUrl($image['image'] ?? null);
                        if ($meta['image'] !== null) {
                            break;
                        }
                    }
                }
                $graphs[] = $this->seo->product($record, $model['heading'], $meta['canonical'], $meta['image']);
                $crumbs = [$homeCrumb, $catalogCrumb];
                if (!empty($record['brand_slug']) && !empty($record['brand'])) {
                    $crumbs[] = [$record['brand'], $this->seo->url('/brand/' . $record['brand_slug'])];
                }
                $crumbs[] = [$record['sku'], $meta['canonical']];
                $graphs[] = $this->seo->breadcrumbs($crumbs);
                break;
            case 'articles':
                $articlePage = $this->catalog->content('articles', null, $params['page']);
                $model += ['entries' => array_map(self::htmlFields(...), $articlePage['items'])] + $articlePage;
                if (!$this->paginate($path, $params['page'], $model, $meta)) {
                    return null;
                }
                break;
            case 'article':
                $article = self::htmlFields($record);
                [$article['body_html'], $model['headings']] = self::articleBody($article['body_html'] ?? '');
                $model['content'] = $article;
                $model['relatedLinks'] = $this->articleCategoryLinks($record);
                foreach ($this->catalog->content('articles')['items'] as $candidate) {
                    if ($candidate['id'] !== $record['id']) {
                        $model['related'] = self::htmlFields($candidate);
                        break;
                    }
                }
                $meta['title'] = ($record['seo_title'] ?? '') ?: $record['title'];
                $meta['description'] = ($record['seo_description'] ?? '') ?: (($record['excerpt'] ?? '') ?: $record['title']);
                $meta['image'] ??= $this->editorialImage($record['cover_image'] ?? null, $record['cover_image_path'] ?? null);
                $graphs[] = $this->seo->article($record, $site, $meta['canonical']);
                $graphs[] = $this->seo->breadcrumbs([$homeCrumb, ['Статьи', $this->seo->url('/articles')], [$record['title'], $meta['canonical']]]);
                break;
            case 'page':
                $slug = substr($path, 1);
                $model['slug'] = $slug;
                $model['content'] = self::htmlFields($record);
                break;
            case 'request':
                $meta['robots'] = 'noindex,nofollow';
                break;
        }

        if ($page !== 'home' && !empty($site['company_name'])) {
            $meta['title'] = $meta['title'] === '' ? $site['company_name'] : $meta['title'] . ' | ' . $site['company_name'];
        }
        $meta['description'] = StorefrontSeo::text($meta['description']);
        $jsonLd = [];
        foreach (array_filter($graphs) as $graph) {
            $jsonLd[] = json_encode($graph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }

        return compact('page', 'params', 'model', 'site', 'navigation', 'meta', 'jsonLd') + ['status' => 200];
    }

    private function paginate(string $path, int $requested, array $listing, array &$meta): bool
    {
        if ($requested === 1) {
            return true;
        }
        if ($listing['page'] !== $requested || ($requested - 1) * $listing['limit'] >= $listing['total']) {
            return false;
        }
        if ($meta['canonical'] === $this->seo->url($path)) {
            $meta['canonical'] .= '?page=' . $requested;
        }
        $meta['title'] = ($meta['title'] === '' ? '' : $meta['title'] . ' · ') . 'Страница ' . $requested;

        return true;
    }

    private function brands(): array
    {
        return $this->brands ??= $this->catalog->brands();
    }

    private function categories(): array
    {
        return $this->categories ??= $this->catalog->categories();
    }

    private function find(array $items, string $slug): ?array
    {
        foreach ($items as $item) {
            if ($item['slug'] === $slug) {
                return $item;
            }
        }

        return null;
    }

    private static function slug(string $value): bool
    {
        return mb_strlen($value) <= 160 && preg_match('/^[\p{L}\p{N}][\p{L}\p{N}_-]*$/uD', $value) === 1;
    }

    private function params(array $raw): array
    {
        $params = ['page' => 1];
        foreach (['q', 'brand', 'category', 'article', 'import'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && mb_check_encoding($raw[$key], 'UTF-8')) {
                $params[$key] = mb_substr(trim($raw[$key]), 0, 512);
            }
        }
        foreach (['availability' => Catalog::AVAILABILITY, 'partType' => Catalog::PART_TYPES,
                  'sort' => ['popular', 'title', 'price_asc', 'price_desc']] as $key => $values) {
            if (in_array($raw[$key] ?? null, $values, true)) {
                $params[$key] = $raw[$key];
            }
        }
        $page = $raw['page'] ?? '';
        if (is_string($page) && preg_match('/^\d{1,16}$/D', $page)) {
            $params['page'] = max(1, min(1000000, (int) $page));
        }

        return $params;
    }

    private function filtered(array $params): bool
    {
        foreach (['q', 'brand', 'category', 'availability', 'partType', 'sort'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    private static function heading(array $product): string
    {
        $title = $product['title'];
        preg_match_all('~[A-Z0-9А-ЯЁ]+(?:[-./][A-Z0-9А-ЯЁ]+)*~u', mb_strtoupper($title), $tokens);
        $tokens = array_map(CatalogInput::normalize(...), $tokens[0]);
        $parts = [$title];
        foreach ([$product['brand'], $product['sku']] as $identity) {
            $normalized = CatalogInput::normalize($identity);
            $found = false;
            foreach ($tokens as $start => $_) {
                $candidate = '';
                for ($end = $start; isset($tokens[$end]) && strlen($candidate) < strlen($normalized); $end++) {
                    $candidate .= $tokens[$end];
                    if ($candidate === $normalized) {
                        $found = true;
                        break 2;
                    }
                }
            }
            if ($identity !== '' && !$found) {
                $parts[] = $identity;
            }
        }

        return implode(' ', $parts);
    }

    private function relatedProducts(array $product): array
    {
        $seen = [$product['id']];
        foreach ($product['relations'] as $relation) {
            $seen[] = $relation['product']['id'];
        }
        $result = $this->catalog->products(['category' => $product['category']['slug'] ?? null, 'limit' => 5]);
        $candidates = array_filter($result['products'], static fn(array $item): bool => !in_array($item['id'], $seen, true));

        return array_slice(array_values($candidates), 0, 4);
    }

    private static function htmlFields(array $record): array
    {
        $record['body_html'] = is_string($record['content'] ?? null) ? $record['content'] : '';
        $record['cover_url'] = EditorialDefaults::imageUrl($record['cover_image_path'] ?? null);
        if (!is_array($record['sections'] ?? null)) {
            $record['sections'] = [];
        }
        $record['sections'] = array_values(array_filter($record['sections'], 'is_array'));
        foreach ($record['sections'] as &$section) {
            $section['text_html'] = is_string($section['text'] ?? null) ? $section['text'] : '';
            $section['image_url'] = EditorialDefaults::imageUrl($section['image_path'] ?? null);
        }
        unset($section);

        return $record;
    }

    private function editorialImage(mixed $uuid, mixed $path): ?string
    {
        $image = EditorialDefaults::imageUrl($path);
        return $image === null ? $this->seo->assetUrl($uuid) : $this->seo->url($image);
    }

    private static function articleBody(string $html): array
    {
        if (preg_match_all('/<h[2-6]\b/i', $html) < 2) {
            return [$html, []];
        }
        $document = new DOMDocument();
        $document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $document->getElementsByTagName('body')->item(0);
        $headings = [];
        $ids = [];
        foreach ($body->getElementsByTagName('*') as $element) {
            if (!preg_match('/^h([2-6])$/D', $element->tagName, $match)) {
                continue;
            }
            $label = trim($element->textContent);
            if ($label === '') {
                continue;
            }
            $id = CatalogInput::slugify($label) ?: 'section';
            $ids[$id] = ($ids[$id] ?? 0) + 1;
            $id = 'article-' . $id . '-' . $ids[$id];
            $element->setAttribute('id', $id);
            $headings[] = ['id' => $id, 'title' => $label, 'level' => (int) $match[1]];
        }
        if (count($headings) < 4 && (count($headings) < 2 || mb_strlen($html) < 6000)) {
            return [$html, []];
        }
        $bodyHtml = '';
        foreach ($body->childNodes as $child) {
            $bodyHtml .= $document->saveHTML($child);
        }

        return [$bodyHtml, $headings];
    }

    private function articleCategoryLinks(array $article): array
    {
        $categories = $article['related_categories'] ?? [];
        if (!is_array($categories)) {
            return [];
        }
        $links = [];
        foreach ($this->categories() as $category) {
            if (in_array($category['id'], $categories, true)) {
                $links[] = ['title' => $category['title'], 'href' => $this->seo->url('/category/' . $category['slug'])];
            }
        }

        return $links;
    }
}
