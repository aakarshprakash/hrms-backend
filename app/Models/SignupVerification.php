<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An emailed sign-up code, and once confirmed, the token that proves it. */
class SignupVerification extends Model
{
    protected $fillable = [
        'email',
        'code_hash',
        'attempts',
        'expires_at',
        'verified_at',
        'token_hash',
        'token_expires_at',
        'consumed_at',
        'ip',
    ];

    protected $hidden = ['code_hash', 'token_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
