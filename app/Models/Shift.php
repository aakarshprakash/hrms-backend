<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Shift extends Model
{
    use Audited, BelongsToCompany, HasFactory, HasBranchScope;

    protected string $auditLog = 'settings';

    protected $fillable = [
        'branch_id',
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'grace_minutes',
        'half_day_threshold_minutes',
        'code',
        'early_exit_grace_minutes',
        'absent_threshold_minutes',
        'ot_threshold_minutes',
        'punch_window_before_minutes',
        'punch_window_after_minutes',
        'color',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Crosses midnight (e.g. 22:00 -> 06:00). */
    public function isOvernight(): bool
    {
        return substr((string) $this->end_time, 0, 5) <= substr((string) $this->start_time, 0, 5);
    }

    /** Scheduled working minutes (span minus break). */
    public function netMinutes(): int
    {
        [$sh, $sm] = array_map('intval', explode(':', (string) $this->start_time));
        [$eh, $em] = array_map('intval', explode(':', (string) $this->end_time));
        $span = ($eh * 60 + $em) - ($sh * 60 + $sm);
        if ($span <= 0) {
            $span += 1440;
        }

        return max(1, $span - (int) $this->break_minutes);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function employeeShifts()
    {
        return $this->hasMany(EmployeeShift::class);
    }

    public function rosters()
    {
        return $this->hasMany(ShiftRoster::class);
    }
}
