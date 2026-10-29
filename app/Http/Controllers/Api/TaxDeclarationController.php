<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TaxDeclaration;
use App\Services\Payroll\TaxDeclarationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Income-tax regime and investment declarations: employees declare, HR reviews. */
class TaxDeclarationController extends Controller
{
    public function __construct(private TaxDeclarationService $tax)
    {
    }

    /** My declaration for a year, the section catalogue and a regime comparison. */
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->actorEmployee();
        abort_unless($employee, 422, 'No employee profile found for this user.');
        $fy = $request->input('financial_year') ?: $this->currentFy();

        return response()->json(['data' => $this->payload($employee, $fy)]);
    }

    /** Live comparison while the employee edits (nothing is saved). */
    public function preview(Request $request): JsonResponse
    {
        $employee = $this->actorEmployee();
        abort_unless($employee, 422, 'No employee profile found for this user.');
        $v = $this->validateItems($request);
        $eval = $this->tax->evaluate($employee, $v['items'] ?? [], (bool) ($v['metro'] ?? false));

        return response()->json(['data' => ['evaluation' => $eval, 'comparison' => $this->tax->compare($employee, $eval['total'])]]);
    }

    public function save(Request $request): JsonResponse
    {
        $employee = $this->actorEmployee();
        abort_unless($employee, 422, 'No employee profile found for this user.');
        $v = $this->validateItems($request) + $request->validate(['submit' => 'sometimes|boolean', 'financial_year' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);
        $fy = $v['financial_year'] ?? $this->currentFy();

        $this->tax->save($employee, $fy, $v, (bool) ($v['submit'] ?? false));

        return response()->json(['data' => $this->payload($employee, $fy), 'message' => ($v['submit'] ?? false) ? 'Submitted to HR for review.' : 'Draft saved.']);
    }

    /** HR: declarations to review. */
    public function index(Request $request): JsonResponse
    {
        $fy = $request->input('financial_year') ?: $this->currentFy();
        $rows = TaxDeclaration::with('employee:id,employee_code,first_name,last_name,branch_id', 'reviewer:id,name')
            ->visibleTo($request->user())
            ->where('financial_year', $fy)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByRaw("FIELD(status, 'submitted', 'draft', 'rejected', 'approved')")
            ->latest('submitted_at')
            ->get()
            ->map(fn (TaxDeclaration $d) => $d->toArray() + ['employee_name' => $d->employee?->full_name]);

        return response()->json(['data' => $rows, 'financial_year' => $fy, 'counts' => $rows->countBy('status')]);
    }

    public function review(Request $request, TaxDeclaration $declaration): JsonResponse
    {
        $this->authorizeEmployeeVisible($declaration->employee_id);
        $v = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'approved_total' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);
        abort_if($v['decision'] === 'reject' && empty($v['note']), 422, 'Tell the employee what to correct.');

        $d = $this->tax->review($declaration, $v['decision'] === 'approve', isset($v['approved_total']) ? (float) $v['approved_total'] : null, $v['note'] ?? null, $request->user());

        return response()->json(['data' => $d, 'message' => $v['decision'] === 'approve' ? 'Approved — TDS uses it from the next payroll run.' : 'Sent back to the employee.']);
    }

    public function reopen(TaxDeclaration $declaration): JsonResponse
    {
        $this->authorizeEmployeeVisible($declaration->employee_id);

        return response()->json(['data' => $this->tax->reopen($declaration), 'message' => 'Reopened for review.']);
    }

    private function payload(Employee $employee, string $fy): array
    {
        $declaration = TaxDeclaration::where('employee_id', $employee->id)->where('financial_year', $fy)->first();
        $total = $declaration ? (float) $declaration->declared_total : 0.0;

        return [
            'financial_year' => $fy,
            'declaration' => $declaration,
            'sections' => collect(TaxDeclarationService::SECTIONS)->map(fn ($s, $k) => ['key' => $k] + $s)->values(),
            'salary' => $this->tax->annualSalary($employee),
            'comparison' => $this->tax->compare($employee, $total),
            'current_regime' => $employee->tax_regime ?: 'new',
        ];
    }

    private function validateItems(Request $request): array
    {
        return $request->validate([
            'regime' => ['required', Rule::in(['new', 'old'])],
            'metro' => 'sometimes|boolean',
            'items' => 'nullable|array|max:20',
            'items.*.section' => ['required', Rule::in(array_keys(TaxDeclarationService::SECTIONS))],
            'items.*.amount' => 'required|numeric|min:0|max:100000000',
            'items.*.note' => 'nullable|string|max:255',
            'items.*.senior' => 'sometimes|boolean',
        ]);
    }

    private function currentFy(): string
    {
        return $this->tax->financialYear(app(TenantContext::class)->company());
    }
}
