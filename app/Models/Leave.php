<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Leave extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'source_attendance_id',
        'start_date',
        'end_date',
        'days',
        'reason',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'decimal:2',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function sourceAttendance()
    {
        return $this->belongsTo(Attendance::class, 'source_attendance_id');
    }

    public function approvalActions()
    {
        return $this->morphMany(ApprovalAction::class, 'requestable');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function onApproved(): void
    {
        $balance = LeaveBalance::where('employee_id', $this->employee_id)
            ->where('leave_type_id', $this->leave_type_id)
            ->where('year', $this->start_date->year)
            ->first();

        if ($balance) {
            $balance->increment('used', $this->days);
            $balance->decrement('balance', $this->days);
        }

        if ($this->source_attendance_id) {
            $this->sourceAttendance?->update(['status' => 'on_leave']);
        }
    }

    public function onRejected(): void
    {
        // no-op
    }
}
