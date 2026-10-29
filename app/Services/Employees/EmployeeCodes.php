<?php

namespace App\Services\Employees;

use App\Models\Company;
use App\Models\Employee;
use App\Support\Tenancy\TenantContext;

/**
 * Automatic employee codes: a prefix plus a zero-padded running number
 * (EMP0001, EMP0002...). The next number is never below the highest one
 * already used with that prefix, so codes typed by hand or imported earlier
 * don't collide. Settings live in `companies.settings.employee_code`.
 */
class EmployeeCodes
{
    public const DEFAULTS = ['auto' => false, 'prefix' => 'EMP', 'digits' => 4, 'start' => 1];

    public function settings(?Company $company): array
    {
        $s = array_merge(self::DEFAULTS, (array) data_get($company?->settings ?? [], 'employee_code', []));

        return [
            'auto' => (bool) $s['auto'],
            'prefix' => (string) $s['prefix'],
            'digits' => max(1, min(8, (int) $s['digits'])),
            'start' => max(1, (int) $s['start']),
        ];
    }

    /** The code the next employee would get (a preview; nothing is reserved). */
    public function next(?Company $company = null): string
    {
        $s = $this->settings($company ?? app(TenantContext::class)->company());
        $prefix = $s['prefix'];

        $highest = Employee::query()
            ->where('employee_code', 'like', addcslashes($prefix, '%_\\') . '%')
            ->pluck('employee_code')
            ->map(fn ($code) => substr((string) $code, strlen($prefix)))
            ->filter(fn ($rest) => $rest !== '' && ctype_digit($rest))
            ->map(fn ($rest) => (int) $rest)
            ->max() ?? 0;

        $number = max($s['start'], $highest + 1);

        return $prefix . str_pad((string) $number, $s['digits'], '0', STR_PAD_LEFT);
    }

    /**
     * Takes the next code for a new employee. Call inside the transaction
     * that creates the employee: the company row lock serialises
     * concurrent hires so two people never draw the same number.
     */
    public function claim(): string
    {
        $company = Company::whereKey(app(TenantContext::class)->id())->lockForUpdate()->first();

        return $this->next($company);
    }
}
