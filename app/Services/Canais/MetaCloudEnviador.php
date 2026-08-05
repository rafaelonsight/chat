<?php

namespace App\Services\Canais;

use App\Models\Channel;
use Illuminate\Support\Facades\Http;

/**
 * Envio pela API oficial da Meta (WhatsApp Cloud API).
 *
 * Duas diferencas em relacao a Evolution que o codigo tem de respeitar:
 *
 * 1. O destino vai SEM o sinal de mais. A Meta recusa "+5584..." com erro de
 *    parametro, e o erro nao diz que o problema e o sinal — dava um dia de caca.
 * 2. Texto livre so sai dentro da janela de 24h. Isso NAO e checado aqui de proposito:
 *    a trava vive no job de envio, que e quem sabe se vale a pena tentar e o que fazer
 *    quando nao vale. Enviador tenta e relata.
 */
class MetaCloudEnviador implements Enviador
{
    public function __construct(
        private readonly string $token,
        private readonly string $versao,
        private readonly int $timeout,
    ) {}

    public function nome(): string
    {
        return 'meta_cloud';
    }

    public function texto(Channel $canal, string $destino, string $texto): array
    {
        $r = $this->cliente()
            ->post($this->url($canal).'/messages', [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => self::soDigitos($destino),
                'type'              => 'text',
                'text'              => [
                    // Sem previa de link: a Meta busca a pagina para montar o cartao, e
                    // isso atrasa o envio e vaza para o servidor deles qual link o
                    // atendente mandou. Quem quiser previa liga depois, sabendo.
                    'preview_url' => false,
                    'body'        => $texto,
                ],
            ])
            ->throw()
            ->json();

        return ['external_id' => data_get($r, 'messages.0.id')];
    }

    /**
     * O numero na URL e o Phone Number ID, nao o telefone.
     *
     * Fica no CANAL e nao na configuracao global porque cada cliente vai ter o proprio
     * numero: com isso no .env, o segundo cliente nao caberia.
     */
    private function url(Channel $canal): string
    {
        $id = trim((string) $canal->meta_phone_number_id);

        if ($id === '') {
            throw new \RuntimeException(
                "O canal \"{$canal->nome}\" e do tipo oficial mas nao tem Phone Number ID."
            );
        }

        return "https://graph.facebook.com/{$this->versao}/{$id}";
    }

    private function cliente()
    {
        return Http::withToken($this->token)
            ->timeout($this->timeout)
            ->acceptJson()
            // Sem retentativa aqui: o job de envio ja tem backoff, e retentar em dois
            // lugares multiplica a espera sem ninguem perceber.
            ->asJson();
    }

    public static function soDigitos(string $numero): string
    {
        return preg_replace('/\D+/', '', $numero) ?? '';
    }
}
