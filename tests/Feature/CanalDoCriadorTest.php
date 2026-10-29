<?php

use App\Models\{Channel, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * QUEM CRIA O CANAL ABRE O CANAL. O que se perde se quebrar: o administrador do cliente cria o
 * WhatsApp e cai num 404 em vez do QR — achado na homologacao, porque a suite promove todo
 * mundo a Super Admin e o problema so aparece para admin comum.
 */

beforeEach(function () {
    Http::fake(['*' => Http::response([])]);
    $this->conta = Tenant::create(['nome' => 'Criador', 'slug' => 'criador']);
    TenantContext::set($this->conta->id);
    $this->admin = User::create(['tenant_id' => $this->conta->id, 'name' => 'Ana', 'email' => 'ana@criador.test', 'password' => 'segredo123', 'admin' => true]);
    $this->admin->forceFill(['super_admin' => false, 'perfil_preenchido_em' => now()])->saveQuietly();
});

afterEach(fn () => TenantContext::forget());

it('admin comum que cria canal de qualquer tipo fica ligado a ele e abre o QR', function (string $tipo) {
    $this->actingAs($this->admin->fresh());

    $canal = Channel::create(['tenant_id' => $this->conta->id, 'nome' => 'Novo', 'tipo' => $tipo, 'status' => 'close']);

    expect($this->admin->fresh()->canalIds())->toContain($canal->id)
        ->and(Channel::find($canal->id))->not->toBeNull();
})->with(['evolution', 'meta_cloud', 'site', 'instagram', 'messenger']);

it('a tela de conectar abre para o admin que acabou de criar o WhatsApp', function () {
    $this->actingAs($this->admin->fresh());
    $canal = Channel::create(['tenant_id' => $this->conta->id, 'nome' => 'Novo', 'tipo' => 'evolution', 'status' => 'close']);

    $this->get("/admin/channels/{$canal->id}/conectar")->assertOk();
});

it('atendente nao vira dono do canal por criar', function () {
    $atendente = User::create(['tenant_id' => $this->conta->id, 'name' => 'Bia', 'email' => 'bia@criador.test', 'password' => 'segredo123', 'perfil' => 'atendente']);
    $atendente->forceFill(['super_admin' => false])->saveQuietly();
    $this->actingAs($atendente->fresh());

    $canal = Channel::create(['tenant_id' => $this->conta->id, 'nome' => 'Novo', 'tipo' => 'evolution', 'status' => 'close']);

    expect($atendente->fresh()->canalIds())->not->toContain($canal->id);
});
