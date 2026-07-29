<?php

namespace App\Models;

use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Shift extends Model
{
    use HasFactory, HasBranchScope;

    protected $fillable = [
        'branch_id',
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'grace_minutes',
        'half_day_threshold_minutes',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function employeeShifts()
    {
        return $this->hasMany(EmployeeShift::class);
    }

    public function rosters()
    {
        return $this->hasMany(ShiftRoster::class);
    }
}
