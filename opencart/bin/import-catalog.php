<?php

declare(strict_types=1);

use Cmteh\Catalog;
use Cmteh\CatalogImport;
use Cmteh\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', [
    'config:', 'input:', 'preview', 'apply', 'confirm:', 'run-key:', 'resume', 'allow-unpublish', 'batch-size:', 'max-batches:',
], $rest);
if ($rest !== $argc || !is_string($options['config'] ?? null) || !is_string($options['input'] ?? null)
    || isset($options['preview']) === isset($options['apply'])) {
    fwrite(STDERR, "Usage: php import-catalog.php --config=/path/to/config.php --input=/private/source.ndjson --preview\n"
        . "   or: php import-catalog.php --config=... --input=... --apply --confirm=SHA256 --run-key=RUN [--resume]\n");
    exit(2);
}

try {
    $config = realpath($options['config']);
    if ($config === false || !is_file($config)) {
        throw new InvalidArgumentException('Конфигурация не найдена');
    }
    require $config;
    $development = dirname(__DIR__) . '/extension/cmteh/system';
    $library = is_file($development . '/Catalog.php') ? $development : DIR_EXTENSION . 'cmteh/system';
    require_once $library . '/Database.php';
    require_once $library . '/Catalog.php';
    require_once $library . '/CatalogImport.php';

    $input = realpath($options['input']);
    $documentRoot = realpath(DIR_OPENCART);
    if ($input === false || $documentRoot === false || str_starts_with($input, $documentRoot . DIRECTORY_SEPARATOR)) {
        throw new InvalidArgumentException('Исходные данные должны храниться вне document root');
    }
    $pdo = Database::connectFromOpenCart();
    $catalog = new Catalog($pdo, DB_PREFIX, 1, 0, Database::privateDir());
    $import = new CatalogImport($pdo, $catalog, DB_PREFIX);
    $allowUnpublish = isset($options['allow-unpublish']);

    if (isset($options['preview'])) {
        $report = $import->preview($input, $allowUnpublish);
    } else {
        $report = $import->apply(
            $input, (string) ($options['confirm'] ?? ''), (string) ($options['run-key'] ?? ''), isset($options['resume']),
            $allowUnpublish, (int) ($options['batch-size'] ?? 200),
            isset($options['max-batches']) ? (int) $options['max-batches'] : null,
        );
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($report['errors'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    $public = $error instanceof InvalidArgumentException || $error::class === RuntimeException::class
        ? $error->getMessage() : 'Не удалось выполнить импорт. Проверьте конфигурацию и наличие миграций.';
    fwrite(STDERR, $public . PHP_EOL);
    exit(1);
}
