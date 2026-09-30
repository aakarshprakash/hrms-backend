<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use Audited, BelongsToCompany, HasFactory;

    protected string $auditLog = 'organisation';

    protected array $tenantParents = [];

    protected $fillable = [
        'company_id', 'name', 'address', 'city', 'state', 'country', 'timezone', 'currency_code',
        'payroll_days_in_month', 'week_off_days', 'default_shift_id', 'attendance_settings',
    ];

    protected function casts(): array
    {
        return [
            'week_off_days' => 'array',
            'attendance_settings' => 'array',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function defaultShift()
    {
        return $this->belongsTo(Shift::class, 'default_shift_id');
    }

    public function attendanceSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->attendance_settings ?? [], $key, $default);
    }

    public function biometricConfig()
    {
        return $this->hasOne(BiometricConfig::class);
    }

    /**
     * Whether $date is a working day for this branch, per its configured
     * week_off_days (Carbon dayOfWeek integers, 0=Sunday..6=Saturday).
     * Defaults to Sat+Sun if unset, matching this app's original hardcoded
     * assumption before per-branch work weeks existed.
     */
    public function isWorkingDay(\Carbon\Carbon $date): bool
    {
        $weekOff = $this->week_off_days ?? [0, 6];
        return !in_array($date->dayOfWeek, $weekOff, true);
    }
}
