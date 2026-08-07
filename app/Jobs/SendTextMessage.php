<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Message;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Arr;

class SendTextMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15, 60];

    public function __construct(public int $messageId) {}

    // Sem serializar por conversa, duas mensagens enviadas em sequencia podem
    // chegar trocadas no aparelho do cliente.
    public function middleware(): array
    {
        $m = Message::withoutGlobalScope('tenant')->find($this->messageId);

        return [(new WithoutOverlapping('conversa:'.($m?->conversation_id ?? 0)))->releaseAfter(5)];
    }

    public function handle(\App\Services\Canais\Enviadores $enviadores): void
    {
        $mensagem = Message::withoutGlobalScope('tenant')->find($this->messageId);

        if (! $mensagem || $mensagem->status !== Message::STATUS_QUEUED) {
            return;
        }

        TenantContext::runAs($mensagem->tenant_id, function () use ($mensagem, $enviadores) {
            $canal = $mensagem->conversation->channel;

            // Fora da janela de 24h a API oficial RECUSA texto livre. Barrar aqui e
            // nao so na tela porque a mensagem pode ter sido enfileirada com a janela
            // aberta e chegar a vez dela depois de fechada — fila nao anda instantanea.
            if (! $mensagem->conversation->podeEnviarLivre()) {
                $mensagem->update([
                    'status' => Message::STATUS_FAILED,
                    'erro'   => 'Janela de 24 horas fechada: neste canal, fora dela só sai template aprovado.',
                ]);

                broadcast(new MessageStored($mensagem->refresh()));

                // Sem relancar de proposito: nao e falha transitoria. Retentar tres
                // vezes uma janela fechada so enche o Horizon de erro repetido.
                return;
            }
            $destino = $mensagem->conversation->contact->destinoWhatsApp();

            try {
                // Quem envia e o canal, nao o job. Trocar de provedor deixou de ser
                // mexer aqui dentro.
                // paraCitacao devolve null quando a mensagem citada nao tem external_id
                // (nao saiu, falhou, ou e nota interna). Ai a resposta vai sem citacao em vez
                // de nao ir: o vinculo continua registrado aqui dentro.
                $citar = $mensagem->respondeA?->paraCitacao();

                $r = $enviadores->para($canal)->texto($canal, $destino, (string) $mensagem->corpo, $citar);

                $mensagem->update([
                    'external_id' => Arr::get($r, 'external_id'),
                    'status'      => Message::STATUS_SENT,
                    'enviada_em'  => now(),
                    'erro'        => null,
                ]);
            } catch (\Throwable $e) {
                $mensagem->update([
                    'status' => Message::STATUS_FAILED,
                    'erro'   => mb_substr($e->getMessage(), 0, 500),
                ]);

                // Relanca duas coisas, por razoes opostas:
                //
                // 1. o que pode dar certo depois (provedor fora do ar, limite de taxa);
                // 2. erro de CONFIGURACAO, que nao vai dar certo nunca mas precisa
                //    aparecer alto no Horizon, porque alguem tem de consertar.
                //
                // Fica em silencio o meio: recusa definitiva do provedor. "Empresa
                // restrita neste pais" nao muda por retentar, e tres tentativas dariam
                // tres erros identicos escondendo falha de verdade no meio. O motivo ja
                // esta na bolha da conversa, que e onde o atendente olha.
                if (\App\Services\Canais\FalhaDoProvedor::transitoria($e)
                    || $e instanceof \App\Services\Canais\ConfiguracaoInvalida) {
                    throw $e; // deixa o Horizon registrar e reagendar
                }
            } finally {
                broadcast(new MessageStored($mensagem->refresh()));
            }
        });
    }
}
