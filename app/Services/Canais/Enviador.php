<?php

namespace App\Services\Canais;

use App\Models\Channel;

/**
 * Como uma mensagem sai do OnChat.
 *
 * Existe porque o envio falava Evolution direto: o job montava
 * $evolution->sendText($canal->instance_name, ...) com o nome da instancia embutido.
 * Com um segundo canal de verdade, aquilo viraria um "if" no meio do job de envio — e
 * "if" de driver dentro de job se multiplica por seis meses ate ninguem entender mais
 * o envio.
 *
 * Cada canal sabe enviar por si. Quem chama nao pergunta o tipo.
 */
interface Enviador
{
    /**
     * Envia texto livre.
     *
     * @return array{external_id: ?string} o id da mensagem no provedor, para casar o
     *                                     recibo de entrega que volta pelo webhook
     *
     * @throws \Throwable quando o provedor recusa — quem chama decide se retenta
     */
    public function texto(Channel $canal, string $destino, string $texto): array;

    /** Nome curto para aparecer em log e em erro, sem revelar credencial. */
    public function nome(): string;
}
