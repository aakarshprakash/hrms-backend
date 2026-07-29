<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AccrueLeaveBalances extends Command
{
    protected $signature = 'hrms:accrue-leave-balances';

    protected $description = 'Monthly leave balance accrual';

    public function handle(): void
    {
        $year = Carbon::now()->year;
        $employees = Employee::all();

        foreach ($employees as $employee) {
            $leaveTypes = LeaveType::withoutGlobalScopes()
                ->where('branch_id', $employee->branch_id)
                ->get();

            foreach ($leaveTypes as $leaveType) {
                $accrual = round($leaveType->days_per_year / 12, 2);

                $balance = LeaveBalance::firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveType->id,
                        'year' => $year,
                    ],
                    [
                        'allocated' => 0,
                        'used' => 0,
                        'balance' => 0,
                    ]
                );

                $balance->increment('allocated', $accrual);
                $balance->increment('balance', $accrual);
            }
        }

        $this->info('Leave balances accrued successfully.');
    }
}
