<?php

namespace App\Services\Canais;

use Illuminate\Support\Facades\Http;

/**
 * Cadastro Incorporado (Embedded Signup v3): troca o "code" que o popup da Meta devolve
 * por um token de acesso da WABA do cliente, inscreve o app para receber webhook dela, e
 * busca o numero/nome para preencher o canal sem perguntar de novo.
 *
 * Diferente do MetaCloudEnviador: aqui quem chama e uma tela com alguem esperando na
 * frente, entao os metodos devolvem ['ok' => bool, ...] em vez de lancar - o mesmo motivo
 * pelo qual MetaCloudEnviador::conferir() tambem nao lanca.
 */
class MetaEmbeddedSignup
{
    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
        private readonly string $versao,
        private readonly int $timeout,
    ) {}

    /**
     * Troca o "code" do popup por token, inscreve o webhook da WABA e busca o numero.
     *
     * Aborta SEM criar nada nos dois primeiros passos: um canal sem token valido ou sem
     * inscricao de webhook pareceria "aberto" e nao receberia nada, calado - pior que nao
     * existir. So o terceiro passo (nome/numero) e cosmetico e nao aborta o fluxo.
     *
     * @return array{ok: bool, erro?: string, token?: string, numero?: string, nome?: string}
     */
    public function conectar(string $code, string $wabaId, string $phoneNumberId): array
    {
        try {
            $token = $this->trocarCodigoPorToken($code);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Nao foi possivel confirmar com o Facebook: '.mb_substr($e->getMessage(), 0, 300)];
        }

        try {
            $this->inscreverWebhook($wabaId, $token);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'O numero foi autorizado, mas a inscricao do webhook falhou: '.mb_substr($e->getMessage(), 0, 300).'. Sem ela a Meta nao entrega mensagem nenhuma - tente conectar de novo.'];
        }

        $dados = $this->dadosDoNumero($phoneNumberId, $token);

        return [
            'ok'     => true,
            'token'  => $token,
            'numero' => $dados['numero'] ?? null,
            'nome'   => $dados['nome'] ?? null,
        ];
    }

    /**
     * Os identificadores recebidos pelo navegador sao dicas, nunca prova de acesso.
     * Descobre os ativos autorizados no token e confere o numero dentro da WABA.
     * Sem selecao inequivoca, falha em vez de conectar o primeiro numero de uma lista.
     */
    public function autorizar(string $code, ?string $wabaId = null, ?string $phoneNumberId = null): array
    {
        try {
            $token = $this->trocarCodigoPorToken($code);
            $debug = Http::withToken($this->appId.'|'.$this->appSecret)
                ->timeout($this->timeout)
                ->get("https://graph.facebook.com/{$this->versao}/debug_token", ['input_token' => $token])
                ->throw()->json('data');
            if (! is_array($debug) || ! ($debug['is_valid'] ?? false)
                || (string) ($debug['app_id'] ?? '') !== $this->appId) {
                return ['ok' => false, 'erro' => 'A Meta devolveu uma autorização inválida para este aplicativo. Inicie novamente.'];
            }
            $autorizadas = [];
            foreach ($debug['granular_scopes'] ?? [] as $scope) {
                if (($scope['scope'] ?? '') === 'whatsapp_business_management') {
                    $autorizadas = array_merge($autorizadas, array_map('strval', $scope['target_ids'] ?? []));
                }
            }
            $autorizadas = array_values(array_unique($autorizadas));
            if (! $wabaId && count($autorizadas) === 1) $wabaId = $autorizadas[0];
            if (! $wabaId || ! in_array($wabaId, $autorizadas, true)) {
                return ['ok' => false, 'erro' => 'Não foi possível identificar uma única conta WhatsApp autorizada. Selecione a conta da empresa no cadastro da Meta.'];
            }
            $response = Http::withToken($token)->timeout($this->timeout)
                ->get("https://graph.facebook.com/{$this->versao}/{$wabaId}/phone_numbers", [
                    'fields' => 'id,display_phone_number,verified_name', 'limit' => 100,
                ])->throw()->json();
            $numeros = $response['data'] ?? [];
            if (! $phoneNumberId && count($numeros) === 1 && empty($response['paging']['next'])) {
                $phoneNumberId = (string) ($numeros[0]['id'] ?? '');
            }
            $numero = collect($numeros)->first(fn ($item) => (string) ($item['id'] ?? '') === $phoneNumberId);
            if (! $phoneNumberId || ! $numero) {
                return ['ok' => false, 'erro' => 'O número não foi identificado na conta WhatsApp autorizada. Se houver vários números, selecione o número desejado na Meta.'];
            }
            $this->inscreverWebhook($wabaId, $token);
            $telefone = preg_replace('/\D+/', '', (string) ($numero['display_phone_number'] ?? ''));
            return [
                'ok' => true, 'token' => $token, 'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
                'numero' => $telefone ? '+'.$telefone : null,
                'nome' => $numero['verified_name'] ?? null,
            ];
        } catch (\Throwable $e) {
            // Excecoes HTTP podem carregar tokens, codes e detalhes do pedido.
            // Registra apenas a classe e o status; nunca corpo/URL/credenciais.
            \Illuminate\Support\Facades\Log::warning('meta-signup: autorização não concluída', [
                'exception' => get_class($e),
                'http_status' => $e instanceof \Illuminate\Http\Client\RequestException ? $e->response->status() : null,
            ]);
            return ['ok' => false, 'erro' => 'Não foi possível validar a autorização ou inscrever o recebimento de mensagens na Meta. Inicie uma nova tentativa; se persistir, confira as permissões do aplicativo.'];
        }
    }

    /**
     * O "code" do FB.login vale uma vez e por pouco tempo: troca por um token de acesso
     * de longa duracao, ja da WABA do cliente (nao do nosso app).
     */
    private function trocarCodigoPorToken(string $code): string
    {
        $r = Http::timeout($this->timeout)
            ->get("https://graph.facebook.com/{$this->versao}/oauth/access_token", [
                'client_id'     => $this->appId,
                'client_secret' => $this->appSecret,
                'code'          => $code,
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
     * A armadilha que ja custou tempo antes (ver CLAUDE.md): sem isto a Meta tem o
     * numero e nao entrega webhook nenhum, e o sintoma nao e um erro - e silencio.
     * Nao tem botao equivalente no painel; so esta chamada resolve.
     */
    private function inscreverWebhook(string $wabaId, string $token): void
    {
        $resposta = Http::withToken($token)
            ->timeout($this->timeout)
            ->post("https://graph.facebook.com/{$this->versao}/{$wabaId}/subscribed_apps")
            ->throw()->json();
        if (($resposta['success'] ?? false) !== true) {
            throw new \RuntimeException(__('ui_2cc90147e743'));
        }
        // A inscrição simples usa o callback global, que pode pertencer à versão antiga.
        // O endereço por WABA mantém cada cliente nesta instalação sem afetar outros apps.
        $callback = rtrim((string) config('app.url'), '/').'/webhooks/meta/whatsapp';
        $verificacao = (string) config('services.meta.verify_token');
        if (! str_starts_with($callback, 'https://') || $verificacao === '') {
            throw new \RuntimeException(__('ui_43f692f50688'));
        }
        $direcionamento = Http::withToken($token)->timeout($this->timeout)
            ->post("https://graph.facebook.com/{$this->versao}/{$wabaId}/subscribed_apps", [
                'override_callback_uri' => $callback,
                'verify_token' => $verificacao,
            ])->throw()->json();
        if (($direcionamento['success'] ?? false) !== true) {
            throw new \RuntimeException(__('ui_e7d59c04f6ed'));
        }
        $inscricoes = Http::withToken($token)->timeout($this->timeout)
            ->get("https://graph.facebook.com/{$this->versao}/{$wabaId}/subscribed_apps")
            ->throw()->json('data');
        $confirmado = collect($inscricoes ?? [])->contains(fn ($item) =>
            (string) data_get($item, 'whatsapp_business_api_data.id', data_get($item, 'id', '')) === $this->appId
            && (string) data_get($item, 'override_callback_uri', '') === $callback);
        if (! $confirmado) {
            throw new \RuntimeException(__('ui_bbe26fbf77d6'));
        }
    }

    /**
     * @return array{numero: ?string, nome: ?string}
     */
    private function dadosDoNumero(string $phoneNumberId, string $token): array
    {
        try {
            $r = Http::withToken($token)
                ->timeout($this->timeout)
                ->get("https://graph.facebook.com/{$this->versao}/{$phoneNumberId}", [
                    'fields' => 'display_phone_number,verified_name',
                ])
                ->throw()
                ->json();
        } catch (\Throwable) {
            // Cosmetico: sem nome/numero o canal nasce com Channel::nomeProvisorio() e
            // telefone_e164 vazio - nao vale abortar uma conexao que ja funciona por isto.
            return ['numero' => null, 'nome' => null];
        }

        $numero = (string) data_get($r, 'display_phone_number', '');

        return [
            'numero' => $numero !== '' ? '+'.preg_replace('/\D+/', '', $numero) : null,
            'nome'   => data_get($r, 'verified_name') ?: null,
        ];
    }
}
