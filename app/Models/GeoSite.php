<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A place employees may punch from: a point and a radius, belonging to a branch. */
class GeoSite extends Model
{
    use Audited, BelongsToCompany;

    protected string $auditLog = 'organisation';

    protected array $tenantParents = ['branch_id' => 'branches'];

    protected $fillable = ['branch_id', 'name', 'latitude', 'longitude', 'radius_m', 'address', 'is_active'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_m' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_geo_site');
    }
}
