<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeaveType extends Model
{
    use Audited, BelongsToCompany, HasFactory, HasBranchScope;

    protected string $auditLog = 'settings';

    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'days_per_year',
        'accrual',
        'prorate_on_joining',
        'carry_forward',
        'max_carry_forward',
        'encashable',
        'paid',
        'applicable_gender',
        'allow_negative',
        'allow_half_day',
        'min_service_days',
        'min_notice_days',
        'max_consecutive_days',
        'requires_document_after_days',
        'sandwich_rule',
        'color',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'carry_forward' => 'boolean',
            'paid' => 'boolean',
            'max_carry_forward' => 'decimal:2',
            'encashable' => 'boolean',
            'allow_negative' => 'boolean',
            'allow_half_day' => 'boolean',
            'sandwich_rule' => 'boolean',
            'is_active' => 'boolean',
            'prorate_on_joining' => 'boolean',
            'days_per_year' => 'integer',
            'min_service_days' => 'integer',
            'min_notice_days' => 'integer',
            'max_consecutive_days' => 'integer',
            'requires_document_after_days' => 'integer',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    public function balances()
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /** Unpaid types that don't track a balance (loss of pay): never "insufficient". */
    public function isUnlimited(): bool
    {
        return $this->allow_negative && $this->accrual === 'none' && (float) $this->days_per_year <= 0;
    }

    public function appliesTo(Employee $employee): bool
    {
        return $this->applicable_gender === null || $this->applicable_gender === '' || $this->applicable_gender === $employee->gender;
    }
}
