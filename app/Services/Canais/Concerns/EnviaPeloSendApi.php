<?php

namespace App\Services\Canais\Concerns;

use App\Models\Channel;
use App\Services\Canais\ConfiguracaoInvalida;
use Illuminate\Support\Facades\Http;

/**
 * O que Messenger e Instagram Direct tem IGUAL, porque os dois falam pelo mesmo Send
 * API da Meta com o token da Pagina — a unica diferenca entre eles e QUAL id vai no
 * "recipient" (PSID de um lado, IGSID do outro) e o que o Instagram faz quando a
 * resposta e a um comentario em vez de um direct (ver MetaInstagramEnviador::texto()).
 *
 * Preso a classes com $versao e $timeout, no mesmo molde do MetaCloudEnviador.
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

    /** @return array{external_id: ?string} */
    private function enviarMensagem(Channel $canal, string $destinoId, array $mensagem): array
    {
        $r = $this->cliente($canal)
            ->post("https://graph.facebook.com/{$this->versao}/me/messages", [
                'recipient' => ['id' => $destinoId],
                'message'   => $mensagem,
            ])
            ->throw()
            ->json();

        return ['external_id' => data_get($r, 'message_id')];
    }

    private function acaoDoRemetente(Channel $canal, string $destinoId, string $acao): void
    {
        $this->cliente($canal)
            ->post("https://graph.facebook.com/{$this->versao}/me/messages", [
                'recipient'      => ['id' => $destinoId],
                'sender_action'  => $acao,
            ])
            ->throw();
    }

    /**
     * Envia arquivo por upload de anexo — DUAS chamadas, mesmo espirito do
     * MetaCloudEnviador::midia(): sobe o arquivo, a Meta devolve um id, a mensagem
     * referencia esse id.
     *
     * SEM LEGENDA: o Send API nao tem campo de legenda junto do anexo, ao contrario do
     * WhatsApp. Mandar a legenda como mensagem separada criaria um segundo id de
     * provedor sem Message nossa correspondente — melhor nao mandar do que mandar pela
     * metade.
     *
     * @param  array{tipo: string, bytes: string, mime: ?string, nome: ?string, legenda: ?string}  $arquivo
     * @return array{external_id: ?string}
     */
    private function enviarAnexo(Channel $canal, string $destinoId, array $arquivo): array
    {
        $tipoAnexo = match ($arquivo['tipo']) {
            'image', 'video', 'audio' => $arquivo['tipo'],
            default                   => 'file',
        };

        $r = Http::withToken($this->token($canal))
            ->timeout(max($this->timeout, 120))
            ->attach('filedata', $arquivo['bytes'], $arquivo['nome'] ?: 'arquivo', ['Content-Type' => $arquivo['mime'] ?: 'application/octet-stream'])
            ->post("https://graph.facebook.com/{$this->versao}/me/message_attachments", [
                'message' => json_encode([
                    'attachment' => ['type' => $tipoAnexo, 'payload' => ['is_reusable' => false]],
                ]),
            ])
            ->throw()
            ->json();

        $attachmentId = (string) data_get($r, 'attachment_id');

        if ($attachmentId === '') {
            throw new \RuntimeException('a Meta aceitou o arquivo mas nao devolveu attachment_id.');
        }

        return $this->enviarMensagem($canal, $destinoId, [
            'attachment' => ['type' => $tipoAnexo, 'payload' => ['attachment_id' => $attachmentId]],
        ]);
    }

    private function token(Channel $canal): string
    {
        $token = trim((string) $canal->meta_token);

        if ($token === '') {
            throw new ConfiguracaoInvalida(
                "O canal \"{$canal->nome}\" nao tem token de Pagina — reconecte pelo Facebook."
            );
        }

        return $token;
    }

    private function cliente(Channel $canal)
    {
        return Http::withToken($this->token($canal))
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();
    }
}
