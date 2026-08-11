<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use BelongsToTenant;

    /**
     * O acesso do usuario logado, aplicado por construcao.
     *
     * Aqui e o gargalo certo: toda tela de atendimento, todo relatorio e toda abertura de
     * conversa por id passam por este modelo. Filtrar em cada lugar seria confiar em memoria
     * humana para uma regra cujo esquecimento nao da erro nenhum — so mostra ao atendente uma
     * conversa que nao era dele.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Models\Scopes\Acesso('channel_id', 'team_id'));

        /*
         * CONVERSA NOVA CAI NA TRIAGEM.
         *
         * Este gancho fecha o buraco que a regra de acesso abriu: quem esta num time nao ve
         * conversa sem time, entao conversa nascendo sem time nasceria invisivel para todo
         * atendente — visivel so para administrador, que e justamente quem nao atende.
         *
         * So preenche quando ninguem escolheu: quem cria a conversa ja sabendo o time (chatbot,
         * transferencia, campanha) manda o dela e este gancho nao encosta.
         *
         * E se a Triagem nao existir, segue sem time: nao ha desculpa para perder mensagem de
         * cliente por causa de uma equipe apagada.
         */
        static::creating(function (Conversation $conversa) {
            if ($conversa->team_id !== null) {
                return;
            }

            $tenantId = $conversa->tenant_id ?: \App\Support\TenantContext::get();

            if ($tenantId && $padrao = \App\Models\Team::padraoDe((int) $tenantId)) {
                $conversa->team_id = $padrao->id;
            }
        });
    }

    public const NOVA           = 'nova';
    public const EM_ATENDIMENTO = 'em_atendimento';
    public const ARQUIVADA      = 'arquivada';

    public const ROTULOS = [
        self::NOVA           => 'Novas',
        self::EM_ATENDIMENTO => 'Em atendimento',
        self::ARQUIVADA      => 'Arquivadas',
    ];

    protected $fillable = [
        'tenant_id', 'channel_id', 'contact_id', 'status', 'atendente_id', 'team_id',
        'pesquisa_enviada_em', 'satisfacao', 'satisfacao_em',
        'fixada_em', 'fixada_por', 'mencao_em',
        'funnel_stage_id', 'etapa_em',
        'ultima_msg_em', 'ultima_entrada_em', 'nao_lidas',
        'origem_tipo', 'origem_id', 'origem',
        'chatbot_id', 'chatbot_tentativas', 'chatbot_estado',
        'chatbot_step_id', 'chatbot_aguardando', 'chatbot_acao_ordem', 'chatbot_respostas',
        'chatbot_visto_msg_id', 'chatbot_marca',
    ];

    protected $casts = [
        'pesquisa_enviada_em' => 'datetime',
        'fixada_em'           => 'datetime',
        'mencao_em'           => 'datetime',
        'etapa_em'            => 'datetime',
        'satisfacao_em'       => 'datetime',
        'satisfacao'          => 'integer',
        'ultima_msg_em'      => 'datetime',
        'ultima_entrada_em'  => 'datetime',
        'chatbot_respostas'  => 'array',
        'origem'             => 'array',
        'chatbot_acao_ordem' => 'integer',
        'chatbot_marca'      => 'integer',
    ];

    // O default tambem em PHP: sem isto uma conversa recem-criada reporta status
    // null ate alguem dar refresh(), porque o valor vem do banco.
    protected $attributes = [
        'status'    => self::NOVA,
        'nao_lidas' => 0,
    ];

    public function veioDeAnuncio(): bool
    {
        return $this->origem_tipo !== null;
    }

    /**
     * Uma linha para a tela do atendente.
     *
     * Existe para ele saber de onde a pessoa veio ANTES de responder, em vez de comecar o
     * atendimento perguntando "como voce nos conheceu?" — pergunta que o proprio sistema
     * ja sabe responder.
     */
    public function origemResumo(): ?string
    {
        if (! $this->veioDeAnuncio()) {
            return null;
        }

        $rotulo = match ($this->origem_tipo) {
            'ad'    => 'Anúncio',
            'post'  => 'Publicação',
            default => 'Origem',
        };

        $titulo = data_get($this->origem, 'titulo') ?: data_get($this->origem, 'texto');

        return $titulo
            ? $rotulo.': '.mb_substr((string) $titulo, 0, 80)
            : $rotulo;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function atendente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendente_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function ultimaMensagem(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    // ------------------------------------------------------------ transicoes

    // Chamado pelo hook de criacao de mensagem. Concentrar a regra aqui e o que
    // garante que qualquer caminho — tela, automacao ou a IA no futuro — mova a
    // conversa do jeito certo sem precisar lembrar de fazer isso.
    public function aoReceberMensagem(Message $mensagem): void
    {
        if ($mensagem->direcao === 'out') {
            $this->forceFill([
                'status'       => self::EM_ATENDIMENTO,
                'atendente_id' => $this->atendente_id ?? auth()->id(),
            ])->save();

            return;
        }

        // Conversa arquivada nao reabre sozinha. Quem decide para onde vai a
        // mensagem e o resolvedor abertaOuNova(), que cria uma conversa nova
        // quando a unica existente esta encerrada — assim cada atendimento tem
        // comeco e fim proprios em vez de um fio infinito.
    }

    // Resolve para onde a mensagem vai: a conversa aberta do contato neste
    // canal, ou uma nova se a ultima ja foi encerrada.
    public static function abertaOuNova(int $channelId, int $contactId, ?int $tenantId = null): self
    {
        $aberta = static::where('channel_id', $channelId)
            ->where('contact_id', $contactId)
            ->where('status', '!=', self::ARQUIVADA)
            ->first();

        if ($aberta) {
            return $aberta;
        }

        try {
            return static::create(array_filter([
                'tenant_id'  => $tenantId,
                'channel_id' => $channelId,
                'contact_id' => $contactId,
            ]));
        } catch (\Illuminate\Database\QueryException $e) {
            // Corrida: outra mensagem do mesmo contato chegou no mesmo instante
            // e criou a conversa primeiro. O indice unico parcial barrou esta —
            // e o certo e usar a que venceu.
            return static::where('channel_id', $channelId)
                ->where('contact_id', $contactId)
                ->where('status', '!=', self::ARQUIVADA)
                ->firstOrFail();
        }
    }

    // Reabrir e para desfazer arquivamento por engano. Se ja existe conversa
    // aberta com o contato, reabrir violaria o indice unico parcial.
    public function podeReabrir(): bool
    {
        if ($this->status !== self::ARQUIVADA) {
            return false;
        }

        return ! static::where('channel_id', $this->channel_id)
            ->where('contact_id', $this->contact_id)
            ->where('status', '!=', self::ARQUIVADA)
            ->whereKeyNot($this->getKey())
            ->exists();
    }

    /**
     * Passa a conversa para uma PESSOA.
     *
     * Diferente de transferir para equipe, e a diferenca e de proposito. Mandar para a equipe
     * devolve a conversa para a fila: perde o dono e volta a ser Nova, para quem estiver livre
     * pegar. Mandar para uma pessoa ja escolhe o dono — a conversa entra em atendimento no nome
     * dela, e nao fica parada em Novos esperando alguem notar.
     *
     * A equipe NAO muda junto. Se o Joao do Suporte passa para a Maria das Vendas, a conversa
     * continua contando como Suporte no relatorio ate que alguem transfira a equipe de fato.
     * Trocar as duas coisas num clique so faria o numero do relatorio mudar sem ninguem ter
     * pedido.
     */
    public function passarPara(User $destino, ?User $por = null): bool
    {
        if ($destino->tenant_id !== $this->tenant_id) {
            return false;
        }

        $origem = $this->atendente?->name;

        $this->forceFill([
            'atendente_id' => $destino->id,
            'status'       => self::EM_ATENDIMENTO,
        ])->save();

        ConversationEvent::create([
            'tenant_id'       => $this->tenant_id,
            'conversation_id' => $this->id,
            'user_id'         => $por?->id ?? auth()->id(),
            'tipo'            => ConversationEvent::TRANSFERENCIA,
            'descricao'       => $origem
                ? "Passada de {$origem} para {$destino->name}"
                : "Passada para {$destino->name}",
            'dados'           => ['de' => $origem, 'para' => $destino->name, 'pessoa' => true],
        ]);

        return true;
    }

    /**
     * Devolve a conversa para a fila de "quero olhar isto depois".
     *
     * Poe 1 e nao o numero que havia antes: o contador diz "quantas mensagens do cliente
     * ninguem leu", e reconstruir isso seria mentir — as mensagens FORAM lidas, a pessoa e que
     * quer voltar nelas. Um significa "tem coisa aqui para voce", que e o que ela quis dizer.
     *
     * max(1, ...) e nao 1 puro: se chegou mensagem nova entre abrir e marcar, o numero real
     * e maior e nao pode encolher.
     */
    /**
     * Esta pessoa pode ver esta conversa?
     *
     * Mora aqui, e nao dentro da closure do canal, para ter teste proprio: e a regra que
     * separa uma empresa da outra no tempo real. O escopo global protege o banco; aqui e o
     * unico lugar onde alguem poderia assinar o canal de outra conta e receber as mensagens
     * dela ao vivo.
     */
    /**
     * Etiquetas DESTE atendimento.
     *
     * Separada das do contato de proposito: esta fica presa ao que aconteceu aqui, e por isso
     * o relatorio do mes passado nunca muda. A do contato descreve a pessoa e muda com ela.
     */
    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'conversation_tag')
            ->withPivot(['origem', 'aplicado_por', 'created_at'])
            ->orderBy('tags.nome');
    }

    public static function visivelPara(?User $user, int $conversationId): bool
    {
        if (! $user) {
            return false;
        }

        return static::withoutGlobalScope('tenant')
            ->whereKey($conversationId)
            ->where('tenant_id', $user->tenant_id)
            ->exists();
    }

    /**
     * Prende a conversa no topo da lista.
     *
     * Serve para o atendimento que nao pode escorregar: o caso grave do dia, o cliente que
     * esta esperando uma resposta que depende de terceiro. Sem isso, a conversa desce sozinha
     * conforme outras chegam, e sumir da vista e sumir da cabeca.
     *
     * E de quem fixou, nao da conta: a coluna guarda o usuario. Conversa que um atendente
     * fixou nao pode ficar presa no topo da tela de todo mundo.
     */
    public function fixarPara(User $quem): void
    {
        $this->forceFill(['fixada_em' => now(), 'fixada_por' => $quem->id])->save();
    }

    public function desafixar(): void
    {
        $this->forceFill(['fixada_em' => null, 'fixada_por' => null])->save();
    }

    public function fixadaPara(?User $quem): bool
    {
        return $quem !== null && $this->fixada_em !== null && (int) $this->fixada_por === $quem->id;
    }

    public function funnelStage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FunnelStage::class, 'funnel_stage_id');
    }

    /**
     * Move o cartao de coluna.
     *
     * Guarda QUANDO entrou na etapa, e nao so qual e. Sem a data nao da para responder "ha
     * quanto tempo esse negocio esta parado em Negociacao?", que e a unica pergunta que faz
     * um funil valer alguma coisa — sem ela, ele e uma lista bonita.
     */
    public function moverPara(?FunnelStage $etapa): void
    {
        $this->forceFill([
            'funnel_stage_id' => $etapa?->id,
            'etapa_em'        => $etapa ? now() : null,
        ])->save();
    }

    public function marcarNaoLida(): void
    {
        $this->forceFill(['nao_lidas' => max(1, (int) $this->nao_lidas)])->save();
    }

    public function assumir(?User $atendente = null): void
    {
        $this->forceFill([
            'status'       => self::EM_ATENDIMENTO,
            'atendente_id' => $atendente?->id ?? auth()->id(),
        ])->save();
    }

    public function arquivar(): void
    {
        $this->forceFill(['status' => self::ARQUIVADA])->save();
    }

    public function reabrir(): bool
    {
        if (! $this->podeReabrir()) {
            return false;
        }

        $this->forceFill([
            'status'       => self::EM_ATENDIMENTO,
            'atendente_id' => $this->atendente_id ?? auth()->id(),
        ])->save();

        return true;
    }

    public function rotuloStatus(): string
    {
        return self::ROTULOS[$this->status] ?? $this->status;
    }

    /** Horas da janela de atendimento da Meta. Nao e configuravel: e regra deles. */
    public const JANELA_HORAS = 24;

    /**
     * Quando a janela de 24 horas fecha — ou null quando a pergunta nao se aplica.
     *
     * Null tem dois motivos diferentes, e os dois significam "nao ha limite a
     * mostrar": o canal nao usa janela, ou o cliente ainda nao falou nada.
     */
    public function janelaAte(): ?\Illuminate\Support\Carbon
    {
        if (! $this->channel?->exigeJanela() || ! $this->ultima_entrada_em) {
            return null;
        }

        return $this->ultima_entrada_em->copy()->addHours(self::JANELA_HORAS);
    }

    public function janelaAberta(): bool
    {
        $ate = $this->janelaAte();

        return $ate !== null && $ate->isFuture();
    }

    /**
     * Da para enviar texto livre agora?
     *
     * Canal sem janela: sempre. Canal com janela: so dentro dela. Fora dela a API
     * oficial recusa, e template aprovado e outra coisa — passa por analise da Meta e
     * e cobrado por envio.
     */
    public function podeEnviarLivre(): bool
    {
        if (! $this->channel?->exigeJanela()) {
            return true;
        }

        return $this->janelaAberta();
    }

    /** "3h 20min" do que resta, ou null quando nao se aplica. */
    public function janelaRestante(): ?string
    {
        $ate = $this->janelaAte();

        if ($ate === null || $ate->isPast()) {
            return null;
        }

        $minutos = (int) ceil(now()->diffInMinutes($ate, absolute: true));

        if ($minutos < 60) {
            return $minutos.'min';
        }

        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        return $resto === 0 ? $horas.'h' : $horas.'h '.$resto.'min';
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function chatbotStep(): BelongsTo
    {
        return $this->belongsTo(ChatbotStep::class, 'chatbot_step_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * As reunioes por video que sairam desta conversa.
     *
     * Elas entram na linha do tempo do atendimento junto com as mensagens: a chamada aconteceu
     * num ponto do tempo, e o que foi combinado nela — inclusive o que foi digitado no
     * bate-papo da sala — faz parte do atendimento como qualquer outra coisa que foi dita.
     */
    public function meetings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ConversationEvent::class);
    }

    // Transferir devolve a conversa para a fila da equipe destino: ninguem da
    // nova equipe esta nela ainda, entao volta para Novos sem atendente. O rastro
    // vai para conversation_events porque mensagem nossa iria para o cliente.
    public function transferir(Team $destino, ?User $por = null): bool
    {
        if ($destino->tenant_id !== $this->tenant_id) {
            return false;
        }

        $origem = $this->team?->nome;

        $this->forceFill([
            'team_id'      => $destino->id,
            'status'       => self::NOVA,
            'atendente_id' => null,
        ])->save();

        ConversationEvent::create([
            'tenant_id'       => $this->tenant_id,
            'conversation_id' => $this->id,
            'user_id'         => $por?->id ?? auth()->id(),
            'tipo'            => ConversationEvent::TRANSFERENCIA,
            'descricao'       => $origem
                ? "Transferida de {$origem} para {$destino->nome}"
                : "Transferida para {$destino->nome}",
            'dados'           => ['de' => $origem, 'para' => $destino->nome],
        ]);

        return true;
    }
}
