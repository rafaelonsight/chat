<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Channel;

/**
 * Acha o canal de UMA entry do payload de Messenger/Instagram.
 *
 * Separado por entry (nao pelo payload inteiro) porque a Meta pode BATELAR varias
 * entries num unico POST — cada uma pode ser de uma Pagina/conta diferente. Resolver
 * uma vez so a partir de "entry.0" (como o controller fazia antes) atribuia ou
 * perdia em silencio as entries seguintes quando eram de outro canal. Compartilhado
 * entre o controller (resolucao "melhor esforco" da entry.0, so para o registro de
 * diagnostico) e o job (resolucao de verdade, entry por entry).
 */
trait AcharCanalMetaSocial
{
    private function acharCanalDaEntry(string $objeto, array $entry): ?Channel
    {
        if ($objeto === 'page') {
            $pageId = data_get($entry, 'messaging.0.recipient.id');

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
            // conversa), e o id da conta vem no proprio "entry.id".
            $igId = data_get($entry, 'messaging.0.recipient.id') ?: data_get($entry, 'id');

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
}
