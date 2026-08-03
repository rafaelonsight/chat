<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Message;
use App\Services\EvolutionService;
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

    public function handle(EvolutionService $evolution): void
    {
        $mensagem = Message::withoutGlobalScope('tenant')->find($this->messageId);

        if (! $mensagem || $mensagem->status !== Message::STATUS_QUEUED) {
            return;
        }

        TenantContext::runAs($mensagem->tenant_id, function () use ($mensagem, $evolution) {
            $canal = $mensagem->conversation->channel;
            $destino = $mensagem->conversation->contact->destinoWhatsApp();

            try {
                $r = $evolution->sendText($canal->instance_name, $destino, (string) $mensagem->corpo);

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

                throw $e; // deixa o Horizon registrar e reagendar
            } finally {
                broadcast(new MessageStored($mensagem->refresh()));
            }
        });
    }
}
