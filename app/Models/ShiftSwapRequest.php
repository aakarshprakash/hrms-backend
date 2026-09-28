<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * "I cover your day, you cover mine": the colleague accepts, an approver
 * decides, and approval swaps the rosters (ShiftSwapService).
 *
 * status: pending → approved | rejected | cancelled
 * target_response: null (waiting) | accepted | declined
 */
class ShiftSwapRequest extends Model
{
    use BelongsToCompany, HasFactory;

    protected array $tenantParents = ['requester_id' => 'employees'];

    protected $fillable = [
        'requester_id',
        'target_employee_id',
        'roster_id',
        'my_date',
        'their_date',
        'status',
        'reason',
        'target_response',
        'responded_at',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'my_date' => 'date:Y-m-d',
            'their_date' => 'date:Y-m-d',
            'responded_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function requester()
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function targetEmployee()
    {
        return $this->belongsTo(Employee::class, 'target_employee_id');
    }

    public function roster()
    {
        return $this->belongsTo(ShiftRoster::class, 'roster_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
