<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\VisibleThroughEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AttendanceRegularization extends Model
{
    use BelongsToCompany, VisibleThroughEmployee, HasFactory;

    protected $fillable = [
        'attendance_id',
        'employee_id',
        'date',
        'requested_check_in',
        'requested_check_out',
        'reason',
        'status',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
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

    /**
     * Approved: the requested times become punches (source "regularization")
     * and the day is reprocessed, so status, hours and lateness all follow
     * from the corrected times -- instead of patching two columns and leaving
     * an "absent" status behind.
     */
    public function onApproved(): void
    {
        app(\App\Services\Attendance\RegularizationApplier::class)->apply($this);
    }
}
