<?php

declare(strict_types=1);

namespace Cmteh;

use InvalidArgumentException;
use RuntimeException;

final class LeadMail
{
    public static function smtp(array $settings): callable
    {
        $host = $settings['host'] ?? null;
        $port = $settings['port'] ?? null;
        $tls = $settings['tls'] ?? null;
        $from = self::email($settings, 'from');
        $to = self::email($settings, 'to');
        $username = self::credential($settings, 'username');
        $password = self::credential($settings, 'password');
        if (!is_string($host) || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !is_int($port) || $port < 1 || $port > 65535 || !in_array($tls, ['starttls', 'implicit'], true)) {
            throw new InvalidArgumentException('Invalid lead SMTP connection settings.');
        }

        return static function (string $id) use ($host, $port, $tls, $from, $to, $username, $password): void {
            $domain = substr($from, strrpos($from, '@') + 1);
            $subject = '=?UTF-8?B?' . base64_encode('Новая заявка СМ ТЕХНО') . '?=';
            $message = "From: <$from>\r\n"
                . "To: <$to>\r\n"
                . "Subject: $subject\r\n"
                . 'Date: ' . gmdate(DATE_RFC2822) . "\r\n"
                . "Message-ID: <cmteh-lead-$id@$domain>\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode("Заявка $id сохранена. Контакты и позиции доступны в админке СМ ТЕХНО."));
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('Unable to prepare lead notification.');
            }

            $scheme = $tls === 'implicit' ? 'smtps' : 'smtp';
            $smtp = curl_init("$scheme://$host:$port");
            try {
                if ($smtp === false || fwrite($stream, $message) !== strlen($message) || !rewind($stream)) {
                    throw new RuntimeException('Unable to prepare lead notification.');
                }
                $configured = curl_setopt_array($smtp, [
                    CURLOPT_USE_SSL => CURLUSESSL_ALL,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERNAME => $username,
                    CURLOPT_PASSWORD => $password,
                    CURLOPT_MAIL_FROM => "<$from>",
                    CURLOPT_MAIL_RCPT => ["<$to>"],
                    CURLOPT_UPLOAD => true,
                    CURLOPT_INFILE => $stream,
                    CURLOPT_INFILESIZE => strlen($message),
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 6,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_PROXY => '',
                ]);
                if (!$configured || curl_exec($smtp) === false) {
                    throw new RuntimeException('Lead notification was not accepted by SMTP.');
                }
            } finally {
                if ($smtp !== false) {
                    curl_close($smtp);
                }
                fclose($stream);
            }
        };
    }

    public static function capture(string $privateDir, string $filename): callable
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}\.ndjson$/D', $filename)) {
            throw new InvalidArgumentException('Capture requires a plain .ndjson filename.');
        }
        $directory = $privateDir . '/lead-test-capture';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new RuntimeException('Unable to open the private lead test capture directory.');
        }
        $path = $directory . '/' . $filename;

        return static function (string $id) use ($path): void {
            $line = json_encode(['id' => $id], JSON_THROW_ON_ERROR) . "\n";
            if (is_link($path) || (file_exists($path) && !is_file($path))
                || file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
                throw new RuntimeException('Unable to capture the test notification.');
            }
        };
    }

    private static function email(array $settings, string $name): string
    {
        $value = $settings[$name] ?? null;
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $value)) {
            throw new InvalidArgumentException('Invalid lead SMTP email settings.');
        }

        return $value;
    }

    private static function credential(array $settings, string $name): string
    {
        $value = $settings[$name] ?? null;
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Missing lead SMTP credentials.');
        }

        return $value;
    }
}
