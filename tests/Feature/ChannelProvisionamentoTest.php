<?php

use App\Models\Channel;
use App\Models\Tenant;
use App\Services\EvolutionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

afterEach(fn () => TenantContext::forget());

it('provisiona a instancia na Evolution com a url secreta de webhook', function () {
    Http::fake([
        '*/instance/create'    => Http::response(['instance' => ['instanceName' => 'x']], 201),
        '*/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,AAA'], 200),
    ]);

    $t = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($t->id);

    $canal = Channel::create(['nome' => 'Principal'])->refresh();

    app(EvolutionService::class)->createInstance($canal->instance_name, $canal->webhookUrl());

    expect($canal->instance_name)->toBe("t{$t->id}-c{$canal->id}")
        ->and(strlen($canal->webhook_secret))->toBe(48);

    Http::assertSent(fn ($r) => str_contains($r['webhook']['url'], $canal->webhook_secret));
});

it('mostra o QR e marca conectado quando o estado vira open', function () {
    Http::fake([
        '*/instance/connectionState/*' => Http::sequence()
            ->push(['instance' => ['state' => 'connecting']], 200)
            ->push(['instance' => ['state' => 'open']], 200),
        '*/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,QRQR'], 200),
    ]);

    $t = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($t->id);
    $canal = Channel::create(['nome' => 'P'])->refresh();

    $c = Livewire\Livewire::test(App\Livewire\ChannelQrCode::class, ['channel' => $canal]);

    $c->assertSet('estado', 'connecting')
        ->assertSet('qrBase64', 'data:image/png;base64,QRQR');

    $c->call('atualizar')->assertSet('estado', 'open')->assertSet('qrBase64', null);

    expect($canal->refresh()->status)->toBe('open')
        ->and($canal->conectado())->toBeTrue();
});

it('registra o erro no canal quando a Evolution falha', function () {
    Http::fake(['*' => Http::response(['message' => 'fora do ar'], 500)]);

    $t = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($t->id);
    $canal = Channel::create(['nome' => 'P'])->refresh();

    Livewire\Livewire::test(App\Livewire\ChannelQrCode::class, ['channel' => $canal])
        ->assertSet('estado', 'erro');

    expect($canal->refresh()->ultimo_erro)->not->toBeNull();
});
