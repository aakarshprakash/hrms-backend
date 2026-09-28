<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\VisibleThroughEmployee;
use Illuminate\Database\Eloquent\Model;

/**
 * One credit or debit to a leave balance. Written only through
 * App\Services\Leave\LeaveLedger, never edited.
 */
class LeaveTransaction extends Model
{
    use BelongsToCompany, VisibleThroughEmployee;

    public const TYPES = [
        'opening' => 'Opening balance',
        'accrual' => 'Accrued',
        'carry_forward' => 'Carried forward',
        'adjustment' => 'Adjustment',
        'availed' => 'Leave taken',
        'reversal' => 'Leave cancelled',
        'carry_forward_out' => 'Carried to next year',
        'lapse' => 'Lapsed',
    ];

    protected array $tenantParents = ['employee_id' => 'employees', 'leave_balance_id' => 'leave_balances'];

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'leave_balance_id',
        'year',
        'type',
        'days',
        'period',
        'leave_id',
        'note',
        'created_by',
    ];

    protected $appends = ['label'];

    protected function casts(): array
    {
        return [
            'days' => 'decimal:2',
            'period' => 'date:Y-m-d',
        ];
    }

    public function getLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function balance()
    {
        return $this->belongsTo(LeaveBalance::class, 'leave_balance_id');
    }

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
