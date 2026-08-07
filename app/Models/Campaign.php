<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use BelongsToTenant;

    public const RASCUNHO = 'rascunho';

    public const AGENDADA = 'agendada';

    public const ENVIANDO = 'enviando';

    public const PAUSADA = 'pausada';

    public const CONCLUIDA = 'concluida';

    public const CANCELADA = 'cancelada';

    public const ROTULOS = [
        self::RASCUNHO  => 'Rascunho',
        self::AGENDADA  => 'Agendada',
        self::ENVIANDO  => 'Enviando',
        self::PAUSADA   => 'Pausada',
        self::CONCLUIDA => 'Concluída',
        self::CANCELADA => 'Cancelada',
    ];

    protected $fillable = [
        'tenant_id', 'channel_id', 'criada_por', 'nome', 'status',
        'publico', 'tag_id', 'corpo', 'meta_template_id', 'template_valores',
        'agendada_para', 'iniciada_em', 'concluida_em',
        'por_minuto', 'hora_inicio', 'hora_fim',
    ];

    protected $casts = [
        'template_valores' => 'array',
        'agendada_para'    => 'datetime',
        'iniciada_em'      => 'datetime',
        'concluida_em'     => 'datetime',
        'por_minuto'       => 'integer',
        'hora_inicio'      => 'integer',
        'hora_fim'         => 'integer',
    ];

    // Padroes conservadores no objeto tambem, e nao so no banco: campanha criada por codigo
    // sem passar por aqui nasceria com null e o calculo de ritmo dividiria por nada.
    protected $attributes = [
        'status'      => self::RASCUNHO,
        'publico'     => 'etiqueta',
        'por_minuto'  => 6,
        'hora_inicio' => 9,
        'hora_fim'    => 20,
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MetaTemplate::class, 'meta_template_id');
    }

    public function criadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function rodando(): bool
    {
        return in_array($this->status, [self::AGENDADA, self::ENVIANDO], true);
    }

    /** Da para mexer nela? Depois que o primeiro disparo sai, nao. */
    public function editavel(): bool
    {
        return in_array($this->status, [self::RASCUNHO, self::AGENDADA], true);
    }

    /**
     * Este canal exige template aprovado?
     *
     * O canal oficial exige, e nao e escolha nossa: fora da janela de 24 horas a Meta recusa
     * texto livre — e numa campanha a janela esta fechada para quase todo mundo, por definicao.
     * Quem responder abre a janela, e a conversa segue normal.
     */
    public function exigeTemplate(): bool
    {
        return (bool) $this->channel?->exigeJanela();
    }
}
