<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile app registers its push token after sign-in and forgets it on
 * sign-out. A token names one install, so it moves to whoever signed in on
 * that phone most recently -- even across organisations.
 */
class DeviceTokenController extends Controller
{
    private const TOKEN_RULE = ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[[A-Za-z0-9_\-]+\]$/'];

    public function __construct(private TenantContext $context)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => self::TOKEN_RULE,
            'platform' => ['required', 'in:android,ios'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();
        if (! $user->company_id) {
            return response()->json(['message' => 'Push notifications are for members of an organisation.'], 422);
        }

        // Drop the token wherever it was registered before (another user or organisation).
        $this->context->withoutScoping(fn () => DeviceToken::where('token', $validated['token'])
            ->where('user_id', '!=', $user->id)->delete());

        $device = DeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['data' => ['id' => $device->id], 'message' => 'Device registered for notifications.'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => self::TOKEN_RULE]);

        DeviceToken::where('token', $validated['token'])->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Device removed.']);
    }
}
