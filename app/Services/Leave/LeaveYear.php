<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A tenant's leave year. Most companies run January–December; some follow
 * the financial year (April–March). A leave year is named by the calendar
 * year it starts in, so April 2026 – March 2027 is leave year 2026.
 *
 * Configured per company in settings.leave_year_start_month (1–12).
 */
class LeaveYear
{
    /** @var array<int, int> */
    private array $startMonths = [];

    public function startMonth(int $companyId): int
    {
        return $this->startMonths[$companyId] ??= (function () use ($companyId) {
            $month = (int) (DB::table('companies')->where('id', $companyId)->value('settings->leave_year_start_month') ?? 1);

            return $month >= 1 && $month <= 12 ? $month : 1;
        })();
    }

    public function of(int $companyId, CarbonInterface|string $date): int
    {
        $date = CarbonImmutable::parse($date);

        return $date->month >= $this->startMonth($companyId) ? $date->year : $date->year - 1;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day */
    public function bounds(int $companyId, int $year): array
    {
        $start = CarbonImmutable::create($year, $this->startMonth($companyId), 1)->startOfDay();

        return [$start, $start->addYear()->subDay()];
    }

    public function label(int $companyId, int $year): string
    {
        return $this->startMonth($companyId) === 1 ? (string) $year : $year . '–' . substr((string) ($year + 1), 2);
    }

    public function forget(int $companyId): void
    {
        unset($this->startMonths[$companyId]);
    }
}
