<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiometricSyncLog;
use App\Models\Branch;
use App\Services\BiometricAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiometricSyncController extends Controller
{
    private function assertCanManage(Request $request, Branch $branch): void
    {
        $actor = $request->user();

        if ($actor->is_super_admin || $actor->hasRole('super_admin')) {
            return;
        }

        $isAdmin = $actor->hasAnyRole(['branch_admin', 'hr']) || $actor->can('attendance.manage');

        abort_unless($isAdmin && $actor->branch_id === $branch->id, 403,
            'You are not allowed to sync attendance for this branch.');
    }

    public function sync(Request $request, Branch $branch, BiometricAttendanceService $service): JsonResponse
    {
        $this->assertCanManage($request, $branch);

        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        try {
            $log = $service->sync($branch, $validated['date_from'], $validated['date_to'], $request->user());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $log,
            'message' => "Synced {$log->matched_count} day(s) of attendance." .
                ($log->unmatched_count > 0 ? " {$log->unmatched_count} device code(s) could not be matched to an employee." : ''),
        ]);
    }

    public function logs(Request $request, Branch $branch): JsonResponse
    {
        $this->assertCanManage($request, $branch);

        $logs = BiometricSyncLog::where('branch_id', $branch->id)
            ->with('triggeredBy:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json(['data' => $logs]);
    }
}
