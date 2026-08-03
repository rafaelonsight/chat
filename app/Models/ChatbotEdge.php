<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotEdge extends Model
{
    use BelongsToTenant;

    public const SAIDA = 'saida';

    public const SIM = 'sim';

    public const NAO = 'nao';

    protected $fillable = ['tenant_id', 'chatbot_id', 'from_step_id', 'from_handle', 'to_step_id'];

    protected $attributes = ['from_handle' => self::SAIDA];

    public static function opcao(string $gatilho): string
    {
        return 'opcao:'.$gatilho;
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(ChatbotStep::class, 'from_step_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(ChatbotStep::class, 'to_step_id');
    }
}
