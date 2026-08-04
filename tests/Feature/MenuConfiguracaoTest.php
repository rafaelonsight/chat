<?php

use App\Filament\Pages\Cadastro;
use App\Filament\Pages\ConsumoConversas;
use App\Filament\Pages\HorarioAtendimento;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

function usuarioConf(string $slug, bool $admin = true): User
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create([
        'tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test",
        'password' => 'segredo123', 'admin' => $admin,
    ]);
    TenantContext::forget();

    return $u;
}

function sessaoConf(User $u): array
{
    return ['login_web_'.sha1('Illuminate\Auth\SessionGuard') => $u->id];
}

afterEach(fn () => TenantContext::forget());

it('tudo de configuracao fica no grupo Configuracoes', function () {
    expect(UserResource::getNavigationGroup())->toBe('Configurações')
        ->and(TeamResource::getNavigationGroup())->toBe('Configurações')
        ->and(MessageTemplateResource::getNavigationGroup())->toBe('Configurações')
        ->and(Cadastro::getNavigationGroup())->toBe('Configurações')
        ->and(ChannelResource::getNavigationGroup())->toBe('Configurações')
        ->and(HorarioAtendimento::getNavigationGroup())->toBe('Configurações')
        ->and(ConsumoConversas::getNavigationGroup())->toBe('Configurações');
});

it('os quatro itens de Conta ficam aninhados sob Conta', function () {
    expect(Cadastro::getNavigationParentItem())->toBe('Conta')
        ->and(ChannelResource::getNavigationParentItem())->toBe('Conta')
        ->and(HorarioAtendimento::getNavigationParentItem())->toBe('Conta')
        ->and(ConsumoConversas::getNavigationParentItem())->toBe('Conta');
});

it('Usuarios, Equipe e Modelo de mensagens ficam no nivel de cima', function () {
    expect(UserResource::getNavigationParentItem())->toBeNull()
        ->and(TeamResource::getNavigationParentItem())->toBeNull()
        ->and(MessageTemplateResource::getNavigationParentItem())->toBeNull();
});

it('configuracao inteira e so para admin', function () {
    $atendente = usuarioConf('cf1', admin: false);
    $this->actingAs($atendente);

    expect(Cadastro::canAccess())->toBeFalse()
        ->and(TeamResource::canViewAny())->toBeFalse()
        ->and(HorarioAtendimento::canAccess())->toBeFalse()
        ->and(ConsumoConversas::canAccess())->toBeFalse()
        ->and(MessageTemplateResource::canViewAny())->toBeFalse();
});

it('as telas reservadas abrem com aviso, para o admin', function () {
    $admin = usuarioConf('cf2');

    foreach (['/admin/consumo-conversas'] as $rota) {
        $this->withSession(sessaoConf($admin))->get($rota)
            ->assertSuccessful()
            ->assertSee('em constru');
    }
});

// ------------------------------------------------------------------ Cadastro

it('o Cadastro carrega os dados da conta e salva', function () {
    $admin = usuarioConf('cf3');

    // Preencher o CNPJ dispara a consulta na Receita. Aqui o assunto e carregar e
    // salvar, entao a consulta e cortada: sem isso o teste sai na rede de verdade
    // e o que vier de la sobrescreve a razao social digitada logo abaixo.
    Illuminate\Support\Facades\Http::fake(['*' => Illuminate\Support\Facades\Http::response([], 404)]);

    $pagina = Livewire\Livewire::actingAs($admin)->test(Cadastro::class);

    $pagina->assertSet('nome', 'CF3')
        ->set('nome', 'Provedor Alfa')
        ->set('razao_social', 'Alfa Telecom LTDA')
        ->set('documento', '11.222.333/0001-81')
        ->set('email', 'contato@alfa.test')
        ->set('telefone', '(84) 3333-4444')
        ->call('salvar')
        ->assertHasNoErrors();

    $t = Tenant::find($admin->tenant_id);
    expect($t->nome)->toBe('Provedor Alfa')
        ->and($t->razao_social)->toBe('Alfa Telecom LTDA')
        ->and($t->documento)->toBe('11.222.333/0001-81')
        ->and($t->email)->toBe('contato@alfa.test');
});

it('o Cadastro exige nome', function () {
    $admin = usuarioConf('cf4');

    Livewire\Livewire::actingAs($admin)
        ->test(Cadastro::class)
        ->set('nome', '')
        ->call('salvar')
        ->assertHasErrors('nome');
});

it('o Cadastro nao alcanca a conta de outro tenant', function () {
    $a = usuarioConf('cf5');
    $b = usuarioConf('cf6');

    Livewire\Livewire::actingAs($b)
        ->test(Cadastro::class)
        ->set('nome', 'Mudado pelo B')
        ->call('salvar');

    expect(Tenant::find($a->tenant_id)->nome)->toBe('CF5');
});

it('Horario de Atendimento deixou de ser reservado e abre a grade', function () {
    $admin = usuarioConf('cf7');

    $this->withoutExceptionHandling();
    $this->withSession(sessaoConf($admin))
        ->get('/admin/horario-atendimento')
        ->assertSuccessful()
        ->assertSee('Segunda-feira')
        ->assertSee('Início do almoço')
        ->assertSee('Feriados e exceções')
        ->assertDontSee('em constru');
});
