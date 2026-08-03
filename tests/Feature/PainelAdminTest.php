<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

afterEach(fn () => TenantContext::forget());

it('abre o painel admin com usuario autenticado', function () {
    $t = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => 'u@t.test', 'password' => 'segredo123', 'admin' => true]);
    TenantContext::forget();

    $this->withoutExceptionHandling();

    $this->actingAs($u)->get('/admin')->assertSuccessful();
});

it('abre a lista de canais no painel', function () {
    $t = Tenant::create(['nome' => 'T', 'slug' => 't2']);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => 'u2@t.test', 'password' => 'segredo123', 'admin' => true]);
    TenantContext::forget();

    $this->withoutExceptionHandling();

    $this->actingAs($u)->get('/admin/channels')->assertSuccessful();
});
