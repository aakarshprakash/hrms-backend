<?php

namespace App\Support;

/**
 * Phone numbers as stored by people ("98470 12345", "+91-9847012345",
 * "09847012345") → E.164 ("+919847012345"). Ten-digit numbers are taken as
 * Indian mobiles; anything else must carry its country code.
 */
final class Phone
{
    public static function e164(?string $raw, string $defaultCountryCode = '91'): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $international = str_starts_with(trim($raw), '+') || str_starts_with(trim($raw), '00');
        $digits = preg_replace('/\D+/', '', $raw);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (! $international) {
            if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
                $digits = substr($digits, 1);
            }
            if (strlen($digits) === 10) {
                $digits = $defaultCountryCode . $digits;
            }
        }

        return strlen($digits) >= 11 && strlen($digits) <= 15 ? '+' . $digits : null;
    }

    /** "+919847012345" → "919847012345" (providers that want no plus). */
    public static function digits(string $e164): string
    {
        return ltrim($e164, '+');
    }

    /** For display in logs: +91 98XXXXXX45. */
    public static function mask(string $e164): string
    {
        $d = self::digits($e164);

        return '+' . substr($d, 0, 4) . str_repeat('X', max(0, strlen($d) - 6)) . substr($d, -2);
    }
}
