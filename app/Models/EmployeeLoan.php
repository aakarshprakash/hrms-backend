<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\VisibleThroughEmployee;
use Illuminate\Database\Eloquent\Model;

/**
 * A loan or salary advance, recovered in monthly instalments by payroll
 * from start_month/start_year. Recovered so far = its loan_recoveries;
 * it is settled once that reaches total_payable (or it is cancelled).
 */
class EmployeeLoan extends Model
{
    use Audited, BelongsToCompany, VisibleThroughEmployee;

    protected string $auditLog = 'payroll';

    protected $fillable = [
        'employee_id', 'type', 'principal', 'interest_rate', 'total_payable', 'tenure_months', 'emi',
        'start_month', 'start_year', 'disbursed_on', 'status', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'emi' => 'decimal:2',
            'disbursed_on' => 'date:Y-m-d',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function recoveries()
    {
        return $this->hasMany(LoanRecovery::class);
    }

    /** Whether payroll month (year, month) is on or after the first recovery month. */
    public function startsBy(int $year, int $month): bool
    {
        return $this->start_year * 100 + $this->start_month <= $year * 100 + $month;
    }
}
