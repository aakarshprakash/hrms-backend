<?php

namespace App\Support\Mobile;

use App\Models\Company;
use App\Models\PlatformSetting;
use App\Support\Billing\Features;
use Illuminate\Http\Request;

/**
 * Who may use the PeopleNex mobile app. Three switches, all set from the
 * platform console:
 *
 *  - the platform-wide switch (off = the app is closed for everyone, e.g.
 *    while a release is pulled);
 *  - the `mobile_app` plan feature (which plans include the app);
 *  - a per-organisation override: `plan` (follow the plan), `on` or `off`.
 *
 * The app identifies itself with `X-Client: mobile` and `X-App-Version`;
 * the browser never sends them, so the web app is unaffected.
 */
final class MobileAccess
{
    public const KEY = 'mobile_app';

    public const OVERRIDES = ['plan' => 'Follow the plan', 'on' => 'Always on', 'off' => 'Always off'];

    private const DEFAULTS = [
        'enabled' => true,
        'min_version' => null,
        'latest_version' => null,
        'android_url' => null,
        'ios_url' => null,
        'message' => null,
    ];

    public static function settings(): array
    {
        return array_merge(self::DEFAULTS, (array) PlatformSetting::get(self::KEY, []));
    }

    public static function save(array $values): array
    {
        $settings = array_merge(self::settings(), array_intersect_key($values, self::DEFAULTS));
        PlatformSetting::put(self::KEY, $settings);

        return $settings;
    }

    public static function isMobile(Request $request): bool
    {
        return str_starts_with(strtolower((string) $request->header('X-Client')), 'mobile');
    }

    public static function override(Company $company): string
    {
        $value = $company->setting('mobile_app', 'plan');

        return array_key_exists($value, self::OVERRIDES) ? $value : 'plan';
    }

    /** Whether the organisation's people may sign in on the app. */
    public static function allowsCompany(Company $company): bool
    {
        if (! self::settings()['enabled']) {
            return false;
        }

        return match (self::override($company)) {
            'on' => true,
            'off' => false,
            default => Features::has($company, 'mobile_app'),
        };
    }

    /**
     * Null when the request may proceed; otherwise [code, message] for the
     * app to show. The platform admin (no company) is never refused.
     */
    public static function refusal(Request $request, ?Company $company): ?array
    {
        $settings = self::settings();

        if (! $settings['enabled']) {
            return ['MOBILE_DISABLED', $settings['message'] ?: 'The mobile app is not available right now. Please use the web app.'];
        }

        if ($settings['min_version'] && version_compare((string) $request->header('X-App-Version', '0'), $settings['min_version'], '<')) {
            return ['APP_UPDATE_REQUIRED', 'A newer version of the app is required. Please update to continue.'];
        }

        if ($company && ! self::allowsCompany($company)) {
            return ['MOBILE_NOT_IN_PLAN', 'Your organisation’s plan does not include the mobile app. Ask your HR team, or use the web app.'];
        }

        return null;
    }

    /** What the app reads before sign-in (no secrets). */
    public static function publicConfig(): array
    {
        $s = self::settings();

        return [
            'enabled' => (bool) $s['enabled'],
            'min_version' => $s['min_version'],
            'latest_version' => $s['latest_version'],
            'android_url' => $s['android_url'],
            'ios_url' => $s['ios_url'],
            'message' => $s['enabled'] ? null : $s['message'],
        ];
    }
}
