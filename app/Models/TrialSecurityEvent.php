<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registro interno de alertas de segurança de trials monitorados. */
class TrialSecurityEvent extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'tipo', 'gravidade', 'ip_hash', 'rota', 'metodo', 'status_http', 'detalhes', 'ocorreu_em',
    ];

    protected $casts = [
        'detalhes' => 'array',
        'ocorreu_em' => 'datetime',
    ];
}
