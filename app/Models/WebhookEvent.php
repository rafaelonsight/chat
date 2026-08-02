<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Sem BelongsToTenant de proposito: o webhook chega sem usuario autenticado e
// precisa ser gravado antes de sabermos a que tenant pertence.
class WebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'channel_id', 'evento', 'payload',
        'recebido_em', 'processado_em', 'erro',
    ];

    protected $casts = [
        'payload'       => 'array',
        'recebido_em'   => 'datetime',
        'processado_em' => 'datetime',
    ];
}
