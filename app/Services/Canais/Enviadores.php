<?php

namespace App\Services\Canais;

use App\Models\Channel;

/**
 * Escolhe o enviador do canal.
 *
 * Um lugar so faz a escolha. Se amanha entrar Messenger ou Instagram, o "match" cresce
 * aqui e nenhum job muda — era esse o objetivo de tirar a Evolution de dentro do envio.
 *
 * Tipo desconhecido ESTOURA em vez de cair num padrao silencioso: canal com tipo que
 * ninguem implementou nao pode "quase funcionar" mandando pelo driver errado.
 */
class Enviadores
{
    public function para(Channel $canal): Enviador
    {
        return match ($canal->tipo) {
            Channel::EVOLUTION  => app(EvolutionEnviador::class),
            Channel::META_CLOUD => app(MetaCloudEnviador::class),
            default             => throw new ConfiguracaoInvalida(
                "Canal \"{$canal->nome}\" tem tipo \"{$canal->tipo}\", que nao sabe enviar."
            ),
        };
    }
}
