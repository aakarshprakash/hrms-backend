<?php

namespace App\Support;

/**
 * Amounts in words the Indian way (thousand, lakh, crore), as printed on
 * payslips and salary letters: 125430.50 -> "One Lakh Twenty Five Thousand
 * Four Hundred Thirty Rupees and Fifty Paise Only".
 */
final class IndianNumberToWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(float $amount): string
    {
        $negative = $amount < 0;
        $amount = abs(round($amount, 2));
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $words = $rupees === 0 ? 'Zero' : self::convert($rupees);
        $result = "{$words} Rupees";
        if ($paise > 0) {
            $result .= ' and ' . self::convert($paise) . ' Paise';
        }

        return ($negative ? 'Minus ' : '') . $result . ' Only';
    }

    private static function convert(int $n): string
    {
        $parts = [];

        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
            if ($n >= $unit) {
                $count = intdiv($n, $unit);
                $parts[] = ($unit === 10000000 ? self::convert($count) : self::belowHundred($count)) . " {$name}";
                $n %= $unit;
            }
        }

        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)] . ' ' . self::ONES[$n % 10]);
    }
}
