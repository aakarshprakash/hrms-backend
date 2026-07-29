<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-off bulk import for a real staff roster exported from an existing
 * spreadsheet (name, employee code, designation, CTC only -- no email or
 * joining date, so those are generated placeholders that should be
 * corrected per employee afterward). Idempotent on employee_code: safe
 * to re-run against the same JSON without creating duplicates.
 *
 * Also creates a login (User) per employee, mirroring exactly how
 * EmployeeController::store() does it for the normal Add Employee flow --
 * except the welcome email is never sent (placeholder addresses can't
 * receive real mail), so generated passwords are printed instead for you
 * to hand out directly.
 */
class ImportStaffFromJson extends Command
{
    protected $signature = 'staff:import {json_path} {--branch=} {--dry-run}';

    protected $description = 'Import employees + basic salary + login accounts from a prepared staff JSON file';

    public function handle(): int
    {
        $path = $this->argument('json_path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->error('File does not contain valid JSON.');
            return self::FAILURE;
        }

        $branchName = $this->option('branch') ?: 'Legacy TVS';
        $branch = Branch::withoutGlobalScopes()->where('name', $branchName)->first();
        if (! $branch) {
            $this->error("Branch \"{$branchName}\" not found. Create it first.");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $placeholderJoinDate = '2024-01-01';

        $basicSalary = SalaryComponent::withoutGlobalScopes()->firstOrCreate(
            ['branch_id' => $branch->id, 'name' => 'Basic Salary'],
            ['type' => 'earning', 'calculation_type' => 'fixed']
        );

        $created = 0;
        $skipped = 0;
        $summary = [];
        $credentials = [];

        foreach ($rows as $row) {
            $deptName = $row['department'];
            $designationTitle = $row['designation'];

            $department = Department::withoutGlobalScopes()->firstOrCreate(
                ['branch_id' => $branch->id, 'name' => $deptName]
            );

            $designation = Designation::withoutGlobalScopes()->firstOrCreate(
                ['branch_id' => $branch->id, 'department_id' => $department->id, 'title' => $designationTitle]
            );

            $fullName = trim($row['first_name'].' '.$row['last_name']);
            $employee = Employee::withoutGlobalScopes()->where('employee_code', $row['employee_code'])->first();

            if ($employee && $employee->user_id) {
                $skipped++;
                $summary[] = [$row['employee_code'], $fullName, $designationTitle, $row['ctc'], 'already exists (with login)'];
                continue;
            }

            if ($dryRun) {
                $created++;
                $summary[] = [$row['employee_code'], $fullName, $designationTitle, $row['ctc'], $employee ? 'will add login' : 'will create'];
                continue;
            }

            $placeholderEmail = strtolower($row['first_name']).'.'.strtolower($row['employee_code']).'@placeholder.legacytvs.local';

            if (! $employee) {
                $employee = Employee::create([
                    'branch_id' => $branch->id,
                    'department_id' => $department->id,
                    'designation_id' => $designation->id,
                    'employee_code' => $row['employee_code'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'email' => $placeholderEmail,
                    'date_of_joining' => $placeholderJoinDate,
                    'employment_type' => 'full_time',
                    'status' => 'active',
                    'notes' => "Imported from staff spreadsheet. Original designation: \"{$row['designation_raw']}\". Placeholder email and joining date -- update both.",
                ]);

                SalaryStructure::create([
                    'employee_id' => $employee->id,
                    'component_id' => $basicSalary->id,
                    'amount' => $row['ctc'],
                    'effective_from' => $placeholderJoinDate,
                ]);
            }

            $created++;
            $loginEmail = $employee->email;
            $plainPassword = Str::password(10, letters: true, numbers: true, symbols: false, spaces: false);

            $user = User::create([
                'name' => $fullName,
                'email' => $loginEmail,
                'password' => Hash::make($plainPassword),
                'user_type' => 'employee',
                'employee_id' => $employee->id,
                'branch_id' => $branch->id,
            ]);
            $user->assignRole('employee');
            $employee->update(['user_id' => $user->id]);

            $summary[] = [$row['employee_code'], $fullName, $designationTitle, $row['ctc'], 'created'];
            $credentials[] = [$row['employee_code'], $fullName, $loginEmail, $plainPassword];
        }

        $this->table(['Code', 'Name', 'Designation', 'CTC', 'Result'], $summary);
        $this->info(($dryRun ? '[DRY RUN] Would create' : 'Created')." {$created}, skipped (already exist) {$skipped}.");

        if (! $dryRun && $credentials) {
            $this->newLine();
            $this->warn('Login credentials (not emailed -- addresses are placeholders). Hand these out directly and have each person change their password after first login:');
            $this->table(['Code', 'Name', 'Login Email', 'Temporary Password'], $credentials);
            $this->warn('Joining dates are all set to the placeholder 2024-01-01 -- correct per employee where accurate dates matter.');
        }

        return self::SUCCESS;
    }
}
