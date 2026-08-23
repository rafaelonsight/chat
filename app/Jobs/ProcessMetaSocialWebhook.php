<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\{Channel, Contact, Conversation, Message, WebhookEvent};
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transforma o evento de Messenger/Instagram em conversa e mensagem.
 *
 * Espelha o ProcessMetaWebhook (WhatsApp) na ingestao — idempotencia por external_id,
 * Conversation::abertaOuNova, broadcast — porque isso e regra do OnChat, nao do
 * provedor. Fica de fora, de proposito, o que o WhatsApp tem e Messenger/Instagram nao
 * pediram ainda (sequencias, pesquisa de satisfacao, download de midia, chatbot): o
 * pedido desta entrega foi ler e responder direct e comentario, nao replicar cada
 * automacao do canal oficial.
 */
class ProcessMetaSocialWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 20, 60];

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
    {
        $evento = WebhookEvent::withoutGlobalScopes()->find($this->webhookEventId);

        if (! $evento || $evento->processado_em) {
            return; // reentrega da Meta de algo que ja tratamos
        }

        $canal = Channel::withoutGlobalScope('tenant')->find($evento->channel_id);

        if (! $canal) {
            $evento->update(['erro' => 'canal nao existe mais', 'processado_em' => now()]);

            return;
        }

        try {
            TenantContext::runAs($canal->tenant_id, function () use ($canal, $evento) {
                foreach ((array) data_get($evento->payload, 'entry.0.messaging', []) as $item) {
                    if (data_get($item, 'message')) {
                        $this->mensagemDireta($canal, $item);
                    }
                }

                foreach ((array) data_get($evento->payload, 'entry.0.changes', []) as $mudanca) {
                    if (($mudanca['field'] ?? '') === 'comments') {
                        $this->comentario($canal, (array) data_get($mudanca, 'value', []));
                    }
                }
            });

            $evento->update(['processado_em' => now(), 'erro' => null]);
        } catch (\Throwable $e) {
            // Registra e NAO relanca: payload torto nao pode derrubar a fila em cascata.
            // O evento cru fica gravado para investigar.
            Log::warning('webhook de messenger/instagram falhou', [
                'evento' => $evento->id,
                'erro'   => $e->getMessage(),
            ]);

            $evento->update(['erro' => mb_substr($e->getMessage(), 0, 500), 'processado_em' => now()]);
        }
    }

    /** Mensagem direta (Messenger ou Instagram) - mesmo formato "messaging" nos dois. */
    private function mensagemDireta(Channel $canal, array $item): void
    {
        $mid = (string) data_get($item, 'message.mid', '');
        $de = (string) data_get($item, 'sender.id', '');
        $texto = data_get($item, 'message.text');

        if ($mid === '' || $de === '') {
            throw new \RuntimeException('mensagem sem mid ou sem remetente');
        }

        // Eco: a Meta reenvia pelo webhook a mensagem que NOS mandamos pela mesma API,
        // marcada com is_echo. Sem este corte ela nasceria de novo como entrada.
        if (data_get($item, 'message.is_echo')) {
            return;
        }

        if ($texto === null) {
            // Anexo (imagem/audio/etc) ainda nao tratado - fica registrado no evento
            // cru, e o atendente nao ve balao vazio no lugar.
            Log::info('mensagem de messenger/instagram sem texto ainda nao tratada', ['canal' => $canal->id]);

            return;
        }

        $jid = $this->jidDoContato($canal, $de);

        $mensagem = DB::transaction(function () use ($canal, $jid, $mid, $texto) {
            $contato = Contact::firstOrCreate(
                ['jid' => $jid],
                ['tenant_id' => $canal->tenant_id, 'tipo' => Contact::PESSOA, 'nome' => null],
            );

            $conversa = Conversation::abertaOuNova($canal->id, $contato->id, $canal->tenant_id);

            $mensagem = Message::updateOrCreate(
                ['channel_id' => $canal->id, 'external_id' => $mid],
                [
                    'tenant_id'       => $canal->tenant_id,
                    'conversation_id' => $conversa->id,
                    'direcao'         => 'in',
                    'remetente_jid'   => $contato->jid,
                    'tipo'            => 'text',
                    'corpo'           => (string) $texto,
                    'status'          => Message::STATUS_DELIVERED,
                    'enviada_em'      => now(),
                ],
            );

            if ($mensagem->wasRecentlyCreated) {
                $conversa->increment('nao_lidas');
                $conversa->update(['ultima_msg_em' => now(), 'ultima_entrada_em' => now()]);
            }

            return $mensagem;
        });

        if (! $mensagem->wasRecentlyCreated) {
            return; // reentrega
        }

        broadcast(new MessageStored($mensagem->refresh()));
    }

    /**
     * Comentario de post do Instagram. Nao e "origem_tipo"/"origem_id"/"origem" da
     * Conversation - esses campos ja sao usados para atribuicao de anuncio Click-to-
     * WhatsApp (ver RelatorioPorAnuncio) e reaproveitar aqui teria efeito colateral
     * nesse relatorio. tipo="comentario_ig" e external_id=comment_id bastam para
     * distinguir de uma mensagem direta sem tocar nesses campos.
     */
    private function comentario(Channel $canal, array $valor): void
    {
        $commentId = (string) ($valor['id'] ?? '');
        $de = (string) data_get($valor, 'from.id', '');
        $texto = (string) ($valor['text'] ?? '');

        if ($commentId === '' || $de === '') {
            throw new \RuntimeException('comentario sem id ou sem autor');
        }

        // O proprio comentario que NOS respondemos tambem chega por webhook, do mesmo
        // jeito que o eco de mensagem direta - sem este corte, cada resposta nossa
        // voltaria e nasceria de novo como se o cliente tivesse comentado.
        if ($de === $canal->meta_ig_account_id) {
            return;
        }

        $jid = $this->jidDoContato($canal, $de);
        $nomeAutor = data_get($valor, 'from.username');

        DB::transaction(function () use ($canal, $jid, $nomeAutor, $commentId, $texto) {
            $contato = Contact::firstOrCreate(
                ['jid' => $jid],
                ['tenant_id' => $canal->tenant_id, 'tipo' => Contact::PESSOA, 'nome' => $nomeAutor],
            );

            $conversa = Conversation::abertaOuNova($canal->id, $contato->id, $canal->tenant_id);

            $mensagem = Message::updateOrCreate(
                ['channel_id' => $canal->id, 'external_id' => $commentId],
                [
                    'tenant_id'       => $canal->tenant_id,
                    'conversation_id' => $conversa->id,
                    'direcao'         => 'in',
                    'remetente_nome'  => $nomeAutor,
                    'remetente_jid'   => $contato->jid,
                    'tipo'            => 'comentario_ig',
                    'corpo'           => $texto,
                    'status'          => Message::STATUS_DELIVERED,
                    'enviada_em'      => now(),
                ],
            );

            if (! $mensagem->wasRecentlyCreated) {
                return;
            }

            $conversa->increment('nao_lidas');
            $conversa->update(['ultima_msg_em' => now(), 'ultima_entrada_em' => now()]);

            broadcast(new MessageStored($mensagem->refresh()));
        });
    }

    /**
     * PSID/IGSID valem so dentro da Pagina/conta que os gerou - por isso o jid carrega
     * o id do canal, e nao so o id da pessoa: o mesmo cliente falando com duas Paginas
     * diferentes da MESMA empresa e, para a Meta, duas pessoas diferentes.
     */
    private function jidDoContato(Channel $canal, string $id): string
    {
        $prefixo = $canal->tipo === Channel::INSTAGRAM ? 'instagram' : 'messenger';

        return "{$prefixo}_{$canal->id}_{$id}";
    }
}
