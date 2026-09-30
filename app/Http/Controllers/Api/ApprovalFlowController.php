<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use App\Models\Branch;
use App\Models\Role;
use App\Services\ApprovalWorkflowService;
use App\Support\Access\Roles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Who approves leave, overtime and regularization requests, per branch.
 * Changing a flow affects new requests only; requests already waiting keep
 * the approvers they were submitted with.
 */
class ApprovalFlowController extends Controller
{
    public const MODULES = ['leave' => 'Leave', 'regularization' => 'Attendance regularization', 'overtime' => 'Overtime'];

    public function __construct(private ApprovalWorkflowService $workflow)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $branchId = $this->requestedBranchId() ?? $request->user()->branch_id ?? Branch::query()->orderBy('id')->value('id');
        abort_unless($branchId, 404, 'No branch found.');

        $flows = ApprovalFlow::where('branch_id', $branchId)->get()->keyBy('module');

        $data = collect(self::MODULES)->map(function ($label, $module) use ($flows) {
            $steps = collect($flows->get($module)?->steps_json ?? ApprovalWorkflowService::DEFAULT_STEPS)
                ->sortBy('step')
                ->map(fn ($s) => $s['approver_type'] ?? $s['approver_role'] ?? ApprovalWorkflowService::ANY)
                ->map(fn ($t) => $t === Roles::SUPER_ADMIN ? Roles::TENANT_ADMIN : $t)
                ->values();

            return [
                'module' => $module,
                'label' => $label,
                'configured' => $flows->has($module),
                'steps' => $steps->map(fn ($t) => ['approver_type' => $t, 'label' => $this->workflow->describe($t)])->all(),
            ];
        })->values();

        return response()->json(['data' => $data, 'branch_id' => $branchId, 'approver_types' => $this->approverTypes()]);
    }

    public function update(Request $request, Branch $branch, string $module): JsonResponse
    {
        $this->authorizeBranch($branch->id);
        abort_unless(array_key_exists($module, self::MODULES), 404);

        $types = collect($this->approverTypes())->pluck('value')->all();
        $validated = $request->validate([
            'steps' => 'present|array|max:4',
            'steps.*.approver_type' => ['required', 'string', Rule::in($types)],
            'apply_to_all_branches' => 'nullable|boolean',
        ]);

        $steps = collect($validated['steps'])->values()
            ->map(fn ($s, $i) => ['step' => $i + 1, 'approver_type' => $s['approver_type']])
            ->all();

        $branchIds = $request->boolean('apply_to_all_branches')
            ? Branch::query()->pluck('id')->filter(fn ($id) => $request->user()->canAccessBranch($id))->all()
            : [$branch->id];

        foreach ($branchIds as $id) {
            ApprovalFlow::updateOrCreate(['branch_id' => $id, 'module' => $module], ['steps_json' => $steps]);
        }

        return response()->json([
            'message' => count($steps) === 0
                ? self::MODULES[$module] . ' requests will be approved automatically.'
                : self::MODULES[$module] . ' approvals updated' . (count($branchIds) > 1 ? ' for ' . count($branchIds) . ' branches.' : '.'),
        ]);
    }

    /** Everyone a step can wait for: the reporting manager, built-in approver roles, and custom roles. */
    private function approverTypes(): array
    {
        $types = [
            ['value' => ApprovalWorkflowService::MANAGER, 'label' => 'Reporting manager', 'hint' => 'Anyone above the employee in their reporting line; HR can act on their behalf.'],
            ['value' => Roles::HR, 'label' => 'HR', 'hint' => 'HR of the employee’s branch.'],
            ['value' => Roles::BRANCH_ADMIN, 'label' => 'Branch Admin', 'hint' => 'Admin of the employee’s branch.'],
            ['value' => Roles::TENANT_ADMIN, 'label' => 'Tenant Admin', 'hint' => 'The organisation’s owner / admins.'],
            ['value' => ApprovalWorkflowService::ANY, 'label' => 'Any approver', 'hint' => 'Anyone with approval rights over the employee.'],
        ];

        // Roles aren't tenant-scoped (see App\Models\Role): only this tenant's own.
        $custom = Role::query()->where('company_id', app(TenantContext::class)->id() ?? 0)
            ->orderBy('display_name')->get(['name', 'display_name'])
            ->map(fn ($r) => ['value' => $r->name, 'label' => $r->display_name ?: $r->name, 'hint' => 'Custom role.'])
            ->all();

        return array_merge($types, $custom);
    }
}
