<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One instalment recovered: by a payroll run, or paid back outside payroll. */
class LoanRecovery extends Model
{
    use BelongsToCompany;

    protected array $tenantParents = ['employee_loan_id' => 'employee_loans', 'payroll_run_id' => 'payroll_runs'];

    protected $fillable = ['employee_loan_id', 'payroll_run_id', 'amount', 'recovered_on', 'method', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recovered_on' => 'date:Y-m-d',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(EmployeeLoan::class, 'employee_loan_id');
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
