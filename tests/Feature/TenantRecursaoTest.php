<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

afterEach(fn () => TenantContext::forget());

// O guard resolve o usuario consultando a tabela users. Essa consulta dispara o
// escopo global de tenant, que pergunta ao TenantContext qual e o tenant, que
// pergunta ao guard quem e o usuario — que ainda nao resolveu. Recursao infinita.
//
// actingAs() nao expoe isso porque injeta a instancia do usuario no guard, sem
// tocar no banco. Por isso os 45 testes passavam e a producao quebrava.
it('resolve o usuario da sessao sem entrar em recursao', function () {
    $t = Tenant::create(['nome' => 'T', 'slug' => 'rec']);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => 'rec@t.test', 'password' => 'segredo123']);
    TenantContext::forget();

    auth()->loginUsingId($u->id);
    auth()->forgetUser(); // obriga o guard a buscar no banco, como numa requisicao nova

    expect(auth()->user()?->id)->toBe($u->id)
        ->and(TenantContext::get())->toBe($t->id);
});

it('abre o painel admin com o usuario carregado da sessao', function () {
    $t = Tenant::create(['nome' => 'T', 'slug' => 'rec2']);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => 'rec2@t.test', 'password' => 'segredo123']);
    TenantContext::forget();

    // simula o que o navegador manda: id do usuario na sessao, sem instancia
    // pre-resolvida — o caminho que actingAs() nunca exercita
    $chave = 'login_web_'.sha1('Illuminate\Auth\SessionGuard');

    $this->withoutExceptionHandling();
    $this->withSession([$chave => $u->id])->get('/admin')->assertSuccessful();
});

it('mantem o isolamento entre tenants depois da correcao', function () {
    $a = Tenant::create(['nome' => 'A', 'slug' => 'ia']);
    $b = Tenant::create(['nome' => 'B', 'slug' => 'ib']);

    TenantContext::set($a->id);
    User::create(['tenant_id' => $a->id, 'name' => 'A', 'email' => 'ia@t.test', 'password' => 'segredo123']);
    TenantContext::set($b->id);
    $ub = User::create(['tenant_id' => $b->id, 'name' => 'B', 'email' => 'ib@t.test', 'password' => 'segredo123']);
    TenantContext::forget();

    // usuario de B logado: o contexto passa a ser B e ele so ve o proprio tenant
    auth()->loginUsingId($ub->id);
    auth()->forgetUser();

    expect(TenantContext::get())->toBe($b->id)
        ->and(User::count())->toBe(1)
        ->and(User::first()->email)->toBe('ib@t.test');
});
