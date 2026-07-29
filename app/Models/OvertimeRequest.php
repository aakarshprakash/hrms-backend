<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OvertimeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'date',
        'hours',
        'reason',
        'status',
        'approved_by',
        'comments',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hours' => 'decimal:2',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function onApproved(): void
    {
        $action = ApprovalAction::where('requestable_type', self::class)
            ->where('requestable_id', $this->id)
            ->where('status', 'approved')
            ->latest()
            ->first();

        if ($action) {
            $this->approved_by = $action->approver_id;
            $this->save();
        }
    }

    public function onRejected(): void
    {
        // Additional logic on rejection can be added here
    }
}
