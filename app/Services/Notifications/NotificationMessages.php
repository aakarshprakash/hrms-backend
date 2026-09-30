<?php

namespace App\Services\Notifications;

use App\Models\AttendanceRegularization;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\OvertimeRequest;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Support\Notifications\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** The words of every notification, per event. */
class NotificationMessages
{
    public const MODULES = [
        Leave::class => 'leave',
        AttendanceRegularization::class => 'regularization',
        OvertimeRequest::class => 'overtime',
    ];

    public static function moduleOf(Model $request): ?string
    {
        return self::MODULES[get_class($request)] ?? null;
    }

    /** "Your approval is needed" for the approver of the current step. */
    public function approvalNeeded(Model $request, User $approver): ?NotificationMessage
    {
        $module = self::moduleOf($request);
        $name = $this->employeeName($request);
        $approverFirst = $this->firstName($approver->name);

        return match ($module) {
            'leave' => new NotificationMessage(
                event: 'leave.submitted',
                title: "Leave request from {$name}",
                body: "{$name} applied for {$this->leaveType($request)}, {$this->leaveDates($request)} ({$this->days($request->days)}). It's waiting for your approval.",
                link: '/approvals',
                params: [$approverFirst, $name, $this->leaveType($request), $this->leaveDates($request)],
                lines: array_values(array_filter([
                    "{$name} applied for {$this->leaveType($request)}: {$this->leaveDates($request)} ({$this->days($request->days)}).",
                    $request->reason ? "Reason: {$request->reason}" : null,
                    'It is waiting for your approval.',
                ])),
                actionLabel: 'Review request',
                companyName: $this->companyName(),
            ),
            'regularization' => new NotificationMessage(
                event: 'regularization.submitted',
                title: "Attendance correction from {$name}",
                body: "{$name} asked to correct their attendance for {$this->date($request->date)}. It's waiting for your approval.",
                link: '/approvals',
                params: [$approverFirst, $name, $this->date($request->date)],
                lines: ["{$name} asked to correct their attendance for {$this->date($request->date)}.", 'It is waiting for your approval.'],
                actionLabel: 'Review request',
                companyName: $this->companyName(),
            ),
            'overtime' => new NotificationMessage(
                event: 'overtime.submitted',
                title: "Overtime claim from {$name}",
                body: "{$name} claimed {$this->hours($request->hours)} of overtime for {$this->date($request->date)}. It's waiting for your approval.",
                link: '/approvals',
                params: [$approverFirst, $name, $this->date($request->date), $this->hours($request->hours)],
                lines: ["{$name} claimed {$this->hours($request->hours)} of overtime for {$this->date($request->date)}.", 'It is waiting for your approval.'],
                actionLabel: 'Review request',
                companyName: $this->companyName(),
            ),
            default => null,
        };
    }

    /** The final decision, for the employee who asked. */
    public function decided(Model $request, bool $approved, ?User $by, ?string $comments = null): ?NotificationMessage
    {
        $module = self::moduleOf($request);
        $decision = $approved ? 'approved' : 'rejected';
        $first = $this->firstName($this->employeeName($request));
        $byText = $by ? " by {$by->name}" : '';
        $comment = $comments ? "Comment: “{$comments}”" : null;

        return match ($module) {
            'leave' => new NotificationMessage(
                event: 'leave.decided',
                title: 'Leave ' . $decision,
                body: "Your {$this->leaveType($request)} for {$this->leaveDates($request)} was {$decision}{$byText}." . ($comments ? " “{$comments}”" : ''),
                link: '/leaves',
                params: [$first, $this->leaveType($request), $this->leaveDates($request), $decision],
                lines: array_values(array_filter(["Your {$this->leaveType($request)} for {$this->leaveDates($request)} ({$this->days($request->days)}) was {$decision}{$byText}.", $comment])),
                actionLabel: 'View my leave',
                companyName: $this->companyName(),
            ),
            'regularization' => new NotificationMessage(
                event: 'regularization.decided',
                title: 'Attendance correction ' . $decision,
                body: "Your attendance correction for {$this->date($request->date)} was {$decision}{$byText}." . ($comments ? " “{$comments}”" : ''),
                link: '/attendance',
                params: [$first, $this->date($request->date), $decision],
                lines: array_values(array_filter(["Your attendance correction for {$this->date($request->date)} was {$decision}{$byText}.", $comment])),
                actionLabel: 'View my attendance',
                companyName: $this->companyName(),
            ),
            'overtime' => new NotificationMessage(
                event: 'overtime.decided',
                title: 'Overtime claim ' . $decision,
                body: "Your overtime claim of {$this->hours($request->hours)} for {$this->date($request->date)} was {$decision}{$byText}." . ($comments ? " “{$comments}”" : ''),
                link: '/overtime',
                params: [$first, $this->date($request->date), $this->hours($request->hours), $decision],
                lines: array_values(array_filter(["Your overtime claim of {$this->hours($request->hours)} for {$this->date($request->date)} was {$decision}{$byText}.", $comment])),
                actionLabel: 'View overtime',
                companyName: $this->companyName(),
            ),
            default => null,
        };
    }

    public function leaveCancelled(Leave $leave, User $by, ?string $reason = null): NotificationMessage
    {
        $first = $this->firstName($this->employeeName($leave));

        return new NotificationMessage(
            event: 'leave.cancelled',
            title: 'Leave cancelled',
            body: "Your {$this->leaveType($leave)} for {$this->leaveDates($leave)} was cancelled by {$by->name}." . ($reason ? " “{$reason}”" : ''),
            link: '/leaves',
            params: [$first, $this->leaveType($leave), $this->leaveDates($leave)],
            lines: array_values(array_filter([
                "Your {$this->leaveType($leave)} for {$this->leaveDates($leave)} was cancelled by {$by->name}. The days are back in your balance.",
                $reason ? "Reason: “{$reason}”" : null,
            ])),
            actionLabel: 'View my leave',
            companyName: $this->companyName(),
        );
    }

    public function payslipPublished(Payslip $payslip, PayrollRun $run, Employee $employee): NotificationMessage
    {
        $month = CarbonImmutable::create($run->year, $run->month, 1)->format('F Y');
        $net = '₹' . number_format((float) $payslip->net_pay, 0);

        return new NotificationMessage(
            event: 'payslip.published',
            title: "Payslip for {$month}",
            body: "Your payslip for {$month} is ready. Net pay {$net}.",
            link: '/payroll/payslips',
            params: [$employee->first_name, $month, $net],
            lines: ["Your payslip for {$month} is ready.", "Net pay: {$net}."],
            actionLabel: 'View payslip',
            companyName: $this->companyName(),
        );
    }

    private function employeeName(Model $request): string
    {
        $employee = $request->relationLoaded('employee') && $request->employee
            ? $request->employee
            : Employee::withoutGlobalScope(BranchScope::class)->find($request->employee_id);

        return $employee ? trim("{$employee->first_name} {$employee->last_name}") : 'An employee';
    }

    private function leaveType(Leave $leave): string
    {
        return $leave->leaveType?->name ?? 'leave';
    }

    private function leaveDates(Leave $leave): string
    {
        $from = CarbonImmutable::parse($leave->start_date);
        $to = CarbonImmutable::parse($leave->end_date);
        $text = $from->equalTo($to) ? $from->format('j M Y') : ($from->month === $to->month
            ? $from->format('j') . '–' . $to->format('j M Y')
            : $from->format('j M') . ' – ' . $to->format('j M Y'));

        return $leave->half_day_session ? $text . ' (' . str_replace('_', ' ', $leave->half_day_session) . ')' : $text;
    }

    private function days($days): string
    {
        $d = (float) $days;

        return rtrim(rtrim(number_format($d, 2, '.', ''), '0'), '.') . ($d == 1.0 ? ' day' : ' days');
    }

    private function hours($hours): string
    {
        $h = (float) $hours;

        return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.') . ($h == 1.0 ? ' hour' : ' hours');
    }

    private function date($date): string
    {
        return CarbonImmutable::parse($date)->format('j M Y');
    }

    private function firstName(?string $name): string
    {
        return trim(explode(' ', trim((string) $name))[0] ?? '') ?: 'there';
    }

    private function companyName(): ?string
    {
        return app(TenantContext::class)->company()?->name;
    }
}
