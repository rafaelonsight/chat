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

    /**
     * Avisa o provedor de que o atendente leu as mensagens do cliente.
     *
     * Mora aqui, e nao no job, pela mesma razao do envio: era um "if" de driver
     * esperando para nascer. E nasceu — o job checava instance_name como se isso
     * quisesse dizer "e Evolution", e quando o canal oficial apareceu com um
     * instance_name gerado automaticamente ele foi chamar a Evolution e tomou 404.
     *
     * @param  array<int, string>  $externalIds  ids das mensagens do cliente, da mais
     *                                           antiga para a mais nova
     *
     * @throws \Throwable quando o provedor recusa — quem chama decide se retenta
     */
    public function marcarLida(Channel $canal, string $jid, array $externalIds): void;

    /** Nome curto para aparecer em log e em erro, sem revelar credencial. */
    public function nome(): string;
}
