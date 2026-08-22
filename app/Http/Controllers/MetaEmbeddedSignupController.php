<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\Canais\MetaEmbeddedSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Cadastro Incorporado: dois passos que nao podem confiar na mesma sessao.
 *
 * `retorno` (GET) e o callback do link hospedado pela Meta — roda DENTRO DO POPUP,
 * troca o code por token e guarda o resultado num cache de curta duracao, sob um id
 * de uso unico. `conectar` (POST) e quem persiste no banco, chamado pela ABA
 * PRINCIPAL depois que o popup manda esse id de volta por postMessage.
 *
 * Por que nao criar o canal direto em `retorno`: a unica forma de saber PARA QUAL
 * canal esta reconexao era (criar um novo ou atualizar o canal X) e a aba que abriu
 * o popup — nao a sessao do navegador, que e compartilhada entre abas. Guardar
 * "canal X" numa chave de sessao fixa quebra no instante em que o mesmo admin abre
 * uma segunda reconexao numa aba diferente antes de terminar a primeira: o retorno
 * de uma aba acaba lido pela sessao que a OUTRA aba deixou por ultimo, e o token
 * escolhido para o canal A e gravado no canal B, em silencio.
 *
 * A aba principal, ao contrario da sessao, nao tem esse problema — cada aba tem o
 * proprio `canalId`, capturado no carregamento da pagina, isolado das outras.
 *
 * E por isso que o TOKEN nunca atravessa o navegador: o que o popup manda de volta
 * pela postMessage e so o id do cache (um UUID que nao vale nada sem a sessao de
 * administrador que o gerou), nao a credencial da Meta.
 */
class MetaEmbeddedSignupController extends Controller
{
    /**
     * Persiste no banco. Chamado pela aba principal, com o id do cache que o
     * `retorno` gerou e, se for reconexao, o id do canal — que veio do JS da
     * PROPRIA aba, nunca da sessao.
     */
    public function conectar(Request $request): JsonResponse
    {
        abort_unless((bool) auth()->user()?->admin, 403);

        $validador = Validator::make($request->all(), [
            'signup_token' => ['required', 'string'],
            'canal_id'     => ['nullable', 'integer'],
        ]);

        if ($validador->fails()) {
            return response()->json(['ok' => false, 'erro' => $validador->errors()->first()], 422);
        }

        $dados = $validador->validated();

        // pull: le e apaga na mesma operacao. Sem isto, chamar esta rota duas vezes
        // com o mesmo signup_token (o relay do popup falhando e sendo repetido,
        // por exemplo) criaria ou atualizaria o canal duas vezes com um token que
        // a Meta ja considerou entregue.
        $pendente = Cache::pull("meta_signup:{$dados['signup_token']}");

        if ($pendente === null) {
            return response()->json([
                'ok'   => false,
                'erro' => 'Esta conexão expirou ou já foi usada. Clique em "Continuar com o Facebook" de novo.',
            ], 422);
        }

        // Entre o retorno da Meta e este POST, a sessao pode ter mudado de conta —
        // o id sozinho nao prova de quem e, e um id de outro tenant aqui criaria
        // canal na conta errada.
        if ($pendente['tenant_id'] !== auth()->user()->tenant_id) {
            return response()->json(['ok' => false, 'erro' => 'Esta conexão pertence a outra conta.'], 403);
        }

        $canalId = $dados['canal_id'] ?? null;
        $canalExistente = $canalId !== null
            ? Channel::query()->where('tipo', Channel::META_CLOUD)->find($canalId)
            : null;

        $canal = $this->criarOuAtualizarCanal($pendente, $pendente['waba_id'], $pendente['phone_number_id'], $pendente['business_id'], $canalExistente);

        return response()->json([
            'ok'       => true,
            'redirect' => ChannelResource::getUrl('edit', ['record' => $canal]),
        ]);
    }

    /**
     * Callback do Cadastro Incorporado hospedado pela Meta. Roda DENTRO DO POPUP
     * (mesma origem nossa, porque e para ca que o redirect_uri aponta): fala com a
     * Meta, guarda o resultado por pouco tempo, e devolve so o id desse resultado
     * para o popup mandar a aba principal terminar o resto.
     */
    public function retorno(Request $request, MetaEmbeddedSignup $signup): Response
    {
        abort_unless((bool) auth()->user()?->admin, 403);

        // 'code' nunca vai para o log: e uma credencial de uso unico, mas ainda assim
        // uma credencial — o resto do projeto trata token do mesmo jeito (ver
        // MetaCloudEnviador), e o log nao pode ser a excecao.
        Log::info('meta-embedded-signup: retorno do link hospedado', $this->parametrosParaExibir($request));

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

        // 5 minutos: tempo de sobra para o JS do popup mandar a mensagem e a aba
        // principal responder, curto o bastante para nao deixar token pendurado no
        // cache se a aba principal tiver sido fechada no meio do caminho.
        $signupToken = (string) Str::uuid();
        Cache::put("meta_signup:{$signupToken}", [
            'waba_id'         => $wabaId,
            'phone_number_id' => $phoneNumberId,
            'business_id'     => $businessId,
            'token'           => $r['token'],
            'nome'            => $r['nome'],
            'numero'          => $r['numero'],
            'tenant_id'       => auth()->user()->tenant_id,
        ], now()->addMinutes(5));

        return $this->paginaDeSucesso($signupToken);
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

    private function paginaDeSucesso(string $signupToken): Response
    {
        return response()->view('filament.resources.channels.pages.conectar-meta-retorno', [
            'sucesso'     => true,
            'signupToken' => $signupToken,
        ]);
    }

    private function paginaDeErro(string $mensagem): Response
    {
        return response()->view('filament.resources.channels.pages.conectar-meta-retorno', [
            'sucesso'  => false,
            'mensagem' => $mensagem,
        ], 422);
    }
}
