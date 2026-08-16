<?php

use App\Models\License;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CriarCliente;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/*
 * A licenca decide se o tenant entra no painel do produto. Ate aqui nada bloqueava —
 * este arquivo cobre o que passou a bloquear, e o que continua de proposito sem bloqueio
 * (operador, conta sem licenca nenhuma).
 */

beforeEach(function () {
    config(['mail.default' => 'array']);

    $this->casa = Tenant::create(['nome' => 'Casa', 'slug' => 'casa']);
    TenantContext::set($this->casa->id);

    $this->operador = User::create([
        'tenant_id' => $this->casa->id, 'name' => 'Dono',
        'email' => 'dono@casa.test', 'password' => 'segredo123', 'admin' => true,
    ]);
    $this->operador->forceFill(['operador' => true])->save();
});

function tenantComUsuario(string $slug, array $licenca = []): array
{
    $tenant = Tenant::create(['nome' => $slug, 'slug' => $slug]);

    $usuario = User::create([
        'tenant_id' => $tenant->id, 'name' => 'Fulano',
        'email' => $slug.'@teste.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    if ($licenca !== []) {
        License::create(array_merge(['tenant_id' => $tenant->id], $licenca));
    }

    return [$tenant, $usuario];
}

// ------------------------------------------------------------- nasce com o cliente

it('cria o cliente com licenca em trial', function () {
    $this->actingAs($this->operador);

    $r = app(CriarCliente::class)->criar([
        'nome' => 'Padaria Aurora', 'fuso_horario' => 'America/Sao_Paulo',
        'responsavel_nome' => 'Marina', 'responsavel_email' => 'marina@aurora.test',
    ]);

    $licenca = $r['cliente']->license;

    expect($licenca)->not->toBeNull()
        ->and($licenca->status)->toBe(License::TRIAL)
        ->and($licenca->vence_em->isFuture())->toBeTrue()
        ->and($licenca->estaValida())->toBeTrue();
});

// ------------------------------------------------------------------- estaValida()

it('ativa vale sempre, sem olhar para a data', function () {
    $licenca = new License(['status' => License::ATIVA, 'vence_em' => Carbon::yesterday()]);

    expect($licenca->estaValida())->toBeTrue();
});

it('trial vale ate vencer', function () {
    $valendo = new License(['status' => License::TRIAL, 'vence_em' => Carbon::tomorrow()]);
    $vencido = new License(['status' => License::TRIAL, 'vence_em' => Carbon::yesterday()]);
    $semPrazo = new License(['status' => License::TRIAL, 'vence_em' => null]);

    expect($valendo->estaValida())->toBeTrue()
        ->and($vencido->estaValida())->toBeFalse()
        ->and($semPrazo->estaValida())->toBeTrue();
});

it('em atraso, suspensa e cancelada nunca valem, mesmo com vence_em no futuro', function () {
    foreach ([License::EM_ATRASO, License::SUSPENSA, License::CANCELADA] as $status) {
        $licenca = new License(['status' => $status, 'vence_em' => Carbon::tomorrow()]);

        expect($licenca->estaValida())->toBeFalse();
    }
});

// ----------------------------------------------------------------- o bloqueio

it('bloqueia quem tenta entrar com a licenca suspensa', function () {
    [, $usuario] = tenantComUsuario('suspensa', ['status' => License::SUSPENSA, 'motivo' => 'Inadimplente']);

    $this->actingAs($usuario)
        ->get('/admin')
        ->assertRedirect(route('licenca.bloqueada'));
});

it('deixa passar quem tem licenca ativa', function () {
    [, $usuario] = tenantComUsuario('ativa', ['status' => License::ATIVA]);

    $this->actingAs($usuario)
        ->get('/admin')
        ->assertOk();
});

it('deixa passar tenant sem licenca nenhuma — conta anterior a esta tabela', function () {
    [, $usuario] = tenantComUsuario('sem-licenca');

    $this->actingAs($usuario)
        ->get('/admin')
        ->assertOk();
});

it('nunca bloqueia o operador, mesmo se a conta dele tivesse licenca invalida', function () {
    License::create([
        'tenant_id' => $this->casa->id, 'status' => License::CANCELADA,
    ]);

    $this->actingAs($this->operador)
        ->get('/admin')
        ->assertOk();
});

it('mostra o motivo na tela de bloqueio', function () {
    [, $usuario] = tenantComUsuario('cancelada', [
        'status' => License::CANCELADA, 'motivo' => 'Contrato encerrado em 01/08.',
    ]);

    $this->actingAs($usuario)
        ->get(route('licenca.bloqueada'))
        ->assertOk()
        ->assertSee('Contrato encerrado em 01/08.');
});
