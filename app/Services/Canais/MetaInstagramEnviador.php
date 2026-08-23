<?php

namespace App\Services\Canais;

use App\Models\Channel;
use App\Models\Message;
use App\Services\Canais\Concerns\EnviaPeloSendApi;
use Illuminate\Support\Facades\Http;

/**
 * Envio pelo Instagram: direct (mesmo Send API do Messenger) e resposta de comentario
 * (endpoint diferente, publico, tecnicamente NAO e uma mensagem privada).
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
    public function texto(Channel $canal, string $destino, string $texto, ?array $citar = null): array
    {
        $comentario = $this->comentarioCitado($canal, $citar);

        if ($comentario !== null) {
            return $this->responderComentario($canal, $comentario, $texto);
        }

        return $this->enviarMensagem($canal, $this->idDoDestino($destino, $canal), ['text' => $texto]);
    }

    public function midia(Channel $canal, string $destino, array $arquivo, ?array $citar = null): array
    {
        // Comentario nao aceita arquivo pela nossa tela (so texto); midia so vale para
        // direct, mesmo quando a conversa nasceu de um comentario.
        return $this->enviarAnexo($canal, $this->idDoDestino($destino, $canal), $arquivo);
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
            ->post("https://graph.facebook.com/{$this->versao}/{$commentId}/replies", [
                'message' => $texto,
            ])
            ->throw()
            ->json();

        return ['external_id' => data_get($r, 'id')];
    }
}
