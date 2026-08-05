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
        'tenant_id', 'channel_id', 'nome', 'ativo',
        'mensagem_boas_vindas', 'mensagem_nao_entendi',
        'mensagem_fora_horario', 'mensagem_transferindo',
        'max_tentativas', 'palavra_escape',
        'status', 'versao', 'publicado_em', 'team_id',
        'tolerancia_segundos', 'espera_segundos', 'espera_acao', 'mensagem_sem_resposta',
    ];

    protected $casts = [
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
            if (! $bot->ativo || ! $bot->isDirty('ativo')) {
                return;
            }

            static::where('ativo', true)
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

    /**
     * Fluxo inicial de exemplo, pronto para editar. Comecar de uma arvore vazia e
     * a parte mais dificil de configurar um bot; comecar de uma arvore plausivel e
     * questao de trocar os textos.
     */
    public static function criarExemplo(): self
    {
        $bot = static::create([
            'nome'                  => 'Recepção',
            'ativo'                 => false,
            'mensagem_boas_vindas'  => "Olá! Sou o atendimento automático. Como podemos ajudar?",
            'mensagem_nao_entendi'  => 'Não entendi. Escolha uma das opções abaixo:',
            'mensagem_transferindo' => 'Um momento, já vou te encaminhar para um atendente.',
            'max_tentativas'        => 2,
            'palavra_escape'        => 'atendente',
        ]);

        $suporte = Team::where('nome', 'ilike', 'suporte%')->first();
        $financeiro = Team::where('nome', 'ilike', 'financ%')->first();

        $no = fn (array $attr) => ChatbotNode::create(array_merge(['chatbot_id' => $bot->id], $attr));

        $no([
            'gatilho' => '1', 'rotulo' => 'Financeiro', 'ordem' => 1,
            'tipo' => ChatbotNode::EQUIPE, 'team_id' => $financeiro?->id,
            'mensagem' => 'Vou te encaminhar para o Financeiro.',
        ]);

        $tecnico = $no([
            'gatilho' => '2', 'rotulo' => 'Suporte técnico', 'ordem' => 2,
            'tipo' => ChatbotNode::MENU, 'mensagem' => 'Qual o problema?',
        ]);

        $no([
            'parent_id' => $tecnico->id,
            'gatilho' => '1', 'rotulo' => 'Falar com o Suporte', 'ordem' => 1,
            'tipo' => ChatbotNode::EQUIPE, 'team_id' => $suporte?->id,
            'mensagem' => 'Vou te encaminhar para o Suporte.',
        ]);

        $no([
            'parent_id' => $tecnico->id,
            'gatilho' => '2', 'rotulo' => 'Ver dúvidas frequentes', 'ordem' => 2,
            'tipo' => ChatbotNode::MENSAGEM,
            'mensagem' => "Boa parte das dúvidas já está respondida na nossa página de ajuda.\n\nSe não resolver, escolha 1 para falar com o Suporte.",
        ]);

        $no([
            'gatilho' => '3', 'rotulo' => 'Horário de atendimento', 'ordem' => 3,
            'tipo' => ChatbotNode::MENSAGEM,
            'mensagem' => 'Atendemos de segunda a sexta, das 8h às 18h.',
        ]);

        return $bot;
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

    public function nodes(): HasMany
    {
        return $this->hasMany(ChatbotNode::class);
    }

    /** Opcoes do menu raiz. */
    public function raiz(): HasMany
    {
        return $this->hasMany(ChatbotNode::class)->whereNull('parent_id')->orderBy('ordem');
    }

    // Bot do canal tem prioridade sobre o bot geral da conta: conta com um
    // numero de suporte e outro comercial precisa de arvores diferentes.
    /**
     * O que atende de verdade: ativo E publicado. Rascunho existe para poder mexer
     * no fluxo sem afetar quem esta conversando agora — se rascunho atendesse, o
     * cliente pegaria o fluxo pela metade durante a edicao.
     */
    public static function publicadoPara(Channel $canal): ?self
    {
        $doCanal = static::where('ativo', true)->where('status', self::PUBLICADO);

        return (clone $doCanal)->where('channel_id', $canal->id)->first()
            ?? $doCanal->whereNull('channel_id')->first();
    }

    public static function ativoPara(Channel $canal): ?self
    {
        return static::where('ativo', true)->where('channel_id', $canal->id)->first()
            ?? static::where('ativo', true)->whereNull('channel_id')->first();
    }
}
