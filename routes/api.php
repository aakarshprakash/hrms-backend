<?php

use App\Http\Controllers\Api\AiInsightsController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AttendanceExceptionController;
use App\Http\Controllers\Api\AttendanceProcessingController;
use App\Http\Controllers\Api\AttendanceReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BiometricConfigController;
use App\Http\Controllers\Api\BiometricSyncController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CertificateRequestController;
use App\Http\Controllers\Api\CertificateTemplateController;
use App\Http\Controllers\Api\CertificateTokenController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DesignationController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\ApprovalFlowController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\EmployeeHomeController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\DashboardOverviewController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\Platform\PlatformBillingController;
use App\Http\Controllers\Api\PublicSignupController;
use App\Http\Controllers\Api\MyProfileController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationSettingController;
use App\Http\Controllers\Api\IssuedCertificateController;
use App\Http\Controllers\Api\LeaveBalanceController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\OvertimeRequestController;
use App\Http\Controllers\Api\OvertimeRuleController;
use App\Http\Controllers\Api\PayrollAdjustmentController;
use App\Http\Controllers\Api\PayrollRunController;
use App\Http\Controllers\Api\PayslipController;
use App\Http\Controllers\Api\Platform\PlatformCompanyController;
use App\Http\Controllers\Api\PublicCertificateController;
use App\Http\Controllers\Api\QuickSetupController;
use App\Http\Controllers\Api\RegularizationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RosterController;
use App\Http\Controllers\Api\SalaryComponentController;
use App\Http\Controllers\Api\SalaryStructureController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\ShiftSwapController;
use App\Http\Controllers\Api\StatutoryRuleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Access model
|--------------------------------------------------------------------------
|
| Every authenticated route runs through `tenant` (ResolveTenant): the
| request acts for exactly one organisation and every tenant-owned query is
| filtered to it.
|
| Writes are gated here by permission (`permission:x.y`); tenant admins hold
| every permission. Reads of reference data (departments, shifts, leave
| types, holidays...) and self-service endpoints are open to any signed-in
| member of the organisation -- their controllers narrow results to what
| the user's data scope (company / branch / team / self) allows.
|
*/

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::middleware('throttle:login')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

// Public: what the login page may offer, self-serve sign-up, payment webhook.
Route::get('/public/config', [PublicSignupController::class, 'config'])->middleware('throttle:60,1');
Route::post('/auth/signup', [PublicSignupController::class, 'signup'])->middleware('throttle:signup');
Route::post('/billing/razorpay/webhook', [PublicSignupController::class, 'razorpayWebhook'])->middleware('throttle:120,1');

// Public certificate verification (no auth)
Route::get('/verify/{certificateNumber}', [PublicCertificateController::class, 'verify'])
    ->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {

    // ── Session ───────────────────────────────────────────────────────────
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('permission:employees.manage');

    // ── Platform (super admin only) ───────────────────────────────────────
    Route::prefix('platform')->middleware('can:platform.manage')->group(function () {
        Route::get('/stats', [PlatformCompanyController::class, 'stats']);
        Route::get('/industries', [PlatformCompanyController::class, 'industries']);
        Route::get('/companies', [PlatformCompanyController::class, 'index']);
        Route::post('/companies', [PlatformCompanyController::class, 'store']);
        Route::get('/companies/{company}', [PlatformCompanyController::class, 'show']);
        Route::put('/companies/{company}', [PlatformCompanyController::class, 'update']);
        Route::post('/companies/{company}/status', [PlatformCompanyController::class, 'setStatus']);

        // Billing: plan catalogue, subscriptions, offline payments.
        Route::get('/plans', [PlatformBillingController::class, 'plans']);
        Route::put('/plans/{plan}', [PlatformBillingController::class, 'updatePlan']);
        Route::get('/companies/{company}/subscription', [PlatformBillingController::class, 'show']);
        Route::put('/companies/{company}/subscription', [PlatformBillingController::class, 'update']);
        Route::post('/companies/{company}/invoices', [PlatformBillingController::class, 'issueInvoice']);
        Route::post('/invoices/{invoice}/mark-paid', [PlatformBillingController::class, 'markPaid']);
        Route::post('/invoices/{invoice}/void', [PlatformBillingController::class, 'void']);
    });

    // ── Dashboard ─────────────────────────────────────────────────────────
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/overview', [DashboardOverviewController::class, 'show']);
    Route::get('/dashboard/calendar', [CalendarController::class, 'events']);
    Route::get('/ai/insights', [AiInsightsController::class, 'insights'])->middleware('permission:insights.view')->middleware('feature:insights');

    // ── Organisation ──────────────────────────────────────────────────────
    Route::get('/company', [BranchController::class, 'company']);
    Route::put('/company', [BranchController::class, 'updateCompany'])->middleware('permission:settings.manage');

    Route::get('/branches', [BranchController::class, 'index']);
    Route::get('/branches/{branch}', [BranchController::class, 'show']);
    Route::middleware('permission:settings.manage')->group(function () {
        Route::post('/branches', [BranchController::class, 'store']);
        Route::put('/branches/{branch}', [BranchController::class, 'update']);
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy']);
        Route::post('/quick-setup', [QuickSetupController::class, 'seed']);
        Route::get('/industry-templates', [QuickSetupController::class, 'templates']);
    });

    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::get('/departments/{department}', [DepartmentController::class, 'show']);
    Route::get('/designations', [DesignationController::class, 'index']);
    Route::get('/designations/{designation}', [DesignationController::class, 'show']);
    Route::middleware('permission:departments.manage')->group(function () {
        Route::post('/departments', [DepartmentController::class, 'store']);
        Route::put('/departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);
        Route::post('/designations', [DesignationController::class, 'store']);
        Route::put('/designations/{designation}', [DesignationController::class, 'update']);
        Route::delete('/designations/{designation}', [DesignationController::class, 'destroy']);
    });

    // ── Audit trail ───────────────────────────────────────────────────────
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->middleware('feature:audit_log');
    Route::get('/employees/{employee}/audit', [AuditLogController::class, 'employee'])->middleware('permission:employees.manage')->middleware('feature:audit_log');

    // ── Users & roles ─────────────────────────────────────────────────────
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/roles', [UserController::class, 'roles']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });
    Route::middleware('permission:roles.manage')->group(function () {
        Route::get('/roles/manage', [RoleController::class, 'index']);
        Route::get('/permissions', [RoleController::class, 'permissions']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
    });

    // Plan module: biometric devices
    Route::middleware('feature:biometric')->group(function () {
        // ── Biometric integration ─────────────────────────────────────────────
        Route::middleware('permission:settings.manage')->group(function () {
            Route::get('biometric-configs', [BiometricConfigController::class, 'index']);
            Route::get('branches/{branch}/biometric-config', [BiometricConfigController::class, 'show']);
            Route::put('branches/{branch}/biometric-config', [BiometricConfigController::class, 'update']);
        });
        Route::middleware('permission:attendance.manage|settings.manage')->group(function () {
            Route::post('branches/{branch}/biometric-config/sync', [BiometricSyncController::class, 'sync']);
            Route::get('branches/{branch}/biometric-config/logs', [BiometricSyncController::class, 'logs']);
        });
    });

    // ── Employees ─────────────────────────────────────────────────────────
    // Reads: EmployeePolicy + data scope. Writes: policy on top of the permission.
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::post('/employees/{employee}/avatar', [EmployeeController::class, 'uploadAvatar']);
    Route::get('/employees/{employee}/documents', [DocumentController::class, 'index']);
    Route::post('/employees/{employee}/documents', [DocumentController::class, 'upload']);
    Route::get('/employees/{employee}/documents/{media}/download', [DocumentController::class, 'download']);
    Route::delete('/employees/{employee}/documents/{media}', [DocumentController::class, 'destroy']);
    Route::middleware('permission:employees.manage')->group(function () {
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
    });

    // ── Holidays & shifts ─────────────────────────────────────────────────
    Route::get('holidays', [HolidayController::class, 'index']);
    Route::get('holidays/{holiday}', [HolidayController::class, 'show']);
    Route::get('shifts', [ShiftController::class, 'index']);
    Route::get('shifts/{shift}', [ShiftController::class, 'show']);
    Route::get('employees/{employee}/shift-assignments', [ShiftController::class, 'employeeAssignments']);
    Route::get('shift-rosters', [ShiftController::class, 'rosterIndex']);
    Route::get('shift-rosters/{roster}', [ShiftController::class, 'rosterShow']);
    Route::get('shift-swaps', [ShiftSwapController::class, 'index'])->middleware('feature:shifts');
    Route::get('shift-swaps/colleagues', [ShiftSwapController::class, 'colleagues'])->middleware('feature:shifts');
    Route::post('shift-swaps', [ShiftSwapController::class, 'store'])->middleware('feature:shifts');
    Route::post('shift-swaps/{swap}/respond', [ShiftSwapController::class, 'respond'])->middleware('feature:shifts');
    Route::post('shift-swaps/{swap}/cancel', [ShiftSwapController::class, 'cancel'])->middleware('feature:shifts');
    Route::middleware('permission:shifts.manage')->group(function () {
        Route::post('holidays', [HolidayController::class, 'store']);
        Route::put('holidays/{holiday}', [HolidayController::class, 'update']);
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy']);
        Route::post('shifts', [ShiftController::class, 'store']);
        Route::put('shifts/{shift}', [ShiftController::class, 'update']);
        Route::delete('shifts/{shift}', [ShiftController::class, 'destroy']);
        Route::post('shifts/{shift}/assign', [ShiftController::class, 'assignToEmployee']);
        Route::post('shifts/{shift}/assign-bulk', [ShiftController::class, 'assignBulk']);
        Route::post('shift-rosters', [ShiftController::class, 'rosterStore']);
        Route::put('shift-swaps/{swap}', [ShiftSwapController::class, 'update']);
        Route::post('shift-swaps/{swap}/approve', [ShiftSwapController::class, 'approve']);
        Route::post('shift-swaps/{swap}/reject', [ShiftSwapController::class, 'reject']);
    });

    // ── Attendance ────────────────────────────────────────────────────────
    // Static paths before the {attendance} wildcard.
    Route::post('attendance/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('attendance/check-out', [AttendanceController::class, 'checkOut']);
    Route::get('attendance/regularizations', [RegularizationController::class, 'index']);
    Route::post('attendance/regularizations', [RegularizationController::class, 'store']);
    Route::middleware('permission:leaves.approve')->group(function () {
        Route::post('attendance/regularizations/{regularization}/approve', [RegularizationController::class, 'approve']);
        Route::post('attendance/regularizations/{regularization}/reject', [RegularizationController::class, 'reject']);
    });
    Route::middleware('permission:attendance.view|attendance.manage')->group(function () {
        Route::get('attendance/day-summary', [AttendanceController::class, 'daySummary']);
        Route::get('attendance/reports/summary', [AttendanceReportController::class, 'summary']);
        Route::get('attendance/reports/summary/export', [AttendanceReportController::class, 'summaryExport']);
        Route::get('attendance/reports/daily', [AttendanceReportController::class, 'daily']);
        Route::get('attendance/reports/daily/export', [AttendanceReportController::class, 'dailyExport']);
        Route::get('attendance/reports/monthly-punches', [AttendanceReportController::class, 'monthlyPunches']);
        Route::get('attendance/reports/monthly-punches/export', [AttendanceReportController::class, 'monthlyPunchesExport']);
        Route::get('attendance/reports/muster-roll', [AttendanceReportController::class, 'musterRoll']);
        Route::get('attendance/exceptions', [AttendanceExceptionController::class, 'index']);
    });
    Route::post('attendance/manual', [AttendanceController::class, 'manualUpsert'])->middleware('permission:attendance.manage');
    Route::post('attendance/reprocess', [AttendanceProcessingController::class, 'reprocess'])->middleware('permission:attendance.manage');
    Route::middleware('permission:attendance.view|attendance.manage')->group(function () {
        Route::get('raw-punches', [AttendanceProcessingController::class, 'index']);
        Route::get('raw-punches/unmatched-codes', [AttendanceProcessingController::class, 'unmatchedCodes']);
    });
    Route::post('employees/{employee}/map-device-code', [AttendanceProcessingController::class, 'mapDeviceCode'])
        ->middleware('permission:attendance.manage|employees.manage');

    // Roster
    Route::get('rosters/grid', [RosterController::class, 'grid'])->middleware('permission:attendance.view|shifts.manage')->middleware('feature:shifts');
    Route::middleware(['permission:shifts.manage', 'feature:shifts'])->group(function () {
        Route::put('rosters/grid', [RosterController::class, 'save']);
        Route::post('rosters/copy-week', [RosterController::class, 'copyWeek']);
        Route::put('rosters/weekly-offs', [RosterController::class, 'weeklyOffs']);
    });
    Route::get('attendance', [AttendanceController::class, 'index']);
    Route::get('attendance/{attendance}', [AttendanceController::class, 'show']);

    // ── Leave ─────────────────────────────────────────────────────────────
    Route::get('leave-types', [LeaveTypeController::class, 'index']);
    Route::get('leave-types/{leave_type}', [LeaveTypeController::class, 'show']);
    Route::middleware('permission:leaves.manage')->group(function () {
        Route::post('leave-types', [LeaveTypeController::class, 'store']);
        Route::put('leave-types/{leave_type}', [LeaveTypeController::class, 'update']);
        Route::delete('leave-types/{leave_type}', [LeaveTypeController::class, 'destroy']);
        Route::post('leave-balances/adjust', [LeaveBalanceController::class, 'adjust']);
        Route::post('leave-balances/recalculate', [LeaveBalanceController::class, 'recalculate']);
    });
    // Balances: own (or visible employees') via summary / transactions; everyone's for HR.
    Route::get('leave-balances', [LeaveBalanceController::class, 'index']);
    Route::get('leave-balances/summary', [LeaveBalanceController::class, 'summary']);
    Route::get('leave-balances/overview', [LeaveBalanceController::class, 'overview'])
        ->middleware('permission:leaves.view|leaves.approve|leaves.manage');
    Route::get('leave-balances/{balance}/transactions', [LeaveBalanceController::class, 'transactions']);
    // Static paths before the {leave} wildcard.
    Route::get('leaves', [LeaveController::class, 'index']);
    Route::get('leaves/quote', [LeaveController::class, 'quote']);
    Route::get('leaves/calendar', [LeaveController::class, 'calendar']);
    Route::get('leaves/export', [LeaveController::class, 'export'])
        ->middleware('permission:leaves.view|leaves.manage|reports.view');
    Route::post('leaves', [LeaveController::class, 'store']);
    Route::get('leaves/{leave}', [LeaveController::class, 'show']);
    Route::get('leaves/{leave}/attachment', [LeaveController::class, 'attachment']);
    Route::post('leaves/{leave}/cancel', [LeaveController::class, 'cancel']);
    Route::middleware('permission:leaves.approve')->group(function () {
        Route::post('leaves/{leave}/approve', [LeaveController::class, 'approve']);
        Route::post('leaves/{leave}/reject', [LeaveController::class, 'reject']);
    });

    // ── Approvals ─────────────────────────────────────────────────────────
    Route::get('approvals/count', [ApprovalController::class, 'count']);
    Route::get('approvals', [ApprovalController::class, 'index'])->middleware('permission:leaves.approve');
    Route::middleware('permission:settings.manage|leaves.manage')->group(function () {
        Route::get('approval-flows', [ApprovalFlowController::class, 'index']);
        Route::put('approval-flows/{branch}/{module}', [ApprovalFlowController::class, 'update']);
    });

    // ── Statutory compliance (finalized payroll → PF / ESI / PT / TDS files) ──
    Route::middleware(['permission:payroll.view|payroll.manage|reports.view', 'feature:statutory'])->group(function () {
        Route::get('compliance/summary', [ComplianceController::class, 'summary']);
        Route::get('compliance/{report}/preview', [ComplianceController::class, 'preview']);
        Route::get('compliance/{report}/download', [ComplianceController::class, 'download']);
    });

    // ── Subscription & billing (the organisation's own) ───────────────────
    Route::middleware('permission:billing.manage')->group(function () {
        Route::get('billing', [BillingController::class, 'show']);
        Route::post('billing/plan', [BillingController::class, 'choosePlan']);
        Route::post('billing/cancel', [BillingController::class, 'cancel']);
        Route::post('billing/resume', [BillingController::class, 'resume']);
        Route::put('billing/details', [BillingController::class, 'updateDetails']);
        Route::get('billing/invoices', [BillingController::class, 'invoices']);
        Route::get('billing/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf']);
        Route::post('billing/invoices/{invoice}/pay', [BillingController::class, 'pay']);
    });

    // ── Notifications ─────────────────────────────────────────────────────
    // Everyone: their own bell and channel preferences.
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::get('me/home', [EmployeeHomeController::class, 'show']);
    Route::post('me/devices', [DeviceTokenController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('me/devices', [DeviceTokenController::class, 'destroy']);
    Route::put('me/profile', [MyProfileController::class, 'update']);
    Route::get('me/notification-preferences', [NotificationController::class, 'preferences']);
    Route::put('me/notification-preferences', [NotificationController::class, 'updatePreferences']);
    Route::middleware('permission:notifications.manage')->group(function () {
        Route::get('notification-settings', [NotificationSettingController::class, 'show']);
        Route::put('notification-settings', [NotificationSettingController::class, 'update']);
        Route::post('notification-settings/test', [NotificationSettingController::class, 'test'])->middleware('throttle:10,1');
        Route::get('notification-logs', [NotificationSettingController::class, 'logs']);
    });

    // ── Overtime ──────────────────────────────────────────────────────────
    Route::get('overtime-rules', [OvertimeRuleController::class, 'index']);
    Route::get('overtime-rules/{overtime_rule}', [OvertimeRuleController::class, 'show']);
    Route::middleware('permission:payroll.manage|shifts.manage')->group(function () {
        Route::post('overtime-rules', [OvertimeRuleController::class, 'store']);
        Route::put('overtime-rules/{overtime_rule}', [OvertimeRuleController::class, 'update']);
        Route::delete('overtime-rules/{overtime_rule}', [OvertimeRuleController::class, 'destroy']);
    });
    Route::get('overtime-requests', [OvertimeRequestController::class, 'index']);
    Route::post('overtime-requests', [OvertimeRequestController::class, 'store']);
    Route::get('overtime-requests/{request}', [OvertimeRequestController::class, 'show']);
    Route::middleware('permission:leaves.approve')->group(function () {
        Route::post('overtime-requests/{request}/approve', [OvertimeRequestController::class, 'approve']);
        Route::post('overtime-requests/{request}/reject', [OvertimeRequestController::class, 'reject']);
    });

    // Plan module: payroll
    Route::middleware('feature:payroll')->group(function () {
        // ── Salary & payroll ──────────────────────────────────────────────────
        // Salary structures: the controller applies EmployeePolicy::viewSalary,
        // so employees can read their own.
        Route::get('salary-components', [SalaryComponentController::class, 'index']);
        Route::get('salary-components/{salary_component}', [SalaryComponentController::class, 'show']);
        Route::get('salary-structures', [SalaryStructureController::class, 'index']);
        Route::middleware('permission:payroll.view|payroll.manage')->group(function () {
            Route::get('statutory-rules', [StatutoryRuleController::class, 'index']);
            Route::get('statutory-rules/defaults', [StatutoryRuleController::class, 'defaults']);
            Route::get('statutory-rules/{statutory_rule}', [StatutoryRuleController::class, 'show']);
            Route::get('payroll-runs', [PayrollRunController::class, 'index']);
            Route::get('payroll-runs/{run}', [PayrollRunController::class, 'show']);
            Route::get('payroll-runs/{run}/status', [PayrollRunController::class, 'status']);
            Route::get('payroll-runs/{run}/adjustments', [PayrollAdjustmentController::class, 'index']);
            Route::get('payroll/summary', [PayrollRunController::class, 'summary']);
        });
        Route::middleware('permission:payroll.manage')->group(function () {
            Route::post('salary-components', [SalaryComponentController::class, 'store']);
            Route::put('salary-components/{salary_component}', [SalaryComponentController::class, 'update']);
            Route::delete('salary-components/{salary_component}', [SalaryComponentController::class, 'destroy']);
            Route::post('salary-structures', [SalaryStructureController::class, 'store']);
            Route::put('salary-structures/{structure}', [SalaryStructureController::class, 'update']);
            Route::delete('salary-structures/{structure}', [SalaryStructureController::class, 'destroy']);
            Route::post('employees/{employee}/salary/from-gross', [SalaryStructureController::class, 'fromGross']);
            Route::post('statutory-rules', [StatutoryRuleController::class, 'store']);
            Route::put('statutory-rules/{statutory_rule}', [StatutoryRuleController::class, 'update']);
            Route::delete('statutory-rules/{statutory_rule}', [StatutoryRuleController::class, 'destroy']);

            Route::post('payroll-runs', [PayrollRunController::class, 'store']);
            Route::delete('payroll-runs/{run}', [PayrollRunController::class, 'destroy']);
            Route::post('payroll-runs/{run}/run', [PayrollRunController::class, 'run']);
            Route::get('payroll-runs/{run}/preview', [PayrollRunController::class, 'preview']);
            Route::get('payroll-runs/{run}/bank-export', [PayrollRunController::class, 'bankExport']);
            Route::post('payroll-runs/{run}/adjustments', [PayrollAdjustmentController::class, 'store']);
            Route::post('payroll-runs/{run}/adjustments/bulk', [PayrollAdjustmentController::class, 'bulkStore']);
            Route::put('payroll-adjustments/{adjustment}', [PayrollAdjustmentController::class, 'update']);
            Route::delete('payroll-adjustments/{adjustment}', [PayrollAdjustmentController::class, 'destroy']);
        });

        // Maker / checker: payroll.manage prepares a run, payroll.finalize publishes it.
        Route::middleware('permission:payroll.finalize')->group(function () {
            Route::post('payroll-runs/{run}/finalize', [PayrollRunController::class, 'finalize']);
            Route::post('payroll-runs/{run}/reopen', [PayrollRunController::class, 'reopen']);
            Route::post('payroll-runs/{run}/mark-paid', [PayrollRunController::class, 'markPaid']);
        });

        // Payslips: own payslips for everyone; others need payroll.view (enforced in controller).
        Route::get('payslips', [PayslipController::class, 'index']);
        Route::get('payslips/{payslip}', [PayslipController::class, 'show']);
        Route::get('payslips/{payslip}/pdf', [PayslipController::class, 'pdf']);
    });

    // Plan module: letters & certificates
    Route::middleware('feature:certificates')->group(function () {
        // ── Certificates ──────────────────────────────────────────────────────
        Route::get('/certificate-tokens', [CertificateTokenController::class, 'index']);
        Route::get('certificate-templates', [CertificateTemplateController::class, 'index']);
        Route::get('certificate-templates/{template}', [CertificateTemplateController::class, 'show']);
        Route::get('certificate-requests', [CertificateRequestController::class, 'index']);
        Route::post('certificate-requests', [CertificateRequestController::class, 'store']);
        Route::get('certificate-requests/{request}', [CertificateRequestController::class, 'show']);
        Route::get('issued-certificates', [IssuedCertificateController::class, 'index']);
        Route::get('issued-certificates/{certificate}', [IssuedCertificateController::class, 'show']);
        Route::get('issued-certificates/{certificate}/pdf', [IssuedCertificateController::class, 'pdf']);
        Route::middleware('permission:certificates.manage')->group(function () {
            Route::post('certificate-templates', [CertificateTemplateController::class, 'store']);
            Route::put('certificate-templates/{template}', [CertificateTemplateController::class, 'update']);
            Route::delete('certificate-templates/{template}', [CertificateTemplateController::class, 'destroy']);
            Route::post('certificate-templates/{template}/publish', [CertificateTemplateController::class, 'publish']);
            Route::post('certificate-templates/{template}/clone', [CertificateTemplateController::class, 'clone']);
            Route::post('certificate-requests/{request}/approve', [CertificateRequestController::class, 'approve']);
            Route::post('certificate-requests/{request}/reject', [CertificateRequestController::class, 'reject']);
        });
    });
});
