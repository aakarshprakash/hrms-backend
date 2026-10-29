<?php

namespace App\Support\Billing;

/**
 * Billing cycles (config/billing.php "cycles"): the months a period covers,
 * the months charged for it, and the discount that works out to.
 */
final class Cycles
{
    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(config('billing.cycles', []));
    }

    public static function months(string $cycle): int
    {
        return (int) (config("billing.cycles.{$cycle}.months") ?? 1);
    }

    public static function monthsCharged(string $cycle): float
    {
        return (float) (config("billing.cycles.{$cycle}.months_charged") ?? self::months($cycle));
    }

    public static function label(string $cycle): string
    {
        return (string) (config("billing.cycles.{$cycle}.label") ?? ucfirst(str_replace('_', ' ', $cycle)));
    }

    public static function discountPercent(string $cycle): int
    {
        $months = self::months($cycle);

        return $months > 0 ? (int) round((1 - self::monthsCharged($cycle) / $months) * 100) : 0;
    }

    /** For the SPA: [{key, label, months, months_charged, discount_percent}]. */
    public static function all(): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => self::label($key),
            'months' => self::months($key),
            'months_charged' => self::monthsCharged($key),
            'discount_percent' => self::discountPercent($key),
        ], self::keys());
    }
}
