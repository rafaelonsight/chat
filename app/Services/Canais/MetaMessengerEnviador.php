<?php

namespace App\Services\Canais;

use App\Models\Channel;
use App\Services\Canais\Concerns\EnviaPeloSendApi;
use Illuminate\Support\Facades\Http;

/**
 * Envio pelo Messenger, com o token da Pagina (nao existe token global de reserva:
 * cada Pagina e de um cliente diferente, sem numero "nosso" como no WhatsApp).
 *
 * graph.facebook.com/{versao}/me/... — "me" resolve para a Pagina do proprio token,
 * que e exatamente a Pagina que este canal representa.
 */
class MetaMessengerEnviador implements Enviador
{
    use EnviaPeloSendApi;

    public function __construct(
        private readonly string $versao,
        private readonly int $timeout,
    ) {}

    public function texto(Channel $canal, string $destino, string $texto, ?array $citar = null, array $mencoes = []): array
    {
        return $this->enviarMensagem($canal, $this->idDoDestino($destino, $canal), ['text' => $texto]);
    }

    /**
     * SEM LEGENDA: o Send API nao tem campo de legenda junto do anexo, ao contrario
     * do WhatsApp. Mandar a legenda como mensagem separada criaria um segundo id de
     * provedor sem Message nossa correspondente — melhor nao mandar do que mandar
     * pela metade.
     *
     * @param  array{tipo: string, bytes: string, mime: ?string, nome: ?string, legenda: ?string}  $arquivo
     */
    public function midia(Channel $canal, string $destino, array $arquivo, ?array $citar = null): array
    {
        $destinoId = $this->idDoDestino($destino, $canal);
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
            throw new \RuntimeException(__('ui_b34e9ad45ac9'));
        }

        return $this->enviarMensagem($canal, $destinoId, [
            'attachment' => ['type' => $tipoAnexo, 'payload' => ['attachment_id' => $attachmentId]],
        ]);
    }

    public function nome(): string
    {
        return 'messenger';
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
                'recipient'     => ['id' => $destinoId],
                'sender_action' => $acao,
            ])
            ->throw();
    }

    private function cliente(Channel $canal)
    {
        return Http::withToken($this->token($canal))
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();
    }
}
