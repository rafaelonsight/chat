<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcharCanalMetaSocial;
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
 * "whatsapp_business_account"), e mensagem chega em "entry.N.messaging" (nao
 * "entry.0.changes.0.value.messages"). Comentario do Instagram e um terceiro formato -
 * "entry.N.changes" com field "comments" - dentro do MESMO objeto "instagram".
 *
 * O canal aqui e so "melhor esforco" pela entry.0, para o registro de diagnostico
 * (WebhookEvent so tem UMA coluna channel_id). Quando a Meta batela varias entries de
 * canais diferentes no mesmo POST, quem resolve CADA UMA de verdade e o job
 * (ProcessMetaSocialWebhook), nao este controller.
 */
class MetaSocialWebhookController extends Controller
{
    use AcharCanalMetaSocial;
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
        return $this->acharCanalDaEntry(
            (string) data_get($payload, 'object', ''),
            (array) data_get($payload, 'entry.0', []),
        );
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
