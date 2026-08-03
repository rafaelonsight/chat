<?php

namespace App\Support;

// O WhatsApp identifica todo mundo por JID. Pessoa termina em @s.whatsapp.net,
// grupo em @g.us — e grupo NAO tem telefone, por isso nao cabe no campo E.164.
class Jid
{
    public const SUFIXO_GRUPO = '@g.us';

    public const SUFIXO_PESSOA = '@s.whatsapp.net';

    public static function eGrupo(?string $jid): bool
    {
        return $jid !== null && str_ends_with(self::limpar($jid) ?? '', self::SUFIXO_GRUPO);
    }

    // Remove espaco, normaliza caixa e corta o sufixo de dispositivo (:12) que o
    // multi-dispositivo acrescenta antes do @.
    public static function limpar(?string $jid): ?string
    {
        if ($jid === null || trim($jid) === '') {
            return null;
        }

        $jid = strtolower(trim($jid));

        if (! str_contains($jid, '@')) {
            return $jid;
        }

        [$id, $dominio] = explode('@', $jid, 2);
        $id = explode(':', $id)[0];

        return $id.'@'.$dominio;
    }

    // Monta o JID de pessoa a partir de um telefone em E.164.
    public static function dePessoa(string $e164): string
    {
        return ltrim($e164, '+').self::SUFIXO_PESSOA;
    }
}
