<?php

namespace App\Models;

use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasBranchScope;

    protected $fillable = [
        'branch_id',
        'name',
        'parent_department_id',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function parentDepartment()
    {
        return $this->belongsTo(Department::class, 'parent_department_id');
    }

    public function children()
    {
        return $this->hasMany(Department::class, 'parent_department_id');
    }

    public function designations()
    {
        return $this->hasMany(Designation::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
