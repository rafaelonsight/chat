<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Message;
use App\Models\MetaTemplate;
use App\Services\Canais\ConfiguracaoInvalida;
use App\Services\Canais\Enviadores;
use App\Services\Canais\FalhaDoProvedor;
use App\Services\Canais\MetaCloudEnviador;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Arr;

/**
 * Envia um template aprovado.
 *
 * Job separado do SendTextMessage de proposito, e nao um parametro dele. Os dois tem
 * regras opostas na unica coisa que mais importa: texto livre SO sai com a janela de 24h
 * aberta, e template e exatamente o que sai quando ela esta fechada. Um "if" no meio do
 * envio faria a trava do texto e a razao de existir do template morarem na mesma linha.
 */
class SendTemplateMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15, 60];

    /**
     * @param  array<int, string>  $valores  na ordem de {{1}}, {{2}}, ...
     */
    public function __construct(
        public int $messageId,
        public int $templateId,
        public array $valores = [],
    ) {}

    // Mesma serializacao por conversa do envio de texto: sem ela, duas mensagens em
    // sequencia podem chegar trocadas no aparelho do cliente.
    public function middleware(): array
    {
        $m = Message::withoutGlobalScope('tenant')->find($this->messageId);

        return [(new WithoutOverlapping('conversa:'.($m?->conversation_id ?? 0)))->releaseAfter(5)];
    }

    public function handle(Enviadores $enviadores): void
    {
        $mensagem = Message::withoutGlobalScope('tenant')->find($this->messageId);

        if (! $mensagem || $mensagem->status !== Message::STATUS_QUEUED) {
            return;
        }

        TenantContext::runAs($mensagem->tenant_id, function () use ($mensagem, $enviadores) {
            $canal = $mensagem->conversation->channel;
            $enviador = $enviadores->para($canal);

            // Template existe na API oficial e so nela. Dizer isso, em vez de chamar um
            // driver que nao tem o metodo e estourar com erro de PHP.
            if (! $enviador instanceof MetaCloudEnviador) {
                $this->encerrarComFalha($mensagem, 'Este canal nao envia template: modelo aprovado existe so na API oficial.');

                return;
            }

            $modelo = MetaTemplate::withoutGlobalScope('tenant')->find($this->templateId);

            if (! $modelo) {
                // A sincronizacao apaga template que a Meta nao lista mais. Se isso
                // acontecer entre enfileirar e enviar, o motivo tem de ser este e nao
                // um erro de banco.
                $this->encerrarComFalha($mensagem, 'O template foi removido antes do envio.');

                return;
            }

            // A janela NAO e checada aqui, ao contrario do envio de texto: template e o
            // que existe para funcionar com a janela fechada.
            try {
                $r = $enviador->template(
                    $canal,
                    $mensagem->conversation->contact->destinoWhatsApp(),
                    $modelo,
                    $this->valores,
                );

                $mensagem->update([
                    'external_id' => Arr::get($r, 'external_id'),
                    'status'      => Message::STATUS_SENT,
                    'enviada_em'  => now(),
                    'erro'        => null,
                ]);

                broadcast(new MessageStored($mensagem->refresh()));
            } catch (\Throwable $e) {
                $mensagem->update([
                    'status' => Message::STATUS_FAILED,
                    'erro'   => mb_substr($e->getMessage(), 0, 500),
                ]);

                broadcast(new MessageStored($mensagem->refresh()));

                // Relanca SO o que pode dar certo na proxima tentativa. Erro nosso de
                // montagem — template nao suportado, numero de valores errado, valor com
                // quebra de linha — nao muda por retentar: tres tentativas encheriam o
                // Horizon de erro repetido e esconderiam falha de verdade.
                // Erro de configuracao tambem relanca. O que fica em silencio e recusa do
                // provedor e escolha invalida do atendente — template sem suporte, valores
                // em quantidade errada —, porque o motivo aparece na bolha e retentar nao
                // muda nada.
                if (FalhaDoProvedor::transitoria($e) || $e instanceof ConfiguracaoInvalida) {
                    throw $e;
                }
            }
        });
    }

    private function encerrarComFalha(Message $mensagem, string $motivo): void
    {
        $mensagem->update(['status' => Message::STATUS_FAILED, 'erro' => $motivo]);

        broadcast(new MessageStored($mensagem->refresh()));
    }
}
