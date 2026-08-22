<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\Canais\MetaEmbeddedSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Cadastro Incorporado: dois caminhos que terminam no mesmo lugar.
 *
 * `conectar` (POST) e para quando o JS da tela ja tem code + waba_id + phone_number_id em
 * mãos (via FB.login() + evento de sessao por postMessage). `retorno` (GET) e o callback
 * do link hospedado pela Meta — que roda numa pagina da Meta, nao num popup nosso, e volta
 * para ca por REDIRECT do navegador, com os dados na própria query string.
 *
 * Os dois so existem porque o FB.login() com este config_id nao estava completando o
 * assistente de escolha da WABA (ficava soo na tela de "continuar como Fulano" e fechava
 * sem waba_id/phone_number_id) — o link hospedado prova que a configuracao do lado da Meta
 * esta certa, entao a tela passou a abrir esse link num popup em vez de usar FB.login().
 */
class MetaEmbeddedSignupController extends Controller
{
    public function conectar(Request $request, MetaEmbeddedSignup $signup): JsonResponse
    {
        // Mesmo portao da tela de Canais: quem nao pode ver Canais nao pode criar um.
        abort_unless((bool) auth()->user()?->admin, 403);

        // Validator explicito, em vez de $request->validate(): quem chama e sempre JS
        // esperando JSON, e nunca navegador esperando pagina. O atalho ->validate()
        // decide isso sozinho e, quando erra a decisao, devolve um redirect (HTML) que o
        // fetch() do JS nao sabe ler — e o sintoma vira "falha de rede" sem pista nenhuma
        // do motivo real.
        $validador = Validator::make($request->all(), [
            'code'            => ['required', 'string'],
            'waba_id'         => ['required', 'string'],
            'phone_number_id' => ['required', 'string'],
            'business_id'     => ['nullable', 'string'],
        ]);

        if ($validador->fails()) {
            return response()->json(['ok' => false, 'erro' => $validador->errors()->first()], 422);
        }

        $dados = $validador->validated();

        $r = $signup->conectar($dados['code'], $dados['waba_id'], $dados['phone_number_id']);

        if (! $r['ok']) {
            return response()->json(['ok' => false, 'erro' => $r['erro']], 422);
        }

        $canal = $this->criarOuAtualizarCanal($r, $dados['waba_id'], $dados['phone_number_id'], $dados['business_id'] ?? null, null);

        return response()->json([
            'ok'       => true,
            'redirect' => ChannelResource::getUrl('edit', ['record' => $canal]),
        ]);
    }

    /**
     * Callback do Cadastro Incorporado hospedado pela Meta.
     *
     * Roda DENTRO DO POPUP (mesma origem nossa, porque e para ca que o redirect_uri
     * aponta) — por isso o sucesso manda o OPENER (a aba principal) para o canal recem
     * criado e fecha o popup sozinho, em vez de deixar a pessoa lendo o resultado numa
     * janela pequena que ela precisaria fechar na mao.
     */
    public function retorno(Request $request, MetaEmbeddedSignup $signup): Response|RedirectResponse
    {
        abort_unless((bool) auth()->user()?->admin, 403);

        // 'code' nunca vai para o log: e uma credencial de uso unico, mas ainda assim
        // uma credencial — o resto do projeto trata token do mesmo jeito (ver
        // MetaCloudEnviador), e o log nao pode ser a excecao.
        Log::info('meta-embedded-signup: retorno do link hospedado', [
            ...$request->query(),
            'code' => $request->has('code') ? '(presente)' : '(ausente)',
        ]);

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->paginaDeErro(
                'A Meta não devolveu o "code" de autorização. Parâmetros recebidos: '
                .json_encode($this->parametrosParaExibir($request))
            );
        }

        // Nem toda versao do fluxo hospedado manda waba_id/phone_number_id na propria
        // URL de retorno — por isso o erro aqui e explicito com o que chegou, em vez de
        // estourar tentando montar uma chamada a Graph API com id vazio.
        $wabaId = (string) $request->query('waba_id', '');
        $phoneNumberId = (string) $request->query('phone_number_id', '');
        $businessId = $request->query('business_id');

        if ($wabaId === '' || $phoneNumberId === '') {
            return $this->paginaDeErro(
                'Recebi o code, mas faltou waba_id/phone_number_id na volta. Parâmetros '
                .'recebidos: '.json_encode($this->parametrosParaExibir($request))
            );
        }

        $r = $signup->conectar($code, $wabaId, $phoneNumberId);

        if (! $r['ok']) {
            return $this->paginaDeErro($r['erro']);
        }

        // Reconexao (token expirado/revogado) atualiza o canal que ja existe — se fosse
        // Channel::create() sempre, cada reconexao duplicaria o canal e o historico da
        // conversa ficaria preso no canal velho, morto. Ver ConectarMetaIncorporado::mount().
        $canalId = session()->pull('meta_reconectar_canal_id');
        $canalExistente = $canalId ? Channel::query()->where('tipo', Channel::META_CLOUD)->find($canalId) : null;

        $canal = $this->criarOuAtualizarCanal($r, $wabaId, $phoneNumberId, $businessId, $canalExistente);

        return $this->paginaDeSucesso(ChannelResource::getUrl('edit', ['record' => $canal]));
    }

    /**
     * Sem 'code': e uma credencial de uso unico e curto, mas segredo em log e em pagina
     * de erro e o tipo de coisa que aparece num print de tela pedido de suporte.
     */
    private function parametrosParaExibir(Request $request): array
    {
        return [
            ...$request->query(),
            'code' => $request->has('code') ? '(presente)' : '(ausente)',
        ];
    }

    /**
     * @param  array{token: string, numero: ?string, nome: ?string}  $r
     */
    private function criarOuAtualizarCanal(
        array $r,
        string $wabaId,
        string $phoneNumberId,
        ?string $businessId,
        ?Channel $canalExistente,
    ): Channel {
        $dados = [
            'tipo'                 => Channel::META_CLOUD,
            'meta_phone_number_id' => $phoneNumberId,
            'meta_waba_id'         => $wabaId,
            'meta_business_id'     => $businessId,
            'meta_token'           => $r['token'],
            'status'               => 'open',
            'conectado_em'         => now(),
            'ultimo_erro'          => null,
        ];

        if ($canalExistente !== null) {
            // O nome e o numero sao do canal que ja existe, escolhidos por quem usa o
            // sistema — a reconexao nao pode pisar num nome que a pessoa ja personalizou.
            $canalExistente->update($dados);

            return $canalExistente;
        }

        return Channel::create([
            ...$dados,
            'tenant_id'     => auth()->user()->tenant_id,
            'nome'          => $r['nome'] ?: Channel::nomeProvisorio(),
            'telefone_e164' => $r['numero'],
        ]);
    }

    private function paginaDeSucesso(string $urlDoCanal): Response
    {
        return response()->view('filament.resources.channels.pages.conectar-meta-retorno', [
            'sucesso' => true,
            'destino' => $urlDoCanal,
        ]);
    }

    private function paginaDeErro(string $mensagem): Response
    {
        return response()->view('filament.resources.channels.pages.conectar-meta-retorno', [
            'sucesso' => false,
            'mensagem' => $mensagem,
        ], 422);
    }
}
