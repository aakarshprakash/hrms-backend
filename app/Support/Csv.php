<?php

namespace App\Support;

/**
 * CSV output that is safe to open in Excel / Sheets: a cell beginning with
 * = + - @ (or a tab / carriage return) would run as a formula, so free text
 * such as a leave reason is prefixed with an apostrophe. Numbers pass
 * through untouched.
 */
final class Csv
{
    /** @param  resource  $handle */
    public static function put($handle, array $row): void
    {
        fputcsv($handle, array_map([self::class, 'cell'], $row));
    }

    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
