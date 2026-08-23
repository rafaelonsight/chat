<?php

use App\Models\{Channel, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Conexao de Messenger e Instagram pelo Login do Facebook.
 *
 * Duas rotas, na MESMA aba (sem popup, ao contrario do Cadastro Incorporado do
 * WhatsApp): `listarPaginas` troca o token curto do FB.login() por um de longa
 * duracao, lista as Paginas e guarda o resultado (com token de cada uma) num cache de
 * curta duracao — devolvendo ao navegador so o que ele precisa para desenhar a
 * escolha. `conectar` persiste no banco, usando o uuid desse cache.
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
            ['id' => 'page-com-ig', 'name' => 'Loja Principal', 'access_token' => 'PAGE-TOKEN-COM-IG'],
            ['id' => 'page-sem-ig', 'name' => 'Loja Sem Instagram', 'access_token' => 'PAGE-TOKEN-SEM-IG'],
        ]]),
        'graph.facebook.com/*/subscribed_apps*' => Http::response(['success' => true]),
        'graph.facebook.com/*/page-com-ig*'     => Http::response(['instagram_business_account' => ['id' => 'ig-123', 'username' => 'minhaloja']]),
        'graph.facebook.com/*/page-sem-ig*'     => Http::response([]),
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

function paginaComInstagram(): array
{
    return ['id' => 'page-com-ig', 'nome' => 'Loja Principal', 'token' => 'PAGE-TOKEN-COM-IG', 'tem_instagram' => true, 'ig_account_id' => 'ig-123', 'ig_username' => 'minhaloja'];
}

function paginaSemInstagram(): array
{
    return ['id' => 'page-sem-ig', 'nome' => 'Loja Sem Instagram', 'token' => 'PAGE-TOKEN-SEM-IG', 'tem_instagram' => false, 'ig_account_id' => null, 'ig_username' => null];
}

/*
 * listarPaginas (POST): fala com a Meta, mas NUNCA cria canal — so guarda a lista
 * (com token) no cache. Quem persiste e sempre o conectar.
 */

it('lista as páginas com token e diz quais têm Instagram, sem devolver token ao navegador', function () {
    fakesDeListagem();

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.social.listar-paginas'), ['token' => 'token-curto-do-fblogin']);

    $resposta->assertOk()->assertJson(['ok' => true]);

    $paginas = $resposta->json('paginas');
    expect($paginas)->toHaveCount(2)
        ->and($paginas[0]['id'])->toBe('page-com-ig')
        ->and($paginas[0]['tem_instagram'])->toBeTrue()
        ->and($paginas[0]['ig_username'])->toBe('minhaloja')
        ->and($paginas[1]['tem_instagram'])->toBeFalse();

    // Nenhum campo de token no que volta para o navegador.
    $resposta->assertDontSee('PAGE-TOKEN-COM-IG')->assertDontSee('PAGE-TOKEN-SEM-IG');

    $uuid = $resposta->json('uuid');
    $pendente = Cache::get("meta_social:{$uuid}");
    expect($pendente)->not->toBeNull()
        ->and($pendente['tenant_id'])->toBe($this->tenant->id)
        ->and($pendente['paginas'][0]['token'])->toBe('PAGE-TOKEN-COM-IG');
});

it('erro claro quando a conta não administra nenhuma Página', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'USER-TOKEN-LONGO']),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
    ]);

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.social.listar-paginas'), ['token' => 'token-curto']);

    $resposta->assertStatus(422)->assertJson(['ok' => false]);
    expect(Cache::get('meta_social'))->toBeNull();
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
 * conectar (POST): persiste. Sempre cria o Messenger; o Instagram e opcional.
 */

it('cria o canal do Messenger quando não marca conectar_instagram', function () {
    fakesDeListagem();
    $uuid = guardarPaginasPendentes([paginaComInstagram()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-com-ig', 'conectar_instagram' => false,
    ]);

    $resposta->assertOk()->assertJson(['ok' => true]);

    expect(Channel::count())->toBe(1);
    $canal = Channel::where('tipo', Channel::MESSENGER)->firstOrFail();
    expect($canal->meta_page_id)->toBe('page-com-ig')
        ->and($canal->meta_token)->toBe('PAGE-TOKEN-COM-IG')
        ->and($canal->status)->toBe('open');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/page-com-ig/subscribed_apps'));
});

it('cria os dois canais quando marca conectar_instagram numa página que tem Instagram', function () {
    fakesDeListagem();
    $uuid = guardarPaginasPendentes([paginaComInstagram()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-com-ig', 'conectar_instagram' => true,
    ]);

    $resposta->assertOk()->assertJson(['ok' => true]);
    expect(Channel::count())->toBe(2);

    $messenger = Channel::where('tipo', Channel::MESSENGER)->firstOrFail();
    $instagram = Channel::where('tipo', Channel::INSTAGRAM)->firstOrFail();

    expect($messenger->meta_page_id)->toBe('page-com-ig')
        ->and($instagram->meta_page_id)->toBe('page-com-ig')
        ->and($instagram->meta_ig_account_id)->toBe('ig-123')
        ->and($instagram->meta_ig_username)->toBe('minhaloja')
        ->and($instagram->nome)->toBe('@minhaloja')
        ->and($instagram->status)->toBe('open');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/ig-123/subscribed_apps'));
});

it('marca conectar_instagram numa página sem Instagram cria só o Messenger', function () {
    fakesDeListagem();
    $uuid = guardarPaginasPendentes([paginaSemInstagram()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-sem-ig', 'conectar_instagram' => true,
    ]);

    $resposta->assertOk();
    expect(Channel::count())->toBe(1)
        ->and(Channel::first()->tipo)->toBe(Channel::MESSENGER);
});

it('reconecta um canal do Messenger existente em vez de criar outro', function () {
    fakesDeListagem();
    $canal = Channel::create([
        'nome' => 'Antiga', 'tipo' => Channel::MESSENGER,
        'meta_page_id' => 'page-velha', 'meta_token' => 'TOKEN-VELHO', 'status' => 'close',
    ])->refresh();

    $uuid = guardarPaginasPendentes([paginaComInstagram()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-com-ig', 'canal_id' => $canal->id,
    ]);

    $resposta->assertOk();
    expect(Channel::count())->toBe(1);

    $canal->refresh();
    expect($canal->meta_page_id)->toBe('page-com-ig')
        ->and($canal->meta_token)->toBe('PAGE-TOKEN-COM-IG')
        ->and($canal->status)->toBe('open')
        // nome e do canal que ja existia — reconectar nao pode pisar num nome
        // que a pessoa ja personalizou.
        ->and($canal->nome)->toBe('Antiga');
});

it('reconectar um canal do Instagram para uma página sem Instagram recusa, sem tocar no canal', function () {
    fakesDeListagem();
    $canal = Channel::create([
        'nome' => 'Instagram antigo', 'tipo' => Channel::INSTAGRAM,
        'meta_page_id' => 'page-velha', 'meta_ig_account_id' => 'ig-velho', 'meta_token' => 'TOKEN-VELHO',
    ])->refresh();

    $uuid = guardarPaginasPendentes([paginaSemInstagram()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-sem-ig', 'canal_id' => $canal->id,
    ]);

    $resposta->assertStatus(422)->assertJson(['ok' => false]);

    $canal->refresh();
    expect($canal->meta_ig_account_id)->toBe('ig-velho'); // nao mudou nada
});

it('recusa uuid inexistente ou expirado, sem criar canal', function () {
    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => (string) Illuminate\Support\Str::uuid(), 'page_id' => 'page-com-ig',
    ]);

    $resposta->assertStatus(422)->assertJson(['ok' => false]);
    expect(Channel::count())->toBe(0);
});

it('recusa uuid de outra conta, sem criar canal', function () {
    $outroTenant = Tenant::create(['nome' => 'Outro', 'slug' => 'outro-social']);
    $uuid = guardarPaginasPendentes([paginaComInstagram()], $outroTenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-com-ig',
    ]);

    $resposta->assertForbidden();
    expect(Channel::count())->toBe(0);
    expect(Cache::get("meta_social:{$uuid}"))->toBeNull(); // consumido mesmo recusando
});

it('recusa reutilizar o mesmo uuid uma segunda vez', function () {
    fakesDeListagem();
    $uuid = guardarPaginasPendentes([paginaComInstagram()], $this->tenant->id);

    $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-com-ig',
    ])->assertOk();

    $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-com-ig',
    ])->assertStatus(422);

    expect(Channel::count())->toBe(1);
});

it('recusa page_id que não está na lista guardada', function () {
    $uuid = guardarPaginasPendentes([paginaComInstagram()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-que-nunca-existiu',
    ]);

    $resposta->assertStatus(422);
    expect(Channel::count())->toBe(0);
});

it('não cria canal quando a inscrição do webhook do Messenger falha', function () {
    Http::fake(['graph.facebook.com/*/subscribed_apps*' => Http::response(['error' => ['message' => 'recusado']], 400)]);
    $uuid = guardarPaginasPendentes([paginaComInstagram()], $this->tenant->id);

    $resposta = $this->actingAs($this->admin)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-com-ig',
    ]);

    $resposta->assertStatus(422);
    expect(Channel::count())->toBe(0);
});

it('recusa quem não é admin no conectar', function () {
    $semPermissao = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Sem admin 2', 'email' => 'semadmin2@social.test',
        'password' => 'segredo123', 'admin' => false,
    ]);
    $uuid = guardarPaginasPendentes([paginaComInstagram()], $this->tenant->id);

    $resposta = $this->actingAs($semPermissao)->postJson(route('canais.meta.social.conectar'), [
        'uuid' => $uuid, 'page_id' => 'page-com-ig',
    ]);

    $resposta->assertForbidden();
    expect(Channel::count())->toBe(0);
    expect(Cache::get("meta_social:{$uuid}"))->not->toBeNull();
});
