<?php

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\{Channel, Tenant, User};
use App\Services\Canais\MetaInstagramSignup;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * Login direto no Instagram ("Business Login for Instagram") — SEM Pagina do
 * Facebook no meio, e por isso SEM a etapa de escolher entre varias contas: a conta
 * e a que a pessoa usou para logar.
 *
 * O 'state' e o que impede um "code" forjado (de uma conta do Instagram que nao e a
 * pessoa que clicou aqui) de ser aceito — ver MetaInstagramSignupController. Por isso
 * cada teste guarda 'instagram_oauth_state' na sessao ANTES do GET de callback, do
 * mesmo jeito que ConectarMetaSocial::mount() faz antes de mostrar o link.
 */

beforeEach(function () {
    config([
        'services.meta.versao'               => 'v23.0',
        'services.meta.instagram_app_id'     => 'ig-app-id-teste',
        'services.meta.instagram_app_secret' => 'ig-app-secret-teste',
    ]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'instagram-signup']);
    TenantContext::set($this->tenant->id);

    $this->admin = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@instagram-signup.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    \Filament\Facades\Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

function fakesDoInstagramSignup(): void
{
    Http::fake([
        'api.instagram.com/oauth/access_token'   => Http::response(['access_token' => 'TOKEN-CURTO', 'user_id' => 'ig-999']),
        'graph.instagram.com/*/access_token*'     => Http::response(['access_token' => 'TOKEN-LONGO']),
        'graph.instagram.com/*/subscribed_apps*'  => Http::response(['success' => true]),
        'graph.instagram.com/*/ig-999*'           => Http::response(['username' => 'minhaloja']),
    ]);
}

it('monta a url de autorização com o escopo novo e o state', function () {
    $signup = new MetaInstagramSignup('ig-app-id-teste', 'ig-app-secret-teste', 'v23.0', 20);

    $url = $signup->urlDeAutorizacao('https://app.test/canais/instagram/callback', 'estado-xyz');

    expect($url)->toStartWith('https://www.instagram.com/oauth/authorize?')
        ->toContain('client_id=ig-app-id-teste')
        ->toContain('state=estado-xyz')
        ->toContain('instagram_business_basic')
        ->toContain('instagram_business_manage_comments')
        ->toContain('instagram_business_manage_messages');
});

it('a tela de conectar expõe a url do Instagram com o state guardado na sessão', function () {
    $resposta = $this->actingAs($this->admin)->get(ChannelResource::getUrl('conectar-meta-social'));

    $resposta->assertOk();

    $state = session('instagram_oauth_state');
    expect($state)->not->toBeNull()->not->toBe('');

    $resposta->assertSee($state, false);
});

it('cria o canal do Instagram quando code e state batem', function () {
    fakesDoInstagramSignup();

    $resposta = $this->actingAs($this->admin)
        ->withSession(['instagram_oauth_state' => 'estado-abc'])
        ->get(route('canais.instagram.callback', ['code' => 'codigo-do-instagram', 'state' => 'estado-abc']));

    $resposta->assertRedirect();

    $canal = Channel::where('tipo', Channel::INSTAGRAM)->firstOrFail();
    expect($canal->meta_ig_account_id)->toBe('ig-999')
        ->and($canal->meta_ig_username)->toBe('minhaloja')
        ->and($canal->meta_token)->toBe('TOKEN-LONGO')
        ->and($canal->nome)->toBe('@minhaloja')
        ->and($canal->status)->toBe('open')
        ->and($canal->tenant_id)->toBe($this->tenant->id);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/ig-999/subscribed_apps'));
});

it('reconecta um canal existente em vez de criar outro, quando canal_id está na sessão', function () {
    fakesDoInstagramSignup();

    $canal = Channel::create([
        'nome' => 'Antigo', 'tipo' => Channel::INSTAGRAM,
        'meta_ig_account_id' => 'ig-velho', 'meta_token' => 'TOKEN-VELHO', 'status' => 'close',
    ])->refresh();

    $resposta = $this->actingAs($this->admin)
        ->withSession(['instagram_oauth_state' => 'estado-abc', 'instagram_oauth_canal' => $canal->id])
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-abc']));

    $resposta->assertRedirect();
    expect(Channel::count())->toBe(1);

    $canal->refresh();
    expect($canal->meta_ig_account_id)->toBe('ig-999')
        ->and($canal->status)->toBe('open')
        // nome e do canal que ja existia — reconectar nao pode pisar num nome
        // que a pessoa ja personalizou.
        ->and($canal->nome)->toBe('Antigo');
});

it('recusa quando o state não bate com o guardado na sessão', function () {
    fakesDoInstagramSignup();

    $resposta = $this->actingAs($this->admin)
        ->withSession(['instagram_oauth_state' => 'estado-certo'])
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-errado']));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
    Http::assertNothingSent();
});

it('mostra o erro que o Instagram devolveu, sem chamar a API', function () {
    $resposta = $this->actingAs($this->admin)
        ->withSession(['instagram_oauth_state' => 'estado-abc'])
        ->get(route('canais.instagram.callback', [
            'state' => 'estado-abc', 'error' => 'access_denied',
            'error_description' => 'O usuário cancelou o login.',
        ]));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
});

it('mostra erro claro quando falta o code', function () {
    $resposta = $this->actingAs($this->admin)
        ->withSession(['instagram_oauth_state' => 'estado-abc'])
        ->get(route('canais.instagram.callback', ['state' => 'estado-abc']));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
});

it('não cria canal quando a inscrição do webhook falha', function () {
    Http::fake([
        'api.instagram.com/oauth/access_token'  => Http::response(['access_token' => 'TOKEN-CURTO', 'user_id' => 'ig-999']),
        'graph.instagram.com/*/access_token*'    => Http::response(['access_token' => 'TOKEN-LONGO']),
        'graph.instagram.com/*/subscribed_apps*' => Http::response(['error' => ['message' => 'recusado']], 400),
    ]);

    $resposta = $this->actingAs($this->admin)
        ->withSession(['instagram_oauth_state' => 'estado-abc'])
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-abc']));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
});

it('não cria canal quando a troca do code por token falha', function () {
    Http::fake(['api.instagram.com/oauth/access_token' => Http::response(['error_message' => 'code invalido'], 400)]);

    $resposta = $this->actingAs($this->admin)
        ->withSession(['instagram_oauth_state' => 'estado-abc'])
        ->get(route('canais.instagram.callback', ['code' => 'codigo-invalido', 'state' => 'estado-abc']));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
});

it('recusa quem não é admin', function () {
    $semPermissao = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Sem admin', 'email' => 'semadmin@instagram-signup.test',
        'password' => 'segredo123', 'admin' => false,
    ]);

    $resposta = $this->actingAs($semPermissao)
        ->withSession(['instagram_oauth_state' => 'estado-abc'])
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-abc']));

    $resposta->assertForbidden();
    expect(Channel::count())->toBe(0);
});
