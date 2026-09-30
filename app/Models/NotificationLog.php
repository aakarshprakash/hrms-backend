<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;

/**
 * One email / SMS / WhatsApp message: the outbox while queued, the delivery
 * record once sent (or failed, with the provider's error).
 */
class NotificationLog extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['queued', 'sending', 'sent', 'failed', 'skipped'];

    protected array $tenantParents = [];

    protected $fillable = [
        'company_id',
        'user_id',
        'event',
        'channel',
        'recipient',
        'subject',
        'body',
        'template',
        'variables',
        'status',
        'attempts',
        'provider',
        'provider_message_id',
        'error',
        'next_attempt_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** API shape: phone numbers masked, message bodies trimmed. */
    public function toListArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'channel' => $this->channel,
            'recipient' => $this->channel === 'email' ? $this->recipient : Phone::mask($this->recipient),
            'user' => $this->user?->only(['id', 'name']),
            'subject' => $this->subject,
            'body' => mb_strimwidth((string) $this->body, 0, 160, '…'),
            'template' => $this->template,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'provider' => $this->provider,
            'error' => $this->error,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
