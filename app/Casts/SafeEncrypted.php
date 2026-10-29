<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Like Laravel's `encrypted` / `encrypted:array` casts, but a value that
 * can't be decrypted -- typically data copied from another server, which
 * was encrypted with that server's APP_KEY -- reads as null (and is logged)
 * instead of breaking every page that loads the record. Saving a new value
 * re-encrypts it with this server's key.
 */
class SafeEncrypted implements CastsAttributes
{
    public function __construct(private ?string $type = null)
    {
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($value);
        } catch (DecryptException) {
            Log::warning('Could not decrypt ' . $model::class . "::{$key} (id {$model->getKey()}); it was probably encrypted with another APP_KEY. Treating it as empty.");

            return null;
        }

        return $this->type === 'array' ? json_decode($plain, true) : $plain;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString($this->type === 'array' ? json_encode($value) : (string) $value);
    }
}
