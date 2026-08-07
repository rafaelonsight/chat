<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sequence extends Model
{
    use BelongsToTenant;

    public const PRIMEIRA_CONVERSA = 'primeira_conversa';

    public const ATENDIMENTO_ENCERRADO = 'atendimento_encerrado';

    public const SEM_RESPOSTA = 'sem_resposta';

    /**
     * Os tres gatilhos, e por que sao esses.
     *
     * "Cobranca vencendo" estava na lista de ideias e ficou de fora: depende de saber a data de
     * vencimento, que so existe no sistema de cobranca do cliente. Prometer um gatilho que
     * depende de integracao inexistente seria vender o que nao tem.
     */
    public const GATILHOS = [
        self::PRIMEIRA_CONVERSA     => 'Quando alguém fala com a gente pela primeira vez',
        self::ATENDIMENTO_ENCERRADO => 'Quando um atendimento é encerrado',
        self::SEM_RESPOSTA          => 'Quando o cliente some depois de a gente responder',
    ];

    protected $fillable = [
        'tenant_id', 'channel_id', 'nome', 'gatilho', 'ativa',
        'parar_ao_responder', 'sem_resposta_horas', 'hora_inicio', 'hora_fim',
    ];

    protected $casts = [
        'ativa'              => 'boolean',
        'parar_ao_responder' => 'boolean',
        'sem_resposta_horas' => 'integer',
        'hora_inicio'        => 'integer',
        'hora_fim'           => 'integer',
    ];

    protected $attributes = [
        'ativa'              => false,
        'parar_ao_responder' => true,
        'sem_resposta_horas' => 24,
        'hora_inicio'        => 9,
        'hora_fim'           => 20,
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class)->orderBy('ordem');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class);
    }
}
