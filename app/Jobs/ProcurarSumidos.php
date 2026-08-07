<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Sequence;
use App\Services\Cadenciador;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Quem sumiu depois de a gente responder.
 *
 * O gatilho "sem resposta" nao tem um evento proprio — ninguem dispara "o cliente ficou
 * quieto". So da para descobrir varrendo, e por isso este job existe separado do tique.
 *
 * A CONDICAO E MAIS ESTREITA DO QUE PARECE, e cada pedaco dela evita um constrangimento:
 *
 * - conversa ABERTA: encerrada tem a jornada de pos-atendimento, e as duas juntas seriam duas
 *   mensagens dizendo coisas diferentes no mesmo dia;
 * - a ultima mensagem tem de ser NOSSA: se a ultima foi dele, quem esta devendo resposta somos
 *   nos, e cobrar o cliente nesse caso e ofensivo;
 * - passou o tempo configurado desde ela.
 */
class ProcurarSumidos implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public const LOTE = 200;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sumidos'))->dontRelease()];
    }

    public function handle(Cadenciador $cadenciador): void
    {
        $sequencias = Sequence::withoutGlobalScope('tenant')
            ->where('gatilho', Sequence::SEM_RESPOSTA)
            ->where('ativa', true)
            ->get();

        foreach ($sequencias as $sequencia) {
            TenantContext::runAs($sequencia->tenant_id, function () use ($sequencia, $cadenciador) {
                $limite = now()->subHours(max(1, $sequencia->sem_resposta_horas));

                Conversation::query()
                    ->where('channel_id', $sequencia->channel_id)
                    ->where('status', '!=', Conversation::ARQUIVADA)
                    ->where('ultima_msg_em', '<=', $limite)
                    ->with(['contact', 'ultimaMensagem'])
                    ->limit(self::LOTE)
                    ->get()
                    ->each(function (Conversation $conversa) use ($sequencia, $cadenciador) {
                        $ultima = $conversa->ultimaMensagem;

                        // Sem mensagem, ou a ultima foi do cliente: nao e caso de cobrar.
                        if (! $ultima || $ultima->entrada()) {
                            return;
                        }

                        $cadenciador->inscrever(Sequence::SEM_RESPOSTA, $conversa->contact, $conversa);
                    });
            });
        }
    }
}
