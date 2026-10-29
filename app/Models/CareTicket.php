<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A helpdesk ticket: an employee's request or grievance to HR. An anonymous
 * grievance still records who raised it (so they can follow it up), but the
 * people handling it never see that.
 */
class CareTicket extends Model
{
    use Audited, BelongsToCompany;

    protected string $auditLog = 'helpdesk';

    public const CATEGORIES = [
        'payroll' => 'Salary & payslips',
        'attendance' => 'Attendance & shifts',
        'leave' => 'Leave',
        'documents' => 'Letters & documents',
        'benefits' => 'Benefits & reimbursements',
        'it' => 'IT & access',
        'facilities' => 'Workplace & facilities',
        'conduct' => 'Conduct & harassment',
        'other' => 'Something else',
    ];

    /** Hours to first response and to resolution, by priority. */
    public const SLA = [
        'urgent' => [2, 24],
        'high' => [8, 72],
        'normal' => [24, 120],
        'low' => [48, 240],
    ];

    public const OPEN = ['open', 'in_progress', 'waiting'];

    protected $fillable = [
        'branch_id', 'employee_id', 'number', 'type', 'category', 'subject', 'description', 'priority', 'status', 'anonymous',
        'assigned_to', 'response_due_at', 'resolution_due_at', 'first_response_at', 'resolved_at', 'closed_at', 'rating', 'rating_comment',
    ];

    protected function casts(): array
    {
        return [
            'anonymous' => 'boolean',
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(CareTicketMessage::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Past its response or resolution target. */
    public function isOverdue(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        return (! $this->first_response_at && $this->response_due_at?->isPast())
            || (bool) $this->resolution_due_at?->isPast();
    }
}
