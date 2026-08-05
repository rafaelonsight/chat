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
        'ultima_msg_em', 'nao_lidas',
        'chatbot_id', 'chatbot_node_id', 'chatbot_tentativas', 'chatbot_estado',
        'chatbot_step_id', 'chatbot_aguardando', 'chatbot_acao_ordem', 'chatbot_respostas',
        'chatbot_visto_msg_id', 'chatbot_marca',
    ];

    protected $casts = [
        'ultima_msg_em'      => 'datetime',
        'chatbot_respostas'  => 'array',
        'chatbot_acao_ordem' => 'integer',
        'chatbot_marca'      => 'integer',
    ];

    // O default tambem em PHP: sem isto uma conversa recem-criada reporta status
    // null ate alguem dar refresh(), porque o valor vem do banco.
    protected $attributes = [
        'status'    => self::NOVA,
        'nao_lidas' => 0,
    ];

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

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function chatbotStep(): BelongsTo
    {
        return $this->belongsTo(ChatbotStep::class, 'chatbot_step_id');
    }

    public function chatbotNode(): BelongsTo
    {
        return $this->belongsTo(ChatbotNode::class, 'chatbot_node_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
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
