<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um passo da cadencia.
 *
 * O atraso e contado a partir do PASSO ANTERIOR, e nao do gatilho. "1 dia depois, depois 3
 * dias depois" e como as pessoas descrevem uma jornada; obrigar a somar (1, 4, 11) faz errar
 * na terceira linha.
 */
class SequenceStep extends Model
{
    protected $fillable = ['sequence_id', 'ordem', 'atraso_horas', 'corpo'];

    protected $casts = ['ordem' => 'integer', 'atraso_horas' => 'integer'];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    public function atrasoLegivel(): string
    {
        $h = $this->atraso_horas;

        if ($h < 1) {
            return 'na hora';
        }

        if ($h % 24 === 0) {
            $d = intdiv($h, 24);

            return $d === 1 ? '1 dia depois' : $d.' dias depois';
        }

        return $h === 1 ? '1 hora depois' : $h.' horas depois';
    }
}
