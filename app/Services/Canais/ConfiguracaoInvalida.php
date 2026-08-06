<?php

namespace App\Services\Canais;

/**
 * Falta configuracao no canal para ele poder operar.
 *
 * Tipo proprio porque a decisao de RETENTAR depende de saber a diferenca entre duas
 * falhas que, olhadas de longe, parecem a mesma:
 *
 * - "a Meta recusou este pedido" (4xx): definitivo para esta mensagem. Retentar tres vezes
 *   da tres erros identicos no Horizon e esconde falha de verdade no meio.
 * - "este canal nao tem Phone Number ID": defeito de configuracao. Tem de continuar
 *   aparecendo alto, porque alguem precisa consertar — silenciar junto com a recusa do
 *   provedor esconderia justamente o que tem conserto.
 *
 * Estende RuntimeException para nao quebrar quem ja trata a falha de forma generica.
 */
class ConfiguracaoInvalida extends \RuntimeException
{
}
