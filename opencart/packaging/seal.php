<?php

declare(strict_types=1);

/**
 * Run only after OpenCart has written both config files and before public access.
 * Config files contain credentials, so never print their contents.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function pathDirectory(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/') . '/';
}

function checkedWrite(string $path, string $contents, int $mode): void
{
    $temporary = tempnam(dirname($path), '.cmteh-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create a temporary configuration file.');
    }

    try {
        if (!chmod($temporary, $mode) || file_put_contents($temporary, $contents) !== strlen($contents)) {
            throw new RuntimeException('Cannot write configuration.');
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Cannot replace configuration.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function makeBridge(string $path, bool $public): string
{
    $bridge = "<?php\n// CMTEH sealed OpenCart configuration\n";

    if ($public) {
        $bridge .= <<<'PHP'
if (PHP_SAPI !== 'cli') {
    if (array_key_exists('route', $_GET) || array_key_exists('_route_', $_GET)) {
        http_response_code(404);
        exit;
    }
    if (!is_file(__DIR__ . '/extension/cmteh/catalog/controller/storefront/router.php')) {
        http_response_code(503);
        exit;
    }
}

PHP;
    }

    $bridge .= 'require ' . var_export($path, true) . ";\n";
    if ($public) {
        $bridge .= <<<'PHP'
if (PHP_SAPI !== 'cli' && !is_file(CMTEH_PRIVATE_DIR . '/storefront.ready')) {
    http_response_code(503);
    exit;
}
PHP;
        $bridge .= "\n";
    }
    return $bridge;
}

function seal(string $rootArgument, string $privateArgument): void
{
    $root = realpath($rootArgument);
    $private = realpath($privateArgument);
    if ($root === false || $private === false || !is_dir($root) || !is_dir($private)) {
        throw new RuntimeException('Provide existing web root and private directory.');
    }
    if (str_starts_with(pathDirectory($private), pathDirectory($root))) {
        throw new RuntimeException('Private directory must be outside the web root.');
    }
    if (!is_file($root . '/index.php') || !is_file($root . '/admin/index.php')) {
        throw new RuntimeException('Expected an OpenCart installation.');
    }

    $config = $root . '/config.php';
    $adminConfig = $root . '/admin/config.php';
    $publicBridge = makeBridge($private . '/catalog.php', true);
    $adminBridge = makeBridge($private . '/admin.php', false);
    $catalogSource = file_get_contents($config);
    $adminSource = file_get_contents($adminConfig);
    if ($catalogSource === false || $adminSource === false) {
        throw new RuntimeException('OpenCart config files are unreadable.');
    }

    if ($catalogSource === $publicBridge && $adminSource === $adminBridge) {
        if (!is_file($private . '/catalog.php') || !is_file($private . '/admin.php')
            || !is_dir($private . '/storage')) {
            throw new RuntimeException('Sealed configuration or storage is missing.');
        }
    } else {
        $catalogSource = withPrivateStorage($catalogSource, $private);
        $adminSource = withPrivateStorage($adminSource, $private);
        $storage = $root . '/system/storage';
        if (file_exists($private . '/catalog.php') || file_exists($private . '/admin.php')) {
            throw new RuntimeException('Private config already exists. Inspect previous seal before retrying.');
        }
        if (!is_dir($storage) || file_exists($private . '/storage')) {
            throw new RuntimeException('Expected original OpenCart storage and an unused private destination.');
        }

        checkedWrite($private . '/catalog.php', $catalogSource, 0640);
        checkedWrite($private . '/admin.php', $adminSource, 0640);
        if (!rename($storage, $private . '/storage')) {
            throw new RuntimeException('Storage move failed. Keep web access closed and inspect both directories.');
        }
        checkedWrite($config, $publicBridge, 0644);
        checkedWrite($adminConfig, $adminBridge, 0644);
    }

    $access = file_get_contents(__DIR__ . '/htaccess');
    if ($access === false) {
        throw new RuntimeException('Apache configuration is missing.');
    }
    $existing = is_file($root . '/.htaccess') ? file_get_contents($root . '/.htaccess') : null;
    if ($existing !== null && $existing !== $access) {
        throw new RuntimeException('Existing .htaccess differs. Review hosting directives before installing the supplied rules.');
    }
    checkedWrite($root . '/.htaccess', $access, 0644);
}

function withPrivateStorage(string $source, string $private): string
{
    $old = "define('DIR_STORAGE', DIR_SYSTEM . 'storage/');";
    if (substr_count($source, $old) !== 1 || !str_contains($source, "define('DB_PASSWORD',")) {
        throw new RuntimeException('Expected the original installer-generated OpenCart configuration.');
    }
    $replacement = "define('DIR_STORAGE', " . var_export(pathDirectory($private . '/storage'), true) . ");\n"
        . "define('CMTEH_PRIVATE_DIR', " . var_export($private, true) . ");";
    return str_replace($old, $replacement, $source);
}

try {
    if ($argc !== 3) {
        throw new RuntimeException('Usage: php seal.php WEB_ROOT EXISTING_PRIVATE_DIRECTORY');
    }
    seal($argv[1], $argv[2]);
    echo "OpenCart configuration and storage are sealed.\n";
} catch (Throwable $exception) {
    // Runtime warnings and exception traces can contain private filesystem paths.
    fwrite(STDERR, "Seal failed. " . ($exception instanceof RuntimeException ? $exception->getMessage() : 'Check filesystem permissions.') . "\n");
    exit(1);
}
