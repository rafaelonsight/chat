<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\ChatbotMotor;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Roda quando o tempo limite de espera estoura: o bot perguntou e ninguem respondeu.
 *
 * Mesma protecao por marca do AgruparMensagens: se o cliente respondeu (ou o fluxo
 * avancou), a marca mudou e este job nao faz nada.
 */
class EncerrarEspera implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $conversationId,
        public int $marca,
    ) {}

    public function handle(ChatbotMotor $motor): void
    {
        $conversa = Conversation::withoutGlobalScope('tenant')->find($this->conversationId);

        if (! $conversa) {
            return;
        }

        TenantContext::runAs($conversa->tenant_id, function () use ($conversa, $motor) {
            $motor->encerrarEspera($conversa, $this->marca);
        });
    }
}
