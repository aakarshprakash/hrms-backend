<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ApprovalFlow extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'module',
        'steps_json',
    ];

    protected function casts(): array
    {
        return [
            'steps_json' => 'array',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function actions()
    {
        return $this->hasMany(ApprovalAction::class, 'flow_id');
    }
}
