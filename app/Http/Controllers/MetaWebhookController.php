<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaWebhook;
use App\Models\Channel;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook do WhatsApp oficial.
 *
 * Diferente do da Evolution em dois pontos que mudam o desenho:
 *
 * 1. A URL e UMA para todos os canais. A Evolution recebe o id do canal na propria URL;
 *    a Meta chama sempre o mesmo endereco e diz de qual numero se trata no corpo
 *    (metadata.phone_number_id). Entao o canal e descoberto do payload, nao da rota.
 * 2. A autenticidade vem de ASSINATURA, nao de segredo na URL. A Meta assina o corpo com
 *    o App Secret (X-Hub-Signature-256). Isso e melhor: segredo em URL aparece em log de
 *    servidor, em histórico de proxy e em print de tela — e o nosso ja apareceu.
 */
class MetaWebhookController extends Controller
{
    /**
     * Verificacao da inscricao (GET).
     *
     * A Meta chama uma vez com um desafio e espera o desafio de volta em texto puro. Se
     * responder JSON, ou com o token errado, a inscricao simplesmente nao se completa e
     * nao chega mensagem nenhuma depois — falha silenciosa classica.
     */
    public function verificar(Request $request): Response
    {
        $esperado = (string) config('services.meta.verify_token');
        $recebido = (string) $request->query('hub_verify_token');

        abort_if($esperado === '', 500, 'META_VERIFY_TOKEN nao configurado.');

        // hash_equals: comparacao de tempo constante. Com == daria para descobrir o
        // token caractere por caractere medindo o tempo de resposta.
        abort_unless(
            $request->query('hub_mode') === 'subscribe' && hash_equals($esperado, $recebido),
            403,
        );

        return response((string) $request->query('hub_challenge'), 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Evento (POST). Grava cru e devolve 200 na hora.
     *
     * Processar dentro do request faria a Meta tomar timeout e reentregar em cascata —
     * e ela desabilita a inscricao depois de muitas falhas.
     */
    public function receber(Request $request): JsonResponse
    {
        $this->conferirAssinatura($request);

        $payload = $request->all();

        // O canal sai do payload. Se nao achar, ainda assim gravamos o evento: sem
        // registro, "a Meta mandou e nada aconteceu" nao tem como ser investigado.
        $canal = $this->acharCanal($payload);

        $evento = WebhookEvent::withoutGlobalScopes()->create([
            'tenant_id'   => $canal?->tenant_id,
            'channel_id'  => $canal?->id,
            'evento'      => 'meta:'.($this->campo($payload) ?? 'desconhecido'),
            'payload'     => $payload,
            'recebido_em' => now(),
        ]);

        if ($canal) {
            ProcessMetaWebhook::dispatch($evento->id);
        } else {
            $evento->update([
                'erro' => 'nenhum canal com este phone_number_id',
                // processado_em junto, mesmo sem ter processado nada: nao existe job
                // pendente para este evento e nunca vai existir. Sem isto o diagnostico
                // conta "processado_em nulo" como webhook parado e grita CRITICO a cada
                // 5 minutos por algo que esta certo — e monitor que chora falso ensina
                // todo mundo a ignorar monitor. O motivo fica gravado em erro; o que muda
                // e so deixar de se passar por pendente.
                'processado_em' => now(),
            ]);
        }

        // 200 sempre, inclusive quando nao achamos o canal: a Meta reentrega em erro, e
        // reentregar algo que nunca vamos conseguir tratar so gera repeticao infinita.
        return response()->json(['ok' => true]);
    }

    /**
     * A Meta assina o CORPO CRU com o App Secret.
     *
     * Tem de ser o corpo cru, byte a byte: se reserializar o JSON, a ordem das chaves ou
     * o escape de acento muda e a assinatura nunca casa — e o erro parece "assinatura
     * invalida" quando o problema e nosso.
     */
    private function conferirAssinatura(Request $request): void
    {
        $segredo = (string) config('services.meta.app_secret');

        abort_if($segredo === '', 500, 'META_APP_SECRET nao configurado.');

        $cabecalho = (string) $request->header('X-Hub-Signature-256');
        $nosso = 'sha256='.hash_hmac('sha256', $request->getContent(), $segredo);

        abort_unless($cabecalho !== '' && hash_equals($nosso, $cabecalho), 403);
    }

    private function acharCanal(array $payload): ?Channel
    {
        $id = data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');

        if (! $id) {
            return null;
        }

        return Channel::withoutGlobalScope('tenant')
            ->where('meta_phone_number_id', (string) $id)
            ->first();
    }

    private function campo(array $payload): ?string
    {
        return data_get($payload, 'entry.0.changes.0.field');
    }
}
