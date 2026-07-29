<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AttendanceRegularization extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_id',
        'employee_id',
        'requested_check_in',
        'requested_check_out',
        'reason',
        'status',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'requested_check_in' => 'datetime',
            'requested_check_out' => 'datetime',
        ];
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function requestable()
    {
        return $this->morphTo();
    }

    public function approvalActions()
    {
        return $this->morphMany(ApprovalAction::class, 'requestable');
    }

    public function onApproved(): void
    {
        if ($this->attendance) {
            $this->attendance->update([
                'check_in' => $this->requested_check_in ?? $this->attendance->check_in,
                'check_out' => $this->requested_check_out ?? $this->attendance->check_out,
            ]);
        }
    }
}
