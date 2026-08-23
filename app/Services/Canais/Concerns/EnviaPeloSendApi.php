<?php

namespace App\Services\Canais\Concerns;

use App\Models\Channel;
use App\Services\Canais\ConfiguracaoInvalida;

/**
 * O que Messenger e Instagram Direct tem IGUAL nas CAPACIDADES do canal — mesmo
 * quando o envio de verdade sai por hosts diferentes (ver MetaMessengerEnviador vs
 * MetaInstagramEnviador: Messenger fala com graph.facebook.com/me, Instagram com
 * graph.instagram.com/{ig_account_id}, porque sao produtos distintos da Meta com
 * tokens de origem diferente).
 *
 * Preso a classes com $timeout e um $canal->meta_token valido, no mesmo molde do
 * MetaCloudEnviador.
 */
trait EnviaPeloSendApi
{
    public function podeReagir(): bool
    {
        return false;
    }

    /** Nao existe endpoint de reacao no Send API — nem no Messenger, nem no Instagram. */
    public function reagir(Channel $canal, string $destino, array $alvo, string $emoji): void
    {
        throw new ConfiguracaoInvalida(
            "O canal \"{$canal->nome}\" nao tem endpoint de reacao — o Send API da Meta nao oferece essa operacao para Messenger/Instagram."
        );
    }

    public function podeApagar(): bool
    {
        return false;
    }

    /** Nao existe endpoint de apagar mensagem enviada, em nenhum dos dois. */
    public function apagar(Channel $canal, string $destino, array $alvo): void
    {
        throw new ConfiguracaoInvalida(
            "O canal \"{$canal->nome}\" nao permite apagar mensagens ja enviadas."
        );
    }

    /**
     * Ao contrario do WhatsApp, o Send API tem sender_action de verdade: mark_seen
     * marca a thread inteira, sem precisar do id de cada mensagem — por isso
     * $externalIds e ignorado aqui, nao por descuido.
     */
    public function marcarLida(Channel $canal, string $jid, array $externalIds): void
    {
        $this->acaoDoRemetente($canal, $this->idDoDestino($jid, $canal), 'mark_seen');
    }

    /**
     * podeDigitando() e true aqui, ao contrario do WhatsApp: typing_on/typing_off e
     * suportado de verdade pelo Send API, sem o limite de 25s presa a um wamid.
     */
    public function podeDigitando(): bool
    {
        return true;
    }

    public function digitando(Channel $canal, string $destino, bool $ativo): void
    {
        try {
            $this->acaoDoRemetente($canal, $this->idDoDestino($destino, $canal), $ativo ? 'typing_on' : 'typing_off');
        } catch (\Throwable) {
            // Engolido de proposito, igual ao EvolutionEnviador: enfeite nao pode
            // aparecer como erro na tela de quem esta no meio de uma frase.
        }
    }

    /** Nao existe busca por telefone nessas plataformas: a conversa so nasce de mensagem de entrada. */
    public function verificarNumero(Channel $canal, string $e164): array
    {
        return ['existe' => null, 'e164' => null];
    }

    /**
     * PSID/IGSID vale so dentro do canal que os gerou — ver ProcessMetaSocialWebhook,
     * onde o mesmo prefixo e montado na entrada. Sem prefixo reconhecido, devolve o
     * destino como veio: quem chamou sabe melhor do que este metodo.
     */
    private function idDoDestino(string $destino, Channel $canal): string
    {
        $prefixo = ($canal->tipo === Channel::INSTAGRAM ? 'instagram' : 'messenger')."_{$canal->id}_";

        return str_starts_with($destino, $prefixo) ? substr($destino, strlen($prefixo)) : $destino;
    }

    private function token(Channel $canal): string
    {
        $token = trim((string) $canal->meta_token);

        if ($token === '') {
            throw new ConfiguracaoInvalida(
                "O canal \"{$canal->nome}\" nao tem token — reconecte pelo Facebook/Instagram."
            );
        }

        return $token;
    }
}
