<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A jornada de UMA pessoa dentro de uma sequencia.
 *
 * Guarda o proximo passo e a hora dele. Assim o tique procura so quem esta na hora, em vez de
 * recalcular a jornada de todo mundo a cada minuto — o que ficaria caro no dia em que houver
 * dez mil contatos, que e justamente o dia em que ninguem quer descobrir isso.
 */
class SequenceEnrollment extends Model
{
    public const ATIVA = 'ativa';

    public const CONCLUIDA = 'concluida';

    public const PARADA = 'parada';

    protected $fillable = [
        'sequence_id', 'contact_id', 'conversation_id',
        'status', 'proximo_passo', 'proximo_em', 'parada_motivo', 'encerrada_em',
    ];

    protected $casts = [
        'proximo_em'    => 'datetime',
        'encerrada_em'  => 'datetime',
        'proximo_passo' => 'integer',
    ];

    protected $attributes = ['status' => self::ATIVA, 'proximo_passo' => 1];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function parar(string $motivo): void
    {
        $this->forceFill([
            'status'        => self::PARADA,
            'parada_motivo' => $motivo,
            'encerrada_em'  => now(),
            'proximo_em'    => null,
        ])->save();
    }
}
