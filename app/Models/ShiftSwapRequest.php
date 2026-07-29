<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShiftSwapRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'requester_id',
        'target_employee_id',
        'roster_id',
        'my_date',
        'their_date',
        'status',
        'reason',
    ];

    public function requester()
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function targetEmployee()
    {
        return $this->belongsTo(Employee::class, 'target_employee_id');
    }

    public function roster()
    {
        return $this->belongsTo(ShiftRoster::class, 'roster_id');
    }
}
