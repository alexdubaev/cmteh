<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$options = getopt('', ['config:', 'hosting', 'capture:', 'limit:'], $first);
$config = $options['config'] ?? null; $capture = $options['capture'] ?? null;
$hosting = array_key_exists('hosting', $options); $limit = $options['limit'] ?? '5';
if ($first !== $argc || !is_string($config) || !is_readable($config) || !is_string($limit)
    || !preg_match('/^(?:[1-9]|10)$/D', $limit) || ($hosting && $capture !== null) || (!$hosting && !is_string($capture))) {
    fwrite(STDERR,"Usage: php send-quotes.php --config=OPEN_CART_CONFIG (--hosting | --capture=TEST.eml) [--limit=5]\n"); exit(2);
}
try {
    require $config;
    $system = rtrim(DIR_EXTENSION,'/\\') . '/cmteh/system/';
    require_once $system . 'QuoteOutbox.php'; require_once $system . 'QuoteMail.php';
    $private = \Cmteh\Database::privateDir();
    if ($hosting) {
        $path = $private . '/quote-mail.json';
        if (is_link($path) || !is_file($path) || !is_readable($path) || filesize($path) > 8192) { throw new RuntimeException('Private quote mail settings unavailable.'); }
        $raw = file_get_contents($path,false,null,0,8193);
        if (!is_string($raw) || strlen($raw)>8192) { throw new RuntimeException('Private quote mail settings unavailable.'); }
        $settings = json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($settings)) { throw new RuntimeException('Invalid quote mail settings.'); }
        $send = \Cmteh\QuoteMail::hosting($settings);
    } else {
        if (!in_array(parse_url(\Cmteh\Database::siteUrl(),PHP_URL_HOST),['localhost','127.0.0.1','[::1]'],true)) {
            throw new RuntimeException('Quote capture requires a loopback site.');
        }
        $send = \Cmteh\QuoteMail::capture($private,$capture);
    }
    $counts = (new \Cmteh\QuoteOutbox(\Cmteh\Database::connectFromOpenCart(),DB_PREFIX))->run($send,(int)$limit);
    echo json_encode($counts,JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($counts['retry']>0 || $counts['failed']>0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR,"Quote worker stopped. Check private configuration and application database.\n"); exit(1);
}
