<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Message;
use App\Services\Canais\Enviadores;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Manda ao WhatsApp a reacao que o atendente escolheu.
 *
 * $emoji vazio quer dizer TIRAR a reacao — e assim que os dois provedores tratam, e assim que
 * o WhatsApp mostra para o cliente.
 */
class SendReaction implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15, 60];

    public function __construct(public int $messageId, public string $emoji) {}

    public function handle(Enviadores $enviadores): void
    {
        $mensagem = Message::withoutGlobalScope('tenant')->find($this->messageId);

        // Sem external_id nao ha o que reagir do lado de la: a mensagem existe so aqui.
        if (! $mensagem || ! $mensagem->external_id) {
            return;
        }

        TenantContext::runAs($mensagem->tenant_id, function () use ($mensagem, $enviadores) {
            $canal = $mensagem->conversation->channel;
            $destino = $mensagem->conversation->contact->destinoWhatsApp();

            try {
                $enviadores->para($canal)->reagir(
                    $canal,
                    $destino,
                    ['external_id' => (string) $mensagem->external_id, 'minha' => ! $mensagem->entrada()],
                    $this->emoji,
                );
            } catch (\Throwable $e) {
                // A tela ja mostrou a reacao antes de o job rodar, para o clique ser
                // instantaneo. Se o envio nao foi, DESFAZ: reacao que aparece aqui e nao
                // existe no aparelho do cliente e uma mentira pequena que ninguem descobre.
                $mensagem->update(['reacao_nossa' => null]);
                broadcast(new MessageStored($mensagem->refresh()));

                if (\App\Services\Canais\FalhaDoProvedor::transitoria($e)
                    || $e instanceof \App\Services\Canais\ConfiguracaoInvalida) {
                    throw $e;
                }
            }
        });
    }
}
