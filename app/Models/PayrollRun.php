<?php

namespace App\Models;

use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    use HasFactory, HasBranchScope;

    protected $fillable = [
        'branch_id',
        'month',
        'year',
        'status',
        'run_by',
        'run_at',
    ];

    protected function casts(): array
    {
        return [
            'run_at' => 'datetime',
        ];
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
