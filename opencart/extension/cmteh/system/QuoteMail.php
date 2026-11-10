<?php
declare(strict_types=1);
namespace Cmteh;
use InvalidArgumentException;
use RuntimeException;

final class QuoteMail
{
    private const SETTINGS = ['from' => 'info@cmteh.ru', 'to' => 'info@cmteh.ru', 'from_name' => 'СМТЕХНО'];

    private static function settings(array $settings): array
    {
        foreach (self::SETTINGS as $key => $value) {
            if (($settings[$key] ?? null) !== $value) { throw new InvalidArgumentException('Invalid quote mail settings.'); }
        }
        return self::SETTINGS;
    }

    public static function message(array $quote, array $settings): string
    {
        $settings = self::settings($settings);
        $id = $quote['public_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D', $id)) { throw new InvalidArgumentException('Invalid quote ID.'); }
        $boundary = 'cmteh-quote-' . $id;
        $encoded = static fn(string $value): string => '=?UTF-8?B?' . base64_encode($value) . '?=';
        $message = 'From: ' . $encoded($settings['from_name']) . ' <' . $settings['from'] . ">\r\n"
            . 'To: <' . $settings['to'] . ">\r\nSubject: " . $encoded('Расценка списка') . ' ' . $id . "\r\n"
            . 'Date: ' . gmdate(DATE_RFC2822, strtotime($quote['created_at'] . ' UTC')) . "\r\n"
            . "Message-ID: <cmteh-quote-$id@cmteh.ru>\r\nMIME-Version: 1.0\r\n";
        $email = $quote['email'] ?? null;
        if ($email !== null) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) { throw new InvalidArgumentException('Invalid reply address.'); }
            $message .= "Reply-To: <$email>\r\n";
        }
        $text = "Расценка списка: $id\nИмя: {$quote['name']}\nТелефон: " . ($quote['phone'] ?? '—')
            . "\nEmail: " . ($email ?? '—') . "\nСтраница: " . ($quote['page_url'] ?? '—')
            . "\nКомментарий:\n" . ($quote['message'] ?? '—') . "\n\n";
        if ($quote['input_kind'] === 'text') { $text .= "Исходный список:\n" . $quote['list_text']; }
        else { $text .= "Исходный файл: {$quote['filename']}\nSHA-256: {$quote['file_hash']}"; }
        $message .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n"
            . "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n");
        if ($quote['input_kind'] === 'file') {
            $file = $quote['attachment'] ?? null;
            if (!is_array($file) || !hash_equals($quote['file_hash'], hash('sha256', $file['bytes']))) { throw new RuntimeException('Original unavailable.'); }
            $name = rawurlencode($file['filename']);
            // RFC 2231 continuations keep long UTF-8 filenames within MIME line limits.
            preg_match_all('/(?:%[A-F0-9]{2}|[A-Za-z0-9_.~-]){1,14}/', $name, $chunks);
            $disposition = 'Content-Disposition: attachment';
            foreach ($chunks[0] as $i => $chunk) { $disposition .= ";\r\n filename*$i*=" . ($i === 0 ? "UTF-8''" : '') . $chunk; }
            $message .= "--$boundary\r\nContent-Type: {$file['mime']}\r\n$disposition\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($file['bytes']), 76, "\r\n");
        }
        return $message . "--$boundary--\r\n";
    }

    public static function hosting(array $settings): callable
    {
        $settings = self::settings($settings);
        return static function (array $quote) use ($settings): void {
            $message = self::message($quote, $settings);
            [$headers,$body] = explode("\r\n\r\n", $message, 2);
            // Static envelope recipients and arguments; no shell interpolation or client address.
            $headers = preg_replace('/^(?:To|Subject):[^\r\n]*\r\n/m', '', $headers . "\r\n");
            $subject = '=?UTF-8?B?' . base64_encode('Расценка списка') . '?= ' . $quote['public_id'];
            if (!mail($settings['to'], $subject, $body, rtrim($headers), '-finfo@cmteh.ru')) {
                throw new RuntimeException('Quote not accepted by hosting MTA.');
            }
        };
    }

    public static function capture(string $privateDir, string $filename): callable
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}\.eml$/D', $filename)) { throw new InvalidArgumentException('Plain .eml name required.'); }
        $dir = $privateDir . '/quote-test-capture';
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))) { throw new RuntimeException('Capture unavailable.'); }
        $path = $dir . '/' . $filename;
        return static function(array $quote) use ($path): void {
            $message = self::message($quote, self::SETTINGS);
            if (is_link($path) || (file_exists($path) && !is_file($path))
                || file_put_contents($path,$message,LOCK_EX) !== strlen($message)) { throw new RuntimeException('Capture unavailable.'); }
            chmod($path,0600);
        };
    }
}
