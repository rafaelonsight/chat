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
     *
     * O $citar chega como dados soltos, e nao como um Message, pela mesma razao de midia()
     * receber BYTES em vez de caminho: o driver nao conhece o banco do OnChat. Cada provedor
     * usa o que precisa — a Meta so quer o id; o Baileys quer id, autoria e o texto citado,
     * porque desenha a previa com o que recebe.
     *
     * @param  array{external_id: string, texto: ?string, minha: bool}|null  $citar
     */
    public function texto(Channel $canal, string $destino, string $texto, ?array $citar = null): array;

    /**
     * Envia arquivo.
     *
     * Recebe BYTES e nao caminho: o driver nao precisa saber onde o OnChat guarda arquivo,
     * e no dia em que o disco virar S3 nada aqui muda. Quem le do disco e o job.
     *
     * @param  array{tipo: string, bytes: string, mime: ?string, nome: ?string, legenda: ?string}  $arquivo
     * @return array{external_id: ?string}
     *
     * @throws \Throwable quando o provedor recusa — quem chama decide se retenta
     *
     * @param  array{external_id: string, texto: ?string, minha: bool}|null  $citar
     */
    public function midia(Channel $canal, string $destino, array $arquivo, ?array $citar = null): array;

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

    /**
     * Este numero tem WhatsApp?
     *
     * Devolve existe = null quando o provedor NAO SABE responder — e o caso da API oficial,
     * que nao tem consulta equivalente. null nao significa "nao tem": significa "nao da para
     * perguntar". Quem chama tem de tratar diferente, porque barrar o atendente por uma
     * pergunta que ninguem respondeu seria inventar impedimento.
     *
     * O e164 devolvido e a forma canonica que o provedor conhece, quando ele informa: e nela
     * que o contato deve ser gravado, para nao nascer duplicado.
     *
     * @return array{existe: ?bool, e164: ?string}
     */
    public function verificarNumero(Channel $canal, string $e164): array;

    /**
     * Reage a uma mensagem com um emoji.
     *
     * $emoji VAZIO quer dizer tirar a reacao. Nao e um caso especial inventado aqui: e assim
     * que os dois provedores entendem, e assim que o WhatsApp mostra para o cliente.
     *
     * @param  array{external_id: string, minha: bool}  $alvo  qual mensagem recebe a reacao
     *
     * @throws \Throwable quando o provedor recusa
     */
    public function reagir(Channel $canal, string $destino, array $alvo, string $emoji): void;

    /**
     * Este canal consegue apagar uma mensagem ja enviada?
     *
     * A resposta e NAO para a API oficial da Meta, que simplesmente nao tem essa operacao. Nao
     * e limitacao nossa nem coisa que da para contornar: nao existe endpoint. Por isso a
     * pergunta existe — para a tela nao oferecer um botao que nunca vai funcionar. Oferecer e
     * falhar depois seria pior do que nao oferecer, porque a pessoa ja teria contado com isso.
     */
    public function podeApagar(): bool;

    /**
     * Apaga uma mensagem para todo mundo, inclusive no aparelho do cliente.
     *
     * @param  array{external_id: string, minha: bool}  $alvo
     *
     * @throws \Throwable quando o provedor recusa — passou do prazo dele, por exemplo
     */
    public function apagar(Channel $canal, string $destino, array $alvo): void;

    /**
     * Este canal consegue mostrar "digitando…" para o cliente?
     *
     * NAO para a API oficial. A Meta so mostra o "digitando" junto do recibo de leitura, preso
     * a um wamid especifico e por no maximo 25 segundos — nao e um estado que da para ligar e
     * desligar enquanto a pessoa escreve. Fingir que da faria o indicador aparecer na hora
     * errada, que e pior que nao aparecer.
     */
    public function podeDigitando(): bool;

    /**
     * Liga ou desliga o "digitando…" no aparelho do cliente.
     *
     * NUNCA lanca. Indicador de digitacao e enfeite: se o provedor recusar, o atendente nao
     * pode ver erro nenhum por causa disso — ele esta no meio de uma frase.
     */
    public function digitando(Channel $canal, string $destino, bool $ativo): void;

    /** Nome curto para aparecer em log e em erro, sem revelar credencial. */
    public function nome(): string;
}
