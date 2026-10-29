<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoverageRule;
use App\Models\Leave;
use App\Services\Attendance\CoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Team coverage: today, the coming days, minimum-staffing rules, and leave impact. */
class CoverageController extends Controller
{
    public function __construct(private CoverageService $coverage)
    {
    }

    public function today(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->coverage->today($this->requestedBranchId())]);
    }

    public function forecast(Request $request): JsonResponse
    {
        $days = min(31, max(1, $request->integer('days', 14)));

        return response()->json(['data' => $this->coverage->forecast($this->requestedBranchId(), $days)]);
    }

    public function rules(): JsonResponse
    {
        return response()->json(['data' => CoverageRule::with(['branch:id,name', 'department:id,name', 'designation:id,title'])
            ->withCount('alerts')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = CoverageRule::create($this->validated($request));

        return response()->json(['data' => $rule->load(['branch:id,name', 'department:id,name', 'designation:id,title']), 'message' => 'Coverage rule added.'], 201);
    }

    public function update(Request $request, CoverageRule $rule): JsonResponse
    {
        $rule->update($this->validated($request));

        return response()->json(['data' => $rule->fresh(['branch:id,name', 'department:id,name', 'designation:id,title']), 'message' => 'Coverage rule saved.']);
    }

    public function destroy(CoverageRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['data' => null, 'message' => 'Coverage rule deleted.']);
    }

    /** Days on which approving this leave leaves a team below its minimum. */
    public function leaveImpact(Leave $leave): JsonResponse
    {
        return response()->json(['data' => $this->coverage->leaveImpact($leave->loadMissing('employee'))]);
    }

    private function validated(Request $request): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'designation_id' => ['nullable', 'integer', 'exists:designations,id'],
            'min_present' => ['required', 'integer', 'min:1', 'max:10000'],
            'check_at' => ['required', 'date_format:H:i'],
            'notify_user_ids' => ['nullable', 'array'],
            'notify_user_ids.*' => ['integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        abort_if(empty($v['department_id']) && empty($v['designation_id']), 422, 'Choose a department or a designation the rule covers.');
        if (! empty($v['branch_id'])) {
            $this->authorizeBranch((int) $v['branch_id']);
        }

        return $v;
    }
}
