<?php

use App\Filament\Pages\{Campanhas, FuncionariosDigitais, Sequencias};
use App\Filament\Resources\Chatbots\ChatbotResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

function usuarioApps(string $slug, bool $admin = true): User
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

afterEach(fn () => TenantContext::forget());

it('os quatro itens ficam no grupo Aplicacoes', function () {
    // Chatbot deixou de ser placeholder e virou recurso; o lugar no menu e o
    // mesmo, mas quem responde agora e o recurso.
    expect(Campanhas::getNavigationGroup())->toBe('Aplicações')
        ->and(ChatbotResource::getNavigationGroup())->toBe('Aplicações')
        ->and(FuncionariosDigitais::getNavigationGroup())->toBe('Aplicações')
        ->and(Sequencias::getNavigationGroup())->toBe('Aplicações');
});

it('ficam no nivel de cima do grupo, sem pai', function () {
    foreach ([Campanhas::class, ChatbotResource::class, FuncionariosDigitais::class, Sequencias::class] as $item) {
        expect($item::getNavigationParentItem())->toBeNull();
    }
});

it('Aplicacoes vem antes de Configuracoes', function () {
    $grupos = Filament\Facades\Filament::getPanel('admin')->getNavigationGroups();
    $ordem = array_values(array_map(fn ($g) => is_string($g) ? $g : $g->getLabel(), $grupos));

    $iApps = array_search('Aplicações', $ordem, true);
    $iConf = array_search('Configurações', $ordem, true);

    expect($iApps)->not->toBeFalse()
        ->and($iConf)->not->toBeFalse()
        ->and($iApps)->toBeLessThan($iConf);
});

it('so admin acessa', function () {
    $atendente = usuarioApps('ap1', admin: false);
    $this->actingAs($atendente);

    expect(Campanhas::canAccess())->toBeFalse()
        ->and(ChatbotResource::canViewAny())->toBeFalse()
        ->and(FuncionariosDigitais::canAccess())->toBeFalse()
        ->and(Sequencias::canAccess())->toBeFalse();
});

it('as telas ainda reservadas abrem para admin, com as decisoes listadas', function () {
    $admin = usuarioApps('ap2');
    $chave = 'login_web_'.sha1('Illuminate\Auth\SessionGuard');

    // Campanhas saiu daqui: deixou de ser tela reservada e virou modulo de verdade.
    // Sequencias tambem saiu: so os Funcionarios Digitais continuam reservados.
    foreach (['/admin/funcionarios-digitais'] as $rota) {
        $this->withSession([$chave => $admin->id])->get($rota)
            ->assertSuccessful()
            ->assertSee('em constru');
    }
});

it('o Chatbot deixou de ser tela reservada', function () {
    $admin = usuarioApps('ap3');
    $chave = 'login_web_'.sha1('Illuminate\Auth\SessionGuard');

    // Nao basta abrir: precisa NAO dizer mais "em construcao", senao a tela
    // continuaria mentindo sobre o proprio estado.
    $this->withSession([$chave => $admin->id])->get('/admin/chatbot')
        ->assertSuccessful()
        ->assertDontSee('em constru')
        ->assertSee('Nenhum fluxo ainda');
});

it('Campanhas deixou de ser tela reservada', function () {
    $conta = App\Models\Tenant::create(['nome' => 'T', 'slug' => 'menu-camp']);
    App\Support\TenantContext::set($conta->id);

    $admin = App\Models\User::create([
        'tenant_id' => $conta->id, 'name' => 'A', 'email' => 'a@menu-camp.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    $this->actingAs($admin)->get('/admin/campanhas')
        ->assertSuccessful()
        ->assertDontSee('em constru')
        ->assertSee('Nova campanha');
});
