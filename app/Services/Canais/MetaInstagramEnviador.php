<?php

namespace App\Services\Canais;

use App\Models\Channel;
use App\Models\Message;
use App\Services\Canais\Concerns\EnviaPeloSendApi;
use Illuminate\Support\Facades\Http;

/**
 * Envio pelo Instagram: direct e resposta de comentario (endpoint diferente,
 * publico, tecnicamente NAO e uma mensagem privada).
 *
 * graph.instagram.com/{versao}/{ig_account_id}/... — NAO graph.facebook.com/me como
 * o Messenger. O token vem do login direto no Instagram (MetaInstagramSignup), nao
 * de uma Pagina do Facebook, e por isso o host e o path sao outros — sao produtos
 * diferentes da Meta, descoberto testando o fluxo de verdade (ver
 * MetaInstagramSignup).
 */
class MetaInstagramEnviador implements Enviador
{
    use EnviaPeloSendApi;

    public function __construct(
        private readonly string $versao,
        private readonly int $timeout,
    ) {}

    /**
     * Responder um COMENTARIO nao e mandar um direct: e postar outro comentario,
     * publico, pendurado no mesmo. Por isso este metodo desvia de rota quando $citar
     * aponta para uma Message do tipo "comentario_ig" — e SO nesse caso: sem citacao
     * explicita, a rota e sempre o direct normal. Adivinhar "o comentario mais recente
     * sem resposta" foi descartado de proposito — errar o alvo posta uma resposta
     * publica no comentario errado, e isso nao tem como desfazer sozinho.
     */
    public function texto(Channel $canal, string $destino, string $texto, ?array $citar = null, array $mencoes = []): array
    {
        $comentario = $this->comentarioCitado($canal, $citar);

        if ($comentario !== null) {
            return $this->responderComentario($canal, $comentario, $texto);
        }

        return $this->enviarMensagem($canal, $this->idDoDestino($destino, $canal), ['text' => $texto]);
    }

    /**
     * SEM LEGENDA, mesmo motivo do Messenger: o Send API nao tem campo de legenda
     * junto do anexo.
     *
     * @param  array{tipo: string, bytes: string, mime: ?string, nome: ?string, legenda: ?string}  $arquivo
     */
    public function midia(Channel $canal, string $destino, array $arquivo, ?array $citar = null): array
    {
        // Comentario nao aceita arquivo pela nossa tela (so texto); midia so vale
        // para direct, mesmo quando a conversa nasceu de um comentario.
        $destinoId = $this->idDoDestino($destino, $canal);
        $tipoAnexo = match ($arquivo['tipo']) {
            'image', 'video', 'audio' => $arquivo['tipo'],
            default                   => 'file',
        };

        $r = Http::withToken($this->token($canal))
            ->timeout(max($this->timeout, 120))
            ->attach('filedata', $arquivo['bytes'], $arquivo['nome'] ?: 'arquivo', ['Content-Type' => $arquivo['mime'] ?: 'application/octet-stream'])
            ->post("https://graph.instagram.com/{$this->versao}/{$canal->meta_ig_account_id}/message_attachments", [
                'message' => json_encode([
                    'attachment' => ['type' => $tipoAnexo, 'payload' => ['is_reusable' => false]],
                ]),
            ])
            ->throw()
            ->json();

        $attachmentId = (string) data_get($r, 'attachment_id');

        if ($attachmentId === '') {
            throw new \RuntimeException(__('ui_65d99f05142e'));
        }

        return $this->enviarMensagem($canal, $destinoId, [
            'attachment' => ['type' => $tipoAnexo, 'payload' => ['attachment_id' => $attachmentId]],
        ]);
    }

    public function nome(): string
    {
        return 'instagram';
    }

    /** @param array{external_id: string, texto: ?string, minha: bool}|null $citar */
    private function comentarioCitado(Channel $canal, ?array $citar): ?string
    {
        if ($citar === null) {
            return null;
        }

        $alvo = Message::where('channel_id', $canal->id)
            ->where('external_id', $citar['external_id'])
            ->first();

        return $alvo && $alvo->tipo === 'comentario_ig' ? $alvo->external_id : null;
    }

    /** @return array{external_id: ?string} */
    private function responderComentario(Channel $canal, string $commentId, string $texto): array
    {
        $r = Http::withToken($this->token($canal))
            ->timeout($this->timeout)
            ->asJson()
            ->acceptJson()
            ->post("https://graph.instagram.com/{$this->versao}/{$commentId}/replies", [
                'message' => $texto,
            ])
            ->throw()
            ->json();

        return ['external_id' => data_get($r, 'id')];
    }

    /** @return array{external_id: ?string} */
    private function enviarMensagem(Channel $canal, string $destinoId, array $mensagem): array
    {
        $r = $this->cliente($canal)
            ->post("https://graph.instagram.com/{$this->versao}/{$canal->meta_ig_account_id}/messages", [
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
            ->post("https://graph.instagram.com/{$this->versao}/{$canal->meta_ig_account_id}/messages", [
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
