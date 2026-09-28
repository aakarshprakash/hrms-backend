<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceRegularization;
use App\Models\BiometricConfig;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\NotificationSetting;
use App\Models\OvertimeRequest;
use App\Models\PayrollRun;
use App\Models\PayrollRunAdjustment;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\Shift;
use App\Models\ShiftRoster;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveLedger;
use App\Services\Attendance\AttendanceProcessor;
use App\Services\Payroll\PayrollRunService;
use App\Services\Tenancy\IndustryTemplateService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Access\Roles;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Velocity Motors" -- a fictional Kerala dealer group used as the
 * demo-ready automotive tenant: two bike showrooms, a car showroom and a
 * service centre; Sales / Service / Spares / Admin; staggered showroom
 * weekly offs; three months of device punches processed through the real
 * attendance pipeline; leave, overtime, a pending approval queue; payroll for
 * the two previous months finalized and paid, the current month open.
 *
 * Everything goes through the application's own services, so the demo
 * exercises the same code paths as production. Dates are relative to today.
 * All people, numbers and identifiers are invented.
 *
 * Sign in (password Demo@1234):
 *   owner@velocitymotors.demo            tenant admin (managing director)
 *   hr@velocitymotors.demo               HR, all branches
 *   accounts@velocitymotors.demo         custom "Accountant" role
 *   manager.edappally@velocitymotors.demo sales manager (reporting team)
 *   admin.thrissur@velocitymotors.demo   branch admin, Thrissur
 *   employee@velocitymotors.demo         sales executive (self-service)
 */
class DemoAutomotiveSeeder extends Seeder
{
    public const SLUG = 'velocity-motors-demo';

    public const PASSWORD = 'Demo@1234';

    private const DOMAIN = 'velocitymotors.demo';

    private const BRANCHES = [
        'EDP' => ['Edappally Car Showroom', 'Kochi', 'Showroom', [2]],
        'VYT' => ['Vyttila Bike Showroom', 'Kochi', 'Showroom', [2]],
        'KLM' => ['Kalamassery Service Centre', 'Kochi', 'Workshop', [0]],
        'TSR' => ['Thrissur Bike Showroom', 'Thrissur', 'Showroom', [2]],
    ];

    /**
     * key, branch, department, designation, monthly gross, role, reports to,
     * gender, personal weekly off (null = branch), login email local part.
     */
    private const PEOPLE = [
        ['edp_gm', 'EDP', 'Admin', 'General Manager', 145000, Roles::BRANCH_ADMIN, null, 'male', [0], null],
        ['edp_sm', 'EDP', 'Sales', 'Sales Manager', 48000, Roles::MANAGER, 'edp_gm', 'male', [2], 'manager.edappally'],
        ['edp_se1', 'EDP', 'Sales', 'Senior Sales Executive', 26000, Roles::EMPLOYEE, 'edp_sm', 'female', [3], 'employee'],
        ['edp_se2', 'EDP', 'Sales', 'Sales Executive', 19500, Roles::EMPLOYEE, 'edp_sm', 'male', [4], null],
        ['edp_se3', 'EDP', 'Sales', 'Sales Executive', 18500, Roles::EMPLOYEE, 'edp_sm', 'male', [5], null],
        ['edp_se4', 'EDP', 'Sales', 'Sales Executive', 18000, Roles::EMPLOYEE, 'edp_sm', 'female', [1], null],
        ['edp_svm', 'EDP', 'Service', 'Service Manager', 52000, Roles::MANAGER, 'edp_gm', 'male', [0], null],
        ['edp_sa', 'EDP', 'Service', 'Service Advisor', 24000, Roles::EMPLOYEE, 'edp_svm', 'male', [0], null],
        ['edp_t1', 'EDP', 'Service', 'Senior Technician', 22500, Roles::EMPLOYEE, 'edp_svm', 'male', [0], null],
        ['edp_t2', 'EDP', 'Service', 'Technician', 16500, Roles::EMPLOYEE, 'edp_svm', 'male', [0], null],
        ['edp_sp', 'EDP', 'Spares', 'Spares Executive', 17500, Roles::EMPLOYEE, 'edp_svm', 'male', null, null],
        ['edp_cre', 'EDP', 'Admin', 'Customer Relationship Executive', 16000, Roles::EMPLOYEE, 'edp_gm', 'female', [3], null],
        ['edp_acc', 'EDP', 'Admin', 'Accountant', 32000, 'accountant', 'edp_gm', 'female', [0], 'accounts'],
        ['edp_hr', 'EDP', 'Admin', 'HR Executive', 30000, Roles::HR, 'edp_gm', 'female', [0], 'hr'],

        ['vyt_mgr', 'VYT', 'Sales', 'Showroom Manager', 58000, Roles::BRANCH_ADMIN, 'edp_gm', 'male', [2], null],
        ['vyt_tl', 'VYT', 'Sales', 'Team Leader - Sales', 30000, Roles::MANAGER, 'vyt_mgr', 'male', [3], null],
        ['vyt_se1', 'VYT', 'Sales', 'Sales Executive', 17500, Roles::EMPLOYEE, 'vyt_tl', 'male', [4], null],
        ['vyt_se2', 'VYT', 'Sales', 'Sales Executive', 17000, Roles::EMPLOYEE, 'vyt_tl', 'female', [5], null],
        ['vyt_se3', 'VYT', 'Sales', 'Sales Executive', 16500, Roles::EMPLOYEE, 'vyt_tl', 'male', [1], null],
        ['vyt_t1', 'VYT', 'Service', 'Technician', 15500, Roles::EMPLOYEE, 'vyt_mgr', 'male', [0], null],
        ['vyt_t2', 'VYT', 'Service', 'Technician', 15000, Roles::EMPLOYEE, 'vyt_mgr', 'male', [0], null],
        ['vyt_sa', 'VYT', 'Service', 'Service Advisor', 21000, Roles::EMPLOYEE, 'vyt_mgr', 'female', [0], null],
        ['vyt_sp', 'VYT', 'Spares', 'Spares Executive', 16000, Roles::EMPLOYEE, 'vyt_mgr', 'male', null, null],
        ['vyt_cre', 'VYT', 'Admin', 'Customer Relationship Executive', 15000, Roles::EMPLOYEE, 'vyt_mgr', 'female', [4], null],

        ['klm_mgr', 'KLM', 'Service', 'Service Manager', 55000, Roles::BRANCH_ADMIN, 'edp_gm', 'male', null, null],
        ['klm_fs', 'KLM', 'Service', 'Floor Supervisor', 32000, Roles::MANAGER, 'klm_mgr', 'male', null, null],
        ['klm_t1', 'KLM', 'Service', 'Senior Technician', 24000, Roles::EMPLOYEE, 'klm_fs', 'male', null, null],
        ['klm_t2', 'KLM', 'Service', 'Senior Technician', 23000, Roles::EMPLOYEE, 'klm_fs', 'male', null, null],
        ['klm_t3', 'KLM', 'Service', 'Technician', 17000, Roles::EMPLOYEE, 'klm_fs', 'male', null, null],
        ['klm_t4', 'KLM', 'Service', 'Technician', 16500, Roles::EMPLOYEE, 'klm_fs', 'male', null, null],
        ['klm_t5', 'KLM', 'Service', 'Technician', 15500, Roles::EMPLOYEE, 'klm_fs', 'male', null, null],
        ['klm_sa', 'KLM', 'Service', 'Service Advisor', 23500, Roles::EMPLOYEE, 'klm_mgr', 'female', null, null],
        ['klm_sp', 'KLM', 'Spares', 'Spares Counter Supervisor', 25000, Roles::EMPLOYEE, 'klm_mgr', 'male', null, null],
        ['klm_ad', 'KLM', 'Admin', 'Admin Executive', 18000, Roles::EMPLOYEE, 'klm_mgr', 'female', null, null],

        ['tsr_mgr', 'TSR', 'Sales', 'Showroom Manager', 56000, Roles::BRANCH_ADMIN, 'edp_gm', 'male', [2], 'admin.thrissur'],
        ['tsr_tl', 'TSR', 'Sales', 'Team Leader - Sales', 29000, Roles::MANAGER, 'tsr_mgr', 'female', [3], null],
        ['tsr_se1', 'TSR', 'Sales', 'Sales Executive', 17000, Roles::EMPLOYEE, 'tsr_tl', 'male', [4], null],
        ['tsr_se2', 'TSR', 'Sales', 'Sales Executive', 16800, Roles::EMPLOYEE, 'tsr_tl', 'male', [5], null],
        ['tsr_se3', 'TSR', 'Sales', 'Sales Executive', 16500, Roles::EMPLOYEE, 'tsr_tl', 'female', [1], null],
        ['tsr_t1', 'TSR', 'Service', 'Technician', 15800, Roles::EMPLOYEE, 'tsr_mgr', 'male', [0], null],
        ['tsr_t2', 'TSR', 'Service', 'Technician', 15200, Roles::EMPLOYEE, 'tsr_mgr', 'male', [0], null],
        ['tsr_sp', 'TSR', 'Spares', 'Spares Executive', 16200, Roles::EMPLOYEE, 'tsr_mgr', 'male', null, null],
        ['tsr_cre', 'TSR', 'Admin', 'Customer Relationship Executive', 15000, Roles::EMPLOYEE, 'tsr_mgr', 'female', [5], null],
    ];

    private const MALE = ['Arjun', 'Rahul', 'Vishnu', 'Akhil', 'Anand', 'Sreejith', 'Jithin', 'Nikhil', 'Midhun', 'Sanjay', 'Abhijith', 'Rohit',
        'Vivek', 'Harikrishnan', 'Manu', 'Deepak', 'Kiran', 'Alan', 'Joel', 'Ashwin', 'Febin', 'Nithin', 'Shyam', 'Basil', 'Jerin', 'Sooraj',
        'Vineeth', 'Ajmal', 'Rajeev', 'Anoop', 'Sibin', 'Tony'];

    private const FEMALE = ['Anjali', 'Aparna', 'Sreelakshmi', 'Divya', 'Athira', 'Reshma', 'Neethu', 'Sneha', 'Gopika', 'Keerthana', 'Aiswarya',
        'Nimisha', 'Fathima', 'Meera', 'Lekshmi', 'Parvathy'];

    private const SURNAMES = ['Nair', 'Menon', 'Pillai', 'Kurup', 'Varghese', 'Thomas', 'Joseph', 'Mathew', 'George', 'Jacob', 'Kumar', 'Das',
        'Krishnan', 'Rajan', 'Babu', 'Abraham', 'Paul', 'Antony', 'Panicker', 'Rahman', 'Haneef', 'Chacko'];

    private TenantContext $context;

    private array $branches = [];

    private array $employees = [];

    private array $users = [];

    public function run(): void
    {
        $this->context = app(TenantContext::class);

        if (Company::where('slug', self::SLUG)->exists()) {
            $this->command?->info('Demo tenant already exists -- run `php artisan demo:automotive --fresh` to rebuild it.');

            return;
        }

        mt_srand(20260927);
        $passwordHash = Hash::make(self::PASSWORD);

        $today = CarbonImmutable::now('Asia/Kolkata')->startOfDay();
        $start = $today->startOfMonth()->subMonths(2);
        $end = $today->subDay();

        $result = app(TenantProvisioner::class)->provision([
            'company_name' => 'Velocity Motors',
            'slug' => self::SLUG,
            'legal_name' => 'Velocity Motors Private Limited',
            'industry' => 'automotive',
            'email' => 'contact@' . self::DOMAIN,
            'city' => 'Kochi',
            'state' => 'Kerala',
            'branch_name' => self::BRANCHES['EDP'][0],
            'is_demo' => true,
            'admin_name' => 'Rajeev Menon',
            'admin_email' => 'owner@' . self::DOMAIN,
            'admin_password' => self::PASSWORD,
        ]);

        $company = $result['company'];

        $this->context->runAs($company->id, function () use ($company, $result, $passwordHash, $start, $end, $today) {
            $this->command?->info('Velocity Motors: organisation, branches and templates…');
            $this->setUpCompany($company, $result['branch']);
            $this->configureNotifications($company);
            $this->users['owner'] = $result['admin'];

            $this->command?->info('Velocity Motors: people and salaries…');
            $this->createPeople($passwordHash, $start);
            $this->createAccountantRole();
            $this->createHolidays($today);
            $this->createRosters($start, $end);

            $this->command?->info('Velocity Motors: leave, punches and attendance…');
            $leaveDays = $this->createLeave($start, $end);
            $this->createPunches($start, $end, $leaveDays);
            app(AttendanceProcessor::class)->processEmployees(collect($this->employees)->values(), $start->toDateString(), $end->toDateString());
            $this->createOvertime($start, $end);
            $this->createPendingRequests($today, $end);

            $this->command?->info('Velocity Motors: payroll…');
            $this->createPayroll($start, $today);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Demo tenant ready. Sign in as owner@' . self::DOMAIN . ' / ' . self::PASSWORD);
    }

    /**
     * WhatsApp in test mode (messages go to the log, nothing is sent) for
     * decisions and payslips, so the delivery log has something to show.
     * Email stays off: the demo addresses aren't real mailboxes.
     */
    private function configureNotifications(Company $company): void
    {
        $settings = new NotificationSetting([
            'whatsapp_enabled' => true,
            'whatsapp_provider' => 'log',
            'events' => [
                'leave.submitted' => ['channels' => ['in_app']],
                'leave.decided' => ['channels' => ['in_app', 'whatsapp'], 'whatsapp_template' => 'leave_status_update'],
                'regularization.decided' => ['channels' => ['in_app', 'whatsapp'], 'whatsapp_template' => 'attendance_correction'],
                'payslip.published' => ['channels' => ['in_app', 'whatsapp'], 'whatsapp_template' => 'payslip_ready'],
            ],
        ]);
        $settings->forceFill(['company_id' => $company->id])->save();
    }

    // ── Organisation ─────────────────────────────────────────────────────

    private function setUpCompany(Company $company, Branch $first): void
    {
        $company->update([
            'phone' => null,
            'website' => 'https://www.' . self::DOMAIN,
            'address_line1' => 'NH 66, Edappally',
            'city' => 'Kochi',
            'state' => 'Kerala',
            'postal_code' => '682024',
            'statutory' => [
                'pf_establishment_code' => 'KRKCH0099999000',
                'esi_employer_code' => '99000099990000999',
                'pan' => 'AAACV9999Z',
                'tan' => 'KOCV99999Z',
                'pt_registration' => 'PT/EKM/2019/99999',
            ],
        ]);
        $company->forceFill(['onboarded_at' => now()])->save();

        $templates = app(IndustryTemplateService::class);

        foreach (self::BRANCHES as $key => [$name, $city, $defaultShift, $weekOff]) {
            $branch = $key === 'EDP'
                ? $first
                : Branch::create([
                    'company_id' => $company->id, 'name' => $name, 'city' => $city, 'state' => 'Kerala', 'country' => 'India',
                    'timezone' => 'Asia/Kolkata', 'currency_code' => 'INR', 'payroll_days_in_month' => 30,
                ]);

            $branch->update(['city' => $city, 'state' => 'Kerala', 'address' => "{$name}, {$city}", 'week_off_days' => $weekOff,
                'attendance_settings' => ['late_marks_per_half_day' => 3]]);
            $templates->apply($branch->fresh(), 'automotive');

            $branch->update(['default_shift_id' => Shift::where('branch_id', $branch->id)->where('name', $defaultShift)->value('id')]);
            $this->branches[$key] = $branch->fresh();
        }

        // Head office needs a General Manager designation beyond the template.
        $admin = Department::where('branch_id', $this->branches['EDP']->id)->where('name', 'Admin')->first();
        Designation::firstOrCreate(['branch_id' => $this->branches['EDP']->id, 'department_id' => $admin->id, 'title' => 'General Manager'], ['level' => 6]);

        // Leave: reporting manager, then HR. Regularization / overtime: the manager.
        foreach ($this->branches as $branch) {
            ApprovalFlow::where('branch_id', $branch->id)->where('module', 'leave')
                ->update(['steps_json' => json_encode([['step' => 1, 'approver_type' => 'manager'], ['step' => 2, 'approver_type' => 'hr']])]);
        }

        BiometricConfig::create([
            'branch_id' => $this->branches['EDP']->id, 'ins_code' => 'VM-EDP-01', 'api_token' => 'demo-token-not-real', 'enabled' => false,
        ]);
    }

    private function createPeople(string $passwordHash, CarbonImmutable $start): void
    {
        $usedNames = [];
        $seq = 1;

        foreach (self::PEOPLE as [$key, $branchKey, $dept, $title, $gross, $role, $manager, $gender, $weekOff, $login]) {
            $branch = $this->branches[$branchKey];
            $department = Department::where('branch_id', $branch->id)->where('name', $dept)->first();
            $designation = Designation::where('branch_id', $branch->id)->where('title', $title)->first();

            do {
                $first = ($gender === 'female' ? self::FEMALE : self::MALE)[mt_rand(0, count($gender === 'female' ? self::FEMALE : self::MALE) - 1)];
                $last = self::SURNAMES[mt_rand(0, count(self::SURNAMES) - 1)];
            } while (isset($usedNames["{$first} {$last}"]));
            $usedNames["{$first} {$last}"] = true;

            if ($key === 'edp_hr') {
                [$first, $last] = ['Lekshmi', 'Nair'];
            }

            $email = ($login ?? strtolower("{$first}.{$last}")) . '@' . self::DOMAIN;
            $joined = $key === 'klm_t5'
                ? $start->addMonth()->addDays(14) // a mid-period joiner: pro-rated first salary
                : $start->subMonths(mt_rand(6, 60))->startOfMonth()->addDays(mt_rand(0, 20));

            $employee = Employee::create([
                'branch_id' => $branch->id,
                'department_id' => $department?->id,
                'designation_id' => $designation?->id,
                'employee_code' => 'VM' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                'biometric_emp_code' => (string) (1000 + $seq),
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                // Starts with 1: never a valid Indian mobile, so demo messages can't reach anyone.
                'phone' => '10000 ' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'gender' => $gender,
                'date_of_birth' => CarbonImmutable::create(mt_rand(1978, 2002), mt_rand(1, 12), mt_rand(1, 28))->toDateString(),
                'date_of_joining' => $joined->toDateString(),
                'employment_type' => 'full_time',
                'status' => 'active',
                'marital_status' => mt_rand(0, 1) ? 'married' : 'single',
                'nationality' => 'Indian',
                'city' => $branch->city,
                'state' => 'Kerala',
                'country' => 'India',
                'tax_id' => 'DEMOP' . str_pad((string) (1000 + $seq), 4, '0', STR_PAD_LEFT) . chr(65 + $seq % 26),
                'uan' => '1009' . str_pad((string) (90000000 + $seq), 8, '0', STR_PAD_LEFT),
                'esi_number' => $gross <= 21000 ? '99' . str_pad((string) (10000000 + $seq), 8, '0', STR_PAD_LEFT) : null,
                'bank_name' => ['Federal Bank', 'South Indian Bank', 'State Bank of India', 'HDFC Bank'][$seq % 4],
                'bank_ifsc_code' => ['FDRL0009999', 'SIBL0009999', 'SBIN0099999', 'HDFC0009999'][$seq % 4],
                'bank_account_number' => '9999' . str_pad((string) (1000000000 + $seq * 7919), 10, '0', STR_PAD_LEFT),
                'payment_method' => 'bank_transfer',
                'weekly_off_days' => $weekOff,
                'tax_regime' => 'new',
            ]);

            // One precomputed bcrypt hash for every demo login (the "hashed"
            // cast keeps an existing hash as is) -- keeps seeding fast.
            $user = User::create([
                'name' => "{$first} {$last}",
                'email' => $email,
                'password' => $passwordHash,
                'branch_id' => $branch->id,
                'employee_id' => $employee->id,
                'user_type' => 'employee',
            ]);
            if ($role !== 'accountant') {
                $user->assignRole($role);
            }
            $employee->update(['user_id' => $user->id]);

            $shiftName = $dept === 'Service' ? 'Workshop' : 'Showroom';
            EmployeeShift::create([
                'employee_id' => $employee->id,
                'shift_id' => Shift::where('branch_id', $branch->id)->where('name', $shiftName)->value('id'),
                'effective_from' => $joined->toDateString(),
            ]);

            $this->salary($employee, $branch, $gross, $joined);

            $this->employees[$key] = $employee;
            $this->users[$key] = $user;
            $seq++;
        }

        foreach (self::PEOPLE as [$key, , , , , , $manager]) {
            if ($manager) {
                $this->employees[$key]->update(['reporting_manager_id' => $this->employees[$manager]->id]);
            }
        }

        // HR at head office covers every branch.
        $this->users['edp_hr']->extraBranches()->sync(collect($this->branches)->except('EDP')
            ->mapWithKeys(fn ($b) => [$b->id => ['company_id' => $b->company_id]])->all());

        foreach ($this->employees as $employee) {
            $employee->refresh();
        }
    }

    /** Basic 50% of gross, HRA 40% of basic, conveyance, special allowance for the rest. */
    private function salary(Employee $employee, Branch $branch, int $gross, CarbonImmutable $joined): void
    {
        $component = fn (string $code) => SalaryComponent::where('branch_id', $branch->id)->where('code', $code)->value('id');

        $basic = (int) (round($gross * 0.5 / 100) * 100);
        $hra = $basic * 0.4;
        $conveyance = $gross >= 15000 ? 1600 : 0;
        $special = max(0, $gross - $basic - $hra - $conveyance);

        foreach (['BASIC' => $basic, 'HRA' => 40, 'CONV' => $conveyance, 'SPL' => $special] as $code => $amount) {
            if ($amount > 0) {
                SalaryStructure::create([
                    'employee_id' => $employee->id, 'component_id' => $component($code), 'amount' => $amount,
                    'effective_from' => $joined->toDateString(),
                ]);
            }
        }
    }

    private function createAccountantRole(): void
    {
        $role = Role::create(['name' => 't' . $this->context->id() . '_accountant', 'guard_name' => 'web']);
        $role->forceFill([
            'company_id' => $this->context->id(), 'display_name' => 'Accountant', 'is_system' => false,
            'description' => 'Payroll processing and statutory reports, organisation-wide.', 'data_scope' => Roles::SCOPE_COMPANY,
        ])->save();
        $role->syncPermissions(['payroll.view', 'payroll.manage', 'reports.view', 'employees.view']);
        $this->users['edp_acc']->assignRole($role);
    }

    private function createHolidays(CarbonImmutable $today): void
    {
        $year = $today->year;
        $holidays = [
            ["{$year}-01-26", 'Republic Day', true],
            ["{$year}-05-01", 'May Day', true],
            ["{$year}-08-15", 'Independence Day', true],
            ["{$year}-10-02", 'Gandhi Jayanti', true],
            ["{$year}-12-25", 'Christmas', true],
            ['2026-08-25', 'First Onam', false],
            ['2026-08-26', 'Thiruvonam', false],
            ['2026-11-08', 'Deepavali', false],
        ];

        foreach ($this->branches as $branch) {
            foreach ($holidays as [$date, $name, $recurring]) {
                Holiday::create(['branch_id' => $branch->id, 'name' => $name, 'date' => $date, 'recurring' => $recurring]);
            }
        }
    }

    /** Service centre technicians rotate onto the late workshop shift on alternate weeks. */
    private function createRosters(CarbonImmutable $start, CarbonImmutable $end): void
    {
        $branch = $this->branches['KLM'];
        $late = Shift::where('branch_id', $branch->id)->where('name', 'Workshop Late')->value('id');
        $techs = ['klm_t1', 'klm_t3', 'klm_t5'];
        $rosterFrom = $end->startOfMonth();

        foreach (CarbonPeriod::create($rosterFrom, $end->addDays(14)) as $day) {
            if ($day->dayOfWeek === 0 || intdiv($day->day - 1, 7) % 2 === 0) {
                continue;
            }
            foreach ($techs as $key) {
                $employee = $this->employees[$key];
                if ($employee->date_of_joining->gt($day)) {
                    continue;
                }
                ShiftRoster::create([
                    'branch_id' => $branch->id, 'department_id' => $employee->department_id,
                    'employee_id' => $employee->id, 'shift_id' => $late, 'date' => $day->toDateString(),
                ]);
            }
        }
    }

    // ── Leave, punches, attendance ───────────────────────────────────────

    /** @return array<int, array<string, true>> employee id => leave dates */
    private function createLeave(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $taken = [];

        // Balances exactly as the policies credit them (annual CL/SL, EL monthly so far).
        app(LeaveAccrualService::class)->syncAll($end->addDay());

        foreach ($this->employees as $key => $employee) {
            $types = LeaveType::where('branch_id', $employee->branch_id)->get()->keyBy('code');

            // One to three single days of casual / sick leave.
            for ($i = 0, $n = mt_rand(1, 3); $i < $n; $i++) {
                $day = $start->addDays(mt_rand(3, (int) $start->diffInDays($end) - 1));
                if ($day->lt($employee->date_of_joining) || in_array($day->dayOfWeek, $employee->weekly_off_days ?? [0], true) || isset($taken[$employee->id][$day->toDateString()])) {
                    continue;
                }
                $type = $types[mt_rand(0, 1) ? 'CL' : 'SL'] ?? $types->first();
                $this->approvedLeave($employee, $type, $day, $day, 1, 'Personal work');
                $taken[$employee->id][$day->toDateString()] = true;
            }
        }

        // Two days of unpaid leave in the middle month: shows up as loss of pay.
        $lop = $this->employees['vyt_se2'];
        $lopType = LeaveType::where('branch_id', $lop->branch_id)->where('code', 'LOP')->first();
        $from = $start->addMonth()->addDays(9);
        while (in_array($from->dayOfWeek, $lop->weekly_off_days ?? [], true)) {
            $from = $from->addDay();
        }
        $this->approvedLeave($lop, $lopType, $from, $from->addDay(), 2, 'Family emergency');
        $taken[$lop->id][$from->toDateString()] = true;
        $taken[$lop->id][$from->addDay()->toDateString()] = true;

        return $taken;
    }

    private function approvedLeave(Employee $employee, LeaveType $type, CarbonImmutable $from, CarbonImmutable $to, float $days, string $reason): void
    {
        $leave = Leave::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'start_date' => $from->toDateString(), 'end_date' => $to->toDateString(), 'leave_year' => $from->year,
            'days' => $days, 'reason' => $reason, 'status' => 'approved',
        ]);

        if (! $type->isUnlimited()) {
            $ledger = app(LeaveLedger::class);
            $ledger->post($ledger->balanceFor($employee, $type, $from->year), 'availed', -$days, [
                'leave_id' => $leave->id, 'note' => $from->format('j M Y'), 'created_by' => null,
            ]);
        }
    }

    private function createPunches(CarbonImmutable $start, CarbonImmutable $end, array $leaveDays): void
    {
        $holidays = Holiday::all()->groupBy('branch_id');
        $rosters = ShiftRoster::with('shift')->get()->groupBy('employee_id')->map(fn ($r) => $r->keyBy(fn ($x) => $x->date->toDateString()));
        $assigned = EmployeeShift::with('shift')->get()->keyBy('employee_id');
        $rows = [];
        $now = now();

        foreach ($this->employees as $key => $employee) {
            $branch = $this->branches[array_search($employee->branch_id, array_map(fn ($b) => $b->id, $this->branches), true)];
            $holidayDates = $holidays->get($branch->id, collect())
                ->map(fn ($h) => $h->recurring ? $h->date->copy()->setYear($end->year)->toDateString() : $h->date->toDateString())->flip();
            $isService = str_contains($key, '_t') || str_contains($key, '_sa') || str_contains($key, '_fs') || str_contains($key, '_svm');
            $mobile = $branch->name !== self::BRANCHES['EDP'][0] && str_contains($key, '_se');

            foreach (CarbonPeriod::create(max($start, CarbonImmutable::instance($employee->date_of_joining)), $end) as $day) {
                $date = $day->toDateString();
                $roster = $rosters->get($employee->id)?->get($date);
                $shift = $roster?->shift ?? $assigned->get($employee->id)?->shift;
                $off = in_array($day->dayOfWeek, $employee->weekly_off_days ?? $branch->week_off_days ?? [0], true) || $holidayDates->has($date);

                if (isset($leaveDays[$employee->id][$date]) || ! $shift) {
                    continue;
                }
                if ($off && mt_rand(1, 100) > 4) {
                    continue; // occasionally someone works their day off
                }

                $roll = mt_rand(1, 1000);
                if ($roll <= 25) {
                    continue; // absent
                }

                $shiftStart = CarbonImmutable::parse("{$date} {$shift->start_time}", 'Asia/Kolkata');
                $shiftEnd = CarbonImmutable::parse("{$date} {$shift->end_time}", 'Asia/Kolkata');
                $in = $roll <= 165 ? $shiftStart->addMinutes(mt_rand(12, 48)) : $shiftStart->addMinutes(mt_rand(-15, 8));

                $out = match (true) {
                    $roll <= 40 => $in->addMinutes(mt_rand(200, 260)),              // left at half-day
                    $roll <= 50 => null,                                              // forgot to punch out
                    $isService && mt_rand(1, 100) <= 6 => $shiftEnd->addMinutes(mt_rand(100, 160)), // overtime
                    default => $shiftEnd->addMinutes(mt_rand(0, 25)),
                };

                foreach (array_filter([$in, $out]) as $i => $local) {
                    $source = $mobile && mt_rand(1, 100) <= 20 ? 'mobile' : 'biometric';
                    $utc = $local->utc();
                    $rows[] = [
                        'company_id' => $employee->company_id,
                        'branch_id' => $employee->branch_id,
                        'employee_id' => $employee->id,
                        'device_emp_code' => $employee->biometric_emp_code,
                        'punched_at' => $utc->format('Y-m-d H:i:s'),
                        'punched_at_local' => $local->format('Y-m-d H:i:s'),
                        'source' => $source,
                        'direction' => $i === 0 ? 'in' : 'out',
                        'latitude' => $source === 'mobile' ? 10.0 + mt_rand(0, 99999) / 1e6 : null,
                        'longitude' => $source === 'mobile' ? 76.3 + mt_rand(0, 99999) / 1e6 : null,
                        'dedupe_hash' => $source === 'biometric'
                            ? sha1("biometric|{$employee->branch_id}|{$employee->biometric_emp_code}|" . $utc->format('Y-m-d H:i:s'))
                            : sha1("{$source}|{$employee->id}|" . $utc->format('Y-m-d H:i:s') . '|'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        // A new joiner whose device code nobody has mapped yet.
        foreach (CarbonPeriod::create($end->subDays(6), $end) as $day) {
            if ($day->dayOfWeek === 2) {
                continue;
            }
            foreach (['09:24', '19:08'] as $time) {
                $local = CarbonImmutable::parse("{$day->toDateString()} {$time}", 'Asia/Kolkata');
                $rows[] = [
                    'company_id' => $this->branches['EDP']->company_id, 'branch_id' => $this->branches['EDP']->id, 'employee_id' => null,
                    'device_emp_code' => '9001', 'punched_at' => $local->utc()->format('Y-m-d H:i:s'), 'punched_at_local' => $local->format('Y-m-d H:i:s'),
                    'source' => 'biometric', 'direction' => null, 'latitude' => null, 'longitude' => null,
                    'dedupe_hash' => sha1("biometric|{$this->branches['EDP']->id}|9001|" . $local->utc()->format('Y-m-d H:i:s')),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('raw_punches')->insertOrIgnore($chunk);
        }
    }

    private function createOvertime(CarbonImmutable $start, CarbonImmutable $end): void
    {
        // Long workshop days become approved (earlier) or pending (recent) OT requests.
        $long = Attendance::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('overtime_minutes', '>=', 90)->where('is_weekly_off', false)
            ->get();

        $workflow = app(ApprovalWorkflowService::class);
        $branchOf = Employee::pluck('branch_id', 'id');

        foreach ($long as $day) {
            if (mt_rand(1, 3) === 1) {
                continue; // not every long day is claimed as overtime
            }
            $recent = $day->date->gt($end->subDays(10));
            $request = OvertimeRequest::create([
                'employee_id' => $day->employee_id,
                'date' => $day->date->toDateString(),
                'hours' => round(min($day->overtime_minutes, 180) / 60 * 2) / 2,
                'reason' => 'Vehicle delivery backlog',
                'status' => $recent ? 'pending' : 'approved',
            ]);
            // Recent claims wait on the reporting manager, like real ones.
            if ($recent) {
                $workflow->submitForApproval($request, 'overtime', $branchOf[$day->employee_id]);
            }
        }
    }

    private function createPendingRequests(CarbonImmutable $today, CarbonImmutable $end): void
    {
        $workflow = app(ApprovalWorkflowService::class);

        // Upcoming leave awaiting approval.
        foreach (['edp_se2' => [5, 6], 'klm_t2' => [8, 8], 'tsr_se1' => [12, 13]] as $key => [$from, $to]) {
            $employee = $this->employees[$key];
            $type = LeaveType::where('branch_id', $employee->branch_id)->where('code', 'CL')->first();
            $leave = Leave::create([
                'employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'start_date' => $today->addDays($from)->toDateString(), 'end_date' => $today->addDays($to)->toDateString(),
                'days' => $to - $from + 1, 'leave_year' => $today->addDays($from)->year, 'reason' => ['Sister\'s wedding', 'Medical appointment', 'Family function'][crc32($key) % 3],
            ]);
            $workflow->submitForApproval($leave, 'leave', $employee->branch_id);
        }

        // Someone forgot to punch out: a regularization waiting for HR.
        $missing = Attendance::where('anomaly', 'missing_out_punch')->whereNull('locked_at')->orderByDesc('date')->first();
        if ($missing) {
            $employee = Employee::find($missing->employee_id);
            $reg = AttendanceRegularization::create([
                'employee_id' => $employee->id,
                'attendance_id' => $missing->id,
                'date' => $missing->date->toDateString(),
                'requested_check_in' => $missing->check_in,
                'requested_check_out' => CarbonImmutable::parse($missing->date->toDateString() . ' 19:05', 'Asia/Kolkata')->utc(),
                'reason' => 'Forgot to punch out after the evening delivery.',
            ]);
            $workflow->submitForApproval($reg, 'regularization', $employee->branch_id);
        }
    }

    // ── Payroll ──────────────────────────────────────────────────────────

    private function createPayroll(CarbonImmutable $start, CarbonImmutable $today): void
    {
        $service = app(PayrollRunService::class);
        $hr = $this->users['edp_hr'];
        $owner = $this->users['owner'];

        foreach ([$start, $start->addMonth(), $today->startOfMonth()] as $i => $month) {
            foreach ($this->branches as $branch) {
                $run = PayrollRun::create(['branch_id' => $branch->id, 'month' => $month->month, 'year' => $month->year]);
                $this->incentives($run, $branch);

                if ($i < 2) {
                    $service->process($run, $hr);
                    $service->finalize($run->fresh(), $owner);
                    $service->markPaid($run->fresh(), $owner, 'NEFT-' . $month->format('Ym') . '-' . $branch->id, $month->endOfMonth()->toDateString());
                }
            }
        }
    }

    private function incentives(PayrollRun $run, Branch $branch): void
    {
        $sales = SalaryComponent::where('branch_id', $branch->id)->where('code', 'INC')->value('id');
        $service = SalaryComponent::where('branch_id', $branch->id)->where('code', 'SINC')->value('id');

        foreach ($this->employees as $key => $employee) {
            if ($employee->branch_id !== $branch->id) {
                continue;
            }
            if (preg_match('/_(se\d|sm|tl)$/', $key)) {
                PayrollRunAdjustment::create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'component_id' => $sales,
                    'amount' => mt_rand(8, 60) * 100, 'note' => mt_rand(3, 14) . ' vehicles delivered', 'created_by' => $this->users['edp_hr']->id]);
            } elseif (preg_match('/_(t\d|sa)$/', $key) && mt_rand(0, 1)) {
                PayrollRunAdjustment::create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'component_id' => $service,
                    'amount' => mt_rand(5, 25) * 100, 'note' => 'Job card target bonus', 'created_by' => $this->users['edp_hr']->id]);
            }
        }
    }
}
