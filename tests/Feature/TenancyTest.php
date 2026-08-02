<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

afterEach(fn () => TenantContext::forget());

it('preenche tenant_id automaticamente na criacao', function () {
    $tenant = Tenant::create(['nome' => 'Acme', 'slug' => 'acme']);
    TenantContext::set($tenant->id);

    $user = User::create([
        'name' => 'Fulano', 'email' => 'f@acme.test', 'password' => 'segredo123',
    ]);

    expect($user->tenant_id)->toBe($tenant->id);
});

it('nao deixa um tenant enxergar dado do outro', function () {
    $a = Tenant::create(['nome' => 'A', 'slug' => 'a']);
    $b = Tenant::create(['nome' => 'B', 'slug' => 'b']);

    TenantContext::set($a->id);
    User::create(['name' => 'De A', 'email' => 'a@t.test', 'password' => 'segredo123']);

    TenantContext::set($b->id);
    User::create(['name' => 'De B', 'email' => 'b@t.test', 'password' => 'segredo123']);

    expect(User::count())->toBe(1)
        ->and(User::first()->name)->toBe('De B');

    TenantContext::set($a->id);
    expect(User::count())->toBe(1)
        ->and(User::first()->name)->toBe('De A');
});

it('enxerga tudo quando nao ha tenant no contexto', function () {
    $a = Tenant::create(['nome' => 'A', 'slug' => 'a']);

    TenantContext::set($a->id);
    User::create(['name' => 'De A', 'email' => 'a@t.test', 'password' => 'segredo123']);

    TenantContext::set(null);
    expect(User::count())->toBe(1);
});

it('respeita tenant_id passado explicitamente na atribuicao em massa', function () {
    $a = Tenant::create(['nome' => 'A', 'slug' => 'a']);
    $b = Tenant::create(['nome' => 'B', 'slug' => 'b']);

    // contexto e A, mas queremos criar o usuario em B de proposito
    TenantContext::set($a->id);

    $user = User::create([
        'tenant_id' => $b->id,
        'name' => 'De B', 'email' => 'b@t.test', 'password' => 'segredo123',
    ]);

    expect($user->tenant_id)->toBe($b->id);
});
