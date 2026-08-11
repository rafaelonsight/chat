<?php

use App\Filament\Pages\Paineis;
use App\Filament\Pages\Relatorios;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Offerings\OfferingResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;

function usuarioMenu(string $slug, bool $admin = true): User
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

function sessaoMenu(User $u): array
{
    return ['login_web_'.sha1('Illuminate\Auth\SessionGuard') => $u->id];
}

afterEach(fn () => TenantContext::forget());

it('os itens ficam nos grupos certos', function () {
    /*
     * PESSOAS SAIU DO CRM E FOI PARA ERP > Cadastro, por pedido do Rafael — junto de Produtos e
     * servicos, com Propostas no primeiro nivel do mesmo grupo.
     *
     * O item pai e o que faz o terceiro nivel do menu existir: sem 'Cadastro' como pai, Pessoas
     * e Produtos voltariam a ser irmaos de Propostas e a arvore desmontaria em silencio — o menu
     * continuaria funcionando, so nao seria mais o que ele desenhou.
     */
    expect(ContactResource::getNavigationGroup())->toBe('ERP')
        ->and(ContactResource::getNavigationParentItem())->toBe('Cadastro')
        ->and(ContactResource::getNavigationLabel())->toBe('Pessoas')
        ->and(OfferingResource::getNavigationGroup())->toBe('ERP')
        ->and(OfferingResource::getNavigationParentItem())->toBe('Cadastro')
        ->and(ProposalResource::getNavigationGroup())->toBe('ERP')
        // Propostas NAO tem pai: ela e irma de Cadastro, e nao filha dele.
        ->and(ProposalResource::getNavigationParentItem())->toBeNull()
        ->and(Paineis::getNavigationGroup())->toBe('CRM')
        ->and(Relatorios::getNavigationGroup())->toBe('Relatórios')
        ->and(Relatorios::getNavigationLabel())->toBe('Visão geral');
});

it('atendente ve Contatos, mas nao ve Relatorios', function () {
    $atendente = usuarioMenu('mn1', admin: false);

    $this->actingAs($atendente);
    expect(ContactResource::canViewAny())->toBeTrue()
        ->and(Relatorios::canAccess())->toBeFalse();
});

it('admin ve tudo', function () {
    $admin = usuarioMenu('mn2');

    $this->actingAs($admin);
    expect(ContactResource::canViewAny())->toBeTrue()
        ->and(Relatorios::canAccess())->toBeTrue()
        ->and(Paineis::canAccess())->toBeTrue();
});

it('a lista de contatos abre e mostra so os do proprio tenant', function () {
    $a = usuarioMenu('mn3');
    TenantContext::set($a->tenant_id);
    Contact::create(['telefone_e164' => '+5584911111111', 'nome' => 'Do A']);
    TenantContext::forget();

    $b = usuarioMenu('mn4');
    TenantContext::set($b->tenant_id);
    Contact::create(['telefone_e164' => '+5584922222222', 'nome' => 'Do B']);
    TenantContext::forget();

    $this->withoutExceptionHandling();
    $this->withSession(sessaoMenu($a))
        ->get('/admin/contacts')
        ->assertSuccessful()
        ->assertSee('Do A')
        ->assertDontSee('Do B');
});

it('Paineis deixou de ser tela reservada: virou o funil', function () {
    $u = usuarioMenu('mn5');

    $this->withoutExceptionHandling();
    $this->withSession(sessaoMenu($u))
        ->get('/admin/paineis')
        ->assertSuccessful()
        ->assertDontSee('em constru')
        // conta sem coluna nenhuma e convidada a criar, em vez de encarar um quadro em branco
        ->assertSee('Você ainda não tem nenhum funil');
});

it('relatorios e barrado para atendente', function () {
    $atendente = usuarioMenu('mn6', admin: false);

    $this->withSession(sessaoMenu($atendente))->get('/admin/relatorios')->assertForbidden();
});

it('relatorios conta o que aconteceu no periodo', function () {
    $admin = usuarioMenu('mn7');
    TenantContext::set($admin->tenant_id);

    $canal = Channel::create(['nome' => 'Comercial']);
    $canal->refresh();
    $ct = Contact::create(['telefone_e164' => '+5584933333333', 'nome' => 'Cliente']);

    $cv = Conversation::create(['channel_id' => $canal->id, 'contact_id' => $ct->id]);
    Message::create(['conversation_id' => $cv->id, 'channel_id' => $canal->id, 'direcao' => 'in', 'tipo' => 'text', 'corpo' => 'oi', 'status' => Message::STATUS_DELIVERED]);

    $this->actingAs($admin);
    Message::create(['conversation_id' => $cv->id, 'channel_id' => $canal->id, 'direcao' => 'out', 'tipo' => 'text', 'corpo' => 'ola', 'status' => Message::STATUS_SENT]);

    $encerrada = Conversation::create(['channel_id' => $canal->id, 'contact_id' => Contact::create(['telefone_e164' => '+5584944444444'])->id]);
    Message::create(['conversation_id' => $encerrada->id, 'channel_id' => $canal->id, 'direcao' => 'out', 'tipo' => 'text', 'corpo' => 'x', 'status' => Message::STATUS_SENT]);
    $encerrada->refresh()->arquivar();

    TenantContext::forget();

    $pagina = Livewire\Livewire::actingAs($admin)->test(Relatorios::class);

    $pagina->assertViewHas('resumo', function ($r) {
        return (int) $r['conversas'] === 2
            && (int) $r['mensagens'] === 3
            && (int) $r['recebidas'] === 1
            && (int) $r['enviadas'] === 2
            && (int) $r['encerradas'] === 1;
    });

    $pagina->assertViewHas('porCanal', fn ($p) => $p->count() === 1 && $p->first()->canal === 'Comercial');
    $pagina->assertViewHas('porAtendente', fn ($p) => $p->count() >= 1);
});

it('relatorios nao mistura tenant', function () {
    $a = usuarioMenu('mn8');
    TenantContext::set($a->tenant_id);
    $canalA = Channel::create(['nome' => 'A']);
    $canalA->refresh();
    $ctA = Contact::create(['telefone_e164' => '+5584955555555']);
    Conversation::create(['channel_id' => $canalA->id, 'contact_id' => $ctA->id]);
    TenantContext::forget();

    $b = usuarioMenu('mn9');
    TenantContext::set($b->tenant_id);
    $canalB = Channel::create(['nome' => 'B']);
    $canalB->refresh();
    $ctB = Contact::create(['telefone_e164' => '+5584966666666']);
    Conversation::create(['channel_id' => $canalB->id, 'contact_id' => $ctB->id]);
    TenantContext::forget();

    Livewire\Livewire::actingAs($b)
        ->test(Relatorios::class)
        ->assertViewHas('resumo', fn ($r) => (int) $r['conversas'] === 1);
});
