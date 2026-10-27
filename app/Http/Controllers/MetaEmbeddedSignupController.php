<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Support\TenantContext;
use App\Services\Canais\MetaEmbeddedSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Cada tentativa guarda no servidor o usuario, a conta selecionada e o canal.
 * O callback valida essa identidade antes de trocar a autorizacao com a Meta.
 * O resultado de uso unico e consumido pela aba principal sob bloqueio.
 */
class MetaEmbeddedSignupController extends Controller
{
    /**
     * Persiste no banco. Chamado pela aba principal, com o id do cache que o
     * `retorno` gerou e, se for reconexao, o id do canal — que veio do JS da
     * PROPRIA aba, nunca da sessao.
     */
    /** Identifica a tentativa antes de abrir a Meta, sem compartilhar destino entre abas. */
    public function iniciar(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);
        $dados = $request->validate(['canal_id' => ['nullable', 'integer']]);
        $tenantId = $this->contaAtual($request);
        $canalId = $dados['canal_id'] ?? null;
        if ($canalId !== null) {
            abort_unless(Channel::query()->where('tenant_id', $tenantId)
                ->where('tipo', Channel::META_CLOUD)->whereKey($canalId)->exists(), 404);
        }
        $state = (string) Str::uuid();
        Cache::put("meta_attempt:{$state}", [
            'tenant_id' => $tenantId,
            'user_id' => (int) $request->user()->id,
            'canal_id' => $canalId,
        ], now()->addMinutes(15));
        Cache::put("meta_progress:{$state}", [
            'tenant_id' => $tenantId, 'user_id' => (int) $request->user()->id,
            'status' => 'pending',
        ], now()->addMinutes(15));
        return response()->json(['ok' => true, 'state' => $state]);
    }

    /** Retorno consultavel sem code, token da Meta ou dependencia de window.opener. */
    public function resultado(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);
        $dados = $request->validate(['state' => ['required', 'uuid']]);
        $resultado = Cache::get("meta_progress:{$dados['state']}");
        if (! $resultado) return response()->json(['ok' => false, 'erro' => 'Esta tentativa expirou. Inicie novamente.'], 410);
        abort_unless($this->pertence($resultado, $request), 403);
        return response()->json(array_intersect_key($resultado, array_flip(['status', 'signup_token', 'redirect', 'erro'])) + ['ok' => true]);
    }

    private function contaAtual(Request $request): int
    {
        $usuario = $request->user();
        $suporte = $request->session()->get(TenantContext::CHAVE_SESSAO_SUPORTE);
        return $usuario->operador && $usuario->super_admin && $suporte
            ? (int) $suporte : (int) $usuario->tenant_id;
    }

    private function pertence(array $dados, Request $request): bool
    {
        return ($dados['user_id'] ?? null) === (int) $request->user()->id
            && ($dados['tenant_id'] ?? null) === $this->contaAtual($request);
    }

    /** Recebe o code do SDK junto com os dados do evento de conclusao. */
    public function receber(Request $request, MetaEmbeddedSignup $signup): JsonResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);
        $dados = $request->validate([
            'state' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:4096'],
            'waba_id' => ['nullable', 'regex:/^\d{1,32}$/'],
            'phone_number_id' => ['nullable', 'regex:/^\d{1,32}$/'],
            'business_id' => ['nullable', 'regex:/^\d{1,32}$/'],
        ]);
        $resultado = $this->processarAutorizacao($request, $signup, $dados);
        return response()->json($resultado, $resultado['ok'] ? 200 : 422);
    }

    private function processarAutorizacao(Request $request, MetaEmbeddedSignup $signup, array $dados): array
    {
        $state = $dados['state'];
        $tentativa = Cache::get("meta_attempt:{$state}");
        if (! $tentativa || ! $this->pertence($tentativa, $request)) {
            return ['ok' => false, 'erro' => 'Tentativa inválida, expirada ou pertencente a outra conta/usuário. Inicie novamente no Virtus.'];
        }
        $lock = Cache::lock("meta_attempt_lock:{$state}", 90);
        if (! $lock->get()) return ['ok' => false, 'erro' => 'Esta autorização já está sendo processada.'];
        try {
            // Consome antes da rede: o code da Meta vale uma vez. Um replay nao deve troca-lo novamente.
            if (Cache::pull("meta_attempt:{$state}") === null) {
                return ['ok' => false, 'erro' => 'Esta tentativa já foi utilizada. Inicie novamente.'];
            }
            $progresso = ['tenant_id' => $tentativa['tenant_id'], 'user_id' => $tentativa['user_id']];
            Cache::put("meta_progress:{$state}", $progresso + ['status' => 'processing'], now()->addMinutes(15));
            $r = $signup->autorizar($dados['code'], $dados['waba_id'] ?? null, $dados['phone_number_id'] ?? null);
            if (! $r['ok']) {
                Cache::put("meta_progress:{$state}", $progresso + ['status' => 'failed', 'erro' => $r['erro']], now()->addMinutes(15));
                return $r;
            }
            $signupToken = (string) Str::uuid();
            Cache::put("meta_signup:{$signupToken}", [
                'waba_id' => $r['waba_id'], 'phone_number_id' => $r['phone_number_id'],
                'business_id' => $dados['business_id'] ?? null,
                'token' => $r['token'], 'nome' => $r['nome'], 'numero' => $r['numero'],
                'state' => $state,
                ...$tentativa,
            ], now()->addMinutes(5));
            Cache::put("meta_progress:{$state}", $progresso + ['status' => 'ready', 'signup_token' => $signupToken], now()->addMinutes(5));
            return ['ok' => true, 'signup_token' => $signupToken];
        } finally {
            $lock->release();
        }
    }

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

        // Verifica o proprietario antes de consumir; o consumo ocorre sob lock abaixo.
        $finalizado = Cache::get("meta_signup_done:{$dados['signup_token']}");
        if ($finalizado) {
            abort_unless($this->pertence($finalizado, $request), 403);
            return response()->json(['ok' => true, 'redirect' => $finalizado['redirect']]);
        }
        $pendente = Cache::get("meta_signup:{$dados['signup_token']}");

        if ($pendente === null) {
            return response()->json([
                'ok'   => false,
                'erro' => 'Esta conexão expirou ou já foi usada. Clique em "Continuar com o Facebook" de novo.',
            ], 422);
        }

        // Entre o retorno da Meta e este POST, a sessao pode ter mudado de conta —
        // o id sozinho nao prova de quem e, e um id de outro tenant aqui criaria
        // canal na conta errada.
        if (! $this->pertence($pendente, $request)) {
            return response()->json(['ok' => false, 'erro' => 'Esta conexão pertence a outra conta.'], 403);
        }

        if (array_key_exists('canal_id', $dados) && $dados['canal_id'] !== $pendente['canal_id']) {
            return response()->json(['ok' => false, 'erro' => 'O canal não corresponde à tentativa de conexão.'], 403);
        }
        $canalId = $pendente['canal_id'];
        $canalExistente = $canalId !== null
            ? Channel::query()->where('tenant_id', $pendente['tenant_id'])->where('tipo', Channel::META_CLOUD)->find($canalId)
            : null;

        if ($canalId !== null && $canalExistente === null) {
            return response()->json(['ok' => false, 'erro' => 'O canal de reconexão não está mais disponível.'], 404);
        }
        // O lock do numero tambem cobre duas tentativas diferentes para o mesmo numero.
        $lock = Cache::lock('meta_phone_lock:'.hash('sha256', $pendente['phone_number_id']), 30);
        if (! $lock->get()) {
            return response()->json(['ok' => false, 'erro' => 'Este número já está sendo processado. Aguarde e tente novamente.'], 409);
        }
        try {
            $finalizado = Cache::get("meta_signup_done:{$dados['signup_token']}");
            if ($finalizado) return response()->json(['ok' => true, 'redirect' => $finalizado['redirect']]);
            if (! Cache::has("meta_signup:{$dados['signup_token']}")) {
                return response()->json(['ok' => false, 'erro' => 'Esta conexão expirou. Inicie novamente.'], 422);
            }
            $ocupante = Channel::withoutGlobalScopes()->where('tipo', Channel::META_CLOUD)
                ->where('meta_phone_number_id', $pendente['phone_number_id'])->first();
            if ($ocupante && ((int) $ocupante->tenant_id !== (int) $pendente['tenant_id']
                || ($canalId !== null && $ocupante->id !== $canalId))) {
                return response()->json(['ok' => false, 'erro' => 'Este número já está vinculado a outro canal. Confira a conta e o canal antes de reconectar.'], 409);
            }
            if ($ocupante && ! $canalExistente) {
                $canalExistente = Channel::query()->where('tenant_id', $pendente['tenant_id'])->find($ocupante->id);
                abort_unless($canalExistente, 403);
            }
            $canal = DB::transaction(function () use ($pendente, $canalExistente) {
                $canal = $this->criarOuAtualizarCanal($pendente, $pendente['waba_id'], $pendente['phone_number_id'], $pendente['business_id'], $canalExistente);
                // Um canal novo precisa aparecer para o administrador da propria conta.
                if (! $canalExistente) {
                    $admins = User::withoutGlobalScopes()->where('tenant_id', $pendente['tenant_id'])
                        ->where('admin', true)->pluck('id');
                    foreach ($admins as $id) {
                        DB::table('channel_user')->insertOrIgnore([
                            'channel_id' => $canal->id, 'user_id' => $id,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
                return $canal;
            });
            $redirect = ChannelResource::getUrl('edit', ['record' => $canal]);
            $concluido = ['tenant_id' => $pendente['tenant_id'], 'user_id' => $pendente['user_id'], 'redirect' => $redirect];
            Cache::put("meta_signup_done:{$dados['signup_token']}", $concluido, now()->addMinutes(15));
            if (! empty($pendente['state'])) {
                Cache::put("meta_progress:{$pendente['state']}", $concluido + ['status' => 'completed'], now()->addMinutes(15));
            }
            Cache::forget("meta_signup:{$dados['signup_token']}");
            return response()->json(['ok' => true, 'redirect' => $redirect]);
        } finally {
            $lock->release();
        }
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

        $validador = Validator::make($request->query(), [
            'state' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:4096'],
            'waba_id' => ['nullable', 'regex:/^\d{1,32}$/'],
            'phone_number_id' => ['nullable', 'regex:/^\d{1,32}$/'],
            'business_id' => ['nullable', 'regex:/^\d{1,32}$/'],
        ]);
        if ($validador->fails()) {
            return $this->paginaDeErro('A Meta não devolveu uma autorização completa vinculada à tentativa. Inicie novamente no Virtus.');
        }
        $resultado = $this->processarAutorizacao($request, $signup, $validador->validated());
        return $resultado['ok']
            ? $this->paginaDeSucesso($resultado['signup_token'])
            : $this->paginaDeErro($resultado['erro']);
    }

    /**
     * Sem 'code': e uma credencial de uso unico e curto, mas segredo em log e em pagina
     * de erro e o tipo de coisa que aparece num print de tela pedido de suporte.
     */
    private function parametrosParaExibir(Request $request): array
    {
        return [
            'code' => $request->has('code') ? '(presente)' : '(ausente)',
            'waba_id_presente' => $request->filled('waba_id'),
            'phone_number_id_presente' => $request->filled('phone_number_id'),
            'state_presente' => $request->filled('state'),
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
            'tenant_id'     => $r['tenant_id'],
            'nome'          => $r['nome'] ?: Channel::nomeProvisorio(),
            'telefone_e164' => $r['numero'],
        ]);
    }

    private function paginaDeSucesso(string $signupToken): Response
    {
        return response()->view('filament.resources.channels.pages.conectar-meta-retorno', [
            'state' => Cache::get("meta_signup:{$signupToken}")['state'] ?? null,
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
