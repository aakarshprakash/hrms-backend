<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StatutoryRule extends Model
{
    use Audited, BelongsToCompany, HasFactory, HasBranchScope;

    protected string $auditLog = 'payroll';

    /** PF, ESI, professional tax, income tax (TDS), labour welfare fund. */
    public const TYPES = ['PF', 'ESI', 'PT', 'TAX', 'LWF'];

    protected $fillable = [
        'branch_id',
        'country',
        'rule_type',
        'config_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
