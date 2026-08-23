<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\VerificaAssinaturaMeta;
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
    use VerificaAssinaturaMeta;

    /**
     * Verificacao da inscricao (GET).
     *
     * A Meta chama uma vez com um desafio e espera o desafio de volta em texto puro. Se
     * responder JSON, ou com o token errado, a inscricao simplesmente nao se completa e
     * nao chega mensagem nenhuma depois — falha silenciosa classica.
     */
    public function verificar(Request $request): Response
    {
        return $this->respostaDoDesafio($request);
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
