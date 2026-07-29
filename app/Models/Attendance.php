<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'shift_id',
        'date',
        'check_in',
        'check_out',
        'status',
        'late_by_minutes',
        'early_by_minutes',
        'worked_minutes',
        'source',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function regularizations()
    {
        return $this->hasMany(AttendanceRegularization::class);
    }

    public function leaveConversion()
    {
        return $this->hasOne(Leave::class, 'source_attendance_id');
    }
}
