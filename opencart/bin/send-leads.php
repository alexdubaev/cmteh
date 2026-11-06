<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Cmteh\Database;
use Cmteh\LeadMail;
use Cmteh\LeadOutbox;

umask(0077);
$options = getopt('', ['config:', 'smtp', 'capture:', 'limit:'], $firstArgument);
$config = $options['config'] ?? null;
$limit = $options['limit'] ?? '5';
$useSmtp = array_key_exists('smtp', $options);
$capture = $options['capture'] ?? null;

if ($firstArgument !== $argc || !is_string($config) || !is_readable($config)
    || !is_string($limit) || !preg_match('/^(?:[1-9]|10)$/D', $limit)
    || ($useSmtp && $capture !== null) || (!$useSmtp && !is_string($capture))) {
    fwrite(STDERR, "Usage: php send-leads.php --config=OPEN_CART_CONFIG (--smtp | --capture=TEST.ndjson) [--limit=5]\n");
    exit(2);
}

try {
    require $config;
    $system = rtrim(DIR_EXTENSION, '/\\') . '/cmteh/system/';
    require_once $system . 'Database.php';
    require_once $system . 'LeadOutbox.php';
    require_once $system . 'LeadMail.php';

    $private = Database::privateDir();
    if ($useSmtp) {
        $settingsFile = $private . '/lead-smtp.json';
        if (is_link($settingsFile) || !is_file($settingsFile) || !is_readable($settingsFile)
            || filesize($settingsFile) > 8192) {
            throw new RuntimeException('Lead SMTP configuration unavailable.');
        }
        $raw = file_get_contents($settingsFile, false, null, 0, 8193);
        if (!is_string($raw) || strlen($raw) > 8192) {
            throw new RuntimeException('Lead SMTP configuration unavailable.');
        }
        $settings = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($settings)) {
            throw new RuntimeException('Lead SMTP configuration invalid.');
        }
        $send = LeadMail::smtp($settings);
    } else {
        $site = parse_url(Database::siteUrl(), PHP_URL_HOST);
        if (!in_array($site, ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new RuntimeException('The lead capture transport requires a loopback site.');
        }
        $send = LeadMail::capture($private, $capture);
    }

    $result = (new LeadOutbox(Database::connectFromOpenCart(), DB_PREFIX))->run($send, (int) $limit);
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['retry'] > 0 || $result['failed'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, "Lead worker stopped. Check the private configuration and application database.\n");
    exit(1);
}
