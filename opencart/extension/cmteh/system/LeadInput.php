<?php

declare(strict_types=1);

namespace Cmteh;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class LeadInput
{
    public static function parse(string $raw): array
    {
        try {
            $body = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Проверьте данные заявки.');
        }
        if (!$body instanceof stdClass) {
            throw new InvalidArgumentException('Проверьте данные заявки.');
        }
        if (self::field($body, 'website', 1000) !== null) {
            throw new InvalidArgumentException('Проверьте данные заявки.');
        }
        if (property_exists($body, 'attachments') || property_exists($body, 'attachment_manifest')) {
            throw new InvalidArgumentException('Вложения к заявке недоступны.');
        }

        $name = self::field($body, 'name', 100);
        if ($name === null || mb_strlen($name, 'UTF-8') < 2) {
            throw new InvalidArgumentException('Укажите имя длиной от 2 до 100 символов.');
        }

        $phone = self::field($body, 'phone', 40);
        if ($phone !== null) {
            $digits = preg_replace('/[^0-9]/', '', $phone);
            if (mb_strlen($phone, 'UTF-8') < 7 || !preg_match('/^\+?[0-9() .-]+$/D', $phone)
                || strlen($digits) < 7) {
                throw new InvalidArgumentException('Укажите корректный телефон.');
            }
            $phone = (str_starts_with($phone, '+') ? '+' : '') . $digits;
        }

        $email = self::field($body, 'email', 254);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Укажите корректный email.');
        }
        if ($phone === null && $email === null) {
            throw new InvalidArgumentException('Укажите телефон или email.');
        }

        $company = self::field($body, 'company', 1000);
        $message = self::field($body, 'message', 3000, true);
        $combined = implode("\n\n", array_filter([
            $company !== null ? 'Компания: ' . $company : null,
            $message,
        ], static fn (?string $part): bool => $part !== null));
        if (mb_strlen($combined, 'UTF-8') > 3000) {
            throw new InvalidArgumentException('Компания и комментарий вместе превышают 3000 символов.');
        }

        $pageUrl = self::field($body, 'page_url', 1000);
        if ($pageUrl !== null) {
            $parts = parse_url($pageUrl);
            if (!filter_var($pageUrl, FILTER_VALIDATE_URL) || !is_array($parts)
                || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])) {
                throw new InvalidArgumentException('Укажите корректный адрес страницы.');
            }
        } elseif (defined('HTTP_SERVER')) {
            $pageUrl = Database::siteUrl();
        }
        $items = self::items($body);
        $intent = property_exists($body, 'intent') ? self::field($body, 'intent', 16) : null;
        if (property_exists($body, 'intent') && $intent !== 'quick') {
            throw new InvalidArgumentException('Проверьте тип обращения.');
        }
        if ($intent === 'quick' && $items !== []) {
            throw new InvalidArgumentException('Короткое обращение не может содержать позиции корзины.');
        }
        if ($intent !== 'quick' && $items === [] && rtrim((string) parse_url($pageUrl ?? '', PHP_URL_PATH), '/') === '/request') {
            throw new InvalidArgumentException('Добавьте позиции в корзину перед отправкой заявки.');
        }

        $key = self::field($body, 'request_key', 36);
        if ($key !== null && !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $key)) {
            throw new InvalidArgumentException('Проверьте идентификатор заявки.');
        }

        $data = [
            $key === null ? self::uuid() : strtolower($key),
            [
                'name' => $name,
                'phone' => $phone,
                'email' => $email === null ? null : self::normalizeEmail($email),
                'company' => $company,
                'message' => $message,
                'page_url' => $pageUrl,
                'items' => $items,
            ],
        ];
        if ($intent === 'quick') {
            $data[1]['intent'] = 'quick';
        }

        return $data;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function items(stdClass $body): array
    {
        if (!property_exists($body, 'request_items')) {
            return [];
        }
        if (!is_array($body->request_items) || count($body->request_items) > 100) {
            throw new InvalidArgumentException('Проверьте список позиций. Максимум 100 позиций.');
        }

        $items = [];
        foreach ($body->request_items as $item) {
            if (!$item instanceof stdClass) {
                throw new InvalidArgumentException('Проверьте список позиций.');
            }
            $article = self::field($item, 'article', 128);
            $quantity = $item->quantity ?? null;
            if ($article === null || (!is_int($quantity) && !is_float($quantity))
                || $quantity < 1 || $quantity > 100000 || (int) $quantity != $quantity) {
                throw new InvalidArgumentException('Укажите артикул и целое количество от 1 до 100 000.');
            }
            $items[] = ['article' => $article, 'quantity' => (int) $quantity];
        }

        return $items;
    }

    private static function field(stdClass $body, string $field, int $limit, bool $multiline = false): ?string
    {
        $value = $body->{$field} ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Проверьте данные заявки.');
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $control = $multiline ? '/[\x00-\x08\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (mb_strlen($value, 'UTF-8') > $limit || preg_match($control, $value)) {
            throw new InvalidArgumentException('Проверьте длину и формат полей заявки.');
        }

        return $value;
    }

    private static function normalizeEmail(string $email): string
    {
        $at = strrpos($email, '@');

        return substr($email, 0, $at + 1) . strtolower(substr($email, $at + 1));
    }
}
