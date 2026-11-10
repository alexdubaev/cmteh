<?php
declare(strict_types=1);
namespace Cmteh;

use InvalidArgumentException;
use stdClass;

require_once __DIR__ . '/LeadInput.php';

final class QuoteInput
{
    public const FILE_BYTES = 5242880;
    public const TEXT_BYTES = 1048576;

    public static function parse(string $raw): array
    {
        try { $body = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new InvalidArgumentException('Проверьте данные расценки.'); }
        if (!$body instanceof stdClass || isset($body->request_items) || isset($body->attachments)
            || !empty($body->website)) {
            throw new InvalidArgumentException('Проверьте данные расценки.');
        }
        $name = self::field($body, 'name', 100);
        $phone = self::field($body, 'phone', 40);
        $email = self::field($body, 'email', 254);
        if ($name === null || mb_strlen($name) < 2) { throw new InvalidArgumentException('Укажите имя от 2 до 100 символов.'); }
        if ($phone !== null) {
            if (!preg_match('/^\+?[0-9() .-]+$/D', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 7) {
                throw new InvalidArgumentException('Укажите корректный телефон.');
            }
            $phone = (str_starts_with($phone, '+') ? '+' : '') . preg_replace('/\D/', '', $phone);
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Укажите корректный email.'); }
        if ($email === null && $phone === null) { throw new InvalidArgumentException('Укажите телефон или email.'); }
        if ($email !== null) { $at = strrpos($email, '@'); $email = substr($email, 0, $at + 1) . strtolower(substr($email, $at + 1)); }
        $url = self::field($body, 'page_url', 1000);
        if ($url !== null) {
            $parts = parse_url($url);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
                || isset($parts['user']) || isset($parts['pass'])) { throw new InvalidArgumentException('Проверьте адрес страницы.'); }
        }
        $key = self::field($body, 'request_key', 36);
        if ($key === null || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $key)) {
            throw new InvalidArgumentException('Проверьте идентификатор расценки.');
        }
        $kind = self::field($body, 'input_kind', 4);
        $data = ['name' => $name, 'phone' => $phone, 'email' => $email,
            'message' => self::field($body, 'message', 3000, true), 'page_url' => $url,
            'input_kind' => $kind, 'list_text' => null, 'filename' => null, 'mime' => null,
            'file_hash' => null, 'file_size' => 0, 'bytes' => null];
        if ($kind === 'text') {
            $text = $body->list_text ?? null;
            if (isset($body->file) || !is_string($text) || trim($text) === '' || strlen($text) > self::TEXT_BYTES
                || !mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
                throw new InvalidArgumentException('Введите список до 1 МиБ без вложения.');
            }
            $data['list_text'] = $text;
        } elseif ($kind === 'file') {
            $file = $body->file ?? null;
            if (!$file instanceof stdClass || isset($body->list_text)) { throw new InvalidArgumentException('Выберите исходный файл.'); }
            $name = self::field($file, 'filename', 180);
            $encoded = $file->data_base64 ?? null;
            if ($name === null || preg_match('~[\\\\/:<>"|?*]~u', $name) || $name === '.' || $name === '..'
                || !is_string($encoded) || strlen($encoded) > 4 * (int) ceil(self::FILE_BYTES / 3)
                || !preg_match('~^[A-Za-z0-9+/]*={0,2}$~D', $encoded)) {
                throw new InvalidArgumentException('Проверьте имя и размер файла (до 5 МиБ).');
            }
            $bytes = base64_decode($encoded, true);
            if ($bytes === false || $bytes === '' || strlen($bytes) > self::FILE_BYTES || base64_encode($bytes) !== $encoded) {
                throw new InvalidArgumentException('Проверьте содержимое файла (до 5 МиБ).');
            }
            $data['filename'] = $name;
            $data['mime'] = self::mime($name, $bytes);
            $data['file_hash'] = hash('sha256', $bytes);
            $data['file_size'] = strlen($bytes);
            $data['bytes'] = $bytes;
        } else { throw new InvalidArgumentException('Выберите текст или файл.'); }
        return [strtolower($key), $data];
    }

    private static function mime(string $name, string $bytes): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
        if (isset($types[$ext]) && $mime === $types[$ext]) {
            if ($ext === 'pdf' ? str_starts_with($bytes, '%PDF-') : @getimagesizefromstring($bytes) !== false) { return $mime; }
        }
        if (in_array($ext, ['txt', 'csv'], true)
            && (mb_check_encoding($bytes, 'UTF-8') || mb_check_encoding($bytes, 'Windows-1251'))
            && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bytes)
            && !preg_match('/<\?(?:php|=)|<svg\b|<script\b/i', $bytes)
            // Some libmagic versions report large valid text as an ambiguous binary stream.
            // Permit that result only after the byte/encoding guards; known non-text MIME still fails.
            && in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream'], true)) {
            return $ext === 'csv' ? 'text/csv' : 'text/plain';
        }
        if (in_array($ext, ['heic', 'heif'], true) && substr($bytes, 4, 4) === 'ftyp'
            && in_array(substr($bytes, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'], true)
            && in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)) { return $mime; }
        if ($ext === 'xlsx' && str_starts_with($bytes, "PK\x03\x04")) {
            $temp = tmpfile();
            if ($temp === false) { throw new \RuntimeException('File validation unavailable.'); }
            try {
                if (fwrite($temp, $bytes) !== strlen($bytes)) { throw new \RuntimeException('File validation unavailable.'); }
                $zip = new \ZipArchive();
                if ($zip->open(stream_get_meta_data($temp)['uri']) === true) {
                    try {
                        $type = $zip->statName('[Content_Types].xml');
                        $book = $zip->statName('xl/workbook.xml');
                        if ($type && $book && $type['size'] < 131072 && $book['size'] < 1048576
                            && str_contains((string) $zip->getFromName('[Content_Types].xml'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml')
                            && $zip->locateName('xl/vbaProject.bin') === false) {
                            return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                        }
                    } finally { $zip->close(); }
                }
            } finally { fclose($temp); }
        }
        throw new InvalidArgumentException('Допустимы XLSX, CSV, TXT, PDF, JPG, PNG, WebP, HEIC и HEIF с соответствующим содержимым.');
    }

    private static function field(stdClass $body, string $key, int $max, bool $multiline = false): ?string
    {
        $value = $body->{$key} ?? null;
        if ($value === null || $value === '') { return null; }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max
            || preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Проверьте длину и формат полей.');
        }
        return trim($value) === '' ? null : trim($value);
    }
}
