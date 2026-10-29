<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A reply on a ticket, an HR-only internal note, or a system line (status changed…). */
class CareTicketMessage extends Model
{
    use BelongsToCompany;

    protected array $tenantParents = ['care_ticket_id' => 'care_tickets'];

    protected $fillable = ['care_ticket_id', 'user_id', 'body', 'internal', 'event'];

    protected function casts(): array
    {
        return ['internal' => 'boolean'];
    }

    public function ticket()
    {
        return $this->belongsTo(CareTicket::class, 'care_ticket_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
