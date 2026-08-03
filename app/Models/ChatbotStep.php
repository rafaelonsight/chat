<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um cartao no canvas: um grupo de acoes que roda em ordem. */
class ChatbotStep extends Model
{
    use BelongsToTenant;

    public const INICIO = 'inicio';

    public const GRUPO = 'grupo';

    protected $fillable = ['tenant_id', 'chatbot_id', 'nome', 'tipo', 'x', 'y'];

    protected $casts = ['x' => 'integer', 'y' => 'integer'];

    protected $attributes = ['tipo' => self::GRUPO, 'nome' => 'Novo grupo', 'x' => 0, 'y' => 0];

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ChatbotAction::class, 'step_id')->orderBy('ordem');
    }

    public function saidas(): HasMany
    {
        return $this->hasMany(ChatbotEdge::class, 'from_step_id');
    }

    public function ehInicio(): bool
    {
        return $this->tipo === self::INICIO;
    }

    /** Para onde ir a partir deste passo por um determinado handle. */
    public function destino(string $handle = ChatbotEdge::SAIDA): ?self
    {
        return $this->saidas()->where('from_handle', $handle)->first()?->to;
    }
}
