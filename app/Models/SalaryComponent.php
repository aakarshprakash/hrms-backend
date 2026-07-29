<?php

namespace App\Models;

use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryComponent extends Model
{
    use HasFactory, HasBranchScope;

    protected $fillable = [
        'branch_id',
        'name',
        'type',
        'calculation_type',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function structures()
    {
        return $this->hasMany(SalaryStructure::class, 'component_id');
    }
}
