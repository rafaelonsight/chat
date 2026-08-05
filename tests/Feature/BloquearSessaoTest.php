<?php

use App\Http\Middleware\SessaoBloqueada;
use App\Models\{Tenant, User};
use App\Support\TenantContext;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'blq']);
    TenantContext::set($this->tenant->id);

    $this->user = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Rafael Paulino',
        'email' => 'r@blq.test', 'password' => bcrypt('segredo123'), 'admin' => true,
    ]);
});

afterEach(fn () => TenantContext::forget());

it('bloquear exige POST: link visitado por engano nao tranca ninguem', function () {
    $this->actingAs($this->user)->get('/sessao/bloquear')->assertStatus(405);
});

it('bloquear leva para a tela de bloqueio', function () {
    $this->actingAs($this->user)
        ->post('/sessao/bloquear')
        ->assertRedirect(route('sessao.travada'));

    expect(session(SessaoBloqueada::CHAVE))->toBeTrue();
});

it('com a sessao travada, o painel redireciona para a tela de bloqueio', function () {
    // O ponto do recurso: nao basta mostrar a tela, tem de barrar o painel.
    $this->actingAs($this->user)->post('/sessao/bloquear');

    $this->actingAs($this->user)->get('/admin')->assertRedirect(route('sessao.travada'));
});

it('a tela de bloqueio mostra o nome e as iniciais de quem travou', function () {
    $this->actingAs($this->user)->post('/sessao/bloquear');

    $this->actingAs($this->user)
        ->get('/sessao/travada')
        ->assertOk()
        ->assertSee('Rafael Paulino')
        ->assertSee('RP');   // primeira e ultima inicial
});

it('senha errada nao destrava', function () {
    $this->actingAs($this->user)->post('/sessao/bloquear');

    $this->actingAs($this->user)
        ->post('/sessao/destravar', ['senha' => 'chute'])
        ->assertSessionHasErrors('senha');

    expect(session(SessaoBloqueada::CHAVE))->toBeTrue();
});

it('senha certa destrava e devolve o painel', function () {
    $this->actingAs($this->user)->post('/sessao/bloquear');

    $this->actingAs($this->user)
        ->post('/sessao/destravar', ['senha' => 'segredo123'])
        ->assertRedirect('/admin');

    expect(session(SessaoBloqueada::CHAVE))->toBeNull();

    $this->actingAs($this->user)->get('/admin')->assertSuccessful();
});

it('travada, a SAIDA continua funcionando: bloqueio nao e armadilha', function () {
    $this->actingAs($this->user)->post('/sessao/bloquear');

    $this->actingAs($this->user)->post(route('filament.admin.auth.logout'))
        ->assertRedirect();

    expect(auth()->check())->toBeFalse();
});

it('sessao destravada nao fica presa na tela de bloqueio', function () {
    $this->actingAs($this->user)->get('/sessao/travada')->assertRedirect('/admin');
});

it('bloqueio e por sessao, nao por usuario: nao derruba o mesmo usuario noutra maquina', function () {
    // A trava vive na sessao. Uma sessao nova do MESMO usuario entra normalmente —
    // travar no balcao nao pode trancar o celular.
    $this->actingAs($this->user)->post('/sessao/bloquear');

    $this->flushSession();

    $this->actingAs($this->user)->get('/admin')->assertSuccessful();
});

it('a limpeza de dados locais nao mexe na sessao', function () {
    // Quem clica em "limpar dados" nao pediu para sair.
    $this->actingAs($this->user)
        ->get('/sessao/limpar-navegador')
        ->assertOk()
        ->assertSee('Limpando os dados deste navegador');

    expect(auth()->check())->toBeTrue();
});

it('visitante nao alcanca nenhuma dessas rotas', function () {
    $this->post('/sessao/bloquear')->assertRedirect();
    $this->get('/sessao/travada')->assertRedirect();
    $this->get('/sessao/limpar-navegador')->assertRedirect();
});
