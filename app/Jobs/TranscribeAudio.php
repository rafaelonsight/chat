<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Message;
use App\Services\TranscriptionService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class TranscribeAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [30, 120];

    public function __construct(public int $messageId) {}

    // Uma transcricao por vez em toda a instalacao. O servidor whisper ja limita
    // as threads, mas sem isto varios workers ficariam bloqueados esperando HTTP
    // enquanto o resto da fila para.
    public function middleware(): array
    {
        return [(new WithoutOverlapping('transcricao'))->releaseAfter(20)->expireAfter(600)];
    }

    public function handle(TranscriptionService $transcricao): void
    {
        $mensagem = Message::withoutGlobalScope('tenant')->find($this->messageId);

        if (! $mensagem || $mensagem->tipo !== 'audio' || ! $mensagem->media_path) {
            return;
        }

        if ($mensagem->transcricao_status === 'pronta') {
            return;
        }

        TenantContext::runAs($mensagem->tenant_id, function () use ($mensagem, $transcricao) {
            $r = $transcricao->transcrever($mensagem->media_path);

            $mensagem->update([
                'transcricao'        => $r['texto'],
                'transcricao_status' => $r['status'],
            ]);

            // A tela mostra a transcricao embaixo do player: precisa saber que
            // chegou, senao o atendente so ve depois de recarregar.
            if ($r['status'] === 'pronta') {
                broadcast(new MessageStored($mensagem->refresh()));
            }
        });
    }
}
