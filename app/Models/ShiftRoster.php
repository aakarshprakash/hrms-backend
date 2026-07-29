<?php

namespace App\Models;

use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShiftRoster extends Model
{
    use HasFactory, HasBranchScope;

    protected $fillable = [
        'branch_id',
        'department_id',
        'employee_id',
        'shift_id',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function swapRequests()
    {
        return $this->hasMany(ShiftSwapRequest::class, 'roster_id');
    }
}
