<?php

namespace App\Services\Leave;

/**
 * Which calendar days a leave actually takes, shared by the leave quote,
 * attendance processing and payroll so they can never disagree:
 *
 *   - working days in the range count (0.5 for a half-day leave);
 *   - weekly offs and holidays don't, unless the type has the sandwich
 *     rule and the day falls strictly between the leave's first and last
 *     day (leave on Saturday and Monday then takes Sunday too).
 *
 * Stored leaves always start and end on a working day (the quote trims
 * leading/trailing off days), so "strictly between" is exactly the
 * sandwich definition.
 */
final class LeaveDays
{
    public static function fractionOn(
        string $date,
        string $start,
        string $end,
        bool $isOff,
        bool $sandwich,
        ?string $halfDaySession,
    ): float {
        if ($date < $start || $date > $end) {
            return 0.0;
        }

        if ($isOff && ! ($sandwich && $date > $start && $date < $end)) {
            return 0.0;
        }

        return $halfDaySession ? 0.5 : 1.0;
    }

    /**
     * @param  array<string, bool>  $offByDate  date => is weekly off / holiday, covering start..end
     * @return array{start: ?string, end: ?string, dates: array<string, float>, days: float, sandwiched: int}
     */
    public static function count(array $offByDate, bool $sandwich, ?string $halfDaySession): array
    {
        ksort($offByDate);
        $working = array_keys(array_filter($offByDate, fn ($off) => ! $off));

        if (empty($working)) {
            return ['start' => null, 'end' => null, 'dates' => [], 'days' => 0.0, 'sandwiched' => 0];
        }

        $start = $working[0];
        $end = $working[count($working) - 1];
        $dates = [];
        $sandwiched = 0;

        foreach ($offByDate as $date => $off) {
            $fraction = self::fractionOn($date, $start, $end, $off, $sandwich, $halfDaySession);
            if ($fraction > 0) {
                $dates[$date] = $fraction;
                $sandwiched += $off ? 1 : 0;
            }
        }

        return ['start' => $start, 'end' => $end, 'dates' => $dates, 'days' => array_sum($dates), 'sandwiched' => $sandwiched];
    }
}
