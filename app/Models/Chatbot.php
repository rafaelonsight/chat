<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chatbot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'channel_id', 'channel_ids', 'nome', 'ativo',
        'mensagem_boas_vindas', 'mensagem_nao_entendi',
        'mensagem_fora_horario', 'mensagem_transferindo',
        'max_tentativas', 'palavra_escape',
        'status', 'versao', 'publicado_em', 'team_id',
        'tolerancia_segundos', 'espera_segundos', 'espera_acao', 'mensagem_sem_resposta',
    ];

    protected $casts = [
        'channel_ids' => 'array',
        'tolerancia_segundos' => 'integer',
        'espera_segundos'     => 'integer',
        'ativo'          => 'boolean',
        'max_tentativas' => 'integer',
        'publicado_em' => 'datetime',
    ];

    // Os defaults tambem em PHP. Sem isto o modelo recem-criado reporta status e
    // versao nulos ate alguem dar refresh(), porque o valor vem do banco — e foi
    // exatamente assim que publicar() calculou versao a partir de null.
    protected $attributes = [
        'ativo'          => false,
        'max_tentativas' => 2,
        'palavra_escape' => 'atendente',
        'status'         => self::RASCUNHO,
        'versao'         => 1,
    ];

    // O banco ja garante um bot ativo por canal com indice parcial. Sem isto, o
    // usuario que ativa o segundo bot tomaria um erro 500 de constraint em vez do
    // comportamento que ele obviamente quis: trocar de bot.
    protected static function booted(): void
    {
        static::saving(function (self $bot) {
            if (! $bot->tenant_id) $bot->tenant_id = \App\Support\TenantContext::get();
            if ($bot->channel_ids !== null) {
                $ids = array_values(array_unique(array_map('intval', $bot->channel_ids)));
                if (! $ids || Channel::where('tenant_id', $bot->tenant_id)->whereIn('id', $ids)->count() !== count($ids)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['channel_ids' => 'Selecione canais válidos desta conta.']);
                }
                $bot->channel_ids = $ids;
                $bot->channel_id = null;
                if ($bot->ativo && $bot->conflitosDeCanais()->isNotEmpty()) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['channel_ids' => 'Há outro chatbot ativo em um dos canais selecionados. Desative-o antes de publicar.']);
                }
                return;
            }
            if (! $bot->ativo || ! $bot->isDirty('ativo')) {
                return;
            }

            static::where('ativo', true)->whereNull('channel_ids')
                ->when($bot->exists, fn ($q) => $q->whereKeyNot($bot->getKey()))
                ->when(
                    $bot->channel_id,
                    fn ($q) => $q->where('channel_id', $bot->channel_id),
                    fn ($q) => $q->whereNull('channel_id'),
                )
                ->update(['ativo' => false]);
        });
    }

    /** Nome do bot que foi desligado ao ativar este, para poder avisar na tela. */
    public function conflitante(): ?self
    {
        return static::where('ativo', true)
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
            ->when(
                $this->channel_id,
                fn ($q) => $q->where('channel_id', $this->channel_id),
                fn ($q) => $q->whereNull('channel_id'),
            )
            ->first();
    }


    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public const RASCUNHO = 'rascunho';

    public const PUBLICADO = 'publicado';

    public function steps(): HasMany
    {
        return $this->hasMany(ChatbotStep::class);
    }

    public function inicio(): ?ChatbotStep
    {
        return $this->steps()->where('tipo', ChatbotStep::INICIO)->first();
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ChatbotAction::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(ChatbotEdge::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function publicado(): bool
    {
        return $this->status === self::PUBLICADO;
    }

    // Bot do canal tem prioridade sobre o bot geral da conta: conta com um
    // numero de suporte e outro comercial precisa de arvores diferentes.
    /**
     * O que atende de verdade: ativo E publicado. Rascunho existe para poder mexer
     * no fluxo sem afetar quem esta conversando agora — se rascunho atendesse, o
     * cliente pegaria o fluxo pela metade durante a edicao.
     */
    public function conflitosDeCanais(): \Illuminate\Support\Collection
    {
        $ids = $this->channel_ids ?? ($this->channel_id ? [$this->channel_id] : []);
        if (! $ids) return collect();
        return static::where('ativo', true)->where('tenant_id', $this->tenant_id)
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))
            ->where(function ($q) use ($ids) {
                $q->where(function ($legacy) use ($ids) {
                    $legacy->whereNull('channel_ids')->where(fn ($c) => $c->whereIn('channel_id', $ids)->orWhereNull('channel_id'));
                });
                foreach ($ids as $id) $q->orWhereJsonContains('channel_ids', (int) $id);
            })->get();
    }

    public static function publicadoPara(Channel $canal): ?self
    {
        return static::paraCanal($canal, true);
    }

    public static function ativoPara(Channel $canal): ?self
    {
        return static::paraCanal($canal, false);
    }

    private static function paraCanal(Channel $canal, bool $publicado): ?self
    {
        $q = static::where('tenant_id', $canal->tenant_id)->where('ativo', true)
            ->when($publicado, fn ($q) => $q->where('status', self::PUBLICADO));
        return (clone $q)->where(fn ($q) => $q->whereJsonContains('channel_ids', (int) $canal->id)
            ->orWhere(fn ($l) => $l->whereNull('channel_ids')->where('channel_id', $canal->id)))->first()
            ?? $q->whereNull('channel_ids')->whereNull('channel_id')->first();
    }
}
