<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Message;
use App\Services\Canais\Enviadores;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Apaga no WhatsApp uma mensagem que ja foi enviada. */
class DeleteMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15, 60];

    public function __construct(public int $messageId) {}

    public function handle(Enviadores $enviadores): void
    {
        $mensagem = Message::withoutGlobalScope('tenant')->find($this->messageId);

        if (! $mensagem || ! $mensagem->external_id) {
            return;
        }

        TenantContext::runAs($mensagem->tenant_id, function () use ($mensagem, $enviadores) {
            $canal = $mensagem->conversation->channel;
            $destino = $mensagem->conversation->contact->destinoWhatsApp();

            try {
                $enviadores->para($canal)->apagar(
                    $canal,
                    $destino,
                    ['external_id' => (string) $mensagem->external_id, 'minha' => ! $mensagem->entrada()],
                );
            } catch (\Throwable $e) {
                // O balao ja sumiu da tela. Se o provedor recusou — passou do prazo dele, em
                // geral — a mensagem VOLTA. Sumir aqui e continuar no aparelho do cliente e a
                // pior das saidas: o atendente iria embora achando que resolveu.
                $mensagem->update(['apagada_em' => null]);
                broadcast(new MessageStored($mensagem->refresh()));

                if (\App\Services\Canais\FalhaDoProvedor::transitoria($e)
                    || $e instanceof \App\Services\Canais\ConfiguracaoInvalida) {
                    throw $e;
                }

                return;
            }

            broadcast(new MessageStored($mensagem->refresh()));
        });
    }
}
