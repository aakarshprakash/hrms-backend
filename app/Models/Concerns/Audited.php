<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit trail for HR / payroll records: who changed what, when, from which
 * value to which. Only changed fields are recorded. Secrets never reach the
 * log: sensitive (encrypted) fields are listed as "changed" without values,
 * and credentials are excluded outright.
 *
 * Models set `protected string $auditLog = 'payroll'` to group entries.
 */
trait Audited
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(array_merge(
                ['created_at', 'updated_at', 'password', 'remember_token'],
                $this->auditSensitiveFields()
            ))
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(property_exists($this, 'auditLog') ? $this->auditLog : 'default');
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        if ($changed = $this->sensitiveFieldsChanged()) {
            $activity->properties = $activity->properties->put('sensitive_changed', $changed);
        }
    }

    /**
     * A change to only a sensitive field (say, the bank account) is exactly
     * what must be audited, even though its value is kept out of the log.
     */
    public function isLogEmpty(array $changes): bool
    {
        return empty($changes['attributes'] ?? []) && empty($changes['old'] ?? []) && ! $this->sensitiveFieldsChanged();
    }

    /** @return list<string> */
    private function sensitiveFieldsChanged(): array
    {
        return array_values(array_filter(
            $this->auditSensitiveFields(),
            fn (string $field) => $this->wasRecentlyCreated
                ? ($this->getAttributes()[$field] ?? null) !== null
                : $this->wasChanged($field)
        ));
    }

    /** @return list<string> */
    protected function auditSensitiveFields(): array
    {
        return defined(static::class . '::SENSITIVE') ? static::SENSITIVE : [];
    }
}
