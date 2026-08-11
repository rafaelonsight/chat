<?php

namespace App\Exceptions;

/**
 * Tentaram apagar uma proposta que ja foi enviada ao cliente.
 *
 * Excecao propria para a tela poder mostrar a frase certa: "ja foi enviada" e uma decisao de
 * produto, e nao uma falha do sistema.
 */
class PropostaEnviadaProtegida extends \RuntimeException
{
}
