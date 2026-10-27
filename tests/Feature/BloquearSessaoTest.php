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

it('bloquear encerra a sessão e exige novo código', function () {
    $this->actingAs($this->user)->post('/sessao/bloquear')->assertRedirect('/admin/login');
    $this->assertGuest();
    $this->get('/admin')->assertRedirect();
});

it('senha não reabre uma sessão', function () {
    $this->actingAs($this->user)->post('/sessao/destravar', ['senha'=>'segredo123'])->assertNotFound();
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
