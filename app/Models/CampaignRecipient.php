<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha por pessoa que a campanha alcanca.
 *
 * Sem isto nao ha resposta para "quem recebeu?", e nao ha como retomar de onde parou. Disparo
 * interrompido que reinicia do comeco manda tudo duas vezes — e a segunda vez e a que faz o
 * cliente bloquear o numero.
 *
 * Nao usa BelongsToTenant: o dono e a campanha, e ela ja e da conta. Uma coluna de tenant aqui
 * seria uma segunda verdade sobre a mesma coisa, com chance de discordar.
 */
class CampaignRecipient extends Model
{
    public const PENDENTE = 'pendente';

    public const ENVIADA = 'enviada';

    public const FALHOU = 'falhou';

    public const PULADA = 'pulada';

    protected $fillable = [
        'campaign_id', 'contact_id', 'message_id', 'status', 'motivo', 'enviada_em',
    ];

    protected $casts = ['enviada_em' => 'datetime'];

    protected $attributes = ['status' => self::PENDENTE];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
