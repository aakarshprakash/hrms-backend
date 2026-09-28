<?php

namespace App\Support\Notifications;

/**
 * Everything the product notifies people about, and the order of the
 * variables each event passes to SMS (DLT) and WhatsApp templates -- a
 * tenant registers templates with exactly these variables, in this order.
 *
 * In-app notifications are on by default; email, SMS and WhatsApp are
 * switched on per event by the tenant.
 */
final class NotificationEvents
{
    public const CHANNELS = ['in_app', 'email', 'sms', 'whatsapp'];

    public const EXTERNAL = ['email', 'sms', 'whatsapp'];

    public const ALL = [
        'leave.submitted' => [
            'group' => 'Leave', 'label' => 'Leave request waiting for approval', 'to' => 'Approver',
            'variables' => ['Approver name', 'Employee name', 'Leave type', 'Dates'],
        ],
        'leave.decided' => [
            'group' => 'Leave', 'label' => 'Leave approved or rejected', 'to' => 'Employee',
            'variables' => ['Employee name', 'Leave type', 'Dates', 'Decision'],
        ],
        'leave.cancelled' => [
            'group' => 'Leave', 'label' => 'Leave cancelled by HR or an approver', 'to' => 'Employee',
            'variables' => ['Employee name', 'Leave type', 'Dates'],
        ],
        'regularization.submitted' => [
            'group' => 'Attendance', 'label' => 'Attendance correction waiting for approval', 'to' => 'Approver',
            'variables' => ['Approver name', 'Employee name', 'Date'],
        ],
        'regularization.decided' => [
            'group' => 'Attendance', 'label' => 'Attendance correction approved or rejected', 'to' => 'Employee',
            'variables' => ['Employee name', 'Date', 'Decision'],
        ],
        'overtime.submitted' => [
            'group' => 'Attendance', 'label' => 'Overtime claim waiting for approval', 'to' => 'Approver',
            'variables' => ['Approver name', 'Employee name', 'Date', 'Hours'],
        ],
        'overtime.decided' => [
            'group' => 'Attendance', 'label' => 'Overtime claim approved or rejected', 'to' => 'Employee',
            'variables' => ['Employee name', 'Date', 'Hours', 'Decision'],
        ],
        'shift_swap.requested' => [
            'group' => 'Attendance', 'label' => 'Shift swap to accept or approve', 'to' => 'Colleague / approver',
            'variables' => [],
        ],
        'shift_swap.decided' => [
            'group' => 'Attendance', 'label' => 'Shift swap accepted, approved or declined', 'to' => 'Employee',
            'variables' => [],
        ],
        'payslip.published' => [
            'group' => 'Payroll', 'label' => 'Payslip published', 'to' => 'Employee',
            'variables' => ['Employee name', 'Month', 'Net pay'],
        ],
    ];

    public static function exists(string $event): bool
    {
        return array_key_exists($event, self::ALL);
    }

    /** @return list<array<string, mixed>> */
    public static function catalog(): array
    {
        return collect(self::ALL)->map(fn ($e, $key) => $e + ['key' => $key])->values()->all();
    }
}
