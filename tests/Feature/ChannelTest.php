<?php

use App\Models\Channel;
use App\Models\Tenant;
use App\Support\TenantContext;

afterEach(fn () => TenantContext::forget());

it('gera segredo de webhook e nome de instancia derivado do tenant', function () {
    $t = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($t->id);

    $c = Channel::create(['nome' => 'Principal'])->refresh();

    expect($c->tenant_id)->toBe($t->id)
        ->and(strlen($c->webhook_secret))->toBe(48)
        ->and($c->instance_name)->toBe("t{$t->id}-c{$c->id}")
        ->and($c->status)->toBe('desconectado')
        ->and($c->conectado())->toBeFalse();
});

it('monta a url de webhook com o segredo', function () {
    $t = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($t->id);

    $c = Channel::create(['nome' => 'P'])->refresh();

    expect($c->webhookUrl())->toContain("/webhooks/evolution/{$c->id}/{$c->webhook_secret}");
});

it('nao mostra canal de outro tenant', function () {
    $a = Tenant::create(['nome' => 'A', 'slug' => 'a']);
    $b = Tenant::create(['nome' => 'B', 'slug' => 'b']);

    TenantContext::set($a->id);
    Channel::create(['nome' => 'Do A']);

    TenantContext::set($b->id);
    expect(Channel::count())->toBe(0);
});
