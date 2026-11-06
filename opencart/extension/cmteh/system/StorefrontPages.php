<?php

declare(strict_types=1);

namespace Cmteh;

use DOMDocument;

final class StorefrontPages
{
    public const INFO = [
        'about' => ['О компании', 'СМ ТЕХНО — поставка запчастей для спецтехники и сельскохозяйственной техники.'],
        'contacts' => ['Контакты', 'Контакты СМ ТЕХНО для заявок и подбора запчастей для спецтехники.'],
        'delivery' => ['Доставка запчастей', 'Условия доставки B2B-заказов запчастей для спецтехники.'],
        'payment' => ['Оплата', 'Условия оплаты B2B-заказов запчастей для юридических лиц.'],
    ];

    private const SYSTEM = ['catalog', 'brands', 'articles', 'request'];
    private const RESERVED = ['admin', 'api', 'account', 'brand', 'cart', 'category', 'checkout', 'extension', 'image',
        'information', 'install', 'product', 'sitemaps', 'system', '_cmteh_platform'];

    private readonly StorefrontSeo $seo;
    private ?array $brands = null;
    private ?array $categories = null;

    public function __construct(private readonly Catalog $catalog, string $origin)
    {
        $this->seo = new StorefrontSeo($origin, $catalog);
    }

    public static function reserved(string $slug): bool
    {
        return isset(self::INFO[$slug]) || in_array($slug, self::SYSTEM, true) || in_array($slug, self::RESERVED, true);
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
                return [$slug, []];
            }
            if (isset(self::INFO[$slug])) {
                return ['page', $this->catalog->content('page', $slug) ?? []];
            }
            if (self::reserved($slug) || !self::slug($slug)) {
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
        $site = $this->catalog->content('site') ?? [];
        $navigation = $this->catalog->content('navigation') ?? [];
        $model = [
            'brands' => $this->brands(), 'categories' => $this->categories(),
            'home' => self::htmlFields($page === 'home' ? $record : ($this->catalog->content('home') ?? [])),
        ];
        $meta = [
            'canonical' => $this->seo->canonical($path, $record['canonical_url'] ?? null),
            'robots' => ($record['is_indexable'] ?? true) ? null : 'noindex,follow',
        ];
        $graphs = [$this->seo->organization($site)];
        $homeCrumb = ['Главная', $this->seo->url('/')];
        $catalogCrumb = ['Каталог', $this->seo->url('/catalog')];

        switch ($page) {
            case 'home':
                $model += $this->catalog->products(['limit' => 6]);
                $model['entries'] = $this->catalog->content('articles')['items'];
                $meta['title'] = ($record['seo_title'] ?? '') ?: (($record['h1'] ?? '') ?: (($record['hero_title'] ?? '') ?: 'Запчасти для спецтехники'));
                $meta['description'] = ($record['seo_description'] ?? '') ?: (($record['hero_text'] ?? '') ?: 'Найдите деталь по артикулу или отправьте список позиций для расчёта');
                $meta['image'] = $this->seo->assetUrl($record['hero_image'] ?? null)
                    ?? $this->seo->url('/extension/cmteh/catalog/view/assets/images/hero-industrial-v3.webp');
                break;
            case 'catalog':
                $model += $this->catalog->products($params + ['limit' => 12]);
                $meta['title'] = 'Каталог запчастей';
                $meta['description'] = 'Мультибрендовый B2B-каталог запчастей для спецтехники с поиском по артикулу и OEM-коду.';
                if ($this->filtered($rawParams)) {
                    $meta['robots'] = 'noindex,follow';
                } elseif ($params['page'] > 1) {
                    $meta['canonical'] .= '?page=' . $params['page'];
                }
                break;
            case 'brands':
                $meta['title'] = 'Бренды запчастей';
                $meta['description'] = 'Каталог брендов запчастей для спецтехники: Caterpillar, Komatsu, JCB, John Deere и другие.';
                break;
            case 'brand':
            case 'category':
                $model += $this->catalog->products([$page => $record['slug'], 'limit' => $page === 'brand' ? 12 : 24]);
                $model[$page] = $record;
                $name = $record['name'] ?? $record['title'];
                if ($page === 'brand') {
                    $meta['title'] = 'Запчасти ' . $name;
                    $meta['description'] = trim(($record['description'] ?? '') . ' Поиск и заявка на запчасти ' . $name . ' для юридических лиц.');
                } else {
                    $meta['title'] = ($record['seo_title'] ?? '') ?: (($record['h1'] ?? '') ?: $name);
                    $meta['description'] = ($record['seo_description'] ?? '') ?: ($record['description'] ?? '');
                    $meta['image'] = $this->seo->assetUrl($record['image'] ?? null);
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
                $meta['description'] = ($record['seo_description'] ?? '') ?: (($record['short_description'] ?? '')
                    ?: "Запчасть {$record['brand']} {$record['sku']}. B2B-заявка и поставка.");
                $meta['image'] = $this->seo->assetUrl($record['main_image']);
                if ($meta['image'] === null) {
                    foreach ($record['images'] as $image) {
                        $meta['image'] = $this->seo->assetUrl($image['image']);
                        if ($meta['image'] !== null) {
                            break;
                        }
                    }
                }
                $graphs[] = $this->seo->product($record, $model['heading'], $meta['canonical'], $meta['image']);
                $graphs[] = $this->seo->breadcrumbs([$homeCrumb, $catalogCrumb,
                    [$record['brand'], $this->seo->url('/brand/' . $record['brand_slug'])], [$record['sku'], $meta['canonical']]]);
                break;
            case 'articles':
                $articlePage = $this->catalog->content('articles', null, $params['page']);
                $model += ['entries' => $articlePage['items']] + $articlePage;
                $meta['title'] = 'Статьи и рекомендации';
                $meta['description'] = 'Материалы о поиске запчастей и подготовке заявки.';
                if ($params['page'] > 1) {
                    $meta['title'] .= ' — страница ' . $params['page'];
                    $meta['canonical'] .= '?page=' . $params['page'];
                }
                break;
            case 'article':
                $article = self::htmlFields($record);
                [$article['body_html'], $model['headings']] = self::articleBody($article['body_html'] ?? '');
                $model['content'] = $article;
                $model['relatedLinks'] = $this->articleCategoryLinks($record);
                foreach ($this->catalog->content('articles')['items'] as $candidate) {
                    if ($candidate['id'] !== $record['id']) {
                        $model['related'] = $candidate;
                        break;
                    }
                }
                $meta['title'] = ($record['seo_title'] ?? '') ?: $record['title'];
                $meta['description'] = ($record['seo_description'] ?? '') ?: (($record['excerpt'] ?? '') ?: $record['title']);
                $meta['image'] = $this->seo->assetUrl(($record['og_image'] ?? null) ?: ($record['cover_image'] ?? null));
                $graphs[] = $this->seo->article($record, $site, $meta['canonical']);
                $graphs[] = $this->seo->breadcrumbs([$homeCrumb, ['Статьи', $this->seo->url('/articles')], [$record['title'], $meta['canonical']]]);
                break;
            case 'page':
                $slug = substr($path, 1);
                [$defaultTitle, $defaultDescription] = self::INFO[$slug] ?? [$slug, ''];
                $model['slug'] = $slug;
                $model['content'] = self::htmlFields($record);
                $meta['title'] = ($record['seo_title'] ?? '') ?: (($record['title'] ?? '') ?: $defaultTitle);
                $meta['description'] = ($record['seo_description'] ?? '') ?: (($record['intro'] ?? '') ?: $defaultDescription);
                $meta['image'] = $this->seo->assetUrl($record['og_image'] ?? null);
                break;
            case 'request':
                $meta['title'] = 'Корзина';
                $meta['description'] = 'Проверьте список товаров и отправьте заявку на расчёт.';
                $meta['robots'] = 'noindex,nofollow';
                break;
        }

        if ($page !== 'home') {
            $meta['title'] .= ' | ' . (($site['company_name'] ?? '') ?: 'СМ ТЕХНО');
        }
        $meta['description'] = StorefrontSeo::text($meta['description']);
        $jsonLd = [];
        foreach (array_filter($graphs) as $graph) {
            $jsonLd[] = json_encode($graph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }

        return compact('page', 'params', 'model', 'site', 'navigation', 'meta', 'jsonLd') + ['status' => 200];
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
        if (isset($record['content']) && is_string($record['content'])) {
            $record['body_html'] = $record['content'];
        }
        foreach ($record['sections'] ?? [] as $index => $section) {
            if (is_array($section) && isset($section['text']) && is_string($section['text'])) {
                $record['sections'][$index]['text_html'] = $section['text'];
            }
        }

        return $record;
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
