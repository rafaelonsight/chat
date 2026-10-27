<?php

namespace App\Services\Canais;

use Illuminate\Support\Facades\Http;

/**
 * Conexao de Messenger via Login do Facebook.
 *
 * Diferente do Cadastro Incorporado do WhatsApp (que tem link hospedado pela Meta com
 * wizard proprio e devolve WABA/numero prontos), aqui o FB.login() classico so devolve
 * consentimento de permissao - a escolha de QUAL Pagina conectar e nossa tela, e por isso
 * o fluxo tem uma etapa a mais (listarPaginas antes de conectar).
 *
 * SO MESSENGER. O Instagram tem login PROPRIO (ver MetaInstagramSignup) - nao esta
 * pendurado na Pagina como parecia no desenho inicial. Descoberto testando de
 * verdade: o app deste projeto tem o Instagram configurado no produto novo da Meta
 * ("Business Login for Instagram"), que exige logar direto no instagram.com com um
 * app_id/app_secret proprios, nao o app_id do Facebook usado aqui.
 *
 * Mesmo idioma do MetaEmbeddedSignup: quem chama e uma tela com alguem esperando na
 * frente, entao os metodos devolvem ['ok' => bool, ...] em vez de lancar.
 */
class MetaSocialSignup
{
    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
        private readonly string $versao,
        private readonly int $timeout,
    ) {}

    /**
     * Troca o token curto do FB.login por um de longa duracao e lista as Paginas que
     * a pessoa administra.
     *
     * O token de cada Pagina JA VEM de /me/accounts quando o token de usuario usado e de
     * longa duracao - nao precisa de uma troca por Pagina.
     *
     * @return array{ok: bool, erro?: string, paginas?: list<array{id: string, nome: string, token: string}>}
     */
    public function listarPaginas(string $tokenCurto): array
    {
        try {
            $tokenLongo = $this->trocarPorTokenDeLongaDuracao($tokenCurto);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Nao foi possivel confirmar com o Facebook: '.mb_substr($e->getMessage(), 0, 300)];
        }

        try {
            $paginas = $this->paginasDoUsuario($tokenLongo);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Nao foi possivel listar suas Paginas: '.mb_substr($e->getMessage(), 0, 300)];
        }

        return ['ok' => true, 'paginas' => $paginas];
    }

    /**
     * A armadilha de sempre (ver CLAUDE.md): sem isto a Meta tem a Pagina e nao entrega
     * webhook nenhum, em silencio. Nao tem botao equivalente no painel.
     *
     * @return array{ok: bool, erro?: string}
     */
    public function inscreverPagina(string $pageId, string $token): array
    {
        try {
            Http::withToken($token)
                ->timeout($this->timeout)
                ->post("https://graph.facebook.com/{$this->versao}/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => 'messages,messaging_postbacks,message_deliveries,message_reads',
                ])
                ->throw();
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => mb_substr($e->getMessage(), 0, 300)];
        }

        return ['ok' => true];
    }

    private function trocarPorTokenDeLongaDuracao(string $tokenCurto): string
    {
        $r = Http::timeout($this->timeout)
            ->get("https://graph.facebook.com/{$this->versao}/oauth/access_token", [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $this->appId,
                'client_secret'     => $this->appSecret,
                'fb_exchange_token' => $tokenCurto,
            ])
            ->throw()
            ->json();

        $token = (string) data_get($r, 'access_token');

        if ($token === '') {
            throw new \RuntimeException(__('ui_e4adbd6f8667'));
        }

        return $token;
    }

    /**
     * @return list<array{id: string, nome: string, token: string}>
     */
    private function paginasDoUsuario(string $tokenLongo): array
    {
        $r = Http::withToken($tokenLongo)
            ->timeout($this->timeout)
            ->get("https://graph.facebook.com/{$this->versao}/me/accounts", [
                'fields' => 'id,name,access_token',
            ])
            ->throw()
            ->json();

        $paginas = [];

        foreach ((array) data_get($r, 'data', []) as $pagina) {
            $id = (string) ($pagina['id'] ?? '');
            $token = (string) ($pagina['access_token'] ?? '');

            if ($id === '' || $token === '') {
                continue;
            }

            $paginas[] = [
                'id'    => $id,
                'nome'  => (string) ($pagina['name'] ?? $id),
                'token' => $token,
            ];
        }

        return $paginas;
    }
}
