<?php

declare(strict_types=1);

namespace Cmteh;

use PDO;
use RuntimeException;

final class Database
{
    public static function connectFromOpenCart(): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (defined('DB_SSL_CA') && DB_SSL_CA !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        if (defined('DB_SSL_CERT') && DB_SSL_CERT !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CERT] = DB_SSL_CERT;
        }
        if (defined('DB_SSL_KEY') && DB_SSL_KEY !== '') {
            $options[PDO::MYSQL_ATTR_SSL_KEY] = DB_SSL_KEY;
        }

        return new PDO(
            'mysql:host=' . DB_HOSTNAME . ';port=' . DB_PORT . ';dbname=' . DB_DATABASE . ';charset=utf8mb4',
            DB_USERNAME,
            DB_PASSWORD,
            $options
        );
    }

    public static function privateDir(): string
    {
        $directory = realpath(defined('CMTEH_PRIVATE_DIR') ? CMTEH_PRIVATE_DIR : DIR_STORAGE);
        $documentRoot = realpath(DIR_OPENCART);
        if ($directory === false || $documentRoot === false || !is_dir($directory)) {
            throw new RuntimeException('The private directory must exist.');
        }
        $normalizedDirectory = self::normalizeDirectory($directory);
        $normalizedRoot = self::normalizeDirectory($documentRoot);
        if (str_starts_with($normalizedDirectory, $normalizedRoot)) {
            throw new RuntimeException('The private directory must be outside the document root.');
        }

        return $directory;
    }

    public static function siteUrl(): string
    {
        $url = rtrim(defined('CMTEH_SITE_URL') ? CMTEH_SITE_URL : HTTP_SERVER, '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || array_diff(array_keys($parts), ['scheme', 'host', 'port'])) {
            throw new RuntimeException('CMTEH_SITE_URL must be a URL origin.');
        }
        if ($parts['scheme'] !== 'https'
            && ($parts['scheme'] !== 'http' || !in_array($parts['host'], ['127.0.0.1', 'localhost', '[::1]'], true))) {
            throw new RuntimeException('CMTEH_SITE_URL requires HTTPS except on loopback.');
        }

        return $url;
    }

    private static function normalizeDirectory(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/') . '/';

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
