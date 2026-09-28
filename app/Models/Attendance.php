<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\VisibleThroughEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Attendance extends Model
{
    use Audited, BelongsToCompany, VisibleThroughEmployee, HasFactory;

    protected string $auditLog = 'attendance';

    /**
     * Device syncs rewrite thousands of rows a day; the audit trail only
     * needs human edits -- records entered or corrected manually.
     */
    protected function shouldLogEvent(string $eventName): bool
    {
        return $this->enableLoggingModelsEvents
            && ($this->source === 'manual' || $this->getOriginal('source') === 'manual');
    }

    /** Processed, one-row-per-employee-per-day attendance (derived from raw_punches). */
    protected $table = 'attendance_daily';

    public const STATUSES = ['present', 'absent', 'half_day', 'late', 'on_leave', 'weekly_off', 'holiday'];

    /** Sources a processing run must never overwrite. */
    public const PROTECTED_SOURCES = ['manual'];

    protected $fillable = [
        'employee_id',
        'shift_id',
        'date',
        'check_in',
        'check_out',
        'punch_count',
        'status',
        'late_by_minutes',
        'early_by_minutes',
        'worked_minutes',
        'overtime_minutes',
        'is_weekly_off',
        'is_holiday',
        'anomaly',
        'source',
        'remarks',
        'latitude',
        'longitude',
        'processed_at',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'is_weekly_off' => 'boolean',
            'is_holiday' => 'boolean',
            'processed_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function regularizations()
    {
        return $this->hasMany(AttendanceRegularization::class);
    }

    public function leaveConversion()
    {
        return $this->hasOne(Leave::class, 'source_attendance_id');
    }
}
