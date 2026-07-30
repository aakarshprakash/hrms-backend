<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'name', 'address', 'city', 'country', 'timezone', 'currency_code',
        'payroll_days_in_month', 'week_off_days',
    ];

    protected function casts(): array
    {
        return [
            'week_off_days' => 'array',
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
