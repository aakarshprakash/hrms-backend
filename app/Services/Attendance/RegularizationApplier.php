<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRegularization;
use App\Models\Employee;
use App\Models\Scopes\BranchScope;
use Carbon\CarbonImmutable;

class RegularizationApplier
{
    public function __construct(
        private readonly PunchRecorder $punches,
        private readonly AttendanceProcessor $processor,
    ) {
    }

    public function apply(AttendanceRegularization $regularization): void
    {
        $employee = Employee::withoutGlobalScope(BranchScope::class)->with('branch')->find($regularization->employee_id);
        if (! $employee) {
            return;
        }

        foreach (['requested_check_in' => 'in', 'requested_check_out' => 'out'] as $field => $direction) {
            if ($regularization->{$field}) {
                $this->punches->recordForEmployee($employee, 'regularization', CarbonImmutable::instance($regularization->{$field}), [
                    'direction' => $direction,
                    'external_id' => "reg-{$regularization->id}-{$direction}",
                    'payload' => ['regularization_id' => $regularization->id],
                ]);
            }
        }

        $date = $regularization->date?->toDateString()
            ?? $regularization->attendance?->date?->toDateString()
            ?? CarbonImmutable::instance($regularization->requested_check_in ?? now())
                ->setTimezone($employee->branch?->timezone ?: 'UTC')->toDateString();

        // An approved correction supersedes a manual entry for that day.
        Attendance::where('employee_id', $employee->id)->whereDate('date', $date)
            ->where('source', 'manual')->whereNull('locked_at')
            ->update(['source' => 'regularization']);

        $this->processor->processEmployee($employee, $date, $date);

        $regularization->forceFill([
            'attendance_id' => Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->value('id'),
        ])->saveQuietly();
    }
}
