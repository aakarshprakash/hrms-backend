<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone signed in to the mobile app (Expo push token). */
class DeviceToken extends Model
{
    use BelongsToCompany;

    protected array $tenantParents = ['user_id' => 'users'];

    protected $fillable = ['company_id', 'user_id', 'token', 'platform', 'device_name', 'app_version', 'last_used_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
