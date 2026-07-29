<?php

namespace App\Models;

use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeaveType extends Model
{
    use HasFactory, HasBranchScope;

    protected $fillable = [
        'branch_id',
        'name',
        'days_per_year',
        'carry_forward',
        'paid',
    ];

    protected function casts(): array
    {
        return [
            'carry_forward' => 'boolean',
            'paid' => 'boolean',
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
}
