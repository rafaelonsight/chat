<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    use BelongsToTenant;

    public const VISIBILITIES = [
        'internal' => 'Interna — somente nossa equipe',
        'partner' => 'Parceiro — equipe e parceiro',
        'public' => 'Pública — cliente também visualiza',
    ];

    protected $fillable = [
        'tenant_id', 'ticket_id', 'author_id', 'requester_id', 'visibility', 'body', 'attachments',
    ];

    protected $casts = ['attachments' => 'array'];

    protected $attributes = ['visibility' => 'internal', 'attachments' => '[]'];

    protected static function booted(): void
    {
        static::creating(fn (self $message) => $message->author_id ??= auth()->id());
        static::created(fn (self $message) => $message->ticket->recordEvent('message_created', null, [
            'message_id' => $message->id,
            'visibility' => $message->visibility,
        ]));
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
