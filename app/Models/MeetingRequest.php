<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Alguem batendo na porta da sala. */
class MeetingRequest extends Model
{
    use BelongsToTenant;

    public const AGUARDANDO = 'aguardando';

    public const ACEITO = 'aceito';

    public const RECUSADO = 'recusado';

    /**
     * Depois disto o pedido nao vale mais.
     *
     * Quem bateu na porta e foi almocar nao pode ser aceito uma hora depois e cair numa sala
     * onde ninguem o espera — e a fila do anfitriao nao pode encher de gente que ja desistiu.
     */
    public const MINUTOS_ATE_VENCER = 10;

    protected $fillable = [
        'tenant_id', 'meeting_id', 'nome', 'status', 'decidido_por', 'decidido_em',
    ];

    protected $casts = ['decidido_em' => 'datetime'];

    protected $attributes = ['status' => self::AGUARDANDO];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /**
     * Os que ainda estao na fila.
     *
     * "Pendentes" e nao "aguardando" por um motivo bem concreto: existe um aguardando() de
     * objeto logo abaixo, e escopo com o mesmo nome de um metodo de instancia nao chega a ser
     * escopo — o PHP resolve para o metodo e o erro so aparece na primeira chamada estatica.
     */
    public function scopePendentes(Builder $q): Builder
    {
        return $q->where('status', self::AGUARDANDO)
            ->where('created_at', '>', now()->subMinutes(self::MINUTOS_ATE_VENCER));
    }

    public function aguardando(): bool
    {
        return $this->status === self::AGUARDANDO && ! $this->vencido();
    }

    public function aceito(): bool
    {
        return $this->status === self::ACEITO;
    }

    public function recusado(): bool
    {
        return $this->status === self::RECUSADO;
    }

    public function vencido(): bool
    {
        return $this->status === self::AGUARDANDO
            && $this->created_at->addMinutes(self::MINUTOS_ATE_VENCER)->isPast();
    }

    public function decidir(string $status, ?int $porQuem): void
    {
        $this->update([
            'status'       => $status,
            'decidido_por' => $porQuem,
            'decidido_em'  => now(),
        ]);
    }
}
