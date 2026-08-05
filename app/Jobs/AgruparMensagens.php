<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\ChatbotMotor;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Roda quando a janela de tolerancia fecha: le de uma vez tudo o que o cliente
 * escreveu na rajada.
 *
 * Carrega a MARCA de quando foi agendado. Se outra mensagem chegou no meio, a marca
 * da conversa mudou e este job sai calado — quem reagendou e que vai atender. Assim
 * nao e preciso remover job da fila, o que com Redis nao e confiavel.
 */
class AgruparMensagens implements ShouldQueue
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
            $motor->atenderAgrupado($conversa, $this->marca);
        });
    }
}
