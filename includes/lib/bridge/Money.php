<?php

declare(strict_types=1);

namespace lib\bridge;

use InvalidArgumentException;

final class Money
{
    public static function cents(string $value): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $parts)) {
            throw new InvalidArgumentException('金额必须为非负数，最多两位小数');
        }
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('金额不能为负数');
        }
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
