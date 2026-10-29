<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One short-staffing alert per rule per day (so it's sent once). */
class CoverageAlert extends Model
{
    use BelongsToCompany;

    protected $fillable = ['coverage_rule_id', 'date', 'present', 'required', 'on_leave', 'notified'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function rule()
    {
        return $this->belongsTo(CoverageRule::class, 'coverage_rule_id');
    }
}
