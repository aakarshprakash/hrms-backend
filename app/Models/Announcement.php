<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A notice to everyone, or to one branch; optionally needs acknowledging. */
class Announcement extends Model
{
    use Audited, BelongsToCompany;

    protected string $auditLog = 'announcements';

    public const CATEGORIES = ['general' => 'General', 'policy' => 'Policy', 'event' => 'Event', 'holiday' => 'Holiday', 'urgent' => 'Urgent'];

    protected $fillable = ['branch_id', 'title', 'body', 'category', 'pinned', 'requires_ack', 'published_at', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'requires_ack' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads()
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    /** Published, not expired, and addressed to $branchId (or everyone). */
    public function scopeLiveFor(Builder $query, ?int $branchId): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($w) => $w->orWhere('branch_id', $branchId)));
    }
}
