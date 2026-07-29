<?php

namespace App\Models;

use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Holiday extends Model
{
    use HasFactory, HasBranchScope;

    protected $fillable = [
        'branch_id',
        'name',
        'date',
        'recurring',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'recurring' => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
