<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceExceptionController;
use App\Http\Controllers\Api\AttendanceReportController;
use App\Http\Controllers\Api\QuickSetupController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\CertificateRequestController;
use App\Http\Controllers\Api\CertificateTemplateController;
use App\Http\Controllers\Api\CertificateTokenController;
use App\Http\Controllers\Api\IssuedCertificateController;
use App\Http\Controllers\Api\PublicCertificateController;
use App\Http\Controllers\Api\AiInsightsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BiometricConfigController;
use App\Http\Controllers\Api\BiometricSyncController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DesignationController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\LeaveBalanceController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\OvertimeRequestController;
use App\Http\Controllers\Api\OvertimeRuleController;
use App\Http\Controllers\Api\PayrollAdjustmentController;
use App\Http\Controllers\Api\PayrollRunController;
use App\Http\Controllers\Api\PayslipController;
use App\Http\Controllers\Api\RegularizationController;
use App\Http\Controllers\Api\SalaryComponentController;
use App\Http\Controllers\Api\SalaryStructureController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\ShiftSwapController;
use App\Http\Controllers\Api\StatutoryRuleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

// Auth routes (unauthenticated)
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    // Legacy user endpoint
    Route::get('/user', fn (Request $request) => $request->user());

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('role:super_admin|branch_admin|hr');

    // Quick Setup
    Route::post('/quick-setup', [QuickSetupController::class, 'seed']);

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/calendar', [CalendarController::class, 'events']);

    // AI insights
    Route::get('/ai/insights', [AiInsightsController::class, 'insights'])
        ->middleware('role_or_permission:super_admin|branch_admin|hr|manager|insights.view');

    // User management
    Route::get('/roles', [UserController::class, 'roles'])
        ->middleware('role_or_permission:super_admin|branch_admin|users.manage');
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

    // Dynamic role management (super admin only)
    Route::middleware('role:super_admin')->group(function () {
        Route::get('/roles/manage', [RoleController::class, 'index']);
        Route::get('/permissions', [RoleController::class, 'permissions']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
    });

    // Branches & Company
    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store']);
    Route::get('/branches/{branch}', [BranchController::class, 'show']);
    Route::put('/branches/{branch}', [BranchController::class, 'update']);
    Route::delete('/branches/{branch}', [BranchController::class, 'destroy']);
    Route::match(['get', 'put'], '/company', [BranchController::class, 'company']);

    // Biometric attendance integration (branch-wise)
    Route::get('biometric-configs', [BiometricConfigController::class, 'index']);
    Route::get('branches/{branch}/biometric-config', [BiometricConfigController::class, 'show']);
    Route::put('branches/{branch}/biometric-config', [BiometricConfigController::class, 'update']);
    Route::post('branches/{branch}/biometric-config/sync', [BiometricSyncController::class, 'sync']);
    Route::get('branches/{branch}/biometric-config/logs', [BiometricSyncController::class, 'logs']);

    // Employees
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);

    // Employee avatar
    Route::post('/employees/{employee}/avatar', [EmployeeController::class, 'uploadAvatar']);

    // Employee documents
    Route::get('/employees/{employee}/documents', [DocumentController::class, 'index']);
    Route::post('/employees/{employee}/documents', [DocumentController::class, 'upload']);
    Route::delete('/employees/{employee}/documents/{media}', [DocumentController::class, 'destroy']);

    // Departments
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::get('/departments/{department}', [DepartmentController::class, 'show']);
    Route::put('/departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);

    // Designations
    Route::get('/designations', [DesignationController::class, 'index']);
    Route::post('/designations', [DesignationController::class, 'store']);
    Route::get('/designations/{designation}', [DesignationController::class, 'show']);
    Route::put('/designations/{designation}', [DesignationController::class, 'update']);
    Route::delete('/designations/{designation}', [DesignationController::class, 'destroy']);

    // Holidays
    Route::apiResource('holidays', HolidayController::class);

    // Shifts
    Route::apiResource('shifts', ShiftController::class);
    Route::post('shifts/{shift}/assign', [ShiftController::class, 'assignToEmployee']);
    Route::post('shifts/{shift}/assign-bulk', [ShiftController::class, 'assignBulk']);
    Route::get('employees/{employee}/shift-assignments', [ShiftController::class, 'employeeAssignments']);
    Route::get('shift-rosters', [ShiftController::class, 'rosterIndex']);
    Route::post('shift-rosters', [ShiftController::class, 'rosterStore']);
    Route::get('shift-rosters/{roster}', [ShiftController::class, 'rosterShow']);

    // Shift swaps
    Route::post('shift-swaps', [ShiftSwapController::class, 'store']);
    Route::put('shift-swaps/{swap}', [ShiftSwapController::class, 'update']);

    // Attendance — regularizations must be registered before the {attendance} wildcard
    Route::get('attendance/regularizations', [RegularizationController::class, 'index']);
    Route::post('attendance/regularizations', [RegularizationController::class, 'store']);
    Route::post('attendance/regularizations/{regularization}/approve', [RegularizationController::class, 'approve']);
    Route::post('attendance/regularizations/{regularization}/reject', [RegularizationController::class, 'reject']);
    Route::post('attendance/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('attendance/check-out', [AttendanceController::class, 'checkOut']);
    Route::get('attendance/day-summary', [AttendanceController::class, 'daySummary'])
        ->middleware('role_or_permission:super_admin|branch_admin|hr|manager|attendance.view|attendance.manage');
    Route::post('attendance/manual', [AttendanceController::class, 'manualUpsert']);
    Route::get('attendance/reports/summary', [AttendanceReportController::class, 'summary']);
    Route::get('attendance/reports/summary/export', [AttendanceReportController::class, 'summaryExport']);
    Route::get('attendance/reports/daily', [AttendanceReportController::class, 'daily']);
    Route::get('attendance/reports/daily/export', [AttendanceReportController::class, 'dailyExport']);
    Route::get('attendance/reports/muster-roll', [AttendanceReportController::class, 'musterRoll']);
    Route::get('attendance/exceptions', [AttendanceExceptionController::class, 'index']);
    Route::get('attendance', [AttendanceController::class, 'index']);
    Route::get('attendance/{attendance}', [AttendanceController::class, 'show']);

    // Leave Types
    Route::apiResource('leave-types', LeaveTypeController::class);

    // Leave Balances
    Route::get('leave-balances', [LeaveBalanceController::class, 'index']);

    // Leaves
    Route::get('leaves', [LeaveController::class, 'index']);
    Route::post('leaves', [LeaveController::class, 'store']);
    Route::get('leaves/{leave}', [LeaveController::class, 'show']);
    Route::post('leaves/{leave}/approve', [LeaveController::class, 'approve']);
    Route::post('leaves/{leave}/reject', [LeaveController::class, 'reject']);
    Route::post('leaves/{leave}/cancel', [LeaveController::class, 'cancel']);

    // Overtime
    Route::apiResource('overtime-rules', OvertimeRuleController::class);
    Route::get('overtime-requests', [OvertimeRequestController::class, 'index']);
    Route::post('overtime-requests', [OvertimeRequestController::class, 'store']);
    Route::get('overtime-requests/{request}', [OvertimeRequestController::class, 'show']);
    Route::post('overtime-requests/{request}/approve', [OvertimeRequestController::class, 'approve']);
    Route::post('overtime-requests/{request}/reject', [OvertimeRequestController::class, 'reject']);

    // Salary
    Route::apiResource('salary-components', SalaryComponentController::class);
    Route::get('salary-structures', [SalaryStructureController::class, 'index']);
    Route::post('salary-structures', [SalaryStructureController::class, 'store']);
    Route::put('salary-structures/{structure}', [SalaryStructureController::class, 'update']);
    Route::delete('salary-structures/{structure}', [SalaryStructureController::class, 'destroy']);
    Route::apiResource('statutory-rules', StatutoryRuleController::class);

    // Payroll
    Route::get('payroll-runs', [PayrollRunController::class, 'index']);
    Route::post('payroll-runs', [PayrollRunController::class, 'store']);
    Route::get('payroll-runs/{run}', [PayrollRunController::class, 'show']);
    Route::delete('payroll-runs/{run}', [PayrollRunController::class, 'destroy']);
    Route::post('payroll-runs/{run}/run', [PayrollRunController::class, 'run']);
    Route::get('payroll-runs/{run}/status', [PayrollRunController::class, 'status']);
    Route::get('payroll-runs/{run}/preview', [PayrollRunController::class, 'preview']);
    Route::get('payroll-runs/{run}/bank-export', [PayrollRunController::class, 'bankExport']);
    Route::get('payroll-runs/{run}/adjustments', [PayrollAdjustmentController::class, 'index']);
    Route::post('payroll-runs/{run}/adjustments', [PayrollAdjustmentController::class, 'store']);
    Route::post('payroll-runs/{run}/adjustments/bulk', [PayrollAdjustmentController::class, 'bulkStore']);
    Route::put('payroll-adjustments/{adjustment}', [PayrollAdjustmentController::class, 'update']);
    Route::delete('payroll-adjustments/{adjustment}', [PayrollAdjustmentController::class, 'destroy']);

    // Payslips
    Route::get('payslips', [PayslipController::class, 'index']);
    Route::get('payslips/{payslip}', [PayslipController::class, 'show']);
    Route::get('payslips/{payslip}/pdf', [PayslipController::class, 'pdf']);

    // Payroll dashboard summary
    Route::get('payroll/summary', [PayrollRunController::class, 'summary']);

    // Certificates
    Route::get('/certificate-tokens', [CertificateTokenController::class, 'index']);
    Route::apiResource('certificate-templates', CertificateTemplateController::class);
    Route::post('certificate-templates/{template}/publish', [CertificateTemplateController::class, 'publish']);
    Route::post('certificate-templates/{template}/clone', [CertificateTemplateController::class, 'clone']);

    Route::get('certificate-requests', [CertificateRequestController::class, 'index']);
    Route::post('certificate-requests', [CertificateRequestController::class, 'store']);
    Route::get('certificate-requests/{request}', [CertificateRequestController::class, 'show']);
    Route::post('certificate-requests/{request}/approve', [CertificateRequestController::class, 'approve']);
    Route::post('certificate-requests/{request}/reject', [CertificateRequestController::class, 'reject']);

    Route::get('issued-certificates', [IssuedCertificateController::class, 'index']);
    Route::get('issued-certificates/{certificate}', [IssuedCertificateController::class, 'show']);
    Route::get('issued-certificates/{certificate}/pdf', [IssuedCertificateController::class, 'pdf']);
});

// Public certificate verification (no auth)
Route::get('/verify/{certificateNumber}', [PublicCertificateController::class, 'verify']);
