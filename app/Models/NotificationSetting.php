<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Notifications\NotificationEvents;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's notification setup: which channels are on, provider
 * credentials (encrypted at rest, never returned by the API), and per event
 * which channels to use and which provider templates to send.
 *
 * A tenant without a row gets in-app notifications only.
 */
class NotificationSetting extends Model
{
    use Audited, BelongsToCompany;

    protected string $auditLog = 'settings';

    public const SMS_PROVIDERS = ['msg91' => 'MSG91 (DLT templates)', 'twilio' => 'Twilio', 'log' => 'Test mode (log only)'];

    public const WHATSAPP_PROVIDERS = ['meta' => 'WhatsApp Cloud API (Meta)', 'twilio' => 'Twilio WhatsApp', 'log' => 'Test mode (log only)'];

    /** Audited as "changed" only -- credential values never reach the audit log. */
    public const SENSITIVE = ['sms_credentials', 'whatsapp_credentials'];

    protected $fillable = [
        'email_enabled',
        'sms_enabled',
        'sms_provider',
        'sms_credentials',
        'whatsapp_enabled',
        'whatsapp_provider',
        'whatsapp_credentials',
        'events',
    ];

    protected $hidden = ['sms_credentials', 'whatsapp_credentials'];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'sms_credentials' => 'encrypted:array',
            'whatsapp_credentials' => 'encrypted:array',
            'events' => 'array',
        ];
    }

    /** The tenant's settings, or unsaved defaults (in-app only). */
    public static function for(int $companyId): self
    {
        return static::query()->where('company_id', $companyId)->first()
            ?? new static(['email_enabled' => false, 'sms_enabled' => false, 'whatsapp_enabled' => false, 'events' => []]);
    }

    public function channelEnabled(string $channel): bool
    {
        return match ($channel) {
            'in_app' => true,
            'email' => (bool) $this->email_enabled,
            'sms' => (bool) $this->sms_enabled && $this->sms_provider,
            'whatsapp' => (bool) $this->whatsapp_enabled && $this->whatsapp_provider,
            default => false,
        };
    }

    /** Channels chosen for an event (in-app on unless switched off). */
    public function channelsFor(string $event): array
    {
        $chosen = $this->events[$event]['channels'] ?? ['in_app'];

        return array_values(array_intersect(NotificationEvents::CHANNELS, (array) $chosen));
    }

    public function templateFor(string $event, string $channel): ?string
    {
        $value = $this->events[$event]["{$channel}_template"] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function languageFor(string $event): string
    {
        return (string) ($this->events[$event]['whatsapp_language'] ?? 'en');
    }

    public function credentialsFor(string $channel): array
    {
        return match ($channel) {
            'sms' => (array) ($this->sms_credentials ?? []),
            'whatsapp' => (array) ($this->whatsapp_credentials ?? []),
            default => [],
        };
    }

    public function providerFor(string $channel): ?string
    {
        return match ($channel) {
            'email' => 'mail',
            'sms' => $this->sms_provider,
            'whatsapp' => $this->whatsapp_provider,
            default => null,
        };
    }
}
