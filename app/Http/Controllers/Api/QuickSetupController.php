<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Tenancy\IndustryTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuickSetupController extends Controller
{
    /**
     * POST /api/quick-setup
     *
     * Applies an industry starter template (departments, designations,
     * shifts, leave types, pay components, statutory rules) to a branch.
     * Defaults to the organisation's own industry. Idempotent -- existing
     * items are skipped, so it is safe to run repeatedly.
     */
    public function seed(Request $request, IndustryTemplateService $templates): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'industry' => ['nullable', Rule::in(array_keys(config('industry_templates')))],
        ]);

        $this->authorizeBranch($validated['branch_id']);

        $branch = Branch::findOrFail($validated['branch_id']);

        return response()->json([
            'data' => $templates->apply($branch, $validated['industry'] ?? null),
        ]);
    }

    /**
     * GET /api/industry-templates -- every template with its full contents,
     * so the UI previews exactly what applying one will create.
     */
    public function templates(): JsonResponse
    {
        $templates = collect(config('industry_templates'))->map(fn ($t, $key) => [
            'key' => $key,
            'label' => $t['label'],
            'week_off_days' => $t['week_off_days'],
            'departments' => collect($t['departments'])->map(fn ($designations, $name) => [
                'name' => $name,
                'designations' => array_map(fn ($d) => $d[0], $designations),
            ])->values(),
            'shifts' => $t['shifts'],
            'leave_types' => $t['leave_types'],
            'salary_components' => $t['salary_components'],
            'statutory' => array_column($t['statutory'], 'rule_type'),
        ])->values();

        return response()->json(['data' => $templates]);
    }
}
