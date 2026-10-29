<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLoan;
use App\Services\Payroll\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Loans and salary advances. Everyone sees their own; payroll.manage sees
 * and manages everyone's in their data scope.
 */
class LoanController extends Controller
{
    public function __construct(private LoanService $loans)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = EmployeeLoan::with('employee:id,employee_code,first_name,last_name,branch_id')->latest('id');

        if ($user->can('payroll.manage') || $user->can('payroll.view')) {
            $query->visibleTo($user);
            if ($request->filled('employee_id')) {
                $query->where('employee_id', $request->integer('employee_id'));
            }
        } else {
            $query->where('employee_id', $this->actorEmployee()?->id ?? 0);
        }

        $rows = $query->get()->map(fn (EmployeeLoan $l) => $this->loans->present($l));
        if ($request->filled('state')) {
            $rows = $rows->where('state', $request->string('state')->toString())->values();
        }

        return response()->json(['data' => $rows, 'totals' => [
            'active' => $rows->where('state', 'active')->count(),
            'outstanding' => round($rows->where('state', 'active')->sum('outstanding'), 2),
            'disbursed' => round($rows->where('state', '!=', 'cancelled')->sum(fn ($r) => (float) $r['principal']), 2),
        ]]);
    }

    public function show(EmployeeLoan $loan): JsonResponse
    {
        $this->authorizeLoan($loan);

        return response()->json(['data' => $this->loans->present($loan)]);
    }

    /** EMI and total for given terms, before creating anything. */
    public function quote(Request $request): JsonResponse
    {
        $v = $request->validate(['principal' => 'required|numeric|min:1', 'interest_rate' => 'nullable|numeric|min:0|max:36', 'tenure_months' => 'required|integer|min:1|max:120']);

        return response()->json(['data' => $this->loans->terms((float) $v['principal'], (float) ($v['interest_rate'] ?? 0), (int) $v['tenure_months'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'type' => ['required', Rule::in(['loan', 'advance'])],
            'principal' => 'required|numeric|min:1|max:10000000',
            'interest_rate' => 'nullable|numeric|min:0|max:36',
            'tenure_months' => 'required|integer|min:1|max:120',
            'start_month' => 'required|integer|min:1|max:12',
            'start_year' => 'required|integer|min:2000|max:2100',
            'disbursed_on' => 'nullable|date',
            'reason' => 'nullable|string|max:500',
        ]);
        $employee = $this->authorizeEmployeeVisible($v['employee_id']);

        $loan = $this->loans->create($employee, $v, $request->user());

        return response()->json(['data' => $this->loans->present($loan), 'message' => ($v['type'] === 'advance' ? 'Advance' : 'Loan') . ' recorded. Recovery starts with that month’s payroll.'], 201);
    }

    /** Change the instalment or start month (e.g. to pause recovery), or cancel. */
    public function update(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $this->authorizeLoan($loan);
        $v = $request->validate([
            'emi' => 'sometimes|numeric|min:1',
            'start_month' => 'sometimes|integer|min:1|max:12',
            'start_year' => 'sometimes|integer|min:2000|max:2100',
            'status' => ['sometimes', Rule::in(['active', 'cancelled'])],
            'reason' => 'nullable|string|max:500',
        ]);
        abort_if(isset($v['status']) && $v['status'] === 'cancelled' && $loan->recoveries()->exists(), 422,
            'Instalments have already been recovered — record a waiver for the balance instead of cancelling.');

        $loan->update($v);

        return response()->json(['data' => $this->loans->present($loan->fresh()), 'message' => 'Loan updated.']);
    }

    /** Repayment outside payroll, foreclosure or waiver. */
    public function repay(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $this->authorizeLoan($loan);
        $v = $request->validate([
            'amount' => 'required|numeric|min:1',
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'waiver'])],
            'note' => 'nullable|string|max:255',
        ]);

        $this->loans->repay($loan, (float) $v['amount'], $v['method'], $v['note'] ?? null, $request->user());

        return response()->json(['data' => $this->loans->present($loan->fresh()), 'message' => 'Repayment recorded.']);
    }

    private function authorizeLoan(EmployeeLoan $loan): void
    {
        $this->authorizeEmployeeVisible($loan->employee_id);
    }
}
