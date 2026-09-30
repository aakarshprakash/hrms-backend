<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    use Audited, BelongsToCompany, HasFactory, HasBranchScope;

    protected string $auditLog = 'payroll';

    public const STATUSES = ['draft', 'processing', 'processed', 'finalized', 'paid'];

    protected $fillable = [
        'branch_id',
        'month',
        'year',
        'period_start',
        'period_end',
        'status',
        'run_by',
        'run_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'run_at' => 'datetime',
            'period_start' => 'date',
            'period_end' => 'date',
            'processed_at' => 'datetime',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
            'totals' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PayrollRun $run) {
            $start = \Carbon\CarbonImmutable::create($run->year, $run->month, 1);
            $run->period_start ??= $start->toDateString();
            $run->period_end ??= $start->endOfMonth()->toDateString();
            $run->status ??= 'draft';
        });
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'processed'], true);
    }

    public function finalizedBy()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function runBy()
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function adjustments()
    {
        return $this->hasMany(PayrollRunAdjustment::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
