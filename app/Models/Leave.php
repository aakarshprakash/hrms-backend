<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\VisibleThroughEmployee;
use App\Services\Leave\LeaveRequestService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Leave extends Model
{
    use Audited, BelongsToCompany, VisibleThroughEmployee, HasFactory;

    public const SESSIONS = ['first_half', 'second_half'];

    protected string $auditLog = 'leave';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'source_attendance_id',
        'start_date',
        'end_date',
        'days',
        'half_day_session',
        'leave_year',
        'reason',
        'attachment_path',
        'status',
        'applied_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $hidden = ['attachment_path'];

    protected $appends = ['has_attachment'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'days' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getHasAttachmentAttribute(): bool
    {
        return ! empty($this->attributes['attachment_path'] ?? null);
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

    /** HR / approver who recorded the leave on the employee's behalf. */
    public function recorder()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
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

    /** Called by the approval workflow once the last step approves. */
    public function onApproved(): void
    {
        app(LeaveRequestService::class)->applyApproval($this);
    }

    public function onRejected(): void
    {
        // Nothing was deducted while pending.
    }
}
