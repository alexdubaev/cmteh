<?php

declare(strict_types=1);

namespace Cmteh;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

final class CatalogInput
{
    private const LEGACY_CATALOG_PATHS = ['/catalog/JOHN-DEERE', '/catalog/CATERPILLAR', '/catalog/MILEQUIP',
        '/catalog/Analogi', '/catalog/Новинки', '/catalog/Хиты-продаж', '/catalog/Товары-на-главной'];

    public static function text(mixed $value, int $max, string $field, bool $required = false): string
    {
        if ($value === null && !$required) {
            return '';
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException("$field: требуется строка UTF-8");
        }
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException("$field: недопустимое значение");
        }
        return $value;
    }

    public static function normalize(string $value): string
    {
        return preg_replace('/[^A-Z0-9А-ЯЁ]+/u', '', mb_strtoupper(trim($value))) ?? '';
    }

    public static function slug(mixed $value): string
    {
        $value = self::text($value, 160, 'slug', true);
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N}_-]*$/uD', $value)) {
            throw new InvalidArgumentException('slug: недопустимый адрес');
        }
        return $value;
    }

    public static function slugify(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($value)) ?? '', '-');
    }

    public static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value)
            ? strtolower($value) : null;
    }

    public static function enum(mixed $value, array $allowed, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("$field: неизвестное значение");
        }
        return $value;
    }

    public static function published(array $row): bool
    {
        $status = $row['status'] ?? 'draft';
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new InvalidArgumentException('status: неизвестный статус');
        }
        return $status === 'published';
    }

    public static function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return gmdate('Y-m-d H:i:s');
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2}))?$/D', $value)) {
            throw new InvalidArgumentException('published_at: требуется дата ISO 8601');
        }
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
            throw new InvalidArgumentException('published_at: недопустимая дата');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function path(mixed $value): string
    {
        $path = self::text($value, 512, 'path', true);
        if (!preg_match('~^/(?:[\p{L}\p{N}_.-]+(?:/[\p{L}\p{N}_.-]+)*)?$~uD', $path)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new InvalidArgumentException('path: требуется полный путь сайта без query');
        }
        return $path;
    }

    public static function routeSource(mixed $value): string
    {
        $source = self::text($value, 512, 'path', true);
        if (str_contains($source, '?')) {
            [$path, $query] = explode('?', $source, 2);
            $path = self::path($path);
            $query = self::legacyRouteQuery($path, $query);
            if ($query === null) {
                throw new InvalidArgumentException('path: недопустимый источник перенаправления');
            }
            return $path . '?' . $query;
        }
        return self::path($source);
    }

    public static function legacyRouteQuery(string $path, string $query): ?string
    {
        if ($path === '/' || in_array($path, self::LEGACY_CATALOG_PATHS, true)) {
            if (!preg_match('/^&?page=([1-9][0-9]{0,2})$/D', $query, $match)) {
                return null;
            }
            return 'page=' . (int) $match[1];
        }
        if ($path === '/news' && preg_match('/^sid=([1-3])$/D', $query, $match)) {
            return 'sid=' . $match[1];
        }
        return null;
    }

    public static function isLegacyQueryScope(string $path): bool
    {
        return $path === '/' || $path === '/news' || in_array($path, self::LEGACY_CATALOG_PATHS, true);
    }

    public static function publicImagePath(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (str_starts_with($value, '/image/')) {
            $value = substr($value, strlen('/image/'));
        } elseif (str_starts_with($value, 'image/')) {
            $value = substr($value, strlen('image/'));
        }
        if (strlen($value) > 512 || !preg_match('~^catalog/(?:[a-zA-Z0-9_-][a-zA-Z0-9_.-]*/)*[a-zA-Z0-9_-][a-zA-Z0-9_.-]*[.](?:png|jpe?g|gif|webp|ico)$~iD', $value)) {
            return null;
        }
        return $value;
    }

    public static function url(mixed $value, bool $image = false): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value) || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|2f|5c|7f)/i', $value)) {
            return null;
        }
        if ($image) {
            if (preg_match('~^/api/assets/([a-f0-9-]{36})$~iD', $value, $match) && self::uuid($match[1]) !== null) {
                return '/api/assets/' . strtolower($match[1]);
            }
            $path = self::publicImagePath($value);
            return $path === null ? null : '/image/' . $path;
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }
        if (str_starts_with($value, '#')) {
            return $value;
        }
        $parts = parse_url($value);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && isset($parts['host']) && !isset($parts['user'], $parts['pass'])
            && !array_key_exists('user', $parts) && !array_key_exists('pass', $parts) ? $value : null;
    }

    /** Extract a trusted iframe URL; pasted markup is never stored or rendered. */
    public static function yandexMapEmbed(mixed $value): string
    {
        $error = 'Карта: вставьте iframe-код Яндекс Карт или ссылку виджета HTTPS. Код со script не поддерживается.';
        if (is_string($value) && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) throw new InvalidArgumentException($error);
        $value = self::text($value, 20000, 'Карта');
        if ($value === '') return '';
        if (str_contains($value, '<')) {
            $document = new DOMDocument();
            $document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $value . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $frames = $document->getElementsByTagName('iframe');
            if ($frames->length !== 1 || $document->getElementsByTagName('script')->length !== 0) {
                throw new InvalidArgumentException($error);
            }
            $frame = $frames->item(0);
            if (!$frame instanceof DOMElement || $frame->hasAttribute('srcdoc')) throw new InvalidArgumentException($error);
            $value = $frame->getAttribute('src');
        }
        if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $value)) {
            throw new InvalidArgumentException($error);
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException($error);
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $allowed = (in_array($host, ['yandex.ru', 'www.yandex.ru'], true) && $path === '/map-widget/v1/')
            || ($host === 'api-maps.yandex.ru' && preg_match('~^/frame/v1/-/[A-Za-z0-9_-]+$~D', $path));
        if (!$allowed) throw new InvalidArgumentException($error);
        return 'https://' . $host . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public static function html(mixed $value): string
    {
        $html = self::text($value, 150000, 'HTML');
        if ($html === '' || !str_contains($html, '<')) {
            return $html;
        }

        $document = new DOMDocument();
        $document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        self::cleanChildren($body);
        $safe = '';
        foreach ($body->childNodes as $child) {
            $safe .= $document->saveHTML($child);
        }
        return $safe;
    }

    private static function cleanChildren(DOMNode $node): void
    {
        $allowed = ['p', 'div', 'span', 'br', 'hr', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i',
            'u', 's', 'blockquote', 'code', 'pre', 'ol', 'ul', 'li', 'dl', 'dt', 'dd', 'table', 'caption',
            'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'a', 'img', 'figure', 'figcaption', 'sup', 'sub'];
        $drop = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'link', 'meta', 'base', 'template'];
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                if ($child->nodeType !== XML_TEXT_NODE) {
                    $node->removeChild($child);
                }
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, $drop, true)) {
                $node->removeChild($child);
                continue;
            }
            self::cleanChildren($child);
            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = $attr->name;
                $keep = ($tag === 'a' && $name === 'href' && self::url($attr->value) !== null)
                    || ($tag === 'img' && $name === 'src' && self::url($attr->value, true) !== null)
                    || ($tag === 'img' && $name === 'alt')
                    || (in_array($tag, ['td', 'th'], true) && in_array($name, ['colspan', 'rowspan'], true)
                        && preg_match('/^[1-9][0-9]?$/D', $attr->value));
                if (!$keep) {
                    $child->removeAttributeNode($attr);
                } elseif ($tag === 'img' && $name === 'src') {
                    $child->setAttribute('src', self::url($attr->value, true));
                }
            }
            if ($tag === 'img' && !$child->hasAttribute('src')) {
                $node->removeChild($child);
            }
        }
    }
}
