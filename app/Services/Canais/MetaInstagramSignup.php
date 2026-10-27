<?php

namespace App\Services\Canais;

use Illuminate\Support\Facades\Http;

/**
 * Login direto no Instagram ("Business Login for Instagram") — SEM Pagina do
 * Facebook no meio.
 *
 * Isto NAO e o mesmo app do Messenger. A Meta separou o Instagram em dois produtos:
 * o antigo, vinculado a uma Pagina (permissoes instagram_manage_*, pelo app_id
 * principal), e este, mais novo (permissoes instagram_business_manage_*, com um
 * app_id/app_secret PROPRIOS, gerados automaticamente quando o produto "API do
 * Instagram" foi configurado no Painel de Apps). Descoberto testando o fluxo de
 * verdade: o app deste projeto so tinha o produto novo configurado, e pedir a
 * permissao antiga (instagram_manage_comments) devolvia "Invalid Scopes".
 *
 * Mesmo idioma dos outros Signup: ['ok' => bool, ...] em vez de lancar, porque quem
 * chama e uma tela com alguem esperando.
 */
class MetaInstagramSignup
{
    private const ESCOPO = 'instagram_business_basic,instagram_business_manage_messages,instagram_business_manage_comments';

    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
        private readonly string $versao,
        private readonly int $timeout,
    ) {}

    /**
     * O link que manda a pessoa para o proprio instagram.com logar.
     *
     * $state e responsabilidade de quem chama (ver ConectarMetaSocial): precisa ser
     * imprevisivel e guardado na sessao ANTES deste link ser mostrado, para o
     * callback conferir que a volta pertence ao mesmo login que este site iniciou —
     * sem isso, alguem poderia forjar um "code" da PROPRIA conta Instagram dele e
     * fazer a sessao de um admin nosso vincular a conta errada.
     */
    public function urlDeAutorizacao(string $redirectUri, string $state): string
    {
        return 'https://www.instagram.com/oauth/authorize?'.http_build_query([
            'force_reauth'  => 'true',
            'client_id'     => $this->appId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => self::ESCOPO,
            'state'         => $state,
        ]);
    }

    /**
     * Troca o "code" da volta por um token de longa duracao, inscreve o webhook e
     * busca o @usuario para o canal nao nascer sem nome.
     *
     * Aborta SEM criar nada se a troca de token ou a inscricao do webhook falhar —
     * mesmo raciocinio do MetaEmbeddedSignup: canal sem token valido ou sem webhook
     * pareceria "aberto" e nunca receberia nada, calado.
     *
     * @return array{ok: bool, erro?: string, token?: string, ig_account_id?: string, username?: ?string}
     */
    public function conectar(string $code, string $redirectUri): array
    {
        try {
            [$tokenCurto, $contaId] = $this->trocarCodigoPorTokenCurto($code, $redirectUri);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Nao foi possivel confirmar com o Instagram: '.mb_substr($e->getMessage(), 0, 300)];
        }

        try {
            $tokenLongo = $this->trocarPorTokenDeLongaDuracao($tokenCurto);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Nao foi possivel gerar o token de longa duracao: '.mb_substr($e->getMessage(), 0, 300)];
        }

        $username = $this->usernameDaConta($contaId, $tokenLongo);

        try {
            $this->inscreverWebhook($contaId, $tokenLongo);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'A conta foi autorizada, mas a inscricao do webhook falhou: '.mb_substr($e->getMessage(), 0, 300).'. Sem ela o Instagram nao entrega mensagem nem comentario nenhum - tente conectar de novo.'];
        }

        return [
            'ok'             => true,
            'token'          => $tokenLongo,
            'ig_account_id'  => $contaId,
            'username'       => $username,
        ];
    }

    /**
     * @return array{0: string, 1: string} [token curto, id da conta]
     */
    private function trocarCodigoPorTokenCurto(string $code, string $redirectUri): array
    {
        // Unico ponto deste arquivo que fala com api.instagram.com em vez de
        // graph.instagram.com: e assim que a Meta documenta esta troca especifica.
        $r = Http::asForm()
            ->timeout($this->timeout)
            ->post('https://api.instagram.com/oauth/access_token', [
                'client_id'     => $this->appId,
                'client_secret' => $this->appSecret,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirectUri,
                'code'          => $code,
            ])
            ->throw()
            ->json();

        $token = (string) (data_get($r, 'access_token') ?? data_get($r, 'data.0.access_token'));
        $contaId = (string) (data_get($r, 'user_id') ?? data_get($r, 'data.0.user_id'));

        if ($token === '' || $contaId === '') {
            throw new \RuntimeException(__('ui_4abf7d6c6344'));
        }

        return [$token, $contaId];
    }

    private function trocarPorTokenDeLongaDuracao(string $tokenCurto): string
    {
        $r = Http::timeout($this->timeout)
            ->withOptions(['on_stats' => function (\GuzzleHttp\TransferStats $stats) {
                $uri = $stats->getEffectiveUri();
                \Illuminate\Support\Facades\Log::info('instagram token exchange endpoint', [
                    'host' => $uri->getHost(), 'path' => $uri->getPath(),
                    'status' => $stats->getResponse()?->getStatusCode(),
                ]);
            }])
            ->get('https://graph.instagram.com/access_token', [
                'grant_type'    => 'ig_exchange_token',
                'client_secret' => $this->appSecret,
                'access_token'  => $tokenCurto,
            ])
            ->throw()
            ->json();

        $token = (string) data_get($r, 'access_token');

        if ($token === '') {
            throw new \RuntimeException(__('ui_1bfebffe92c8'));
        }

        return $token;
    }

    /** Mesma armadilha de sempre: sem isto o Instagram tem a conta e nao entrega nada, em silencio. */
    private function inscreverWebhook(string $contaId, string $token): void
    {
        Http::withToken($token)
            ->timeout($this->timeout)
            ->post("https://graph.instagram.com/{$this->versao}/{$contaId}/subscribed_apps", [
                'subscribed_fields' => 'messages,comments',
            ])
            ->throw();
    }

    /** Cosmetico: sem @usuario o canal nasce com um nome generico, e nao vale abortar por isto. */
    private function usernameDaConta(string &$contaId, string $token): ?string
    {
        try {
            $r = Http::withToken($token)
                ->timeout($this->timeout)
                ->get("https://graph.instagram.com/{$this->versao}/{$contaId}", ['fields' => 'user_id,username'])
                ->throw()
                ->json();

            $contaId = (string) (data_get($r, 'user_id') ?: $contaId);

            return data_get($r, 'username') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
