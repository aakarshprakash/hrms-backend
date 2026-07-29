<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceStatusResolver;
use Illuminate\Console\Command;

class RecomputeAttendanceStatus extends Command
{
    protected $signature = 'attendance:recompute-status {--branch_id=}';

    protected $description = 'Backfill shift/late/early/worked-time fields on attendance rows created before the auto-status engine existed';

    public function handle(AttendanceStatusResolver $resolver): int
    {
        $query = Attendance::whereNotNull('check_in')
            ->whereNull('worked_minutes')
            ->where('source', '!=', 'manual'); // manual entries are computed at write time already

        if ($branchId = $this->option('branch_id')) {
            $query->whereHas('employee', fn ($q) => $q->where('branch_id', $branchId));
        }

        $rows = $query->with('employee')->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing to backfill.');
            return self::SUCCESS;
        }

        $employees = Employee::withoutGlobalScopes()->whereIn('id', $rows->pluck('employee_id')->unique())->get()->keyBy('id');
        $updated = 0;

        foreach ($rows as $att) {
            $employee = $employees->get($att->employee_id);
            if (! $employee) {
                continue;
            }

            $resolved = $resolver->resolve($employee, $att->date->toDateString(), $att->check_in, $att->check_out);

            $att->update([
                'shift_id' => $resolved['shift_id'],
                'status' => $resolved['status'],
                'late_by_minutes' => $resolved['late_by_minutes'],
                'early_by_minutes' => $resolved['early_by_minutes'],
                'worked_minutes' => $resolved['worked_minutes'],
            ]);
            $updated++;
        }

        $this->info("Recomputed {$updated} attendance record(s).");
        return self::SUCCESS;
    }
}
