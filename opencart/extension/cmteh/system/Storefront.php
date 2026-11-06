<?php

declare(strict_types=1);

namespace Cmteh;

use Opencart\System\Engine\Registry;
use Opencart\System\Library\Response;
use PDO;
use Throwable;

require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/Lead.php';
require_once __DIR__ . '/StorefrontPages.php';
require_once __DIR__ . '/StorefrontSeo.php';

final class Storefront
{
    private const LEAD_BYTES = 512000;

    private readonly Response $response;
    private readonly array $server;
    private ?PDO $database = null;
    private ?Catalog $catalog = null;
    private ?StorefrontPages $pages = null;

    private function __construct(private readonly Registry $registry)
    {
        $this->response = $registry->get('response');
        // OpenCart HTML-escapes server values, which changes raw URI query delimiters.
        $this->server = $_SERVER;
    }

    public static function dispatch(Registry $registry): void
    {
        (new self($registry))->run();
    }

    private function run(): void
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '');
        $method = (string) ($this->server['REQUEST_METHOD'] ?? 'GET');
        [$raw, $query] = array_pad(explode('?', $uri, 2), 2, '');
        $path = StorefrontSeo::path($raw);

        try {
            if ($path === null) {
                $this->renderError(404);
                return;
            }
            if ($path === '/_cmteh_platform' && $raw === $uri && $method === 'GET'
                && getenv('CMTEH_LOCAL_SMOKE') === '1') {
                Database::privateDir();
                $this->db()->query('SELECT 1')->fetchColumn();
                $this->json(200, ['opencart' => true, 'mysql' => true, 'private_storage' => true]);
                return;
            }
            parse_str($query, $params);

            if ($path === '/api/lead' && $raw === $path) {
                $this->lead($method);
                return;
            }
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                $this->json(405, ['error' => 'Метод не поддерживается.'], ['Allow' => 'GET, HEAD']);
                return;
            }
            if ($path === '/index.php' || isset($params['route']) || isset($params['_route_'])) {
                $this->renderError(404);
                return;
            }
            if ($query !== '' && CatalogInput::isLegacyQueryScope($path)
                && ($path !== '/' || array_key_exists('page', $params))) {
                $sourceQuery = CatalogInput::legacyRouteQuery($path, $query);
                if ($sourceQuery === null) {
                    $this->renderError(404);
                    return;
                }
                $route = $this->catalog()->routes($path . '?' . $sourceQuery);
                if (($route['status'] ?? null) === 301 && $route['target'] !== $path && $this->exists($route['target'])) {
                    $this->redirect(StorefrontSeo::encodePath($route['target']));
                    return;
                }
                $this->renderError(($route['status'] ?? null) === 410 ? 410 : 404);
                return;
            }
            $encoded = StorefrontSeo::encodePath($path);
            if ($raw !== $encoded) {
                $target = $this->target($path);
                if ($target !== null) {
                    $suffix = $query !== '' && !preg_match('/[\x00-\x20\x7f]/', $query) ? '?' . $query : '';
                    $this->redirect($target . ($target === $encoded ? $suffix : ''));
                } elseif (($this->catalog()->routes($path)['status'] ?? null) === 410) {
                    $this->renderError(410);
                } else {
                    $this->renderError(404);
                }
                return;
            }
            if ($path === '/api/search') {
                $this->search($params);
                return;
            }
            if ($path === '/api/health') {
                $this->catalog()->indexableCount();
                $this->json(200, ['ok' => true, 'source' => 'opencart']);
                return;
            }
            if ($path === '/robots.txt') {
                $this->send(200, 'text/plain; charset=utf-8', "User-agent: *\nAllow: /\nAllow: /api/assets/\nDisallow: /api/\nDisallow: /admin/\nSitemap: "
                    . Database::siteUrl() . "/sitemap.xml\n");
                return;
            }
            if ($path === '/sitemap' || $path === '/sitemap.xml' || str_starts_with($path, '/sitemaps/')) {
                $document = (new StorefrontSeo(Database::siteUrl(), $this->catalog()))->sitemap($path === '/sitemap' ? '/sitemap.xml' : $path);
                if ($document === null) {
                    $this->renderError(404);
                } else {
                    $this->send(200, 'application/xml; charset=utf-8', $document);
                }
                return;
            }
            if (str_starts_with($path, '/api/assets/')) {
                $asset = $this->catalog()->asset(substr($path, strlen('/api/assets/')));
                if ($asset !== null && $this->stream($asset, $method === 'HEAD')) {
                    return;
                }
                $this->missing($path);
                return;
            }

            $view = $this->pages()->view($path, $params);
            if ($view !== null) {
                $this->render(200, $view);
            } else {
                $this->missing($path);
            }
        } catch (Throwable $error) {
            error_log('CMTEH storefront failed (' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . ')');
            if ($path === '/api/search') {
                $this->json(502, ['error' => 'Поиск временно недоступен.']);
            } elseif ($path === '/api/health') {
                $this->json(503, ['ok' => false, 'source' => 'opencart']);
            } elseif (str_starts_with($path ?? '', '/api/')) {
                $this->json(503, ['error' => 'Сервис временно недоступен.']);
            } else {
                $this->renderError(503);
            }
        }
    }

    private function db(): PDO
    {
        return $this->database ??= Database::connectFromOpenCart();
    }

    private function catalog(): Catalog
    {
        $config = $this->registry->get('config');

        return $this->catalog ??= new Catalog(
            $this->db(),
            DB_PREFIX,
            (int) ($config->get('config_language_id') ?: 1),
            (int) $config->get('config_store_id'),
            Database::privateDir()
        );
    }

    private function pages(): StorefrontPages
    {
        return $this->pages ??= new StorefrontPages($this->catalog(), Database::siteUrl());
    }

    private function search(array $params): void
    {
        if (isset($params['q']) && !is_string($params['q'])) {
            $this->json(400, ['error' => 'Некорректный поисковый запрос.']);
            return;
        }
        $q = trim($params['q'] ?? '');
        if (!mb_check_encoding($q, 'UTF-8')) {
            $this->json(400, ['error' => 'Некорректный поисковый запрос.']);
            return;
        }
        $length = mb_strlen($q);
        $mode = $params['mode'] ?? null;
        if ($length < 2) {
            $this->json(200, ['data' => [], 'meta' => ['total' => 0]]);
            return;
        }
        if ($length > 64 || ($mode !== null && $mode !== 'suggestions')) {
            $this->json(400, ['error' => $length > 64 ? 'Слишком длинный поисковый запрос.' : 'Некорректный режим поиска.']);
            return;
        }
        $suggestions = $mode === 'suggestions';
        $result = $this->catalog()->products(['q' => $q, 'limit' => $suggestions ? 6 : 10]);
        $meta = ['total' => $suggestions ? count($result['products']) : $result['total']];
        if (!$suggestions && $result['partial']) {
            $meta['partial'] = true;
        }
        $this->json(200, ['data' => $result['products'], 'meta' => $meta], cache: 'private, max-age=15');
    }

    private function lead(string $method): void
    {
        if ($method !== 'POST') {
            $this->json(405, ['error' => 'Метод не поддерживается.'], ['Allow' => 'POST']);
            return;
        }
        $siteOrigin = Database::siteUrl();
        $this->response->addHeader('Access-Control-Allow-Origin: ' . $siteOrigin);
        $this->response->addHeader('Access-Control-Allow-Methods: POST');
        $origin = $this->server['HTTP_ORIGIN'] ?? null;
        if (($origin !== null && $origin !== $siteOrigin)
            || ($this->server['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            $this->json(403, ['error' => 'Отправьте заявку с витрины.']);
            return;
        }
        $contentType = (string) ($this->server['CONTENT_TYPE'] ?? '');
        if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
            $this->json(415, ['error' => 'Отправьте заявку в формате JSON.']);
            return;
        }
        $contentLength = (string) ($this->server['CONTENT_LENGTH'] ?? '');
        if (ctype_digit($contentLength) && (int) $contentLength > self::LEAD_BYTES) {
            $this->json(413, ['error' => 'Заявка слишком большая.']);
            return;
        }
        $raw = file_get_contents('php://input', false, null, 0, self::LEAD_BYTES + 1);
        if ($raw === false) {
            $this->json(400, ['error' => 'Не удалось прочитать заявку.']);
            return;
        }
        $result = (new Lead($this->db(), DB_PREFIX))->submit($raw, (string) ($this->server['REMOTE_ADDR'] ?? ''), [
            'content-type' => $contentType, 'origin' => $origin,
        ]);
        $this->json($result['status'], $result['body'], $result['headers'] ?? []);
    }

    private function missing(string $path): void
    {
        $route = $this->legacyRoute($path);
        if (($route['status'] ?? null) === 301 && $route['target'] !== $path && $this->exists($route['target'])) {
            $this->redirect(StorefrontSeo::encodePath($route['target']));
            return;
        }
        $this->renderError(($route['status'] ?? null) === 410 ? 410 : 404);
    }

    private function target(string $path): ?string
    {
        if ($this->exists($path)) {
            return StorefrontSeo::encodePath($path);
        }
        $route = $this->legacyRoute($path);

        return ($route['status'] ?? null) === 301 && $route['target'] !== $path && $this->exists($route['target'])
            ? StorefrontSeo::encodePath($route['target']) : null;
    }

    private function legacyRoute(string $path): ?array
    {
        return $this->catalog()->routes($path) ?? match ($path) {
            '/page/Доставка' => ['status' => 301, 'target' => '/delivery'],
            default => null,
        };
    }

    private function exists(?string $path): bool
    {
        if ($path === null || !StorefrontSeo::validPath($path)) {
            return false;
        }
        if (str_starts_with($path, '/api/assets/')) {
            return $this->catalog()->asset(substr($path, strlen('/api/assets/'))) !== null;
        }
        if (in_array($path, ['/robots.txt', '/sitemap', '/sitemap.xml', '/sitemaps/static.xml', '/api/search', '/api/health'], true)) {
            return true;
        }
        if (preg_match('~^/sitemaps/products-(0|[1-9][0-9]{0,8})\.xml$~D', $path, $matches)) {
            return (int) $matches[1] < (int) ceil($this->catalog()->indexableCount() / 1000);
        }

        return $this->pages()->record($path) !== null;
    }

    private function stream(array $asset, bool $head): bool
    {
        if (!in_array($asset['mime'], Catalog::MEDIA_TYPES, true)) {
            return false;
        }
        $file = @fopen($asset['path'], 'rb');
        if ($file === false) {
            return false;
        }
        try {
            $size = fstat($file)['size'] ?? null;
            $name = $asset['filename'] ?: 'download';
            $disposition = $asset['mime'] === 'application/pdf' ? 'attachment' : 'inline';
            header('Content-Type: ' . $asset['mime']);
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header("Content-Disposition: $disposition; filename*=UTF-8''" . rawurlencode($name));
            if (is_int($size)) {
                header('Content-Length: ' . $size);
            }
            if (!$head) {
                fpassthru($file);
            }
        } finally {
            fclose($file);
        }

        return true;
    }

    private function render(int $status, array $view): void
    {
        $html = $this->registry->get('load')->view('extension/cmteh/storefront/layout', $view);
        $this->send($status, 'text/html; charset=utf-8', $html);
    }

    private function renderError(int $status): void
    {
        try {
            $this->render($status, [
                'page' => $status >= 500 ? 'error' : 'not_found',
                'status' => $status,
                'model' => [], 'params' => [], 'site' => [], 'navigation' => [],
                'jsonLd' => [],
                'meta' => ['title' => $status >= 500 ? 'Витрина временно недоступна' : 'Страница не найдена', 'robots' => 'noindex,follow'],
            ]);
        } catch (Throwable) {
            $this->send($status, 'text/plain; charset=utf-8', $status >= 500 ? 'Витрина временно недоступна.' : 'Страница не найдена.');
        }
    }

    private function redirect(string $target): void
    {
        $this->send(301, 'text/plain; charset=utf-8', 'Адрес страницы изменился.', ['Location' => Database::siteUrl() . $target]);
    }

    private function json(int $status, array $value, array $headers = [], string $cache = 'no-store'): void
    {
        $this->send($status, 'application/json; charset=utf-8',
            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), $headers, $cache);
    }

    private function send(int $status, string $type, string $body, array $headers = [], string $cache = 'no-store'): void
    {
        http_response_code($status);
        foreach (['Content-Type' => $type, 'Cache-Control' => $cache, 'X-Content-Type-Options' => 'nosniff'] + $headers as $key => $value) {
            $this->response->addHeader($key . ': ' . $value);
        }
        $this->response->setOutput($body);
    }
}
