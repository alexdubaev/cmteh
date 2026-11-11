<?php
declare(strict_types=1);
namespace Cmteh;
require_once __DIR__ . '/InventoryException.php';

final class InventoryInput
{
    public const MAX_QUANTITY = 2147483647;

    public static function integer(mixed $value, string $field, int $minimum = 0): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$value)
            || (int)$value > self::MAX_QUANTITY || (int)$value < $minimum) {
            throw new InventoryException('invalid_input', 'Проверьте целое значение: ' . $field);
        }
        return (int)$value;
    }

    public static function text(mixed $value, string $field, int $max, bool $required = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InventoryException('invalid_input', 'Проверьте текст: ' . $field);
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $max || ($required && $value === '')) {
            throw new InventoryException('invalid_input', 'Проверьте длину: ' . $field);
        }
        return $value;
    }

    public static function price(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/D', $value, $m)) {
            throw new InventoryException('invalid_input', 'Цена должна быть точной суммой в рублях.');
        }
        $result = $m[1] . '.' . str_pad($m[2] ?? '', 2, '0');
        if ($result === '0.00') throw new InventoryException('invalid_input', 'Укажите положительную цену или оставьте цену по запросу.');
        return $result;
    }

    public static function flag(mixed $value): int
    {
        if (!in_array($value, [true,false,0,1,'0','1'], true)) throw new InventoryException('invalid_input','Проверьте подтверждение.');
        return (int)$value;
    }

    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as &$item) $item = self::canonical($item);
        return $value;
    }
}
