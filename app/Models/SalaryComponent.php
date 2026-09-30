<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryComponent extends Model
{
    use Audited, BelongsToCompany, HasFactory, HasBranchScope;

    protected string $auditLog = 'payroll';

    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'type',
        'calculation_type',
        'percentage_of',
        'default_value',
        'is_basic',
        'pf_applicable',
        'esi_applicable',
        'taxable',
        'prorate',
        'is_variable',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_value' => 'decimal:2',
            'is_basic' => 'boolean',
            'pf_applicable' => 'boolean',
            'esi_applicable' => 'boolean',
            'taxable' => 'boolean',
            'prorate' => 'boolean',
            'is_variable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Deductions (loan EMI, advance recovery...) are flat amounts: not
        // pro-rated by attendance, not wages for ESI, not taxable income --
        // unless explicitly configured otherwise.
        static::creating(function (SalaryComponent $component) {
            if ($component->type !== 'deduction') {
                return;
            }
            foreach (['prorate', 'esi_applicable', 'taxable', 'pf_applicable'] as $flag) {
                if (! array_key_exists($flag, $component->getAttributes())) {
                    $component->setAttribute($flag, false);
                }
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function structures()
    {
        return $this->hasMany(SalaryStructure::class, 'component_id');
    }
}
