<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\{Channel, Contact, Conversation, Message, WebhookEvent};
use App\Services\EvolutionService;
use App\Support\{PhoneNumber, TenantContext};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProcessEvolutionWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15, 60];

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
    {
        $evento = WebhookEvent::find($this->webhookEventId);

        if (! $evento || $evento->processado_em) {
            return;
        }

        $canal = Channel::withoutGlobalScope('tenant')->find($evento->channel_id);

        if (! $canal) {
            $evento->update(['processado_em' => now(), 'erro' => 'canal inexistente']);

            return;
        }

        TenantContext::runAs($canal->tenant_id, function () use ($evento, $canal) {
            try {
                match (strtolower((string) $evento->evento)) {
                    'messages.upsert'   => $this->mensagemRecebida($canal, $evento->payload),
                    'messages.update'   => $this->statusAtualizado($canal, $evento->payload),
                    'connection.update' => $this->conexaoAtualizada($canal, $evento->payload),
                    default             => null,
                };
                $evento->update(['processado_em' => now(), 'erro' => null]);
            } catch (\Throwable $e) {
                // Payload inesperado nao pode derrubar a fila: registra e segue.
                // O evento fica no log para reprocessamento manual.
                $evento->update(['processado_em' => now(), 'erro' => $e->getMessage()]);
            }
        });
    }

    private function mensagemRecebida(Channel $canal, array $payload): void
    {
        $data = Arr::get($payload, 'data', []);

        if (Arr::get($data, 'key.fromMe')) {
            return; // eco do que nos mesmos enviamos
        }

        $telefone = PhoneNumber::toE164(Arr::get($data, 'key.remoteJid'));
        $externalId = Arr::get($data, 'key.id');

        if (! $telefone || ! $externalId) {
            throw new \RuntimeException('remetente ou id ausente no payload');
        }

        $texto = Arr::get($data, 'message.conversation')
            ?? Arr::get($data, 'message.extendedTextMessage.text');

        if ($texto === null) {
            return; // nesta fatia so tratamos texto
        }

        DB::transaction(function () use ($canal, $telefone, $externalId, $texto, $data) {
            $contato = Contact::firstOrCreate(
                ['tenant_id' => $canal->tenant_id, 'telefone_e164' => $telefone],
                ['nome' => Arr::get($data, 'pushName')],
            );

            $conversa = Conversation::firstOrCreate(
                ['channel_id' => $canal->id, 'contact_id' => $contato->id],
                ['tenant_id' => $canal->tenant_id],
            );

            $mensagem = Message::updateOrCreate(
                ['channel_id' => $canal->id, 'external_id' => $externalId],
                [
                    'tenant_id'       => $canal->tenant_id,
                    'conversation_id' => $conversa->id,
                    'direcao'         => 'in',
                    'tipo'            => 'text',
                    'corpo'           => $texto,
                    'status'          => Message::STATUS_DELIVERED,
                    'enviada_em'      => now(),
                ],
            );

            if ($mensagem->wasRecentlyCreated) {
                $conversa->increment('nao_lidas');
                $conversa->update(['ultima_msg_em' => now()]);
                broadcast(new MessageStored($mensagem));
            }
        });
    }

    private function statusAtualizado(Channel $canal, array $payload): void
    {
        $externalId = Arr::get($payload, 'data.keyId') ?? Arr::get($payload, 'data.key.id');

        if (! $externalId) {
            return;
        }

        $novo = match (strtoupper((string) Arr::get($payload, 'data.status'))) {
            'DELIVERY_ACK', 'DELIVERED' => Message::STATUS_DELIVERED,
            'READ', 'PLAYED'            => Message::STATUS_READ,
            'SERVER_ACK', 'SENT'        => Message::STATUS_SENT,
            'ERROR'                     => Message::STATUS_FAILED,
            default                     => null,
        };

        if (! $novo) {
            return;
        }

        $mensagem = Message::where('channel_id', $canal->id)
            ->where('external_id', $externalId)
            ->first();

        if ($mensagem) {
            $mensagem->update(['status' => $novo]);
            broadcast(new MessageStored($mensagem));
        }
    }

    private function conexaoAtualizada(Channel $canal, array $payload): void
    {
        $estado = Arr::get($payload, 'data.state') ?? Arr::get($payload, 'data.connection');

        $canal->forceFill([
            'status'       => $estado ?: 'desconhecido',
            'conectado_em' => $estado === 'open' ? now() : $canal->conectado_em,
        ])->saveQuietly();

        // O payload de connection.update nao traz o numero. Quando a conexao
        // abre, perguntamos a Evolution qual e — para o painel mostrar de qual
        // chip cada canal se trata.
        if ($estado === 'open' && ! $canal->telefone_e164) {
            try {
                $info = app(EvolutionService::class)->instanceInfo($canal->instance_name);
                $jid = data_get(collect($info)->first(), 'ownerJid')
                    ?? data_get(collect($info)->first(), 'instance.owner');

                if ($telefone = PhoneNumber::toE164($jid)) {
                    $canal->forceFill(['telefone_e164' => $telefone])->saveQuietly();
                }
            } catch (\Throwable $e) {
                // numero e informativo: nao vale derrubar o processamento
            }
        }
    }
}
