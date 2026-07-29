<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiometricConfig;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiometricConfigController extends Controller
{
    /**
     * Branch admins may only manage their own branch's integration; super
     * admins manage every branch.
     */
    private function assertCanManage(Request $request, Branch $branch): void
    {
        $actor = $request->user();

        if ($actor->is_super_admin || $actor->hasRole('super_admin')) {
            return;
        }

        $isAdmin = $actor->hasAnyRole(['branch_admin']) || $actor->can('settings.manage');

        abort_unless($isAdmin && $actor->branch_id === $branch->id, 403,
            'You are not allowed to manage biometric settings for this branch.');
    }

    /**
     * List every branch the user can see, each merged with its config (or
     * null if not yet set up) — powers the settings table.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $isSuper = $actor->is_super_admin || $actor->hasRole('super_admin');

        $branches = Branch::query()
            ->when(! $isSuper, fn ($q) => $q->where('id', $actor->branch_id))
            ->with(['biometricConfig'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $branches]);
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        $this->assertCanManage($request, $branch);

        $config = BiometricConfig::where('branch_id', $branch->id)->first();

        return response()->json(['data' => $config]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $this->assertCanManage($request, $branch);

        $validated = $request->validate([
            'api_url' => ['required', 'url', 'max:255'],
            'ins_code' => ['required', 'string', 'max:100'],
            'api_token' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['required', 'boolean'],
        ]);

        $config = BiometricConfig::firstOrNew(['branch_id' => $branch->id]);

        if ($config->exists && empty($validated['api_token'])) {
            // Keep the existing token when the admin didn't re-enter it.
            unset($validated['api_token']);
        } elseif (empty($validated['api_token'])) {
            abort(422, 'An API token is required.');
        }

        $config->fill($validated);
        $config->branch_id = $branch->id;
        $config->save();

        return response()->json([
            'data' => $config->fresh(),
            'message' => 'Biometric configuration saved.',
        ]);
    }
}
