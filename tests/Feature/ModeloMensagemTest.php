<?php

use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, MessageTemplate, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

function cenarioModelo(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test", 'password' => 'segredo123', 'admin' => true]);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);
    $ct = Contact::create(['jid' => '5584996143373@s.whatsapp.net', 'tipo' => Contact::PESSOA, 'telefone_e164' => '+5584996143373', 'nome' => 'Joao da Silva']);
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);

    return [$t, $u, $c, $cv];
}

afterEach(fn () => TenantContext::forget());

it('modelo pertence ao tenant e nao vaza', function () {
    [, $uA] = cenarioModelo('md1');
    TenantContext::set($uA->tenant_id);
    MessageTemplate::create(['titulo' => 'Do A', 'atalho' => 'a', 'corpo' => 'texto A']);
    TenantContext::forget();

    [, $uB] = cenarioModelo('md2');
    $this->actingAs($uB);

    expect(MessageTemplate::count())->toBe(0);
});

it('o compositor lista so os modelos ativos', function () {
    [, $u, , $cv] = cenarioModelo('md3');
    TenantContext::set($u->tenant_id);
    MessageTemplate::create(['titulo' => 'Boleto', 'atalho' => 'boleto', 'corpo' => 'Segue seu boleto.']);
    MessageTemplate::create(['titulo' => 'Desligado', 'atalho' => 'off', 'corpo' => 'nao usar', 'ativo' => false]);
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(MessageComposer::class, ['conversationId' => $cv->id])
        ->assertViewHas('modelos', fn ($m) => $m->count() === 1 && $m->first()->titulo === 'Boleto');
});

it('usar modelo preenche o campo e troca os marcadores pelo contato', function () {
    [, $u, , $cv] = cenarioModelo('md4');
    TenantContext::set($u->tenant_id);
    $modelo = MessageTemplate::create([
        'titulo' => 'Saudacao',
        'atalho' => 'ola',
        'corpo'  => 'Ola {{nome}}, o numero {{telefone}} esta no nosso cadastro. Falo com {{atendente}}.',
    ]);
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(MessageComposer::class, ['conversationId' => $cv->id])
        ->call('usarModelo', $modelo->id)
        ->assertSet('corpo', 'Ola Joao da Silva, o numero +5584996143373 esta no nosso cadastro. Falo com U.');
});

it('nao usa modelo de outro tenant', function () {
    [, $uA] = cenarioModelo('md5');
    TenantContext::set($uA->tenant_id);
    $doA = MessageTemplate::create(['titulo' => 'Do A', 'atalho' => 'a', 'corpo' => 'segredo do A']);
    TenantContext::forget();

    [, $uB, , $cvB] = cenarioModelo('md6');
    TenantContext::forget();

    Livewire::actingAs($uB)
        ->test(MessageComposer::class, ['conversationId' => $cvB->id])
        ->call('usarModelo', $doA->id)
        ->assertSet('corpo', '');
});

// Separado em dois testes por causa do PostgreSQL: violacao de constraint aborta
// a transacao inteira, e o RefreshDatabase envolve cada teste numa. Depois do
// erro, nenhum comando roda ate o rollback — provocar a falha e continuar no
// mesmo teste e impossivel aqui (no MySQL passaria).
it('o atalho nao repete dentro do mesmo tenant', function () {
    [, $uA] = cenarioModelo('md7');
    TenantContext::set($uA->tenant_id);
    MessageTemplate::create(['titulo' => 'X', 'atalho' => 'boleto', 'corpo' => 'a']);

    expect(fn () => MessageTemplate::create(['titulo' => 'Y', 'atalho' => 'boleto', 'corpo' => 'b']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('o mesmo atalho pode existir em tenants diferentes', function () {
    [, $uA] = cenarioModelo('md7');
    TenantContext::set($uA->tenant_id);
    $doA = MessageTemplate::create(['titulo' => 'X', 'atalho' => 'boleto', 'corpo' => 'a']);
    TenantContext::forget();

    [, $uB] = cenarioModelo('md8');
    TenantContext::set($uB->tenant_id);
    $doB = MessageTemplate::create(['titulo' => 'Z', 'atalho' => 'boleto', 'corpo' => 'c']);
    TenantContext::forget();

    expect($doA->atalho)->toBe('boleto')
        ->and($doB->atalho)->toBe('boleto')
        ->and($doB->tenant_id)->not->toBe($doA->tenant_id);
});

it('a lista de modelos abre no painel para admin', function () {
    [, $u] = cenarioModelo('md9');
    TenantContext::set($u->tenant_id);
    MessageTemplate::create(['titulo' => 'Segunda via', 'atalho' => 'boleto', 'corpo' => 'Segue.']);
    TenantContext::forget();

    $this->withoutExceptionHandling();
    $this->withSession(['login_web_'.sha1('Illuminate\Auth\SessionGuard') => $u->id])
        ->get('/admin/message-templates')
        ->assertSuccessful()
        ->assertSee('Segunda via');
});
