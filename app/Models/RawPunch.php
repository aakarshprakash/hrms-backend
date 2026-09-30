<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\VisibleThroughEmployee;
use Illuminate\Database\Eloquent\Model;

/**
 * One punch exactly as received. Never edited by processing except to note
 * which day it was attributed to (attendance_date) and when (processed_at).
 */
class RawPunch extends Model
{
    use BelongsToCompany, VisibleThroughEmployee;

    public const SOURCES = ['biometric', 'web', 'mobile', 'kiosk', 'regularization', 'import', 'backfill'];

    protected array $tenantParents = ['branch_id' => 'branches'];

    protected $fillable = [
        'branch_id',
        'employee_id',
        'biometric_config_id',
        'device_emp_code',
        'punched_at',
        'punched_at_local',
        'source',
        'direction',
        'external_id',
        'latitude',
        'longitude',
        'ip_address',
        'attendance_date',
        'processed_at',
        'dedupe_hash',
        'payload',
    ];

    protected $hidden = ['dedupe_hash', 'payload'];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            // Wall-clock at the branch: serialized without a timezone so clients don't shift it.
            'punched_at_local' => 'datetime:Y-m-d H:i:s',
            'attendance_date' => 'date',
            'processed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
