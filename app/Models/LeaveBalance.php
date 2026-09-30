<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\VisibleThroughEmployee;
use App\Services\Leave\LeaveYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * An employee's balance of one leave type for one leave year: the running
 * total of its leave_transactions (see App\Services\Leave\LeaveLedger).
 *
 *   allocated = opening + accrued + adjusted
 *   balance   = allocated - used - carried_forward - lapsed
 */
class LeaveBalance extends Model
{
    use BelongsToCompany, VisibleThroughEmployee, HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'opening',
        'accrued',
        'adjusted',
        'allocated',
        'used',
        'carried_forward',
        'lapsed',
        'balance',
        'accrued_through',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening' => 'decimal:2',
            'accrued' => 'decimal:2',
            'adjusted' => 'decimal:2',
            'allocated' => 'decimal:2',
            'used' => 'decimal:2',
            'carried_forward' => 'decimal:2',
            'lapsed' => 'decimal:2',
            'balance' => 'decimal:2',
            'accrued_through' => 'date:Y-m-d',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A row written the pre-ledger way (allocated / balance given, no
        // breakdown) is taken as an opening balance covering its whole year
        // -- exactly how existing balances were migrated -- and recorded in
        // the ledger so the ledger still adds up to the balance.
        static::creating(function (LeaveBalance $b) {
            $parts = (float) $b->opening + (float) $b->accrued + (float) $b->adjusted;
            if ($parts != 0.0 || (float) $b->allocated == 0.0) {
                return;
            }

            $b->opening = $b->allocated;
            $b->balance ??= $b->allocated;
            $b->used = round((float) $b->allocated - (float) $b->balance, 2);
            $b->accrued_through ??= app(LeaveYear::class)->bounds((int) $b->company_id, (int) $b->year)[1]->toDateString();
            $b->legacyOpening = true;
        });

        static::created(function (LeaveBalance $b) {
            if (! $b->legacyOpening) {
                return;
            }

            $base = ['employee_id' => $b->employee_id, 'leave_type_id' => $b->leave_type_id, 'leave_balance_id' => $b->id,
                'year' => $b->year, 'created_by' => null];
            LeaveTransaction::create($base + ['type' => 'opening', 'days' => $b->opening, 'period' => null, 'note' => 'Opening balance']);
            if ((float) $b->used != 0.0) {
                LeaveTransaction::create($base + ['type' => 'availed', 'days' => -1 * (float) $b->used, 'note' => 'Recorded with the opening balance']);
            }
        });
    }

    /** Set while creating a pre-ledger style row (see booted()). */
    public bool $legacyOpening = false;

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function transactions()
    {
        return $this->hasMany(LeaveTransaction::class);
    }
}
