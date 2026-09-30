<?php

namespace App\Support\Tenancy;

use Illuminate\Validation\DatabasePresenceVerifier;

/**
 * Makes every `exists:` and `unique:` rule tenant-aware without having to
 * remember it at each call site: lookups against a tenant-owned table only
 * see the acting tenant's rows. Without this, `exists:branches,id` would
 * happily accept another tenant's branch id, and `unique:employees,employee_code`
 * would reject a code merely because some other tenant uses it.
 */
class TenantAwarePresenceVerifier extends DatabasePresenceVerifier
{
    protected function table($table)
    {
        $query = parent::table($table);
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return $query;
        }

        // Rules may be written as "connection.table".
        $bareTable = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        if (in_array($bareTable, config('tenancy.tables', []), true)) {
            if ($context->hasTenant()) {
                return $query->where("{$bareTable}.company_id", $context->id());
            }

            return $context->isEnforced() ? $query->whereRaw('1 = 0') : $query;
        }

        if (in_array($bareTable, config('tenancy.shared_tables', []), true)) {
            return $query->where(function ($q) use ($bareTable, $context) {
                $q->whereNull("{$bareTable}.company_id");

                if ($context->hasTenant()) {
                    $q->orWhere("{$bareTable}.company_id", $context->id());
                }
            });
        }

        return $query;
    }
}
