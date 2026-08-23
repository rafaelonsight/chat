<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Verificacao generica de webhook da Graph API (WhatsApp, Messenger e Instagram usam a
 * mesma mecanica, porque os tres sao produtos do MESMO app da Meta):
 *
 * 1. Handshake da inscricao (GET): a Meta manda um desafio e espera ele de volta em
 *    texto puro. JSON ou token errado, e a inscricao simplesmente nao completa.
 * 2. Assinatura do corpo (POST): HMAC-SHA256 com o App Secret, sobre o corpo CRU —
 *    reserializar o JSON muda a ordem das chaves ou o escape de acento e a assinatura
 *    nunca casa, e o erro parece "assinatura invalida" quando o problema e nosso.
 *
 * Extraido do MetaWebhookController (WhatsApp) quando o Messenger/Instagram precisaram
 * da mesma verificacao — nao muda o comportamento de quem ja usava.
 */
trait VerificaAssinaturaMeta
{
    private function respostaDoDesafio(Request $request): Response
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

    private function conferirAssinatura(Request $request): void
    {
        $segredo = (string) config('services.meta.app_secret');

        abort_if($segredo === '', 500, 'META_APP_SECRET nao configurado.');

        $cabecalho = (string) $request->header('X-Hub-Signature-256');
        $nosso = 'sha256='.hash_hmac('sha256', $request->getContent(), $segredo);

        abort_unless($cabecalho !== '' && hash_equals($nosso, $cabecalho), 403);
    }
}
