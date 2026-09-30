<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StatutoryRule;
use App\Support\Payroll\StatutoryDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A branch's statutory rules -- one per type (PF, ESI, PT, TAX, LWF). Rates
 * and slabs live here, editable by the tenant, seeded from
 * config/statutory.php.
 */
class StatutoryRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->requestedBranchId();

        return response()->json([
            'data' => StatutoryRule::with('branch:id,name')
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->orderBy('branch_id')->orderBy('rule_type')
                ->get(),
        ]);
    }

    /** GET /statutory-rules/defaults?type=PT&state=Kerala -- starting config for a rule. */
    public function defaults(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', Rule::in(StatutoryRule::TYPES)],
            'state' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'data' => StatutoryDefaults::config($request->input('type'), $request->input('state')),
            'pt_states' => StatutoryDefaults::ptStates(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'country' => ['nullable', 'string', 'max:10'],
            'rule_type' => ['required', Rule::in(StatutoryRule::TYPES),
                Rule::unique('statutory_rules', 'rule_type')->where('branch_id', $request->input('branch_id'))],
            'config_json' => ['required', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ] + $this->configRules($request->input('rule_type')));

        $this->authorizeBranch((int) $validated['branch_id']);

        $rule = StatutoryRule::create($validated + ['country' => 'IN', 'is_active' => true]);

        return response()->json(['data' => $rule, 'message' => 'Statutory rule created.'], 201);
    }

    public function show(StatutoryRule $statutoryRule): JsonResponse
    {
        return response()->json(['data' => $statutoryRule->load('branch')]);
    }

    public function update(Request $request, StatutoryRule $statutoryRule): JsonResponse
    {
        $validated = $request->validate([
            'config_json' => ['sometimes', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ] + $this->configRules($statutoryRule->rule_type));

        $this->authorizeBranch($statutoryRule->branch_id);

        if (array_key_exists('is_active', $validated) && $validated['is_active'] === null) {
            unset($validated['is_active']);
        }

        $statutoryRule->update($validated);

        return response()->json(['data' => $statutoryRule->fresh(), 'message' => 'Statutory rule updated.']);
    }

    public function destroy(StatutoryRule $statutoryRule): JsonResponse
    {
        $this->authorizeBranch($statutoryRule->branch_id);
        $statutoryRule->delete();

        return response()->json(['message' => 'Statutory rule deleted.']);
    }

    /** Shape checks for each rule type's config, so payroll never meets garbage. */
    private function configRules(?string $type): array
    {
        $pct = ['nullable', 'numeric', 'min:0', 'max:100'];
        $money = ['nullable', 'numeric', 'min:0', 'max:100000000'];

        return match ($type) {
            'PF' => [
                'config_json.wage_ceiling' => $money, 'config_json.employee_rate' => $pct, 'config_json.employer_rate' => $pct,
                'config_json.eps_rate' => $pct, 'config_json.edli_rate' => $pct, 'config_json.admin_rate' => $pct,
                'config_json.restrict_to_ceiling' => ['nullable', 'boolean'],
            ],
            'ESI' => ['config_json.wage_ceiling' => $money, 'config_json.employee_rate' => $pct, 'config_json.employer_rate' => $pct],
            'PT' => [
                'config_json.state' => ['nullable', 'string', 'max:100'],
                'config_json.basis' => ['nullable', Rule::in(['monthly', 'half_yearly'])],
                'config_json.months' => ['nullable', 'array'], 'config_json.months.*' => ['integer', 'between:1,12'],
                'config_json.slabs' => ['nullable', 'array'],
                'config_json.slabs.*' => ['array', 'size:3'],
                'config_json.slabs.*.0' => ['required', 'numeric', 'min:0'],
                'config_json.slabs.*.1' => ['nullable', 'numeric', 'min:0'],
                'config_json.slabs.*.2' => ['required', 'numeric', 'min:0'],
                'config_json.special_months' => ['nullable', 'array'],
            ],
            'TAX' => [
                'config_json.mode' => ['nullable', Rule::in(['income_tax', 'custom_slabs'])],
                'config_json.slabs' => ['nullable', 'array'],
                'config_json.slabs.*.up_to' => ['nullable', 'numeric', 'min:0'],
                'config_json.slabs.*.rate' => $pct,
            ],
            'LWF' => [
                'config_json.employee_amount' => $money, 'config_json.employer_amount' => $money,
                'config_json.months' => ['nullable', 'array'], 'config_json.months.*' => ['integer', 'between:1,12'],
            ],
            default => [],
        };
    }
}
