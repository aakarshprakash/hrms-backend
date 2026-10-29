<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PayrollRun;
use App\Services\Payroll\ArrearsService;
use App\Services\Payroll\BonusService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Statutory bonus and salary-revision arrears: preview, then add to a payroll run. */
class BonusArrearsController extends Controller
{
    public function __construct(private BonusService $bonus, private ArrearsService $arrears)
    {
    }

    public function bonus(Request $request): JsonResponse
    {
        $v = $this->bonusInput($request);

        return response()->json(['data' => $this->bonus->calculate($v['branch_id'], $v['financial_year'], $v)]);
    }

    public function applyBonus(Request $request): JsonResponse
    {
        $v = $this->bonusInput($request) + $request->validate(['payroll_run_id' => 'required|integer|exists:payroll_runs,id']);
        $run = PayrollRun::findOrFail($v['payroll_run_id']);
        abort_unless($run->branch_id === $v['branch_id'], 422, 'Choose a payroll run of the same branch.');

        $result = $this->bonus->calculate($v['branch_id'], $v['financial_year'], $v);
        $count = $this->bonus->apply($run, $result['rows'], $v['financial_year'], (float) $v['rate'], $request->user());

        return response()->json(['data' => ['employees' => $count, 'total' => $result['totals']['bonus']],
            'message' => "Bonus added for {$count} employee(s) to the " . CarbonImmutable::create($run->year, $run->month)->format('F Y') . ' run. Process the run to include it.']);
    }

    public function arrears(Request $request): JsonResponse
    {
        [$run, $v] = $this->arrearsInput($request);

        return response()->json(['data' => $this->arrears->calculate($run, $v['from'], $v['to'], $v['employee_ids'] ?? null)]);
    }

    public function applyArrears(Request $request): JsonResponse
    {
        [$run, $v] = $this->arrearsInput($request);
        $result = $this->arrears->calculate($run, $v['from'], $v['to'], $v['employee_ids'] ?? null);
        $label = CarbonImmutable::parse($v['from'] . '-01')->format('M Y') . ' – ' . CarbonImmutable::parse($v['to'] . '-01')->format('M Y');
        $count = $this->arrears->apply($run, $result['rows'], $label, $request->user());

        return response()->json(['data' => ['employees' => $count, 'total' => $result['totals']['arrears']],
            'message' => "Arrears added for {$count} employee(s). Process the run to include them."]);
    }

    private function bonusInput(Request $request): array
    {
        $v = $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
            'financial_year' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'rate' => 'required|numeric|min:8.33|max:20',
            'eligibility_ceiling' => 'nullable|numeric|min:0',
            'calculation_ceiling' => 'nullable|numeric|min:0',
        ]);
        $this->authorizeBranch($v['branch_id']);
        $v['fy_start_month'] = (int) (Company::find(app(TenantContext::class)->id())?->fiscal_year_start_month ?: 4);

        return $v;
    }

    private function arrearsInput(Request $request): array
    {
        $v = $request->validate([
            'payroll_run_id' => 'required|integer|exists:payroll_runs,id',
            'from' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'to' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer|exists:employees,id',
        ]);
        $run = PayrollRun::findOrFail($v['payroll_run_id']);
        $this->authorizeBranch($run->branch_id);

        return [$run, $v];
    }
}
