<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A place people work from: an office, a site, a client location, or remote. */
class WorkLocation extends Model
{
    use Audited, BelongsToCompany;

    protected string $auditLog = 'organisation';

    public const TYPES = ['office', 'site', 'client', 'remote'];

    protected $fillable = ['branch_id', 'name', 'code', 'type', 'address', 'city', 'state', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
