<?php

use App\Filament\Pages\Auth\Perfil;
use App\Models\{Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
 * "Meu perfil".
 *
 * Nao existia tela nenhuma para a pessoa trocar a propria senha. Depois de aceitar o convite,
 * ficava com aquela senha para sempre — ou usava "esqueci minha senha" para trocar uma senha
 * que ela lembra, que e um jeito torto de fazer uma coisa simples.
 *
 * Duas regras aqui valem mais que a tela em si, e as duas tem teste:
 *
 * 1. EXIGE A SENHA ATUAL. Sem isso, quem passasse por um computador destravado trocaria a
 *    senha sem saber a antiga e tomaria a conta. O produto ja tem bloqueio de sessao por essa
 *    mesma preocupacao; deixar a porta aberta aqui seria incoerente.
 *
 * 2. O E-MAIL NAO SE EDITA AQUI. Ele E o login, e nao ha verificacao de endereco novo. Um erro
 *    de digitacao tranca a pessoa por dois caminhos ao mesmo tempo: nao entra, e o "esqueci
 *    minha senha" vai para um endereco que nao existe.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'perfil']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Marina',
        'email' => 'marina@perfil.test', 'password' => 'senha-antiga-123', 'admin' => false,
    ]);
});

it('a rota existe e abre para quem esta logado', function () {
    $this->actingAs($this->pessoa)
        ->get('/admin/perfil')
        ->assertOk();
});

it('troca a senha quando a atual confere', function () {
    Livewire::actingAs($this->pessoa)->test(Perfil::class)
        ->fillForm([
            'currentPassword'       => 'senha-antiga-123',
            'password'              => 'senha-nova-456',
            'passwordConfirmation'  => 'senha-nova-456',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('senha-nova-456', $this->pessoa->fresh()->password))->toBeTrue();
});

it('recusa a troca quando a senha atual esta errada', function () {
    Livewire::actingAs($this->pessoa)->test(Perfil::class)
        ->fillForm([
            'currentPassword'      => 'chutei-essa',
            'password'             => 'senha-nova-456',
            'passwordConfirmation' => 'senha-nova-456',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);

    // E, o que importa mais que o erro na tela: a senha continua a mesma.
    expect(Hash::check('senha-antiga-123', $this->pessoa->fresh()->password))->toBeTrue();
});

it('recusa quando a confirmacao nao bate', function () {
    Livewire::actingAs($this->pessoa)->test(Perfil::class)
        ->fillForm([
            'currentPassword'      => 'senha-antiga-123',
            'password'             => 'senha-nova-456',
            'passwordConfirmation' => 'digitei-diferente',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect(Hash::check('senha-antiga-123', $this->pessoa->fresh()->password))->toBeTrue();
});

it('deixa trocar so o nome, sem mexer em senha', function () {
    Livewire::actingAs($this->pessoa)->test(Perfil::class)
        ->fillForm(['name' => 'Marina Souza'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->pessoa->fresh()->name)->toBe('Marina Souza')
        ->and(Hash::check('senha-antiga-123', $this->pessoa->fresh()->password))->toBeTrue();
});

it('nao grava e-mail vindo do formulario', function () {
    // O campo esta desabilitado na tela, mas desabilitado e enfeite: o valor viaja no
    // formulario e da para editar pelo navegador. O que garante e o dehydrated(false).
    Livewire::actingAs($this->pessoa)->test(Perfil::class)
        ->fillForm(['name' => 'Marina', 'email' => 'outro@qualquer.test'])
        ->call('save');

    expect($this->pessoa->fresh()->email)->toBe('marina@perfil.test');
});

it('atendente comum tambem entra: perfil nao e coisa de administrador', function () {
    expect($this->pessoa->admin)->toBeFalse();

    Livewire::actingAs($this->pessoa)->test(Perfil::class)->assertOk();
});
