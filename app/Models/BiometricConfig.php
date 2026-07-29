<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BiometricConfig extends Model
{
    protected $fillable = [
        'branch_id',
        'api_url',
        'ins_code',
        'api_token',
        'enabled',
        'last_synced_at',
        'last_sync_status',
        'last_sync_message',
    ];

    protected $hidden = [
        'api_token',
    ];

    protected $appends = ['masked_token'];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function getMaskedTokenAttribute(): ?string
    {
        if (! $this->api_token) {
            return null;
        }

        return '••••••••' . substr($this->api_token, -6);
    }
}
