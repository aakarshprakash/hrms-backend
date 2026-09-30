<?php

namespace App\Services\Leave;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\Attendance\AttendanceProcessor;
use App\Services\Attendance\ScheduleResolver;
use App\Services\Notifications\NotificationMessages;
use App\Services\Notifications\Notifier;
use App\Support\Tenancy\TenantStorage;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applying for, approving and cancelling leave -- one set of rules for the
 * apply form's live preview (quote), submission and approval:
 *
 *  - the type is active, in the employee's branch, and applies to them
 *    (gender, minimum service);
 *  - days are the employee's own working days (their weekly offs, roster
 *    days off and holidays excluded; sandwich rule per type), half days on
 *    a single date only;
 *  - notice period, maximum consecutive days, no overlap with other
 *    pending/approved leave, nothing inside a finalized payroll;
 *  - enough balance once other pending requests are set aside, unless the
 *    type allows going negative (loss of pay never needs a balance);
 *  - a supporting document above the type's day threshold.
 *
 * Approval debits the balance through the ledger and re-derives attendance
 * for days already past; cancelling approved leave credits it back.
 */
class LeaveRequestService
{
    public function __construct(
        private LeaveLedger $ledger,
        private LeaveYear $years,
        private LeaveAccrualService $accrual,
        private ScheduleResolver $schedules,
        private ApprovalWorkflowService $workflow,
    ) {
    }

    /**
     * @param  array{on_behalf?: bool, ignore_leave_id?: ?int}  $options
     * @return array<string, mixed>
     */
    public function quote(Employee $employee, LeaveType $type, string $start, string $end, ?string $session = null, array $options = []): array
    {
        $errors = [];
        $warnings = [];
        $start = CarbonImmutable::parse($start)->toDateString();
        $end = CarbonImmutable::parse($end)->toDateString();
        $today = $this->today($employee);

        $result = [
            'leave_type_id' => $type->id,
            'requested' => ['start_date' => $start, 'end_date' => $end],
            'start_date' => $start,
            'end_date' => $end,
            'half_day_session' => $session,
            'days' => 0.0,
            'dates' => [],
            'sandwiched_days' => 0,
            'year' => null,
            'balance' => null,
            'unlimited' => $type->isUnlimited(),
            'paid' => (bool) $type->paid,
            'requires_document' => false,
        ];

        if ($end < $start) {
            $errors[] = 'The end date is before the start date.';

            return $this->finish($result, $errors, $warnings);
        }
        if (CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) > 366) {
            $errors[] = 'A single leave request can cover at most a year.';

            return $this->finish($result, $errors, $warnings);
        }

        if (! $type->is_active) {
            $errors[] = "{$type->name} is no longer available.";
        }
        if ((int) $type->branch_id !== (int) $employee->branch_id) {
            $errors[] = "{$type->name} is not available in {$this->branchName($employee)}.";
        }
        if (! $type->appliesTo($employee)) {
            $errors[] = "{$type->name} is only for {$type->applicable_gender} employees.";
        }
        if ($session !== null) {
            if (! $type->allow_half_day) {
                $errors[] = "{$type->name} can't be taken as a half day.";
            }
            if ($start !== $end) {
                $errors[] = 'A half day starts and ends on the same date.';
            }
        }
        if ($type->min_service_days > 0 && $employee->date_of_joining) {
            $eligible = CarbonImmutable::parse($employee->date_of_joining)->addDays($type->min_service_days);
            if ($eligible->toDateString() > $start) {
                $errors[] = "{$type->name} becomes available after {$type->min_service_days} days of service, from {$eligible->format('j M Y')}.";
            }
        }
        if ($employee->date_of_leaving && $end > CarbonImmutable::parse($employee->date_of_leaving)->toDateString()) {
            $errors[] = 'The leave runs past the last working day.';
        }

        // Which days the leave takes, by this employee's own schedule.
        $schedule = $this->schedules->forEmployee($employee, $start, $end);
        $offByDate = [];
        foreach ($schedule as $date => $day) {
            $offByDate[$date] = ! $day->isWorkingDay();
        }
        $count = LeaveDays::count($offByDate, (bool) $type->sandwich_rule, $session);

        if ($count['days'] <= 0) {
            $errors[] = $start === $end
                ? 'That day is a weekly off or holiday for you.'
                : 'There are no working days in the selected dates.';

            return $this->finish($result, $errors, $warnings);
        }

        $from = $count['start'];
        $to = $count['end'];
        $days = round($count['days'], 2);
        $result['start_date'] = $from;
        $result['end_date'] = $to;
        $result['days'] = $days;
        $result['dates'] = $count['dates'];
        $result['sandwiched_days'] = $count['sandwiched'];

        if ($from !== $start || $to !== $end) {
            $warnings[] = 'Weekly offs / holidays at the ends are left out: leave runs '
                . CarbonImmutable::parse($from)->format('D j M') . ($from !== $to ? ' – ' . CarbonImmutable::parse($to)->format('D j M') : '') . '.';
        }
        if ($count['sandwiched'] > 0) {
            $warnings[] = "Includes {$count['sandwiched']} weekly off / holiday day(s) between leave days ({$type->name} follows the sandwich rule).";
        }

        $year = $this->years->of($employee->company_id, $from);
        $result['year'] = $year;
        if ($this->years->of($employee->company_id, $to) !== $year) {
            $errors[] = 'Leave can’t span two leave years; please apply separately for each year.';
        }

        if ($type->max_consecutive_days && $days > $type->max_consecutive_days) {
            $errors[] = "{$type->name} can be taken for at most {$type->max_consecutive_days} day(s) at a time.";
        }

        if (empty($options['on_behalf']) && $type->min_notice_days > 0) {
            $notice = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($from), false);
            if ($notice < $type->min_notice_days) {
                $errors[] = "{$type->name} needs to be applied for at least {$type->min_notice_days} day(s) in advance.";
            }
        }

        $clash = $this->overlapping($employee, $from, $to, $session, $options['ignore_leave_id'] ?? null);
        if ($clash) {
            $errors[] = sprintf('This overlaps your %s %s leave (%s).', $clash->status, $clash->leaveType?->name ?? '',
                $this->rangeLabel($clash->start_date->toDateString(), $clash->end_date->toDateString()));
        }

        if ($month = $this->lockedMonth($employee, $from, $to)) {
            $errors[] = "Payroll for {$month} is already finalized, so leave can no longer be added or changed for it.";
        }

        if (! $type->isUnlimited()) {
            $this->accrual->syncEmployee($employee, CarbonImmutable::parse($today));

            $current = (float) (LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
                ->where('year', $year)->value('balance') ?? 0);
            $pending = $this->pendingDays($employee->id, $type->id, $year, $options['ignore_leave_id'] ?? null);
            $available = round($current - $pending, 2);

            $result['balance'] = [
                'balance' => $current,
                'pending' => $pending,
                'available' => $available,
                'after' => round($available - $days, 2),
            ];

            if ($days > $available) {
                if ($type->allow_negative) {
                    $warnings[] = "This takes your {$type->name} balance below zero.";
                } elseif ($year > $this->years->of($employee->company_id, $today) && $available <= 0) {
                    $errors[] = "{$type->name} for next leave year isn't credited yet.";
                } else {
                    $errors[] = $available > 0
                        ? "Not enough {$type->name} balance: {$this->fmt($available)} day(s) available."
                        : "No {$type->name} balance available.";
                }
            }
        }

        if (! $type->paid) {
            $warnings[] = "{$type->name} is unpaid: these days are deducted from salary.";
        }

        $result['requires_document'] = $type->requires_document_after_days !== null && $days > $type->requires_document_after_days;

        return $this->finish($result, $errors, $warnings);
    }

    /**
     * @param  array{leave_type_id: int, start_date: string, end_date: string, half_day_session?: ?string, reason?: ?string, source_attendance_id?: ?int}  $data
     */
    public function submit(Employee $employee, LeaveType $type, array $data, User $actor, ?UploadedFile $attachment = null,
        bool $onBehalf = false, bool $approveNow = false, ?string $comments = null): Leave
    {
        $quote = $this->quote($employee, $type, $data['start_date'], $data['end_date'], $data['half_day_session'] ?? null, ['on_behalf' => $onBehalf]);

        if ($quote['errors']) {
            throw ValidationException::withMessages(['leave' => $quote['errors']]);
        }
        if ($quote['requires_document'] && ! $attachment && empty($data['source_attendance_id'])) {
            throw ValidationException::withMessages(['attachment' => [
                "Please attach a supporting document: {$type->name} for more than {$type->requires_document_after_days} day(s) needs one.",
            ]]);
        }

        $leave = DB::transaction(function () use ($employee, $type, $data, $actor, $attachment, $onBehalf, $approveNow, $comments, $quote) {
            $leave = Leave::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'source_attendance_id' => $data['source_attendance_id'] ?? null,
                'start_date' => $quote['start_date'],
                'end_date' => $quote['end_date'],
                'days' => $quote['days'],
                'half_day_session' => $data['half_day_session'] ?? null,
                'leave_year' => $quote['year'],
                'reason' => $data['reason'] ?? null,
                'status' => 'pending',
                'applied_by' => $onBehalf ? $actor->id : null,
            ]);

            if ($attachment) {
                $name = Str::uuid() . '.' . strtolower($attachment->getClientOriginalExtension() ?: $attachment->extension());
                $path = $attachment->storeAs(TenantStorage::path($employee->company_id, 'leave-attachments'), $name, 'local');
                $leave->forceFill(['attachment_path' => $path])->save();
            }

            if ($approveNow) {
                $this->workflow->approveDirectly($leave, 'leave', $employee->branch_id, $actor, $comments);
            } else {
                $this->workflow->submitForApproval($leave, 'leave', $employee->branch_id);
            }

            return $leave;
        });

        return $leave->fresh(['employee', 'leaveType']);
    }

    /**
     * Checks before an approver's decision takes effect: nothing finalized
     * in payroll, and (for types that can't go negative) the balance still
     * covers it -- approvals are first come, first served.
     */
    public function assertApprovable(Leave $leave): void
    {
        $employee = $leave->employee;
        $type = $leave->leaveType;

        if ($month = $this->lockedMonth($employee, $leave->start_date->toDateString(), $leave->end_date->toDateString())) {
            abort(422, "Payroll for {$month} is already finalized; this leave can't be approved into it.");
        }

        if ($type && ! $type->isUnlimited() && ! $type->allow_negative) {
            $year = $leave->leave_year ?? $this->years->of($employee->company_id, $leave->start_date);
            $this->accrual->syncEmployee($employee, CarbonImmutable::parse($this->today($employee)));
            $balance = (float) (LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
                ->where('year', $year)->value('balance') ?? 0);

            if ((float) $leave->days > $balance) {
                abort(422, "Not enough {$type->name} balance to approve: {$this->fmt($balance)} day(s) left.");
            }
        }
    }

    /** Effects of a final approval (called from Leave::onApproved). */
    public function applyApproval(Leave $leave): void
    {
        $leave->loadMissing(['employee', 'leaveType']);
        $employee = $leave->employee;
        $type = $leave->leaveType;

        if (! $employee || ! $type) {
            return;
        }

        if (! $type->isUnlimited()) {
            $year = $leave->leave_year ?? $this->years->of($employee->company_id, $leave->start_date);
            $this->ledger->post($this->ledger->balanceFor($employee, $type, $year), 'availed', -1 * (float) $leave->days, [
                'leave_id' => $leave->id,
                'note' => $this->rangeLabel($leave->start_date->toDateString(), $leave->end_date->toDateString())
                    . ($leave->half_day_session ? ' (' . str_replace('_', ' ', $leave->half_day_session) . ')' : ''),
            ]);
        }

        // A manual "absent" row converted to leave isn't reprocessed below.
        if ($leave->source_attendance_id && ! $leave->half_day_session) {
            Attendance::whereKey($leave->source_attendance_id)->whereNull('locked_at')->update(['status' => 'on_leave']);
        }

        $this->reprocessPast($leave);
    }

    /**
     * Pending leave: the employee withdraws it, or an approver does.
     * Approved leave: the employee may cancel it before it starts; after
     * that only an approver can, and never inside a finalized payroll.
     */
    public function cancel(Leave $leave, User $actor, bool $asApprover, ?string $reason = null): Leave
    {
        $leave->loadMissing(['employee', 'leaveType']);

        abort_unless(in_array($leave->status, ['pending', 'approved'], true), 422, 'Only pending or approved leave can be cancelled.');

        $wasApproved = $leave->status === 'approved';

        if ($wasApproved) {
            if ($month = $this->lockedMonth($leave->employee, $leave->start_date->toDateString(), $leave->end_date->toDateString())) {
                abort(422, "Payroll for {$month} is already finalized, so this leave can no longer be cancelled.");
            }
            abort_if(! $asApprover && $leave->start_date->toDateString() <= $this->today($leave->employee), 422,
                'Leave that has already started can only be cancelled by your approver or HR.');
        }

        DB::transaction(function () use ($leave, $actor, $reason, $wasApproved) {
            $leave->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ]);

            if ($wasApproved && $leave->leaveType && ! $leave->leaveType->isUnlimited()) {
                $year = $leave->leave_year ?? $this->years->of($leave->employee->company_id, $leave->start_date);
                $this->ledger->post($this->ledger->balanceFor($leave->employee, $leave->leaveType, $year), 'reversal', (float) $leave->days, [
                    'leave_id' => $leave->id,
                    'note' => 'Cancelled: ' . $this->rangeLabel($leave->start_date->toDateString(), $leave->end_date->toDateString()),
                ]);
            }
        });

        if ($wasApproved) {
            $this->reprocessPast($leave);
        }

        // HR / an approver cancelled it: tell the employee.
        if ($actor->employee_id !== $leave->employee_id) {
            $employeeUser = User::query()->where('employee_id', $leave->employee_id)->where('is_active', true)->first();
            app(Notifier::class)->send($employeeUser, app(NotificationMessages::class)->leaveCancelled($leave, $actor, $reason));
        }

        return $leave->fresh(['employee', 'leaveType']);
    }

    public function pendingDays(int $employeeId, int $typeId, int $year, ?int $ignoreLeaveId = null): float
    {
        return round((float) Leave::where('employee_id', $employeeId)
            ->where('leave_type_id', $typeId)
            ->where('status', 'pending')
            ->where('leave_year', $year)
            ->when($ignoreLeaveId, fn ($q, $id) => $q->whereKeyNot($id))
            ->sum('days'), 2);
    }

    /**
     * Every leave type an employee can use, with their balance for the
     * leave year: what's been credited, taken, pending and still available.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(Employee $employee, ?int $year = null): array
    {
        $today = CarbonImmutable::parse($this->today($employee));
        $currentYear = $this->years->of($employee->company_id, $today);
        $year ??= $currentYear;

        if ($year === $currentYear) {
            $this->accrual->syncEmployee($employee, $today);
        }

        $types = LeaveType::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $employee->branch_id)
            ->orderBy('name')
            ->get()
            ->filter(fn (LeaveType $t) => $t->appliesTo($employee));

        $balances = LeaveBalance::where('employee_id', $employee->id)->where('year', $year)->get()->keyBy('leave_type_id');
        $pending = Leave::where('employee_id', $employee->id)->where('leave_year', $year)->where('status', 'pending')
            ->groupBy('leave_type_id')->selectRaw('leave_type_id, SUM(days) as days')->pluck('days', 'leave_type_id');
        $taken = Leave::where('employee_id', $employee->id)->where('leave_year', $year)->where('status', 'approved')
            ->groupBy('leave_type_id')->selectRaw('leave_type_id, SUM(days) as days')->pluck('days', 'leave_type_id');

        [$yearStart, $yearEnd] = $this->years->bounds($employee->company_id, $year);

        return $types
            ->filter(fn (LeaveType $t) => $t->is_active || $balances->has($t->id))
            ->map(function (LeaveType $t) use ($balances, $pending, $taken) {
                $b = $balances->get($t->id);
                $pendingDays = round((float) ($pending[$t->id] ?? 0), 2);
                $balance = $b ? (float) $b->balance : 0.0;

                return [
                    'leave_type' => $t->only(['id', 'name', 'code', 'color', 'paid', 'accrual', 'days_per_year', 'allow_half_day',
                        'allow_negative', 'requires_document_after_days', 'min_notice_days', 'max_consecutive_days', 'sandwich_rule', 'is_active',
                        'applicable_gender']),
                    'unlimited' => $t->isUnlimited(),
                    'balance_id' => $b?->id,
                    'opening' => (float) ($b->opening ?? 0),
                    'accrued' => (float) ($b->accrued ?? 0),
                    'adjusted' => (float) ($b->adjusted ?? 0),
                    'allocated' => (float) ($b->allocated ?? 0),
                    'used' => (float) ($b->used ?? 0),
                    'taken_days' => round((float) ($taken[$t->id] ?? 0), 2),
                    'carried_forward' => (float) ($b->carried_forward ?? 0),
                    'lapsed' => (float) ($b->lapsed ?? 0),
                    'balance' => $balance,
                    'pending' => $pendingDays,
                    'available' => round($balance - $pendingDays, 2),
                    'accrued_through' => $b?->accrued_through?->toDateString(),
                    'annual_entitlement' => (float) $t->days_per_year,
                ];
            })
            ->values()
            ->map(fn ($row) => $row + ['year' => $year, 'year_start' => $yearStart->toDateString(), 'year_end' => $yearEnd->toDateString()])
            ->all();
    }

    public function today(Employee $employee): string
    {
        $tz = $employee->branch?->timezone ?: config('app.timezone');

        return CarbonImmutable::now($tz)->toDateString();
    }

    /** The first month in the range whose payroll is finalized, e.g. "August 2026". */
    public function lockedMonth(Employee $employee, string $from, string $to): ?string
    {
        $lockedDay = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('locked_at')
            ->min('date');

        if ($lockedDay) {
            return CarbonImmutable::parse($lockedDay)->format('F Y');
        }

        foreach (CarbonPeriod::create(CarbonImmutable::parse($from)->startOfMonth(), '1 month', CarbonImmutable::parse($to)->startOfMonth()) as $month) {
            $finalized = PayrollRun::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $employee->branch_id)
                ->where('year', $month->year)->where('month', $month->month)
                ->whereIn('status', ['finalized', 'paid'])
                ->exists();
            if ($finalized) {
                return $month->format('F Y');
            }
        }

        return null;
    }

    private function overlapping(Employee $employee, string $from, string $to, ?string $session, ?int $ignoreId): ?Leave
    {
        $candidates = Leave::with('leaveType:id,name')
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from)
            ->get();

        return $candidates->first(function (Leave $other) use ($session, $from, $to) {
            // Two half days of the same date in different halves can coexist.
            $sameDay = $from === $to && $other->start_date->toDateString() === $from && $other->end_date->toDateString() === $from;

            return ! ($sameDay && $session && $other->half_day_session && $other->half_day_session !== $session);
        });
    }

    /** Re-derive attendance for the leave's days that have already happened. */
    private function reprocessPast(Leave $leave): void
    {
        $employee = $leave->employee;
        $today = $this->today($employee);
        $from = $leave->start_date->toDateString();

        if ($from <= $today) {
            app(AttendanceProcessor::class)->processEmployee($employee, $from, min($leave->end_date->toDateString(), $today));
        }
    }

    private function finish(array $result, array $errors, array $warnings): array
    {
        return $result + [
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'ok' => empty($errors),
        ];
    }

    private function branchName(Employee $employee): string
    {
        return $employee->branch?->name ?? 'your branch';
    }

    private function rangeLabel(string $from, string $to): string
    {
        $a = CarbonImmutable::parse($from);
        $b = CarbonImmutable::parse($to);

        return $from === $to ? $a->format('j M Y') : $a->format('j M') . ' – ' . $b->format('j M Y');
    }

    private function fmt(float $days): string
    {
        return rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');
    }
}
