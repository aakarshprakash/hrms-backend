<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Scopes\BranchScope;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\Attendance\ShiftSwapService;
use App\Services\Notifications\Notifier;
use App\Support\Notifications\NotificationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shift swaps: an employee proposes, the colleague accepts or declines,
 * and someone who manages shifts approves -- which swaps the rosters.
 */
class ShiftSwapController extends Controller
{
    public function __construct(private ShiftSwapService $swaps)
    {
    }

    /** ?scope=mine (requested by / with me) | approvals (waiting for a shift manager). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $approvals = $request->input('scope') === 'approvals';
        abort_if($approvals && ! $user->can('shifts.manage'), 403);

        $query = ShiftSwapRequest::with(['requester:id,first_name,last_name,employee_code', 'targetEmployee:id,first_name,last_name,employee_code', 'decider:id,name'])
            ->latest()->limit(100);

        if ($approvals) {
            $query->where('status', 'pending')
                ->whereIn('requester_id', Employee::query()->visibleTo($user)->select('employees.id'));
        } else {
            $me = $user->employee_id ?? 0;
            $query->where(fn ($q) => $q->where('requester_id', $me)->orWhere('target_employee_id', $me));
        }

        return response()->json(['data' => $query->get()->map(fn (ShiftSwapRequest $s) => $s->toArray() + [
            'can_respond' => $s->status === 'pending' && ! $s->target_response && $s->target_employee_id === $user->employee_id,
            'can_cancel' => $s->status === 'pending' && $s->requester_id === $user->employee_id,
            'can_decide' => $approvals && $s->target_response === 'accepted' && $s->requester_id !== $user->employee_id,
        ])]);
    }

    /**
     * Colleagues someone can swap with: active people in their own branch.
     * Name, code and designation only -- anyone can see who they work with.
     */
    public function colleagues(): JsonResponse
    {
        $me = $this->actorEmployee();
        abort_unless($me, 422, 'No employee profile found for this user.');

        $people = Employee::withoutGlobalScope(BranchScope::class)->with('designation:id,title')
            ->where('branch_id', $me->branch_id)->where('status', 'active')->whereKeyNot($me->id)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'designation_id']);

        return response()->json(['data' => $people->map(fn ($e) => [
            'id' => $e->id, 'first_name' => $e->first_name, 'last_name' => $e->last_name,
            'employee_code' => $e->employee_code, 'designation' => $e->designation?->title,
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'with_employee_id' => 'required|integer|exists:employees,id',
            'my_date' => 'required|date',
            'their_date' => 'required|date',
            'reason' => 'nullable|string|max:500',
        ]);

        $requester = $this->actorEmployee();
        abort_unless($requester, 422, 'No employee profile found for this user.');
        $target = Employee::withoutGlobalScope(BranchScope::class)->findOrFail($validated['with_employee_id']);

        $myDate = substr($validated['my_date'], 0, 10);
        $theirDate = substr($validated['their_date'], 0, 10);
        if ($problem = $this->swaps->problem($requester, $target, $myDate, $theirDate)) {
            return response()->json(['message' => $problem], 422);
        }

        $swap = ShiftSwapRequest::create([
            'requester_id' => $requester->id,
            'target_employee_id' => $target->id,
            'my_date' => $myDate,
            'their_date' => $theirDate,
            'reason' => $validated['reason'] ?? null,
            'status' => 'pending',
        ]);

        $this->notify($this->userOf($target->id), 'shift_swap.requested', 'Shift swap request',
            "{$requester->full_name} would like to swap shifts with you ({$this->dates($swap)}). Accept or decline under Shift swaps.");

        return response()->json(['data' => $swap, 'message' => "Sent to {$target->first_name} to accept."], 201);
    }

    /** The colleague's answer. */
    public function respond(Request $request, ShiftSwapRequest $swap): JsonResponse
    {
        $validated = $request->validate(['response' => 'required|in:accepted,declined']);
        abort_unless($swap->target_employee_id === $request->user()->employee_id, 403, 'Only the colleague asked can answer.');
        abort_unless($swap->status === 'pending' && ! $swap->target_response, 422, 'This request has already been answered.');

        $accepted = $validated['response'] === 'accepted';
        $swap->update([
            'target_response' => $validated['response'],
            'responded_at' => now(),
            'status' => $accepted ? 'pending' : 'rejected',
        ]);

        $target = $swap->targetEmployee;
        $this->notify($this->userOf($swap->requester_id), 'shift_swap.decided', $accepted ? 'Swap accepted' : 'Swap declined',
            $accepted ? "{$target->full_name} accepted your shift swap ({$this->dates($swap)}). It now needs approval."
                : "{$target->full_name} declined your shift swap ({$this->dates($swap)}).");

        if ($accepted) {
            $requester = $swap->requester;
            $approvers = User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('name', ['hr', 'branch_admin']))
                ->get()->filter(fn (User $u) => $u->canAccessBranch($requester->branch_id) && $u->employee_id !== $requester->id);
            $this->notify($approvers, 'shift_swap.requested', 'Shift swap to approve',
                "{$requester->full_name} and {$target->full_name} agreed to swap shifts ({$this->dates($swap)}).", '/shifts/swaps');
        }

        return response()->json(['data' => $swap->fresh(), 'message' => $accepted ? 'Accepted — it goes for approval.' : 'Declined.']);
    }

    public function approve(Request $request, ShiftSwapRequest $swap): JsonResponse
    {
        $this->authorizeDecision($request, $swap);
        abort_unless($swap->target_response === 'accepted', 422, 'The colleague hasn’t accepted this swap yet.');

        $this->swaps->approve($swap, $request->user(), $request->input('note'));
        $this->notifyBoth($swap, true);

        return response()->json(['data' => $swap->fresh(), 'message' => 'Swap approved — the roster is updated.']);
    }

    public function reject(Request $request, ShiftSwapRequest $swap): JsonResponse
    {
        $this->authorizeDecision($request, $swap);
        $swap->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $request->input('note')]);
        $this->notifyBoth($swap, false);

        return response()->json(['data' => $swap->fresh(), 'message' => 'Swap rejected.']);
    }

    public function cancel(Request $request, ShiftSwapRequest $swap): JsonResponse
    {
        abort_unless($swap->requester_id === $request->user()->employee_id, 403);
        abort_unless($swap->status === 'pending', 422, 'Only a pending request can be cancelled.');
        $swap->update(['status' => 'cancelled']);

        return response()->json(['data' => $swap->fresh(), 'message' => 'Request cancelled.']);
    }

    /** Older clients: PUT {status: approved|rejected}. */
    public function update(Request $request, ShiftSwapRequest $swap): JsonResponse
    {
        $validated = $request->validate(['status' => 'required|in:approved,rejected']);

        return $validated['status'] === 'approved' ? $this->approve($request, $swap) : $this->reject($request, $swap);
    }

    private function authorizeDecision(Request $request, ShiftSwapRequest $swap): void
    {
        abort_unless($request->user()->can('shifts.manage'), 403);
        abort_unless($swap->status === 'pending', 422, 'This swap request has already been processed.');
        $requester = $this->authorizeEmployeeVisible($swap->requester_id);
        $this->authorizeBranch($requester->branch_id);
        abort_if($swap->requester_id === $request->user()->employee_id || $swap->target_employee_id === $request->user()->employee_id,
            403, 'You can’t approve a swap you’re part of.');
    }

    private function notifyBoth(ShiftSwapRequest $swap, bool $approved): void
    {
        $users = collect([$this->userOf($swap->requester_id), $this->userOf($swap->target_employee_id)]);
        $this->notify($users, 'shift_swap.decided', $approved ? 'Shift swap approved' : 'Shift swap rejected',
            $approved ? "Your shift swap ({$this->dates($swap)}) is approved and the roster is updated."
                : "Your shift swap ({$this->dates($swap)}) was not approved.");
    }

    private function notify($users, string $event, string $title, string $body, string $link = '/shifts/swaps'): void
    {
        app(Notifier::class)->send($users instanceof User || $users === null ? $users : collect($users)->filter(),
            new NotificationMessage(event: $event, title: $title, body: $body, link: $link, params: [], lines: [$body], actionLabel: 'Open shift swaps'));
    }

    private function userOf(int $employeeId): ?User
    {
        return User::query()->where('employee_id', $employeeId)->where('is_active', true)->first();
    }

    private function dates(ShiftSwapRequest $swap): string
    {
        $a = $swap->my_date;
        $b = $swap->their_date;

        return $a->equalTo($b) ? $a->format('j M') : $a->format('j M') . ' ↔ ' . $b->format('j M');
    }
}
