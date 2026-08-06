<?php

namespace App\Services\Canais;

use Illuminate\Http\Client\RequestException;

/**
 * Vale a pena tentar de novo?
 *
 * Existe porque os jobs de envio relancavam QUALQUER falha, e o Horizon retentava tres
 * vezes coisas que nao mudam por repeticao: "empresa restrita neste pais", "destinatario
 * fora da lista permitida", "template nao existe". O resultado eram tres erros identicos
 * no lugar de um — e ruido repetido esconde a falha de verdade que aparece no meio.
 *
 * A REGRA E PELA FAIXA DO HTTP, e nao por uma lista de codigos da Meta. Lista de codigos
 * envelhece em silencio: a Meta cria codigo novo, ele nao esta na lista, e o
 * comportamento muda sem ninguem perceber. Faixa nao envelhece:
 *
 * - 5xx: o provedor esta fora do ar ou instavel. Tentar de novo e exatamente o certo.
 * - 429 e os codigos de limite de taxa: passou do ritmo, esperar resolve.
 * - resto do 4xx: a Meta esta dizendo que o PEDIDO esta errado. Repetir o mesmo pedido
 *   errado tres vezes nao o torna certo.
 *
 * E falha nossa de montagem — template sem suporte, numero de valores errado — nao e nem
 * RequestException: nao chega a sair daqui.
 */
class FalhaDoProvedor
{
    /**
     * Limite de taxa: 4 e 80007 sao do app, 130429 e 131048 e 131056 sao do WhatsApp.
     *
     * Esta lista pode ficar incompleta sem prejuizo: o que ela nao pegar cai na regra do
     * 4xx e simplesmente nao retenta. Errar para o lado de nao repetir e o lado seguro.
     */
    private const LIMITE_DE_TAXA = [4, 80007, 130429, 131048, 131056];

    public static function transitoria(\Throwable $e): bool
    {
        if (! $e instanceof RequestException || ! $e->response) {
            return false;
        }

        $status = $e->response->status();

        if ($status >= 500 || $status === 429) {
            return true;
        }

        $codigo = (int) data_get($e->response->json(), 'error.code', 0);

        return in_array($codigo, self::LIMITE_DE_TAXA, true);
    }
}
