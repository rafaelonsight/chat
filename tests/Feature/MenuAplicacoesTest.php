<?php

use App\Filament\Pages\{Campanhas, Chatbot, FuncionariosDigitais, Sequencias};
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
    expect(Campanhas::getNavigationGroup())->toBe('Aplicações')
        ->and(Chatbot::getNavigationGroup())->toBe('Aplicações')
        ->and(FuncionariosDigitais::getNavigationGroup())->toBe('Aplicações')
        ->and(Sequencias::getNavigationGroup())->toBe('Aplicações');
});

it('ficam no nivel de cima do grupo, sem pai', function () {
    foreach ([Campanhas::class, Chatbot::class, FuncionariosDigitais::class, Sequencias::class] as $pagina) {
        expect($pagina::getNavigationParentItem())->toBeNull();
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
        ->and(Chatbot::canAccess())->toBeFalse()
        ->and(FuncionariosDigitais::canAccess())->toBeFalse()
        ->and(Sequencias::canAccess())->toBeFalse();
});

it('as quatro telas abrem para admin, com as decisoes listadas', function () {
    $admin = usuarioApps('ap2');
    $chave = 'login_web_'.sha1('Illuminate\Auth\SessionGuard');

    foreach (['/admin/campanhas', '/admin/chatbot', '/admin/funcionarios-digitais', '/admin/sequencias'] as $rota) {
        $this->withSession([$chave => $admin->id])->get($rota)
            ->assertSuccessful()
            ->assertSee('em constru');
    }
});
