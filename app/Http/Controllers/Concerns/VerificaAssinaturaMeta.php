<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Verificacao generica de webhook da Graph API:
 *
 * 1. Handshake da inscricao (GET): a Meta manda um desafio e espera ele de volta em
 *    texto puro. JSON ou token errado, e a inscricao simplesmente nao completa.
 * 2. Assinatura do corpo (POST): HMAC-SHA256 com o App Secret, sobre o corpo CRU —
 *    reserializar o JSON muda a ordem das chaves ou o escape de acento e a assinatura
 *    nunca casa, e o erro parece "assinatura invalida" quando o problema e nosso.
 *
 * Extraido do MetaWebhookController (WhatsApp) quando o Messenger/Instagram precisaram
 * da mesma verificacao — nao muda o comportamento de quem ja usava.
 *
 * NAO SAO TODOS O MESMO APP: WhatsApp e Messenger vivem no app principal
 * (META_APP_SECRET), mas o Instagram (login direto, "Business Login for Instagram")
 * e um app SEPARADO da Meta, com id e chave secreta proprios
 * (META_INSTAGRAM_APP_SECRET) — descoberto testando de verdade, quando um webhook do
 * Instagram nunca chegou a criar WebhookEvent nenhum porque a assinatura dele nunca
 * batia com a chave do app principal. conferirAssinatura() por isso aceita QUALQUER
 * uma das chaves configuradas, nao so a primeira.
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
        $segredos = array_filter([
            (string) config('services.meta.app_secret'),
            (string) config('services.meta.instagram_app_secret'),
        ], fn (string $s) => $s !== '');

        abort_if($segredos === [], 500, 'Nenhum App Secret da Meta configurado.');

        $cabecalho = (string) $request->header('X-Hub-Signature-256');
        $corpo = $request->getContent();

        $bate = $cabecalho !== '' && collect($segredos)->contains(
            fn (string $segredo) => hash_equals('sha256='.hash_hmac('sha256', $corpo, $segredo), $cabecalho)
        );

        abort_unless($bate, 403);
    }
}
