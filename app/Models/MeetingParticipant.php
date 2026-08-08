<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quem esteve na sala.
 *
 * Convidado de fora e dado pessoal de terceiro: fica numa linha com nome, e nao escondido
 * dentro de um JSON de onde ninguem consegue apagar depois se ele pedir.
 */
class MeetingParticipant extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'meeting_id', 'user_id', 'nome', 'entrou_em'];

    protected $casts = ['entrou_em' => 'datetime'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function daEquipe(): bool
    {
        return $this->user_id !== null;
    }
}
