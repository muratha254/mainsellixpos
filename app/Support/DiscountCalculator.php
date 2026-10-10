<?php

namespace App\Support;

class DiscountCalculator
{
    public static function normalizeType(?string $type): string
    {
        return $type === 'percent' ? 'percent' : 'fixed';
    }

    public static function money(?string $type, $value, float $base): float
    {
        $value = max(0.0, (float) $value);
        $base = max(0.0, $base);

        if (self::normalizeType($type) === 'percent') {
            return round($base * (min(100.0, $value) / 100), 2);
        }

        return round(min($value, $base), 2);
    }

    public static function percentStored(?string $type, $value): float
    {
        if (self::normalizeType($type) !== 'percent') {
            return 0.0;
        }

        return round(min(100.0, max(0.0, (float) $value)), 2);
    }
}
