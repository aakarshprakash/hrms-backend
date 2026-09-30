<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Audit-log entry, owned by the tenant whose data changed. The tenant comes
 * from the audited record itself (or, failing that, the acting context), so
 * entries written by console jobs are attributed correctly too.
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Activity $activity) {
            if ($activity->company_id !== null) {
                return;
            }

            $subject = $activity->subject;
            $companyId = $subject?->getAttribute('company_id');

            if ($companyId === null && $subject instanceof Company) {
                $companyId = $subject->getKey();
            }

            $activity->company_id = $companyId ?? app(TenantContext::class)->id();
        });
    }
}
