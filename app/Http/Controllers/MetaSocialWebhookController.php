<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\VerificaAssinaturaMeta;
use App\Jobs\ProcessMetaSocialWebhook;
use App\Models\Channel;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook de Messenger e Instagram (direct e comentario de post).
 *
 * Espelha o MetaWebhookController (WhatsApp) nas duas decisoes que vem da Graph API e
 * nao do produto: verificacao por assinatura (trait compartilhado) e URL UNICA para
 * todos os canais, com o canal descoberto do payload.
 *
 * O que muda e o FORMATO: aqui o objeto e "page" ou "instagram" (nao
 * "whatsapp_business_account"), e mensagem chega em "entry.0.messaging" (nao
 * "entry.0.changes.0.value.messages"). Comentario do Instagram e um terceiro formato -
 * "entry.0.changes" com field "comments" - dentro do MESMO objeto "instagram".
 */
class MetaSocialWebhookController extends Controller
{
    use VerificaAssinaturaMeta;

    public function verificar(Request $request): Response
    {
        return $this->respostaDoDesafio($request);
    }

    public function receber(Request $request): JsonResponse
    {
        $this->conferirAssinatura($request);

        $payload = $request->all();
        $canal = $this->acharCanal($payload);

        $evento = WebhookEvent::withoutGlobalScopes()->create([
            'tenant_id'   => $canal?->tenant_id,
            'channel_id'  => $canal?->id,
            'evento'      => $this->rotuloDoEvento($payload),
            'payload'     => $payload,
            'recebido_em' => now(),
        ]);

        if ($canal) {
            ProcessMetaSocialWebhook::dispatch($evento->id);
        } else {
            $evento->update([
                'erro'          => 'nenhum canal casa com o id deste evento',
                'processado_em' => now(),
            ]);
        }

        // 200 sempre: reentregar algo que nunca vamos conseguir tratar so gera
        // repeticao infinita, e a Meta desliga a inscricao depois de falhar demais.
        return response()->json(['ok' => true]);
    }

    private function acharCanal(array $payload): ?Channel
    {
        $objeto = (string) data_get($payload, 'object', '');

        if ($objeto === 'page') {
            $pageId = data_get($payload, 'entry.0.messaging.0.recipient.id');

            if (! $pageId) {
                return null;
            }

            return Channel::withoutGlobalScope('tenant')
                ->where('tipo', Channel::MESSENGER)
                ->where('meta_page_id', (string) $pageId)
                ->first();
        }

        if ($objeto === 'instagram') {
            // Mensagem direta: o id da conta dona da caixa de entrada vem em
            // recipient.id, igual ao Messenger. Comentario: nao ha "recipient" (nao e
            // conversa), e o id da conta vem no proprio "entry.0.id".
            $igId = data_get($payload, 'entry.0.messaging.0.recipient.id')
                ?: data_get($payload, 'entry.0.id');

            if (! $igId) {
                return null;
            }

            return Channel::withoutGlobalScope('tenant')
                ->where('tipo', Channel::INSTAGRAM)
                ->where('meta_ig_account_id', (string) $igId)
                ->first();
        }

        return null;
    }

    private function rotuloDoEvento(array $payload): string
    {
        $objeto = (string) data_get($payload, 'object', 'desconhecido');

        if ($objeto === 'instagram' && data_get($payload, 'entry.0.changes.0.field') === 'comments') {
            return 'instagram:comentario';
        }

        return $objeto === 'page' ? 'messenger:mensagem' : "{$objeto}:mensagem";
    }
}
