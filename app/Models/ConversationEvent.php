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
