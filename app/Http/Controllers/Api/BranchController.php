<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Support\Billing\Limits;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $ids = $request->user()->accessibleBranchIds();

        $branches = Branch::with('company')
            ->withCount(['employees' => fn ($q) => $q->where('status', 'active')])
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data'    => $branches,
            'message' => 'Branches retrieved successfully.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateBranch($request, creating: true);

        $company = $this->context->company();
        abort_unless($company, 403, 'No organisation selected.');

        Limits::assertCanAdd($company, 'branches');

        // timezone/currency_code are NOT NULL columns; an empty form field
        // becomes null via ConvertEmptyStringsToNull, so fall back explicitly.
        $validated['timezone'] = $validated['timezone'] ?? $company->timezone ?? 'UTC';
        $validated['currency_code'] = $validated['currency_code'] ?? $company->currency_code ?? 'INR';
        $validated['payroll_days_in_month'] = $validated['payroll_days_in_month'] ?? 30;
        $validated['week_off_days'] = $validated['week_off_days'] ?? [0];
        $validated['company_id'] = $company->id;

        $branch = Branch::create($validated);

        return response()->json([
            'data'    => $branch->load('company'),
            'message' => 'Branch created successfully.',
        ], 201);
    }

    public function show(Branch $branch): JsonResponse
    {
        $this->authorizeBranch($branch->id);

        return response()->json([
            'data'    => $branch->load('company'),
            'message' => 'Branch retrieved successfully.',
        ]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch($branch->id);

        $validated = $this->validateBranch($request, creating: false);

        if (! empty($validated['default_shift_id'])) {
            abort_unless(\App\Models\Shift::whereKey($validated['default_shift_id'])->value('branch_id') === $branch->id, 422,
                "The default shift must be one of this branch's shifts.");
        }

        // NOT NULL columns: an emptied form field means "leave unchanged".
        foreach (['timezone', 'currency_code', 'payroll_days_in_month', 'week_off_days'] as $key) {
            if (array_key_exists($key, $validated) && $validated[$key] === null) {
                unset($validated[$key]);
            }
        }

        $branch->update($validated);

        return response()->json([
            'data'    => $branch->fresh('company'),
            'message' => 'Branch updated successfully.',
        ]);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $this->authorizeBranch($branch->id);

        if ($branch->employees()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a branch that has employees. Reassign employees first.',
            ], 422);
        }

        if (Branch::count() <= 1) {
            return response()->json(['message' => 'An organisation needs at least one branch.'], 422);
        }

        $branch->delete();

        return response()->json(['message' => 'Branch deleted successfully.']);
    }

    /** GET /company -- the caller's own organisation. */
    public function company(): JsonResponse
    {
        return response()->json(['data' => $this->context->company()]);
    }

    /** PUT /company */
    public function updateCompany(Request $request): JsonResponse
    {
        $company = $this->context->company();
        abort_unless($company, 403, 'No organisation selected.');

        $validated = $request->validate([
            'name'                    => ['required', 'string', 'max:191'],
            'legal_name'              => ['nullable', 'string', 'max:191'],
            'industry'                => ['nullable', Rule::in(array_keys(Company::INDUSTRIES))],
            'timezone'                => ['nullable', 'timezone:all'],
            'email'                   => ['nullable', 'email', 'max:191'],
            'phone'                   => ['nullable', 'string', 'max:30'],
            'website'                 => ['nullable', 'url', 'max:191'],
            'address_line1'           => ['nullable', 'string', 'max:255'],
            'address_line2'           => ['nullable', 'string', 'max:255'],
            'city'                    => ['nullable', 'string', 'max:100'],
            'state'                   => ['nullable', 'string', 'max:100'],
            'postal_code'             => ['nullable', 'string', 'max:20'],
            'currency_code'           => ['nullable', 'string', 'size:3'],
            'fiscal_year_start_month' => ['nullable', 'integer', 'between:1,12'],
            'statutory'               => ['nullable', 'array'],
            'statutory.pf_establishment_code' => ['nullable', 'string', 'max:30'],
            'statutory.esi_employer_code'     => ['nullable', 'string', 'max:30'],
            'statutory.pan'                   => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'statutory.tan'                   => ['nullable', 'string', 'regex:/^[A-Z]{4}[0-9]{5}[A-Z]$/'],
            'statutory.pt_registration'       => ['nullable', 'string', 'max:40'],
            'statutory.lwf_registration'      => ['nullable', 'string', 'max:40'],
            'statutory.gstin'                 => ['nullable', 'string', 'max:20'],
        ]);

        foreach (['timezone', 'industry', 'currency_code', 'fiscal_year_start_month'] as $key) {
            if (array_key_exists($key, $validated) && $validated[$key] === null) {
                unset($validated[$key]);
            }
        }

        if (isset($validated['statutory'])) {
            $validated['statutory'] = array_merge($company->statutory ?? [], array_filter(
                $validated['statutory'], fn ($v) => $v !== null
            ));
        }

        $company->update($validated);

        return response()->json(['data' => $company->fresh(), 'message' => 'Organisation settings saved.']);
    }

    private function validateBranch(Request $request, bool $creating): array
    {
        return $request->validate([
            'name'                   => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'address'                => ['nullable', 'string', 'max:500'],
            'city'                   => ['nullable', 'string', 'max:191'],
            'state'                  => ['nullable', 'string', 'max:100'],
            'country'                => ['nullable', 'string', 'max:191'],
            'timezone'               => ['nullable', 'timezone:all'],
            'default_shift_id'       => ['nullable', 'integer', 'exists:shifts,id'],
            'attendance_settings'    => ['nullable', 'array'],
            'attendance_settings.late_marks_per_half_day' => ['nullable', 'integer', 'min:0', 'max:31'],
            'currency_code'          => ['nullable', 'string', 'max:10'],
            'payroll_days_in_month'  => ['nullable', 'integer', 'min:1', 'max:31'],
            'week_off_days'          => ['nullable', 'array'],
            'week_off_days.*'        => ['integer', 'min:0', 'max:6'],
        ]);
    }
}
