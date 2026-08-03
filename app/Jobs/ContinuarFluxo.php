<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\ChatbotMotor;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Retoma o fluxo depois de uma acao "Esperar".
 *
 * Job com atraso nao ocupa worker enquanto espera — fica no Redis com hora marcada.
 * Por isso a espera nao precisa de fila propria, ao contrario da transcricao, que
 * consome CPU o tempo todo em que roda.
 */
class ContinuarFluxo implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $conversationId,
        public int $stepId,
        public int $daOrdem,
    ) {}

    public function handle(ChatbotMotor $motor): void
    {
        $conversa = Conversation::withoutGlobalScope('tenant')->find($this->conversationId);

        if (! $conversa) {
            return;
        }

        TenantContext::runAs($conversa->tenant_id, function () use ($conversa, $motor) {
            $motor->retomarDepoisDaEspera($conversa, $this->stepId, $this->daOrdem);
        });
    }
}
