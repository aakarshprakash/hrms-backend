<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AnnouncementRead extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected array $tenantParents = ['announcement_id' => 'announcements'];

    protected $fillable = ['announcement_id', 'user_id', 'read_at', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
