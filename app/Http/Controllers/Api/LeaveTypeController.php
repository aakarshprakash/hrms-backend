<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LeaveType::with('branch:id,name')->withCount(['leaves as leaves_count']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->orderBy('name')->get(), 'message' => 'Leave types retrieved successfully.']);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge(
            ['branch_id' => 'required|exists:branches,id', 'name' => 'required|string|max:191'],
            $this->policyRules($request)
        ));

        $this->authorizeBranch((int) $validated['branch_id']);

        $leaveType = LeaveType::create($this->normalise($validated));

        return response()->json(['data' => $leaveType->load('branch:id,name'), 'message' => 'Leave type created successfully.'], 201);
    }

    public function show(LeaveType $leaveType): JsonResponse
    {
        return response()->json(['data' => $leaveType->load('branch:id,name'), 'message' => 'Leave type retrieved successfully.']);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        $this->authorizeBranch($leaveType->branch_id);

        $validated = $request->validate(array_merge(
            ['name' => 'sometimes|string|max:191'],
            $this->policyRules($request, $leaveType)
        ));

        $leaveType->update($this->normalise($validated));

        return response()->json(['data' => $leaveType->fresh('branch:id,name'), 'message' => 'Leave type updated successfully.']);
    }

    /**
     * Only an unused type is deleted (with its empty balances). Once anyone
     * has applied for it, it can only be switched off: its history stays.
     */
    public function destroy(LeaveType $leaveType): JsonResponse
    {
        $this->authorizeBranch($leaveType->branch_id);

        if (Leave::where('leave_type_id', $leaveType->id)->exists()) {
            return response()->json([
                'message' => "{$leaveType->name} has leave history, so it can't be deleted. Switch it off instead — existing records stay intact.",
            ], 422);
        }

        DB::transaction(function () use ($leaveType) {
            $leaveType->balances()->delete();
            $leaveType->delete();
        });

        return response()->json(['data' => null, 'message' => 'Leave type deleted successfully.']);
    }

    private function policyRules(Request $request, ?LeaveType $existing = null): array
    {
        $branchId = $existing?->branch_id ?? $request->integer('branch_id');

        return [
            'code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('leave_types', 'code')->where('branch_id', $branchId)->ignore($existing?->id)],
            'days_per_year' => 'sometimes|integer|min:0|max:366',
            'accrual' => 'sometimes|in:annual,monthly,none',
            'prorate_on_joining' => 'sometimes|boolean',
            'carry_forward' => 'sometimes|boolean',
            'max_carry_forward' => 'nullable|numeric|min:0|max:366',
            'encashable' => 'sometimes|boolean',
            'paid' => 'sometimes|boolean',
            'applicable_gender' => 'nullable|in:male,female',
            'allow_negative' => 'sometimes|boolean',
            'allow_half_day' => 'sometimes|boolean',
            'min_service_days' => 'sometimes|integer|min:0|max:3650',
            'min_notice_days' => 'sometimes|integer|min:0|max:365',
            'max_consecutive_days' => 'nullable|integer|min:1|max:366',
            'requires_document_after_days' => 'nullable|integer|min:0|max:366',
            'sandwich_rule' => 'sometimes|boolean',
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => 'sometimes|boolean',
        ];
    }

    private function normalise(array $data): array
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }
        if (array_key_exists('carry_forward', $data) && ! $data['carry_forward']) {
            $data['max_carry_forward'] = null;
        }

        return $data;
    }
}
