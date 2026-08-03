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
        'tenant_id', 'channel_id', 'contact_id', 'status', 'atendente_id',
        'ultima_msg_em', 'nao_lidas',
    ];

    protected $casts = ['ultima_msg_em' => 'datetime'];

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

        // Cliente voltou depois de encerrado: e demanda nova, precisa de olhos.
        if ($this->status === self::ARQUIVADA) {
            $this->forceFill([
                'status'       => self::NOVA,
                'atendente_id' => null,
            ])->save();
        }
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

    public function reabrir(): void
    {
        $this->forceFill([
            'status'       => self::EM_ATENDIMENTO,
            'atendente_id' => $this->atendente_id ?? auth()->id(),
        ])->save();
    }

    public function rotuloStatus(): string
    {
        return self::ROTULOS[$this->status] ?? $this->status;
    }
}
