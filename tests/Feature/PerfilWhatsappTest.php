<?php

use App\Jobs\AtualizarPerfilWhatsapp;
use App\Models\{Channel, Contact, Tenant};
use App\Services\EvolutionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{Http, Storage};

afterEach(fn () => TenantContext::forget());

it('baixa e guarda a foto publica do whatsapp', function () {
    Storage::fake('public');
    Http::fake([
        '*/chat/fetchProfilePictureUrl/*' => Http::response(['profilePictureUrl' => 'https://cdn.test/perfil.jpg']),
        'https://cdn.test/perfil.jpg' => Http::response('imagem-binaria', 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $tenant = Tenant::create(['nome' => 'T', 'slug' => 'perfil']);
    TenantContext::set($tenant->id);
    $canal = Channel::create(['nome' => 'Principal', 'instance_name' => 'instancia']);
    $contato = Contact::create(['jid' => '5511999998888@s.whatsapp.net', 'telefone_e164' => '+5511999998888']);

    (new AtualizarPerfilWhatsapp($canal->id, $contato->id))->handle(app(EvolutionService::class));

    $contato->refresh();
    expect($contato->foto_whatsapp)->not->toBeNull()
        ->and($contato->foto_whatsapp_consultada_em)->not->toBeNull();
    Storage::disk('public')->assertExists($contato->foto_whatsapp);
});

it('mantem avatar sem foto quando a privacidade impede a consulta', function () {
    Storage::fake('public');
    Http::fake(['*/chat/fetchProfilePictureUrl/*' => Http::response(['profilePictureUrl' => null])]);
    $tenant = Tenant::create(['nome' => 'T', 'slug' => 'privado']);
    TenantContext::set($tenant->id);
    $canal = Channel::create(['nome' => 'Principal', 'instance_name' => 'instancia']);
    $contato = Contact::create(['jid' => '5511999998888@s.whatsapp.net', 'telefone_e164' => '+5511999998888']);

    (new AtualizarPerfilWhatsapp($canal->id, $contato->id))->handle(app(EvolutionService::class));

    expect($contato->refresh()->foto_whatsapp)->toBeNull()
        ->and($contato->foto_whatsapp_consultada_em)->not->toBeNull();
});
