<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\HtmlPurifierService;
use App\Support\Notifications\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Announcements: everyone reads the ones addressed to them (company-wide or
 * their branch) and acknowledges those that ask for it; announcements.manage
 * publishes them and sees who has read / acknowledged.
 */
class AnnouncementController extends Controller
{
    /** My feed: pinned first, then newest; with my read / acknowledged state. */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $user->employee?->branch_id ?? $user->branch_id;
        $items = Announcement::with('author:id,name')->liveFor($branchId)
            ->orderByDesc('pinned')->orderByDesc('published_at')->limit(100)->get();
        $mine = AnnouncementRead::where('user_id', $user->id)->whereIn('announcement_id', $items->pluck('id'))->get()->keyBy('announcement_id');

        $rows = $items->map(fn (Announcement $a) => $this->present($a) + [
            'read_at' => $mine[$a->id]->read_at ?? null,
            'acknowledged_at' => $mine[$a->id]->acknowledged_at ?? null,
        ]);

        return response()->json(['data' => $rows, 'unread' => $rows->whereNull('read_at')->count(),
            'pending_ack' => $rows->where('requires_ack', true)->whereNull('acknowledged_at')->count()]);
    }

    public function read(Request $request, Announcement $announcement): JsonResponse
    {
        $this->assertAddressed($announcement, $request->user());
        AnnouncementRead::firstOrCreate(['announcement_id' => $announcement->id, 'user_id' => $request->user()->id], ['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function acknowledge(Request $request, Announcement $announcement): JsonResponse
    {
        $this->assertAddressed($announcement, $request->user());
        abort_unless($announcement->requires_ack, 422, 'This announcement doesn’t need acknowledging.');
        $read = AnnouncementRead::firstOrCreate(['announcement_id' => $announcement->id, 'user_id' => $request->user()->id], ['read_at' => now()]);
        $read->update(['acknowledged_at' => $read->acknowledged_at ?? now()]);

        return response()->json(['ok' => true, 'message' => 'Acknowledged.']);
    }

    // ── Managing ─────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $items = Announcement::with('author:id,name', 'branch:id,name')->withCount([
            'reads', 'reads as acknowledged_count' => fn ($q) => $q->whereNotNull('acknowledged_at'),
        ])->latest('id')->get();

        return response()->json(['data' => $items->map(fn (Announcement $a) => $this->present($a) + [
            'branch' => $a->branch?->name,
            'audience' => $this->audience($a)->count(),
            'reads_count' => $a->reads_count,
            'acknowledged_count' => $a->acknowledged_count,
            'state' => ! $a->published_at ? 'draft' : ($a->published_at->isFuture() ? 'scheduled' : ($a->expires_at?->isPast() ? 'expired' : 'live')),
        ]), 'categories' => Announcement::CATEGORIES]);
    }

    public function store(Request $request, HtmlPurifierService $purifier): JsonResponse
    {
        $v = $this->validated($request);
        $a = Announcement::create($this->clean($v, $purifier) + ['created_by' => $request->user()->id]);
        $this->notifyIfLive($a);

        return response()->json(['data' => $this->present($a), 'message' => $a->published_at ? 'Announcement published.' : 'Draft saved.'], 201);
    }

    public function update(Request $request, Announcement $announcement, HtmlPurifierService $purifier): JsonResponse
    {
        $wasLive = $announcement->published_at && $announcement->published_at->isPast();
        $announcement->update($this->clean($this->validated($request), $purifier));
        if (! $wasLive) {
            $this->notifyIfLive($announcement->fresh());
        }

        return response()->json(['data' => $this->present($announcement->fresh()), 'message' => 'Announcement saved.']);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    /** Who has read and acknowledged it, and who hasn't yet. */
    public function readers(Announcement $announcement): JsonResponse
    {
        $reads = $announcement->reads()->get()->keyBy('user_id');
        $rows = $this->audience($announcement)->map(fn (User $u) => [
            'user_id' => $u->id,
            'name' => $u->employee?->full_name ?? $u->name,
            'employee_code' => $u->employee?->employee_code,
            'read_at' => $reads[$u->id]->read_at ?? null,
            'acknowledged_at' => $reads[$u->id]->acknowledged_at ?? null,
        ])->sortBy([['read_at', 'asc'], ['name', 'asc']])->values();

        return response()->json(['data' => $rows]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        $v = $request->validate([
            'title' => 'required|string|max:191',
            'body' => 'required|string|max:20000',
            'category' => ['required', Rule::in(array_keys(Announcement::CATEGORIES))],
            'branch_id' => 'nullable|integer|exists:branches,id',
            'pinned' => 'sometimes|boolean',
            'requires_ack' => 'sometimes|boolean',
            'publish' => 'sometimes|boolean',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:now',
        ]);
        if (! empty($v['branch_id'])) {
            $this->authorizeBranch($v['branch_id']);
        }

        return $v;
    }

    private function clean(array $v, HtmlPurifierService $purifier): array
    {
        return [
            'title' => $v['title'],
            'body' => $purifier->purify($v['body']),
            'category' => $v['category'],
            'branch_id' => $v['branch_id'] ?? null,
            'pinned' => (bool) ($v['pinned'] ?? false),
            'requires_ack' => (bool) ($v['requires_ack'] ?? false),
            'published_at' => ($v['publish'] ?? false) ? ($v['published_at'] ?? now()) : null,
            'expires_at' => $v['expires_at'] ?? null,
        ];
    }

    /** Signed-in users this announcement is addressed to. */
    private function audience(Announcement $a): Collection
    {
        return User::with('employee:id,first_name,last_name,employee_code,branch_id')
            ->where('company_id', app(TenantContext::class)->id())->where('is_active', true)->whereNotNull('employee_id')
            ->get()
            ->filter(fn (User $u) => ! $a->branch_id || ($u->employee?->branch_id ?? $u->branch_id) === $a->branch_id)
            ->values();
    }

    private function assertAddressed(Announcement $a, User $user): void
    {
        $branchId = $user->employee?->branch_id ?? $user->branch_id;
        abort_unless(Announcement::whereKey($a->id)->liveFor($branchId)->exists(), 404);
    }

    private function notifyIfLive(Announcement $a): void
    {
        if (! $a->published_at || $a->published_at->isFuture()) {
            return;
        }
        $message = new NotificationMessage('announcement.published', ($a->category === 'urgent' ? 'Urgent: ' : '') . $a->title,
            $a->requires_ack ? 'Please read and acknowledge.' : 'New announcement', '/announcements');
        foreach ($this->audience($a) as $user) {
            if ($user->id !== request()->user()?->id) {
                $user->notify(new InAppNotification($message));
            }
        }
    }

    private function present(Announcement $a): array
    {
        return $a->only(['id', 'title', 'body', 'category', 'pinned', 'requires_ack', 'branch_id']) + [
            'published_at' => $a->published_at?->toIso8601String(),
            'expires_at' => $a->expires_at?->toIso8601String(),
            'author' => $a->author?->name,
        ];
    }
}
