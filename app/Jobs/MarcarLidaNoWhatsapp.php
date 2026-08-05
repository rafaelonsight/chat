<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Canais\Enviadores;
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

    public function handle(Enviadores $enviadores): void
    {
        // Job roda fora de requisicao: sem contexto de tenant o escopo global
        // nao acha nada. Mesmo padrao dos outros jobs.
        $conversa = Conversation::withoutGlobalScope('tenant')->find($this->conversationId);

        if (! $conversa) {
            return;
        }

        TenantContext::runAs($conversa->tenant_id, function () use ($conversa, $enviadores) {
            $canal = $conversa->channel;

            // instance_name NAO serve mais de guarda: o canal oficial tambem nasce com
            // um, gerado automaticamente, e o guarda antigo deixou passar — o job foi
            // falar com a Evolution sobre uma conversa da Meta e tomou 404 a cada
            // abertura de conversa. Quem sabe marcar e o driver do canal.
            if (! $canal) {
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

            try {
                $enviadores->para($canal)->marcarLida(
                    $canal,
                    $jid,
                    $pendentes->pluck('external_id')->all(),
                );
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
