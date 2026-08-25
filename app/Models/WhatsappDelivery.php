<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WhatsappDelivery extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'ticket_id', 'message_id', 'conversation_id', 'kind', 'status', 'payload', 'error', 'sent_at'];
    protected $casts = ['payload' => 'array', 'sent_at' => 'datetime'];
}
