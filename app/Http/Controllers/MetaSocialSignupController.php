<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\Canais\MetaSocialSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Conexao do Messenger: dois passos, na MESMA aba (ao contrario do Cadastro
 * Incorporado do WhatsApp, que precisa de popup+postMessage porque o link e hospedado
 * pela Meta). Aqui o FB.login() roda na nossa propria pagina, entao nao ha problema de
 * sessao compartilhada entre abas — so ha duas chamadas ao servidor porque listar as
 * Paginas e escolher qual conectar sao dois momentos.
 *
 * SO MESSENGER — o Instagram tem fluxo proprio, ver MetaInstagramSignupController.
 *
 * O token de cada Pagina NUNCA chega ao navegador: `listarPaginas` guarda a lista
 * completa (com token) num cache de curta duracao sob um uuid de uso unico, e devolve
 * ao navegador so o que ele precisa para desenhar a escolha (id, nome). `conectar`
 * pega o token de volta so no servidor, pelo mesmo uuid.
 */
class MetaSocialSignupController extends Controller
{
    /**
     * Recebe o token curto que o FB.login() devolveu no navegador, lista as Paginas
     * que a pessoa administra e guarda o resultado (com token) por 5 minutos.
     */
    public function listarPaginas(Request $request, MetaSocialSignup $signup): JsonResponse
    {
        abort_unless((bool) auth()->user()?->admin, 403);

        $validador = Validator::make($request->all(), [
            'token' => ['required', 'string'],
        ]);

        if ($validador->fails()) {
            return response()->json(['ok' => false, 'erro' => $validador->errors()->first()], 422);
        }

        $r = $signup->listarPaginas($validador->validated()['token']);

        if (! $r['ok']) {
            return response()->json(['ok' => false, 'erro' => $r['erro']], 422);
        }

        if ($r['paginas'] === []) {
            return response()->json([
                'ok'   => false,
                'erro' => 'Nenhuma Página do Facebook encontrada nessa conta. É preciso ser administrador de pelo menos uma Página para conectar.',
            ], 422);
        }

        // 5 minutos: tempo de sobra para escolher a Pagina na tela, curto o bastante
        // para nao deixar token de Pagina pendurado no cache se a aba fechar no meio.
        $uuid = (string) Str::uuid();
        Cache::put("meta_social:{$uuid}", [
            'tenant_id' => auth()->user()->tenant_id,
            'paginas'   => $r['paginas'],
        ], now()->addMinutes(5));

        return response()->json([
            'ok'      => true,
            'uuid'    => $uuid,
            'paginas' => array_map(fn (array $p) => ['id' => $p['id'], 'nome' => $p['nome']], $r['paginas']),
        ]);
    }

    /**
     * Persiste no banco. `canal_id` (quando presente, de uma reconexao) restringe a
     * atualizar SO aquele canal.
     */
    public function conectar(Request $request, MetaSocialSignup $signup): JsonResponse
    {
        abort_unless((bool) auth()->user()?->admin, 403);

        $validador = Validator::make($request->all(), [
            'uuid'     => ['required', 'string'],
            'page_id'  => ['required', 'string'],
            'canal_id' => ['nullable', 'integer'],
        ]);

        if ($validador->fails()) {
            return response()->json(['ok' => false, 'erro' => $validador->errors()->first()], 422);
        }

        $dados = $validador->validated();

        // pull: le e apaga na mesma operacao, para a mesma escolha nao poder ser
        // reenviada duas vezes (o relay do navegador falhando e sendo repetido, por
        // exemplo) e criar/atualizar canal em duplicidade.
        $pendente = Cache::pull("meta_social:{$dados['uuid']}");

        if ($pendente === null) {
            return response()->json([
                'ok'   => false,
                'erro' => 'Esta conexão expirou ou já foi usada. Clique em "Continuar com o Facebook" de novo.',
            ], 422);
        }

        if ($pendente['tenant_id'] !== auth()->user()->tenant_id) {
            return response()->json(['ok' => false, 'erro' => 'Esta conexão pertence a outra conta.'], 403);
        }

        $pagina = collect($pendente['paginas'])->firstWhere('id', $dados['page_id']);

        if ($pagina === null) {
            return response()->json(['ok' => false, 'erro' => 'Página não encontrada na lista — tente conectar de novo.'], 422);
        }

        $r = $signup->inscreverPagina($pagina['id'], $pagina['token']);

        if (! $r['ok']) {
            return response()->json(['ok' => false, 'erro' => 'A Página foi autorizada, mas a inscrição do webhook falhou: '.$r['erro']], 422);
        }

        $canalId = $dados['canal_id'] ?? null;
        $canalExistente = $canalId !== null
            ? Channel::query()->where('tipo', Channel::MESSENGER)->find($canalId)
            : null;

        $dadosDoCanal = [
            'meta_page_id'   => $pagina['id'],
            'meta_page_nome' => $pagina['nome'],
            'meta_token'     => $pagina['token'],
            'status'         => 'open',
            'ultimo_erro'    => null,
        ];

        if ($canalExistente !== null) {
            // Nome e do canal que ja existia — reconectar nao pode pisar num nome
            // que a pessoa ja personalizou.
            $canalExistente->update($dadosDoCanal);
            $canal = $canalExistente;
        } else {
            $canal = Channel::create([
                ...$dadosDoCanal,
                'tenant_id'    => auth()->user()->tenant_id,
                'tipo'         => Channel::MESSENGER,
                'nome'         => $pagina['nome'],
                'conectado_em' => now(),
            ]);
        }

        return response()->json(['ok' => true, 'redirect' => ChannelResource::getUrl('edit', ['record' => $canal])]);
    }
}
