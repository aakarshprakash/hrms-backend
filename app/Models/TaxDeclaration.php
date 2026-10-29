<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\VisibleThroughEmployee;
use Illuminate\Database\Eloquent\Model;

/** An employee's tax regime and investment declarations for one financial year. */
class TaxDeclaration extends Model
{
    use Audited, BelongsToCompany, VisibleThroughEmployee;

    protected string $auditLog = 'payroll';

    protected $fillable = [
        'employee_id', 'financial_year', 'regime', 'items', 'declared_total', 'approved_total', 'status',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'declared_total' => 'decimal:2',
            'approved_total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
