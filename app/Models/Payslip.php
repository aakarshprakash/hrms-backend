<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\VisibleThroughEmployee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    use BelongsToCompany, VisibleThroughEmployee, HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'gross_pay',
        'total_deductions',
        'net_pay',
        'currency_code',
        'pdf_path',
        'breakdown_json',
        'days_in_period',
        'payable_days',
        'lop_days',
        'taxable_gross',
        'pf_wage',
        'pf_employee',
        'pf_employer',
        'eps_employer',
        'esi_wage',
        'esi_employee',
        'esi_employer',
        'professional_tax',
        'tds',
        'employer_cost',
        'published_at',
    ];

    protected $hidden = ['pdf_path'];

    protected function casts(): array
    {
        return [
            'gross_pay' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'days_in_period' => 'decimal:2',
            'payable_days' => 'decimal:2',
            'lop_days' => 'decimal:2',
            'taxable_gross' => 'decimal:2',
            'pf_wage' => 'decimal:2',
            'pf_employee' => 'decimal:2',
            'pf_employer' => 'decimal:2',
            'eps_employer' => 'decimal:2',
            'esi_wage' => 'decimal:2',
            'esi_employee' => 'decimal:2',
            'esi_employer' => 'decimal:2',
            'professional_tax' => 'decimal:2',
            'tds' => 'decimal:2',
            'employer_cost' => 'decimal:2',
            'breakdown_json' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** Visible to the employee (their run has been finalized). */
    public function scopePublished($query)
    {
        return $query->whereNotNull($this->qualifyColumn('published_at'));
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
