<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A team's minimum staffing: who it covers (branch / department / designation) and how many must be in. */
class CoverageRule extends Model
{
    use Audited, BelongsToCompany;

    protected string $auditLog = 'attendance';

    protected $fillable = ['name', 'branch_id', 'department_id', 'designation_id', 'min_present', 'check_at', 'notify_user_ids', 'is_active'];

    protected function casts(): array
    {
        return ['notify_user_ids' => 'array', 'is_active' => 'boolean', 'min_present' => 'integer'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function designation()
    {
        return $this->belongsTo(Designation::class);
    }

    public function alerts()
    {
        return $this->hasMany(CoverageAlert::class);
    }
}
