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
            throw new \RuntimeException('a Meta nao devolveu access_token.');
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
        Http::withToken($token)
            ->timeout($this->timeout)
            ->post("https://graph.facebook.com/{$this->versao}/{$wabaId}/subscribed_apps")
            ->throw();
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
