<?php

use App\Livewire\Inbox\ContactDetails;
use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

function cenarioDet(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'Atendente', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'Comercial']);
    $c->refresh();
    $c->update(['status' => 'open']);
    $ct = Contact::create(['telefone_e164' => '+5584996143373', 'nome' => 'Zap do Cliente']);
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id, 'ultima_msg_em' => now()]);

    return [$t, $u, $c, $ct, $cv];
}

function msgDet(Conversation $cv, string $direcao): Message
{
    return Message::create([
        'conversation_id' => $cv->id,
        'channel_id'      => $cv->channel_id,
        'direcao'         => $direcao,
        'tipo'            => 'text',
        'corpo'           => 'oi',
        'status'          => $direcao === 'in' ? Message::STATUS_DELIVERED : Message::STATUS_QUEUED,
    ]);
}

afterEach(fn () => TenantContext::forget());

it('o cabecalho da conversa dispara a abertura dos detalhes', function () {
    [, $u, , , $cv] = cenarioDet('dt1');

    Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->call('verDetalhes')
        ->assertDispatched('abrir-detalhes');
});

it('o painel comeca fechado e abre no evento', function () {
    [, $u, , , $cv] = cenarioDet('dt2');

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->assertSet('aberto', false)
        ->call('trocarConversa', $cv->id)
        ->call('alternar')
        ->assertSet('aberto', true)
        ->assertSet('conversationId', $cv->id);
});

it('mostra os dados do contato e do canal', function () {
    [, $u, $c, $ct, $cv] = cenarioDet('dt3');

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cv->id)
        ->call('alternar')
        ->assertSee('Zap do Cliente')
        ->assertSee('+5584996143373')
        ->assertSee('Comercial');
});

it('conta mensagens recebidas e enviadas', function () {
    [, $u, , , $cv] = cenarioDet('dt4');

    msgDet($cv, 'in');
    msgDet($cv, 'in');
    $this->actingAs($u);
    msgDet($cv, 'out');

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cv->id)
        ->call('alternar')
        ->assertViewHas('resumo', fn ($r) => (int) $r['total'] === 3
            && (int) $r['recebidas'] === 2
            && (int) $r['enviadas'] === 1);
});

it('renomeia o contato e avisa a lista', function () {
    [, $u, , $ct, $cv] = cenarioDet('dt5');

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cv->id)
        ->set('nome', 'Joao da Silva - Fibra 300')
        ->call('salvarNome')
        ->assertHasNoErrors()
        ->assertDispatched('conversa-atualizada');

    expect($ct->refresh()->nome)->toBe('Joao da Silva - Fibra 300');
});

it('nao aceita nome vazio', function () {
    [, $u, , $ct, $cv] = cenarioDet('dt6');

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cv->id)
        ->set('nome', '   ')
        ->call('salvarNome')
        ->assertHasErrors('nome');

    expect($ct->refresh()->nome)->toBe('Zap do Cliente');
});

it('nao abre detalhes de conversa de outro tenant', function () {
    [, , , , $cvA] = cenarioDet('dt7');
    [, $uB] = cenarioDet('dt8');
    TenantContext::forget();

    Livewire::actingAs($uB)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cvA->id)
        ->assertViewHas('conversa', fn ($c) => $c === null);
});

it('nao renomeia contato de outro tenant', function () {
    [, , , $ctA, $cvA] = cenarioDet('dt9');
    [, $uB] = cenarioDet('dta');
    TenantContext::forget();

    Livewire::actingAs($uB)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cvA->id)
        ->set('nome', 'invadido')
        ->call('salvarNome');

    expect($ctA->refresh()->nome)->toBe('Zap do Cliente');
});

it('mostra quantas conversas o contato tem', function () {
    [$t, $u, , $ct, $cv] = cenarioDet('dtb');

    // mesmo contato falando por um segundo canal
    $outro = Channel::create(['nome' => 'Cobranca']);
    $outro->refresh();
    Conversation::create(['channel_id' => $outro->id, 'contact_id' => $ct->id]);

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cv->id)
        ->call('alternar')
        ->assertViewHas('outrasConversas', fn ($n) => $n === 1);
});
