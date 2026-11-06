<?php

declare(strict_types=1);

namespace Cmteh;

final class EditorialDefaults
{
    private const ASSET_PATH = '/extension/cmteh/catalog/view/assets/images/';
    private const BUNDLED_IMAGES = [
        self::ASSET_PATH . 'sm-techno-logo-transparent.webp',
        self::ASSET_PATH . 'hero-industrial-v3.webp',
        self::ASSET_PATH . 'hero-industrial-v3-mobile.webp',
    ];

    public static function install(Catalog $catalog): void
    {
        $json = file_get_contents(__DIR__ . '/editorial-defaults.json');
        if ($json === false) {
            throw new \RuntimeException('Missing storefront editorial defaults.');
        }
        $defaults = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

        foreach (['site', 'home'] as $type) {
            $record = $defaults[$type];
            $existing = $catalog->editorialBySlug($type, $type);
            if ($existing === null) {
                $catalog->insertEditorialDefault($type, self::record($type, $record + [
                    'id' => $type === 'site' ? 'mock-site' : 'storefront-home',
                ]));
            } else {
                // Preserve explicit blank values and previously authored media and sections.
                if ($type === 'home' && !empty($existing['hero_image'])) {
                    unset($record['hero_image_path'], $record['hero_mobile_image_path']);
                }
                $catalog->backfillEditorialFields($type, $type, $record);
            }
        }

        foreach ($defaults['pages'] as $slug => $record) {
            if ($catalog->editorialBySlug('page', $slug) === null) {
                $catalog->insertEditorialDefault('page', self::record($slug, $record));
            }
        }

        foreach ($defaults['navigation'] as $location => $links) {
            $hasNavigation = $catalog->hasEditorialNavigation($location);
            foreach ($links as $offset => [$label, $url]) {
                // Articles used to be appended even to a custom header or footer menu.
                if ($hasNavigation && $url !== '/articles') {
                    continue;
                }
                if ($catalog->hasNavigationTarget($location, $url)) {
                    continue;
                }
                $slug = $location . '-' . substr($url, 1);
                $catalog->insertEditorialDefault('navigation', self::record($slug, [
                    'id' => $slug, 'label' => $label, 'url' => $url, 'location' => $location,
                    'sort_order' => $hasNavigation ? 100000 : ($offset + 1) * 10,
                ]));
            }
        }
    }

    public static function installSearchContent(Catalog $catalog): void
    {
        $json = file_get_contents(__DIR__ . '/search-editorial.json');
        if ($json === false) {
            throw new \RuntimeException('Missing search editorial defaults.');
        }
        $defaults = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $catalog->reviseEditorialDefaults('home', 'home', $defaults['home']);
        foreach ($defaults['pages'] as $slug => $changes) {
            $catalog->reviseEditorialDefaults('page', $slug, $changes);
        }
        $catalog->reviseEditorialDefaults('article', 'demonstration', ['is_indexable' => [true, false]], 'demonstration');

        $article = $defaults['article'];
        $catalog->insertEditorialDefault('article', self::record($article['slug'], $article + [
            'published_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]));
    }

    public static function imageUrl(mixed $value): ?string
    {
        if (in_array($value, self::BUNDLED_IMAGES, true)) {
            return $value;
        }
        $path = CatalogInput::publicImagePath($value);
        return $path === null ? null : '/image/' . $path;
    }

    private static function record(string $slug, array $record): array
    {
        return $record + [
            'id' => 'storefront-' . $slug,
            'slug' => $slug,
            'status' => 'published',
            'published_at' => '2000-01-01T00:00:00Z',
        ];
    }
}
