<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Toda mensagem nossa vai para o WhatsApp. Isto e o que registra o que NAO deve
// chegar ao cliente: transferencia, nota interna, passo do chatbot. E a base da
// auditoria — quem fez o que, e quando.
class ConversationEvent extends Model
{
    use BelongsToTenant;

    public const TRANSFERENCIA = 'transferencia';

    // Nota interna: fica no historico da conversa e NUNCA e enviada ao
    // cliente. E por isto que vive aqui e nao em messages — toda mensagem
    // nossa, por definicao, vai para o WhatsApp.
    public const NOTA = 'nota';

    // O caminho que o cliente percorreu no bot. Vale ouro para quem recebe a
    // conversa: em vez de perguntar de novo, ja se ve "escolheu Suporte >
    // Trocar produto".
    public const CHATBOT = 'chatbot';

    public function ehNota(): bool
    {
        return $this->tipo === self::NOTA;
    }

    protected $fillable = ['tenant_id', 'conversation_id', 'user_id', 'tipo', 'descricao', 'dados'];

    protected $casts = ['dados' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
