<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\NotificationSetting;
use App\Support\Notifications\NotificationEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in person's own notifications (the bell) and channel preferences. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->notifications()->latest();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 50));

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id,
                'event' => $n->data['event'] ?? $n->type,
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? '',
                'link' => $n->data['link'] ?? null,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'unread' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'All caught up.']);
    }

    /** Which external channels the organisation uses, and whether I've opted out of each. */
    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->company_id ? NotificationSetting::for($user->company_id) : null;
        $prefs = (array) ($user->notification_preferences ?? []);

        $channels = collect(NotificationEvents::EXTERNAL)->map(fn ($channel) => [
            'channel' => $channel,
            'available' => (bool) $settings?->channelEnabled($channel),
            'enabled' => ($prefs[$channel] ?? true) !== false,
        ]);

        // Push to the mobile app: offered once the person has signed in on a phone.
        $channels->push([
            'channel' => 'push',
            'available' => DeviceToken::where('user_id', $user->id)->exists(),
            'enabled' => ($prefs['push'] ?? true) !== false,
        ]);

        return response()->json(['data' => $channels->values(), 'phone' => $user->phone, 'profile_phone' => $user->employee_id
            ? \App\Models\Employee::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->whereKey($user->employee_id)->value('phone')
            : null]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'sometimes|boolean',
            'sms' => 'sometimes|boolean',
            'whatsapp' => 'sometimes|boolean',
            'push' => 'sometimes|boolean',
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[+0-9 ()-]{8,20}$/'],
        ]);

        $user = $request->user();
        $prefs = array_merge((array) ($user->notification_preferences ?? []), array_intersect_key($validated, array_flip([...NotificationEvents::EXTERNAL, 'push'])));
        $user->notification_preferences = $prefs;
        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }
        $user->save();

        return response()->json(['message' => 'Notification preferences saved.']);
    }
}
