<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Message;
use App\Services\Canais\Enviadores;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class SendMediaMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 120];

    public function __construct(public int $messageId) {}

    public function middleware(): array
    {
        $m = Message::withoutGlobalScope('tenant')->find($this->messageId);

        return [(new WithoutOverlapping('conversa:'.($m?->conversation_id ?? 0)))->releaseAfter(10)];
    }

    public function handle(Enviadores $enviadores): void
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
                if (! $mensagem->media_path || ! Storage::disk('local')->exists($mensagem->media_path)) {
                    throw new \RuntimeException('arquivo da mensagem nao encontrado no disco');
                }

                // Quem envia e o canal, nao o job: o "if" de audio e o formato do
                // payload passaram a viver no driver de cada provedor, porque na Meta
                // sao duas chamadas e na Evolution e uma.
                $r = $enviadores->para($canal)->midia($canal, $destino, [
                    'tipo'    => (string) $mensagem->tipo,
                    'bytes'   => (string) Storage::disk('local')->get($mensagem->media_path),
                    'mime'    => $mensagem->media_mime,
                    'nome'    => $mensagem->media_nome,
                    'legenda' => $mensagem->legenda,
                ], $mensagem->respondeA?->paraCitacao());

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

                // Mesma regra do envio de texto: relanca so o que pode dar certo depois.
                // 4xx do provedor e pedido errado, e repetir pedido errado tres vezes nao
                // o torna certo — so multiplica o erro no Horizon.
                if (\App\Services\Canais\FalhaDoProvedor::transitoria($e)
                    || $e instanceof \App\Services\Canais\ConfiguracaoInvalida) {
                    throw $e;
                }
            } finally {
                broadcast(new MessageStored($mensagem->refresh()));
            }
        });
    }
}
