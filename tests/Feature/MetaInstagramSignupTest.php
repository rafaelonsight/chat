<?php

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\{Channel, Tenant, User};
use App\Services\Canais\MetaInstagramSignup;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Login direto no Instagram ("Business Login for Instagram") — SEM Pagina do
 * Facebook no meio, e por isso SEM a etapa de escolher entre varias contas: a conta
 * e a que a pessoa usou para logar.
 *
 * O 'state' e o que impede um "code" forjado (de uma conta do Instagram que nao e a
 * pessoa que clicou aqui) de ser aceito — ver MetaInstagramSignupController. Por isso
 * cada teste guarda o pendente no CACHE (nao sessao) sob a chave "instagram_oauth:{state}"
 * ANTES do GET de callback, do mesmo jeito que ConectarMetaSocial::mount() faz antes de
 * mostrar o link — sessao foi abandonada porque e compartilhada entre abas do mesmo
 * navegador (ver ConectarMetaSocial).
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
    $this->admin->forceFill(['perfil_preenchido_em' => now()])->saveQuietly();

    \Filament\Facades\Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

function fakesDoInstagramSignup(): void
{
    Http::fake([
        'api.instagram.com/oauth/access_token'   => Http::response(['access_token' => 'TOKEN-CURTO', 'user_id' => 'ig-999']),
        'graph.instagram.com/access_token*'     => Http::response(['access_token' => 'TOKEN-LONGO']),
        'graph.instagram.com/*/subscribed_apps*'  => Http::response(['success' => true]),
        'graph.instagram.com/*/ig-999*'           => Http::response(['username' => 'minhaloja']),
    ]);
}

function guardaPendenteDoInstagram(string $state, int $tenantId, ?int $canalId = null): void
{
    Cache::put("instagram_oauth:{$state}", ['tenant_id' => $tenantId, 'canal_id' => $canalId], now()->addMinutes(10));
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

it('a tela de conectar expõe a url do Instagram com um state guardado no cache', function () {
    $resposta = $this->actingAs($this->admin)->get(ChannelResource::getUrl('conectar-meta-social'));

    $resposta->assertOk();

    preg_match('/state=([a-zA-Z0-9]+)/', $resposta->getContent(), $m);
    $state = $m[1] ?? null;

    expect($state)->not->toBeNull();
    expect(Cache::get("instagram_oauth:{$state}"))->not->toBeNull();
});

it('cria o canal do Instagram quando code e state batem', function () {
    fakesDoInstagramSignup();
    guardaPendenteDoInstagram('estado-abc', $this->tenant->id);

    $resposta = $this->actingAs($this->admin)
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

it('não deixa reusar o mesmo state duas vezes', function () {
    fakesDoInstagramSignup();
    guardaPendenteDoInstagram('estado-abc', $this->tenant->id);

    $primeira = $this->actingAs($this->admin)
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-abc']));
    $primeira->assertRedirect();
    expect(Channel::count())->toBe(1);

    $segunda = $this->actingAs($this->admin)
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-abc']));
    $segunda->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(1);
});

it('reconecta um canal existente em vez de criar outro, quando canal_id está no cache', function () {
    fakesDoInstagramSignup();

    $canal = Channel::create([
        'nome' => 'Antigo', 'tipo' => Channel::INSTAGRAM,
        'meta_ig_account_id' => 'ig-velho', 'meta_token' => 'TOKEN-VELHO', 'status' => 'close',
    ])->refresh();

    guardaPendenteDoInstagram('estado-abc', $this->tenant->id, $canal->id);

    $resposta = $this->actingAs($this->admin)
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

it('avisa quando a reconexão troca de conta do Instagram', function () {
    fakesDoInstagramSignup();

    $canal = Channel::create([
        'nome' => 'Antigo', 'tipo' => Channel::INSTAGRAM,
        'meta_ig_account_id' => 'ig-velho', 'meta_ig_username' => 'contavelha',
        'meta_token' => 'TOKEN-VELHO', 'status' => 'open',
    ])->refresh();

    guardaPendenteDoInstagram('estado-abc', $this->tenant->id, $canal->id);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-abc']));

    $resposta->assertRedirect()->assertSessionHas('aviso_troca_de_conta_social');

    $canal->refresh();
    expect($canal->meta_ig_account_id)->toBe('ig-999');
});

it('não avisa quando a reconexão é da mesma conta do Instagram', function () {
    fakesDoInstagramSignup();

    $canal = Channel::create([
        'nome' => 'Antigo', 'tipo' => Channel::INSTAGRAM,
        'meta_ig_account_id' => 'ig-999', 'meta_ig_username' => 'minhaloja',
        'meta_token' => 'TOKEN-VELHO', 'status' => 'open',
    ])->refresh();

    guardaPendenteDoInstagram('estado-abc', $this->tenant->id, $canal->id);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-abc']));

    $resposta->assertRedirect()->assertSessionMissing('aviso_troca_de_conta_social');
});

it('recusa quando o state não bate com o guardado no cache', function () {
    fakesDoInstagramSignup();
    guardaPendenteDoInstagram('estado-certo', $this->tenant->id);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-errado']));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
    Http::assertNothingSent();
});

it('recusa quando o pendente do cache é de outro tenant', function () {
    $outroTenant = Tenant::create(['nome' => 'Outro', 'slug' => 'instagram-signup-outro']);
    guardaPendenteDoInstagram('estado-abc', $outroTenant->id);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-abc']));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
    Http::assertNothingSent();
});

it('mostra o erro que o Instagram devolveu, sem chamar a API', function () {
    guardaPendenteDoInstagram('estado-abc', $this->tenant->id);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.instagram.callback', [
            'state' => 'estado-abc', 'error' => 'access_denied',
            'error_description' => 'O usuário cancelou o login.',
        ]));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
});

it('mostra erro claro quando falta o code', function () {
    guardaPendenteDoInstagram('estado-abc', $this->tenant->id);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.instagram.callback', ['state' => 'estado-abc']));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
});

it('não cria canal quando a inscrição do webhook falha', function () {
    Http::fake([
        'api.instagram.com/oauth/access_token'  => Http::response(['access_token' => 'TOKEN-CURTO', 'user_id' => 'ig-999']),
        'graph.instagram.com/access_token*'    => Http::response(['access_token' => 'TOKEN-LONGO']),
        'graph.instagram.com/*/subscribed_apps*' => Http::response(['error' => ['message' => 'recusado']], 400),
    ]);
    guardaPendenteDoInstagram('estado-abc', $this->tenant->id);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-abc']));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
});

it('não cria canal quando a troca do code por token falha', function () {
    Http::fake(['api.instagram.com/oauth/access_token' => Http::response(['error_message' => 'code invalido'], 400)]);
    guardaPendenteDoInstagram('estado-abc', $this->tenant->id);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.instagram.callback', ['code' => 'codigo-invalido', 'state' => 'estado-abc']));

    $resposta->assertRedirect()->assertSessionHas('erro_instagram');
    expect(Channel::count())->toBe(0);
});

it('recusa quem não é admin', function () {
    $semPermissao = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Sem admin', 'email' => 'semadmin@instagram-signup.test',
        'password' => 'segredo123', 'admin' => false,
    ]);
    $semPermissao->forceFill(['perfil_preenchido_em' => now()])->saveQuietly();

    $resposta = $this->actingAs($semPermissao)
        ->get(route('canais.instagram.callback', ['code' => 'codigo', 'state' => 'estado-abc']));

    $resposta->assertForbidden();
    expect(Channel::count())->toBe(0);
});


it('usa o endpoint sem versao e aceita a resposta documentada em data', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.instagram.com/oauth/access_token' => Http::response(['data' => [['access_token' => 'CURTO', 'user_id' => 'ig-999']]]),
        'graph.instagram.com/access_token*' => Http::response(['access_token' => 'LONGO']),
        'graph.instagram.com/v23.0/ig-999/subscribed_apps' => Http::response(['success' => true]),
        'graph.instagram.com/v23.0/ig-999*' => Http::response(['username' => 'loja']),
    ]);
    $signup = new MetaInstagramSignup('app', 'secret', 'v23.0', 20);
    expect($signup->conectar('codigo', 'https://app.test/callback')['ok'])->toBeTrue();
    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && strtok($r->url(), '?') === 'https://graph.instagram.com/access_token'
        && $r['grant_type'] === 'ig_exchange_token');
});
