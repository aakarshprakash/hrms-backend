<?php

namespace App\Support\Payroll;

/**
 * Starting configuration for a branch's statutory rule of each type, from
 * config/statutory.php. Copied into the tenant's own editable rule.
 */
final class StatutoryDefaults
{
    public static function config(string $type, ?string $state = null): array
    {
        return match ($type) {
            'PF' => config('statutory.pf'),
            'ESI' => config('statutory.esi'),
            'PT' => array_merge(['state' => $state], self::ptFor($state) ?? ['basis' => 'monthly', 'slabs' => []]),
            'TAX' => ['mode' => 'income_tax'],
            'LWF' => ['employee_amount' => 0, 'employer_amount' => 0, 'months' => [6, 12]],
            default => [],
        };
    }

    /** Professional-tax schedule for a state, matched case-insensitively. */
    public static function ptFor(?string $state): ?array
    {
        if (! $state) {
            return null;
        }

        foreach (config('statutory.pt') as $name => $schedule) {
            if (strcasecmp($name, trim($state)) === 0) {
                return $schedule;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function ptStates(): array
    {
        return array_keys(config('statutory.pt'));
    }
}
