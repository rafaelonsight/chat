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
 * Conexao de Messenger e Instagram: dois passos, na MESMA aba (ao contrario do Cadastro
 * Incorporado do WhatsApp, que precisa de popup+postMessage porque o link e hospedado
 * pela Meta). Aqui o FB.login() roda na nossa propria pagina, entao nao ha problema de
 * sessao compartilhada entre abas — so ha duas chamadas ao servidor porque listar as
 * Paginas e escolher qual conectar sao dois momentos.
 *
 * O token de cada Pagina NUNCA chega ao navegador: `listarPaginas` guarda a lista
 * completa (com token) num cache de curta duracao sob um uuid de uso unico, e devolve
 * ao navegador so o que ele precisa para desenhar a escolha (id, nome, se tem
 * Instagram). `conectar` pega o token de volta so no servidor, pelo mesmo uuid.
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
            'paginas' => array_map(fn (array $p) => [
                'id'            => $p['id'],
                'nome'          => $p['nome'],
                'tem_instagram' => $p['tem_instagram'],
                'ig_username'   => $p['ig_username'],
            ], $r['paginas']),
        ]);
    }

    /**
     * Persiste no banco. `canal_id` (quando presente, de uma reconexao) restringe a
     * atualizar SO aquele canal, no tipo que ele ja tem — reconectar nao cria canal
     * novo nem decide sozinho "tambem conectar o Instagram", porque a pessoa esta
     * reconectando um canal especifico, nao escolhendo de novo o que criar.
     */
    public function conectar(Request $request, MetaSocialSignup $signup): JsonResponse
    {
        abort_unless((bool) auth()->user()?->admin, 403);

        $validador = Validator::make($request->all(), [
            'uuid'               => ['required', 'string'],
            'page_id'            => ['required', 'string'],
            'conectar_instagram' => ['nullable', 'boolean'],
            'canal_id'           => ['nullable', 'integer'],
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

        $canalId = $dados['canal_id'] ?? null;
        $canalExistente = $canalId !== null
            ? Channel::query()->whereIn('tipo', [Channel::MESSENGER, Channel::INSTAGRAM])->find($canalId)
            : null;

        // Reconexao: so toca o canal que ja existe, no tipo que ele ja tem.
        if ($canalExistente !== null) {
            if ($canalExistente->tipo === Channel::INSTAGRAM) {
                if (! $pagina['tem_instagram']) {
                    return response()->json(['ok' => false, 'erro' => 'Essa Página não tem Instagram Profissional vinculado.'], 422);
                }

                $r = $signup->inscreverInstagram($pagina['ig_account_id'], $pagina['token']);

                if (! $r['ok']) {
                    return response()->json(['ok' => false, 'erro' => 'A Página foi autorizada, mas a inscrição do webhook do Instagram falhou: '.$r['erro']], 422);
                }

                $canalExistente->update([
                    'meta_page_id'       => $pagina['id'],
                    'meta_page_nome'     => $pagina['nome'],
                    'meta_ig_account_id' => $pagina['ig_account_id'],
                    'meta_ig_username'   => $pagina['ig_username'],
                    'meta_token'         => $pagina['token'],
                    'status'             => 'open',
                    'ultimo_erro'        => null,
                ]);
            } else {
                $r = $signup->inscreverPagina($pagina['id'], $pagina['token']);

                if (! $r['ok']) {
                    return response()->json(['ok' => false, 'erro' => 'A Página foi autorizada, mas a inscrição do webhook falhou: '.$r['erro']], 422);
                }

                $canalExistente->update([
                    'meta_page_id'   => $pagina['id'],
                    'meta_page_nome' => $pagina['nome'],
                    'meta_token'     => $pagina['token'],
                    'status'         => 'open',
                    'ultimo_erro'    => null,
                ]);
            }

            return response()->json(['ok' => true, 'redirect' => ChannelResource::getUrl('edit', ['record' => $canalExistente])]);
        }

        // Conexao nova: sempre cria o Messenger; o Instagram e opcional e so existe se
        // a pessoa marcou a caixa E a Pagina realmente tem uma conta vinculada.
        $r = $signup->inscreverPagina($pagina['id'], $pagina['token']);

        if (! $r['ok']) {
            return response()->json(['ok' => false, 'erro' => 'A Página foi autorizada, mas a inscrição do webhook do Messenger falhou: '.$r['erro']], 422);
        }

        $messenger = Channel::create([
            'tenant_id'      => auth()->user()->tenant_id,
            'tipo'           => Channel::MESSENGER,
            'nome'           => $pagina['nome'],
            'meta_page_id'   => $pagina['id'],
            'meta_page_nome' => $pagina['nome'],
            'meta_token'     => $pagina['token'],
            'status'         => 'open',
            'conectado_em'   => now(),
        ]);

        $quiseramInstagram = (bool) ($dados['conectar_instagram'] ?? false);

        if ($quiseramInstagram && $pagina['tem_instagram']) {
            $rInstagram = $signup->inscreverInstagram($pagina['ig_account_id'], $pagina['token']);

            // Falha aqui NAO desfaz o Messenger, que ja esta funcionando: fica registrado
            // no ultimo_erro do canal do Instagram, que nasce mesmo assim, e a pessoa pode
            // reconectar so ele depois pela tela de edicao.
            $instagram = Channel::create([
                'tenant_id'          => auth()->user()->tenant_id,
                'tipo'               => Channel::INSTAGRAM,
                'nome'               => $pagina['ig_username'] ? '@'.$pagina['ig_username'] : $pagina['nome'].' (Instagram)',
                'meta_page_id'       => $pagina['id'],
                'meta_page_nome'     => $pagina['nome'],
                'meta_ig_account_id' => $pagina['ig_account_id'],
                'meta_ig_username'   => $pagina['ig_username'],
                'meta_token'         => $pagina['token'],
                'status'             => $rInstagram['ok'] ? 'open' : 'connecting',
                'conectado_em'       => $rInstagram['ok'] ? now() : null,
                'ultimo_erro'        => $rInstagram['ok'] ? null : $rInstagram['erro'],
            ]);
        }

        return response()->json(['ok' => true, 'redirect' => ChannelResource::getUrl('index')]);
    }
}
