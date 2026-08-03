<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Message;
use App\Services\EvolutionService;
use App\Services\MediaService;
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

    public function handle(EvolutionService $evolution, MediaService $media): void
    {
        $mensagem = Message::withoutGlobalScope('tenant')->find($this->messageId);

        if (! $mensagem || $mensagem->status !== Message::STATUS_QUEUED) {
            return;
        }

        TenantContext::runAs($mensagem->tenant_id, function () use ($mensagem, $evolution) {
            $canal = $mensagem->conversation->channel;
            $destino = $mensagem->conversation->contact->telefone_e164;

            try {
                if (! $mensagem->media_path || ! Storage::disk('local')->exists($mensagem->media_path)) {
                    throw new \RuntimeException('arquivo da mensagem nao encontrado no disco');
                }

                $base64 = base64_encode((string) Storage::disk('local')->get($mensagem->media_path));

                // Audio vai por endpoint proprio: no sendMedia ele chega como
                // arquivo anexado, sem onda nem play.
                $r = $mensagem->tipo === 'audio'
                    ? $evolution->sendAudio($canal->instance_name, $destino, $base64)
                    : $evolution->sendMedia(
                        $canal->instance_name,
                        $destino,
                        $mensagem->tipo === 'sticker' ? 'image' : $mensagem->tipo,
                        $base64,
                        $mensagem->legenda,
                        $mensagem->media_nome,
                        $mensagem->media_mime,
                    );

                $mensagem->update([
                    'external_id' => Arr::get($r, 'key.id'),
                    'status'      => Message::STATUS_SENT,
                    'enviada_em'  => now(),
                    'erro'        => null,
                ]);
            } catch (\Throwable $e) {
                $mensagem->update([
                    'status' => Message::STATUS_FAILED,
                    'erro'   => mb_substr($e->getMessage(), 0, 500),
                ]);

                throw $e;
            } finally {
                broadcast(new MessageStored($mensagem->refresh()));
            }
        });
    }
}
