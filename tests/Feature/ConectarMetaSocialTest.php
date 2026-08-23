<?php

use App\Models\{Channel, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Conexao do Messenger pelo Login do Facebook.
 *
 * Duas rotas, na MESMA aba (sem popup, ao contrario do Cadastro Incorporado do
 * WhatsApp): `listarPaginas` troca o token curto do FB.login() por um de longa
 * duracao e lista as Paginas — devolvendo ao navegador so o que ele precisa para
 * desenhar a escolha. `conectar` persiste no banco, usando o uuid desse cache.
 *
 * SO MESSENGER. O Instagram tem login proprio, sem Pagina — ver
 * MetaInstagramSignupTest.php. Isso foi descoberto testando de verdade: o app deste
 * projeto tem o Instagram no produto novo da Meta (Business Login for Instagram), que
 * nao aceita a permissao antiga vinculada a Pagina.
 */

beforeEach(function () {
    config(['services.meta.versao' => 'v23.0', 'services.meta.app_id' => 'app-id-teste', 'services.meta.app_secret' => 'app-secret-teste']);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'social']);
    TenantContext::set($this->tenant->id);

    $this->admin = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@social.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    \Filament\Facades\Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

function fakesDeListagem(): void
{
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'USER-TOKEN-LONGO']),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => [
            ['id' => 'page-1', 'name' => 'Loja Principal', 'access_token' => 'PAGE-TOKEN-1'],
            ['id' => 'page-2', 'name' => 'Loja Secundaria', 'access_token' => 'PAGE-TOKEN-2'],
        ]]),
        'graph.facebook.com/*/subscribed_apps*' => Http::response(['success' => true]),
    ]);
}

function guardarPaginasPendentes(array $paginas, ?int $tenantId = null): string
{
    $uuid = (string) Illuminate\Support\Str::uuid();

    Cache::put("meta_social:{$uuid}", [
        'tenant_id' => $tenantId ?? Tenant::first()?->id,
        'paginas'   => $paginas,
    ], now()->addMinutes(5));

    return $uuid;
}

function paginaUm(): array
{
    return ['id' => 'page-1', 'nome' => 'Loja Principal', 'token' => 'PAGE-TOKEN-1'];
}

/*
 * listarPaginas (POST): fala com a Meta, mas NUNCA cria canal — so guarda a lista
 * (com token) no cache. Quem persiste e sempre o conectar.
 */

it('lista as páginas com token, sem devolver token ao navegador', function () {
    fakesDeListagem();

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.social.listar-paginas'), ['token' => 'token-curto-do-fblogin']);

    $resposta->assertOk()->assertJson(['ok' => true]);

    $paginas = $resposta->json('paginas');
    expect($paginas)->toHaveCount(2)
        ->and($paginas[0]['id'])->toBe('page-1')
        ->and($paginas[0]['nome'])->toBe('Loja Principal');

    // Nenhum campo de token no que volta para o navegador.
    $resposta->assertDontSee('PAGE-TOKEN-1')->assertDontSee('PAGE-TOKEN-2');

    $uuid = $resposta->json('uuid');
    $pendente = Cache::get("meta_social:{$uuid}");
    expect($pendente)->not->toBeNull()
        ->and($pendente['tenant_id'])->toBe($this->tenant->id)
        ->and($pendente['paginas'][0]['token'])->toBe('PAGE-TOKEN-1');
});

it('erro claro quando a conta não administra nenhuma Página', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'USER-TOKEN-LONGO']),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
    ]);

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.social.listar-paginas'), ['token' => 'token-curto']);

    $resposta->assertStatus(422)->assertJson(['ok' => false]);
});

it('erro claro quando a troca de token falha', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['message' => 'token invalido']], 400)]);

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.social.listar-paginas'), ['token' => 'token-curto']);

    $resposta->assertStatus(422)->assertJson(['ok' => false]);
});

it('recusa listar páginas para quem não é admin', function () {
    $semPermissao = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Sem admin', 'email' => 'semadmin@social.test',
        'password' => 'segredo123', 'admin' => false,
    ]);

    $resposta = $this->actingAs($semPermissao)
        ->postJson(route('canais.meta.social.listar-paginas'), ['token' => 'token-curto']);

    $resposta->assertForbidden();
});

/*
 * conectar (POST): persiste.
 */

it('cria o canal do Messenger', function () {
    fakesDeListagem();
    $uuid = guardarPaginasPendentes([paginaUm()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-1',
    ]);

    $resposta->assertOk()->assertJson(['ok' => true]);

    $canal = Channel::where('tipo', Channel::MESSENGER)->firstOrFail();
    expect($canal->meta_page_id)->toBe('page-1')
        ->and($canal->meta_token)->toBe('PAGE-TOKEN-1')
        ->and($canal->status)->toBe('open');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/page-1/subscribed_apps'));
});

it('reconecta um canal do Messenger existente em vez de criar outro', function () {
    fakesDeListagem();
    $canal = Channel::create([
        'nome' => 'Antiga', 'tipo' => Channel::MESSENGER,
        'meta_page_id' => 'page-velha', 'meta_token' => 'TOKEN-VELHO', 'status' => 'close',
    ])->refresh();

    $uuid = guardarPaginasPendentes([paginaUm()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-1', 'canal_id' => $canal->id,
    ]);

    $resposta->assertOk();
    expect(Channel::count())->toBe(1);

    $canal->refresh();
    expect($canal->meta_page_id)->toBe('page-1')
        ->and($canal->meta_token)->toBe('PAGE-TOKEN-1')
        ->and($canal->status)->toBe('open')
        // nome e do canal que ja existia — reconectar nao pode pisar num nome
        // que a pessoa ja personalizou.
        ->and($canal->nome)->toBe('Antiga');
});

it('recusa uuid inexistente ou expirado, sem criar canal', function () {
    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => (string) Illuminate\Support\Str::uuid(), 'page_id' => 'page-1',
    ]);

    $resposta->assertStatus(422)->assertJson(['ok' => false]);
    expect(Channel::count())->toBe(0);
});

it('recusa uuid de outra conta, sem criar canal', function () {
    $outroTenant = Tenant::create(['nome' => 'Outro', 'slug' => 'outro-social']);
    $uuid = guardarPaginasPendentes([paginaUm()], $outroTenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-1',
    ]);

    $resposta->assertForbidden();
    expect(Channel::count())->toBe(0);
    expect(Cache::get("meta_social:{$uuid}"))->toBeNull(); // consumido mesmo recusando
});

it('recusa reutilizar o mesmo uuid uma segunda vez', function () {
    fakesDeListagem();
    $uuid = guardarPaginasPendentes([paginaUm()], $this->tenant->id);

    $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-1',
    ])->assertOk();

    $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-1',
    ])->assertStatus(422);

    expect(Channel::count())->toBe(1);
});

it('recusa page_id que não está na lista guardada', function () {
    $uuid = guardarPaginasPendentes([paginaUm()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-que-nunca-existiu',
    ]);

    $resposta->assertStatus(422);
    expect(Channel::count())->toBe(0);
});

it('não cria canal quando a inscrição do webhook falha', function () {
    Http::fake(['graph.facebook.com/*/subscribed_apps*' => Http::response(['error' => ['message' => 'recusado']], 400)]);
    $uuid = guardarPaginasPendentes([paginaUm()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-1',
    ]);

    $resposta->assertStatus(422);
    expect(Channel::count())->toBe(0);
});

it('recusa quem não é admin no conectar', function () {
    $semPermissao = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Sem admin 2', 'email' => 'semadmin2@social.test',
        'password' => 'segredo123', 'admin' => false,
    ]);
    $uuid = guardarPaginasPendentes([paginaUm()], $this->tenant->id);

    $resposta = $this->actingAs($semPermissao)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-1',
    ]);

    $resposta->assertForbidden();
    expect(Channel::count())->toBe(0);
    expect(Cache::get("meta_social:{$uuid}"))->not->toBeNull();
});
