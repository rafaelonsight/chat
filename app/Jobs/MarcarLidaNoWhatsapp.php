<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\EvolutionService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class MarcarLidaNoWhatsapp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $conversationId) {}

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(EvolutionService $evolution): void
    {
        // Job roda fora de requisicao: sem contexto de tenant o escopo global
        // nao acha nada. Mesmo padrao dos outros jobs.
        $conversa = Conversation::withoutGlobalScope('tenant')->find($this->conversationId);

        if (! $conversa) {
            return;
        }

        TenantContext::runAs($conversa->tenant_id, function () use ($conversa, $evolution) {
            $canal = $conversa->channel;

            if (! $canal || ! $canal->instance_name) {
                return;
            }

            // So mensagem do cliente ainda nao marcada. Mensagem nossa nao se
            // marca como lida, e sem external_id o WhatsApp nao sabe qual e.
            $pendentes = $conversa->messages()
                ->where('direcao', 'in')
                ->whereNull('lida_em')
                ->whereNotNull('external_id')
                ->orderBy('id')
                ->limit(50)
                ->get();

            if ($pendentes->isEmpty()) {
                return;
            }

            $jid = $conversa->contact?->jid;

            if (! $jid) {
                return;
            }

            $payload = $pendentes->map(fn (Message $m) => [
                'remoteJid' => $jid,
                'fromMe'    => false,
                'id'        => $m->external_id,
            ])->all();

            try {
                $evolution->marcarComoLida($canal->instance_name, $payload);
            } catch (\Throwable $e) {
                // Deixa lida_em nulo de proposito: na proxima tentativa ele
                // remarca. Marcar antes de confirmar mentiria para o relatorio.
                Log::warning('Falha ao marcar como lida no WhatsApp', [
                    'conversa' => $conversa->id,
                    'erro'     => $e->getMessage(),
                ]);

                throw $e;
            }

            Message::withoutGlobalScope('tenant')
                ->whereIn('id', $pendentes->pluck('id'))
                ->update(['lida_em' => now()]);
        });
    }
}
