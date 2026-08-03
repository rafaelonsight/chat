<?php

use App\Filament\Pages\Atendimento;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

function usuario(string $slug, bool $admin): User
{
    $t = Tenant::firstOrCreate(['slug' => $slug], ['nome' => strtoupper($slug)]);
    TenantContext::set($t->id);
    $u = User::create([
        'tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test",
        'password' => 'segredo123', 'admin' => $admin,
    ]);
    TenantContext::forget();

    return $u;
}

function comSessao(User $u): array
{
    return ['login_web_'.sha1('Illuminate\Auth\SessionGuard') => $u->id];
}

it('a raiz leva para o login quando nao ha sessao', function () {
    $this->get('/')->assertRedirect('/admin');
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('a raiz leva para o atendimento quando ha sessao', function () {
    $u = usuario('org1', true);

    $this->withSession(comSessao($u))->get('/')->assertRedirect('/admin');
    $this->withSession(comSessao($u))->get('/admin')->assertSuccessful();
});

it('o atendimento e a home do painel e mostra o chat', function () {
    $u = usuario('org2', true);

    $this->withoutExceptionHandling();
    $this->withSession(comSessao($u))
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Novas')
        ->assertSee('Em atendimento')
        ->assertSee('Arquivadas')
        ->assertSee('Nova conversa');
});

it('a rota antiga do inbox continua funcionando por redirecionamento', function () {
    $u = usuario('org3', true);

    $this->withSession(comSessao($u))->get('/inbox')->assertRedirect('/admin');
});

it('so admin ve Usuarios e Canais', function () {
    $admin = usuario('org4', true);
    $atendente = usuario('org5', false);

    TenantContext::set($admin->tenant_id);
    expect(UserResource::canViewAny())->toBeFalse(); // sem usuario autenticado
    TenantContext::forget();

    $this->actingAs($admin);
    expect(UserResource::canViewAny())->toBeTrue()
        ->and(ChannelResource::canViewAny())->toBeTrue();

    auth()->logout();
    $this->actingAs($atendente);
    expect(UserResource::canViewAny())->toBeFalse()
        ->and(ChannelResource::canViewAny())->toBeFalse();
});

it('atendente e barrado nas telas de configuracao', function () {
    $atendente = usuario('org6', false);

    $this->withSession(comSessao($atendente))->get('/admin/users')->assertForbidden();
    $this->withSession(comSessao($atendente))->get('/admin/channels')->assertForbidden();
    // mas o atendimento abre normal
    $this->withSession(comSessao($atendente))->get('/admin')->assertSuccessful();
});

it('admin abre a lista de usuarios e ve so os do proprio tenant', function () {
    $a = usuario('org7', true);
    usuario('org8', true); // outro tenant

    $this->withoutExceptionHandling();
    $this->withSession(comSessao($a))->get('/admin/users')->assertSuccessful();

    $this->actingAs($a);
    expect(User::count())->toBe(1);
});

it('o atendimento fica agrupado fora de Configuracoes', function () {
    expect(Atendimento::getNavigationGroup())->toBeNull()
        ->and(UserResource::getNavigationGroup())->toBe('Configurações')
        ->and(ChannelResource::getNavigationGroup())->toBe('Configurações');
});
