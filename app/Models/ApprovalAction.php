<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ApprovalAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'flow_id',
        'requestable_type',
        'requestable_id',
        'step_number',
        'approver_id',
        'status',
        'acted_at',
        'comments',
    ];

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
        ];
    }

    public function flow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'flow_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function requestable()
    {
        return $this->morphTo();
    }
}
