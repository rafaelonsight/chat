<?php

namespace App\Services\Canais;

use App\Models\Channel;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * O "envio" para o chat do site.
 *
 * AQUI NAO SE ENVIA NADA, e essa e a diferenca de fundo entre este canal e os outros. No
 * WhatsApp o texto precisa viajar ate um provedor; no site, a mensagem ja esta gravada na
 * conversa e o navegador do visitante vem buscar. O trabalho deste arquivo e devolver um id e
 * dizer "saiu" — e e justamente por existir que o resto do sistema nao precisa saber disso.
 *
 * O JOB DE ENVIO NAO MUDOU UMA LINHA. Era esse o objetivo de ter tirado a Evolution de dentro
 * dele: quando entrou um canal que nem provedor tem, coube.
 */
class SiteEnviador implements Enviador
{
    public function texto(Channel $canal, string $destino, string $texto, ?array $citar = null): array
    {
        // Um id nosso, no mesmo formato dos outros: o resto do sistema casa recibo por ele.
        return ['external_id' => 'site_'.Str::lower(Str::random(20))];
    }

    /**
     * Arquivo ainda nao.
     *
     * O arquivo vive numa rota que exige login, e o visitante do site nao tem conta. Servir a
     * midia para ele exigiria um endereco publico e assinado — trabalho proprio, e nao um
     * detalhe deste arquivo. Estourar aqui faz a mensagem aparecer FALHADA para o atendente,
     * com o motivo escrito, em vez de sumir e ele achar que o cliente recebeu.
     */
    public function midia(Channel $canal, string $destino, array $arquivo, ?array $citar = null): array
    {
        throw new RuntimeException('O chat do site ainda não recebe arquivo. Mande o link, ou peça o WhatsApp da pessoa.');
    }

    /** Quem le e o proprio visitante, na tela dele: nao ha o que marcar em provedor nenhum. */
    public function marcarLida(Channel $canal, string $jid, array $externalIds): void {}

    /**
     * Nao existe numero para conferir.
     *
     * Devolve "existe" porque quem chama esta perguntando se da para falar com aquele destino —
     * e da: o visitante esta com a pagina aberta.
     */
    public function verificarNumero(Channel $canal, string $e164): array
    {
        return ['existe' => true, 'jid' => $e164];
    }

    public function reagir(Channel $canal, string $destino, array $alvo, string $emoji): void {}

    /** Apagar mudaria a tela do visitante sem ele entender; fica para quando houver desfazer. */
    public function podeApagar(): bool
    {
        return false;
    }

    public function apagar(Channel $canal, string $destino, array $alvo): void {}

    public function podeDigitando(): bool
    {
        return false;
    }

    public function digitando(Channel $canal, string $destino, bool $ativo): void {}

    public function nome(): string
    {
        return 'Chat do site';
    }
}
