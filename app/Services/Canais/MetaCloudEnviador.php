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
        /** Reserva, do .env: vale para canal que ainda nao tem credencial propria. */
        private readonly string $tokenPadrao,
        private readonly string $versao,
        private readonly int $timeout,
    ) {}

    public function nome(): string
    {
        return 'meta_cloud';
    }

    public function texto(Channel $canal, string $destino, string $texto): array
    {
        $r = $this->cliente($canal)
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

    public function marcarLida(Channel $canal, string $jid, array $externalIds): void
    {
        // SO A MAIS NOVA. No WhatsApp, marcar uma mensagem como lida marca as anteriores
        // da conversa junto — e a semantica do proprio aplicativo, nao um atalho nosso.
        // Uma chamada por mensagem daria o mesmo resultado gastando 50 vezes mais cota.
        $ids = array_values(array_filter($externalIds));

        if ($ids === []) {
            return;
        }

        $this->cliente($canal)
            ->post($this->url($canal).'/messages', [
                'messaging_product' => 'whatsapp',
                'status'            => 'read',
                'message_id'        => $ids[count($ids) - 1],
            ])
            ->throw();
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

    /**
     * Confere a configuracao contra a Meta, SEM enviar mensagem.
     *
     * Nao entra na interface Enviador de proposito: e pergunta do caminho oficial. A
     * Evolution ja tem o QR Code para a mesma finalidade — provar que o canal esta de pe —
     * e aquilo nao cabe nesta forma.
     *
     * Devolve resultado em vez de estourar: quem chama e tela, e tela precisa mostrar o
     * motivo ao atendente, nao uma pagina de erro.
     *
     * @return array{ok: bool, erro?: string, numero?: string, nome?: string, qualidade?: string, situacao?: string}
     */
    public function conferir(Channel $canal): array
    {
        try {
            $r = $this->cliente($canal)
                ->get($this->url($canal), [
                    'fields' => 'display_phone_number,verified_name,quality_rating,status',
                ])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => mb_substr($e->getMessage(), 0, 300)];
        }

        return [
            'ok'        => true,
            'numero'    => (string) data_get($r, 'display_phone_number', '—'),
            'nome'      => (string) data_get($r, 'verified_name', '—'),
            'qualidade' => (string) data_get($r, 'quality_rating', '—'),
            'situacao'  => (string) data_get($r, 'status', '—'),
        ];
    }

    /**
     * O token do CANAL, com o do .env como reserva.
     *
     * No modelo em que cada cliente traz o proprio numero, a credencial e por WABA: quem
     * autoriza e o cliente, e a Meta emite o token para a conta dele. Token global so
     * funciona enquanto o numero e nosso — o segundo cliente nao cabe.
     *
     * Falta dos DOIS estoura: canal oficial sem credencial nenhuma nao pode "quase
     * funcionar" mandando com a credencial de outro.
     */
    private function tokenDe(Channel $canal): string
    {
        $doCanal = trim((string) $canal->meta_token);

        if ($doCanal !== '') {
            return $doCanal;
        }

        if (trim($this->tokenPadrao) === '') {
            throw new \RuntimeException(
                "O canal \"{$canal->nome}\" nao tem token da Meta e nao existe token padrao configurado."
            );
        }

        return $this->tokenPadrao;
    }

    private function cliente(Channel $canal)
    {
        // withToken: o segredo vai no cabecalho Authorization, nunca na URL. URL aparece
        // em log de servidor, em historico de proxy e em mensagem de excecao.
        return Http::withToken($this->tokenDe($canal))
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
