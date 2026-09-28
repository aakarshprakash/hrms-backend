<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveTransaction;
use App\Models\LeaveType;
use Illuminate\Support\Facades\DB;

/**
 * The only way a leave balance changes: every movement is a transaction,
 * posted under a row lock on the balance so concurrent approvals and
 * accrual runs can't lose updates, and the balance's columns are always
 * the sum of its transactions.
 */
class LeaveLedger
{
    /** Transactions that credit/debit which balance column (sign as posted). */
    private const COLUMN = [
        'opening' => 'opening',
        'carry_forward' => 'opening',
        'accrual' => 'accrued',
        'adjustment' => 'adjusted',
        'availed' => 'used',
        'reversal' => 'used',
        'carry_forward_out' => 'carried_forward',
        'lapse' => 'lapsed',
    ];

    /** Columns that grow when the posted amount is negative. */
    private const DEBIT_COLUMNS = ['used', 'carried_forward', 'lapsed'];

    public function balanceFor(Employee|int $employee, LeaveType|int $type, int $year): LeaveBalance
    {
        $employeeId = $employee instanceof Employee ? $employee->id : $employee;
        $typeId = $type instanceof LeaveType ? $type->id : $type;

        $balance = LeaveBalance::query()
            ->where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('year', $year)->first();

        if ($balance) {
            return $balance;
        }

        try {
            return LeaveBalance::create([
                'employee_id' => $employeeId, 'leave_type_id' => $typeId, 'year' => $year,
                'opening' => 0, 'accrued' => 0, 'adjusted' => 0, 'allocated' => 0,
                'used' => 0, 'carried_forward' => 0, 'lapsed' => 0, 'balance' => 0,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Created concurrently by another request / worker.
            return LeaveBalance::query()
                ->where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('year', $year)->firstOrFail();
        }
    }

    /**
     * Post a movement. `$days` is signed: credits positive, debits negative.
     * With a `period`, the posting is idempotent: a second post of the same
     * type for the same balance and period is ignored and returns null.
     *
     * @param  array{period?: ?string, leave_id?: ?int, note?: ?string, created_by?: ?int}  $meta
     */
    public function post(LeaveBalance $balance, string $type, float $days, array $meta = []): ?LeaveTransaction
    {
        if (! isset(self::COLUMN[$type])) {
            throw new \InvalidArgumentException("Unknown leave transaction type [{$type}].");
        }

        $days = round($days, 2);

        return DB::transaction(function () use ($balance, $type, $days, $meta) {
            $locked = LeaveBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();

            $period = $meta['period'] ?? null;
            if ($period !== null && LeaveTransaction::query()
                ->where('leave_balance_id', $locked->id)->where('type', $type)->whereDate('period', $period)->exists()) {
                $balance->setRawAttributes($locked->getAttributes(), true);

                return null;
            }

            $txn = LeaveTransaction::create([
                'employee_id' => $locked->employee_id,
                'leave_type_id' => $locked->leave_type_id,
                'leave_balance_id' => $locked->id,
                'year' => $locked->year,
                'type' => $type,
                'days' => $days,
                'period' => $period,
                'leave_id' => $meta['leave_id'] ?? null,
                'note' => isset($meta['note']) ? mb_substr((string) $meta['note'], 0, 255) : null,
                // Explicit null = posted by the system (accrual, year end).
                'created_by' => array_key_exists('created_by', $meta) ? $meta['created_by'] : auth()->id(),
            ]);

            $column = self::COLUMN[$type];
            $delta = in_array($column, self::DEBIT_COLUMNS, true) ? -$days : $days;
            $locked->{$column} = round((float) $locked->{$column} + $delta, 2);
            $this->recompute($locked);
            $locked->save();

            $balance->setRawAttributes($locked->getAttributes(), true);

            return $txn;
        });
    }

    public function recompute(LeaveBalance $b): void
    {
        $b->allocated = round((float) $b->opening + (float) $b->accrued + (float) $b->adjusted, 2);
        $b->balance = round((float) $b->allocated - (float) $b->used - (float) $b->carried_forward - (float) $b->lapsed, 2);
    }
}
