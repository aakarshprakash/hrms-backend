<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\Employee;
use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\Billing\Features;
use App\Support\Notifications\NotificationEvents;
use App\Support\Notifications\NotificationMessage;
use App\Support\Phone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * Sends a notification to people, on every channel the tenant has chosen
 * for that event (and the person hasn't opted out of):
 *
 *  - in-app: written straight away;
 *  - email / SMS / WhatsApp: queued in the notification outbox and sent
 *    after the response (the scheduler retries anything left over).
 *
 * Nothing is sent for work that is rolled back: everything waits for the
 * surrounding transaction to commit. Failures here never break the action
 * that triggered them.
 */
class Notifier
{
    /** @var array<int, NotificationSetting> */
    private array $settings = [];

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    /** @param  iterable<User>|User|null  $users */
    public function send(iterable|User|null $users, ?NotificationMessage $message): void
    {
        $users = collect($users instanceof User ? [$users] : ($users ?? []))->filter()->unique('id')->values();

        if ($users->isEmpty() || ! $message || ! NotificationEvents::exists($message->event)) {
            return;
        }

        DB::afterCommit(function () use ($users, $message) {
            $queued = [];
            foreach ($users as $user) {
                try {
                    array_push($queued, ...$this->deliverTo($user, $message));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            if ($queued) {
                defer(fn () => $this->dispatcher->deliverIds($queued));
            }
        });
    }

    /** @return list<int> outbox ids queued for external channels */
    private function deliverTo(User $user, NotificationMessage $message): array
    {
        if (! $user->company_id || $user->is_active === false) {
            return [];
        }

        $settings = $this->settings[$user->company_id] ??= NotificationSetting::for($user->company_id);
        $channels = $settings->channelsFor($message->event);
        $preferences = (array) ($user->notification_preferences ?? []);

        if (in_array('in_app', $channels, true)) {
            $user->notify(new InAppNotification($message));
        }

        $ids = [];
        foreach (NotificationEvents::EXTERNAL as $channel) {
            if (! in_array($channel, $channels, true) || ! $settings->channelEnabled($channel)
                || ($preferences[$channel] ?? true) === false) {
                continue;
            }
            if ($channel !== 'email' && ! $this->planAllowsMessaging($user->company_id)) {
                continue;
            }

            $recipient = $channel === 'email' ? $user->email : Phone::e164($this->phoneOf($user));
            if (! $recipient) {
                continue;
            }

            $template = $channel === 'email' ? null : $settings->templateFor($message->event, $channel);
            $needsTemplate = in_array($settings->providerFor($channel), ['msg91', 'meta'], true);

            $log = NotificationLog::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'event' => $message->event,
                'channel' => $channel,
                'recipient' => $recipient,
                'subject' => $channel === 'email' ? $message->subject() : null,
                'body' => $message->text(),
                'template' => $template,
                'variables' => [
                    'params' => $message->params, 'title' => $message->title, 'lines' => $message->lines,
                    'link' => $message->link, 'action' => $message->actionLabel, 'company' => $message->companyName,
                ],
                // Template-only providers can't send free text: record why nothing went out.
                'status' => $needsTemplate && ! $template ? 'skipped' : 'queued',
                'error' => $needsTemplate && ! $template ? "No {$channel} template is set for this event." : null,
                'next_attempt_at' => now(),
            ]);

            if ($log->status === 'queued') {
                $ids[] = $log->id;
            }
        }

        return $ids;
    }

    private function phoneOf(User $user): ?string
    {
        return $user->phone ?: ($user->employee_id
            ? Employee::withoutGlobalScope(BranchScope::class)->whereKey($user->employee_id)->value('phone')
            : null);
    }

    private function planAllowsMessaging(int $companyId): bool
    {
        $company = app(TenantContext::class)->withoutScoping(fn () => Company::find($companyId));

        return $company !== null && Features::has($company, 'notifications');
    }
}
