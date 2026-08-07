<?php

namespace App\Listeners;

use App\Models\SystemSetting;
use Illuminate\Mail\Events\MessageSent;
use Throwable;

/**
 * Anota quando o servidor de e-mail ACEITOU uma mensagem.
 *
 * A palavra "aceitou" e escolhida. MessageSent quer dizer que o SMTP respondeu 250, nao que a
 * mensagem entrou na caixa de alguem: spam, bounce e regra de destinatario acontecem depois e
 * ninguem nos avisa. Ja errei essa distincao antes com o wamid da Meta, que significa
 * "aceito", e chamei de "entregue".
 *
 * O que isto habilita e modesto e util: separar "nunca saiu nada daqui" de "sai e-mail". O
 * primeiro caso e configuracao quebrada e tem conserto; o segundo, se a pessoa nao recebeu, e
 * assunto do provedor dela.
 */
class RegistrarEmailEnviado
{
    public function handle(MessageSent $event): void
    {
        try {
            SystemSetting::gravar('email.ultimo_envio', now()->toIso8601String());
        } catch (Throwable $e) {
            // Nunca derrubar um envio por causa da anotacao dele. Se o banco estiver fora, o
            // diagnostico tem coisa mais grave para gritar do que esta.
            report($e);
        }
    }
}
