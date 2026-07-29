<?php

namespace App\Models;

use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OvertimeRule extends Model
{
    use HasFactory, HasBranchScope;

    protected $fillable = [
        'branch_id',
        'daily_threshold_hours',
        'weekly_threshold_hours',
        'rate_multiplier',
    ];

    protected function casts(): array
    {
        return [
            'daily_threshold_hours' => 'decimal:2',
            'weekly_threshold_hours' => 'decimal:2',
            'rate_multiplier' => 'decimal:2',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
