<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Scopes\BranchScope;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveLedger;
use App\Services\Leave\LeaveRequestService;
use App\Services\Leave\LeaveYear;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    public function __construct(
        private LeaveRequestService $leaves,
        private LeaveLedger $ledger,
        private LeaveYear $years,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = LeaveBalance::with(['employee', 'leaveType'])->visibleTo($request->user());

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        $paginator = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'message' => 'Leave balances retrieved successfully.',
        ]);
    }

    /** One employee's balances for a leave year (default: me, this year). */
    public function summary(Request $request): JsonResponse
    {
        $request->validate(['employee_id' => 'nullable|integer', 'year' => 'nullable|integer|min:2000|max:2100']);

        $employee = $request->filled('employee_id')
            ? $this->authorizeEmployeeVisible($request->integer('employee_id'))
            : $this->actorEmployee();
        abort_unless($employee, 422, 'No employee profile found for this user.');

        $year = $request->filled('year') ? $request->integer('year') : null;
        $rows = $this->leaves->summary($employee, $year);
        $year ??= $rows[0]['year'] ?? $this->years->of($employee->company_id, now());

        return response()->json([
            'data' => $rows,
            'meta' => [
                'employee_id' => $employee->id,
                'year' => $year,
                'year_label' => $this->years->label($employee->company_id, $year),
                'years' => LeaveBalance::where('employee_id', $employee->id)->distinct()->orderByDesc('year')->pluck('year'),
            ],
        ]);
    }

    /**
     * Everyone's balances side by side (HR). Columns are leave types by
     * code, since each branch keeps its own copy of each type.
     */
    public function overview(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
            'search' => 'nullable|string|max:100',
            'department_id' => 'nullable|integer',
        ]);

        $company = app(TenantContext::class)->company();
        $year = $request->filled('year') ? $request->integer('year') : $this->years->of($company->id, now());
        $branchId = $this->requestedBranchId();

        $employees = Employee::query()->visibleTo($request->user())
            ->with(['branch:id,name', 'department:id,name', 'designation:id,title'])
            ->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->integer('department_id'), fn ($q, $d) => $q->where('department_id', $d))
            ->when(trim((string) $request->input('search')), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")->orWhere('employee_code', 'like', "%{$s}%")))
            ->orderBy('first_name')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        $ids = collect($employees->items())->pluck('id');
        $balances = LeaveBalance::with('leaveType:id,name,code,color')->whereIn('employee_id', $ids)->where('year', $year)->get()->groupBy('employee_id');
        $pending = Leave::whereIn('employee_id', $ids)->where('leave_year', $year)->where('status', 'pending')
            ->selectRaw('employee_id, leave_type_id, SUM(days) as days')->groupBy('employee_id', 'leave_type_id')->get()
            ->groupBy('employee_id');

        $types = LeaveType::withoutGlobalScope(BranchScope::class)->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'name', 'code', 'color', 'paid', 'accrual', 'allow_negative', 'days_per_year'])
            ->reject(fn (LeaveType $t) => $t->isUnlimited())
            ->groupBy(fn (LeaveType $t) => $t->code ?: $t->name)
            ->map(fn ($group, $key) => ['key' => $key, 'name' => $group->first()->name, 'code' => $group->first()->code, 'color' => $group->first()->color])
            ->values();

        $rows = collect($employees->items())->map(function (Employee $e) use ($balances, $pending) {
            $pendingByType = ($pending->get($e->id) ?? collect())->pluck('days', 'leave_type_id');
            $cells = [];
            foreach ($balances->get($e->id) ?? [] as $b) {
                $key = $b->leaveType?->code ?: $b->leaveType?->name;
                $cells[$key] = [
                    'balance_id' => $b->id,
                    'leave_type_id' => $b->leave_type_id,
                    'allocated' => (float) $b->allocated,
                    'used' => (float) $b->used,
                    'balance' => (float) $b->balance,
                    'pending' => round((float) ($pendingByType[$b->leave_type_id] ?? 0), 2),
                ];
            }

            return [
                'employee' => [
                    'id' => $e->id, 'name' => trim("{$e->first_name} {$e->last_name}"), 'employee_code' => $e->employee_code,
                    'branch' => $e->branch?->name, 'department' => $e->department?->name, 'designation' => $e->designation?->title,
                ],
                'balances' => (object) $cells,
            ];
        });

        return response()->json([
            'data' => $rows,
            'types' => $types,
            'meta' => [
                'year' => $year,
                'year_label' => $this->years->label($company->id, $year),
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    /** The ledger behind one balance: every credit and debit, oldest first. */
    public function transactions(LeaveBalance $balance): JsonResponse
    {
        $this->authorizeEmployeeVisible($balance->employee_id);

        $balance->load('leaveType:id,name,code,color');
        $transactions = $balance->transactions()
            ->with(['creator:id,name', 'leave:id,start_date,end_date,half_day_session'])
            ->orderBy('created_at')->orderBy('id')
            ->get();

        return response()->json(['data' => $transactions, 'balance' => $balance]);
    }

    /** A manual credit or debit (comp-off earned, correction, opening balance ...). */
    public function adjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'year' => 'nullable|integer|min:2000|max:2100',
            'days' => 'required|numeric|between:-365,365|not_in:0',
            'note' => 'required|string|min:3|max:255',
        ]);

        $employee = $this->authorizeEmployeeVisible($validated['employee_id']);
        $this->authorizeBranch($employee->branch_id);

        $type = LeaveType::withoutGlobalScope(BranchScope::class)->findOrFail($validated['leave_type_id']);
        abort_unless((int) $type->branch_id === (int) $employee->branch_id, 422, 'That leave type belongs to another branch.');
        abort_if($type->isUnlimited(), 422, "{$type->name} doesn't keep a balance.");

        $days = round((float) $validated['days'] * 2) / 2;
        abort_if($days == 0.0, 422, 'Adjust by at least half a day.');

        $year = $validated['year'] ?? $this->years->of($employee->company_id, now());
        $balance = $this->ledger->balanceFor($employee, $type, $year);
        $this->ledger->post($balance, 'adjustment', $days, ['note' => $validated['note']]);

        return response()->json([
            'data' => $balance->fresh('leaveType:id,name,code'),
            'message' => ($days > 0 ? 'Credited ' : 'Debited ') . abs($days) . " day(s) of {$type->name}.",
        ]);
    }

    /** Apply the leave policies now (after changing a policy, or when the scheduler is off). */
    public function recalculate(Request $request, LeaveAccrualService $accrual): JsonResponse
    {
        $branchId = $this->requestedBranchId();
        $branches = $branchId ? [$branchId] : $request->user()->accessibleBranchIds();

        $stats = $accrual->syncAll(CarbonImmutable::now(config('app.timezone')), $branches);

        return response()->json([
            'data' => $stats,
            'message' => "Balances are up to date for {$stats['employees']} employee(s); {$stats['credits']} new credit(s).",
        ]);
    }
}
