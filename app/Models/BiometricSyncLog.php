<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BiometricSyncLog extends Model
{
    protected $fillable = [
        'branch_id',
        'triggered_by',
        'date_from',
        'date_to',
        'total_fetched',
        'matched_count',
        'unmatched_count',
        'unmatched_codes',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'unmatched_codes' => 'array',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
