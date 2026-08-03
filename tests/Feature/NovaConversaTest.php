<?php

use App\Jobs\SendTextMessage;
use App\Livewire\Inbox\NewConversation;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function cenarioNova(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);

    return [$t, $u, $c];
}

afterEach(fn () => TenantContext::forget());

it('cria contato e conversa usando o jid que a Evolution devolve', function () {
    // digitado sem o nono digito; a Evolution devolve o JID canonico com ele
    Http::fake([
        '*/chat/whatsappNumbers/*' => Http::response([[
            'exists' => true,
            'jid'    => '5584996143373@s.whatsapp.net',
            'number' => '5584996143373',
        ]], 200),
    ]);

    [, $u] = cenarioNova('nc1');

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->set('numero', '(84) 9614-3373')
        ->call('iniciar')
        ->assertHasNoErrors();

    expect(Contact::count())->toBe(1)
        ->and(Contact::first()->telefone_e164)->toBe('+5584996143373')
        ->and(Conversation::count())->toBe(1);
});

it('recusa numero que nao existe no WhatsApp', function () {
    Http::fake([
        '*/chat/whatsappNumbers/*' => Http::response([[
            'exists' => false,
            'number' => '5584999999999',
        ]], 200),
    ]);

    [, $u] = cenarioNova('nc2');

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->set('numero', '84 99999-9999')
        ->call('iniciar')
        ->assertHasErrors('numero');

    expect(Contact::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

it('recusa numero mal formatado sem nem chamar a Evolution', function () {
    Http::fake();

    [, $u] = cenarioNova('nc3');

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->set('numero', '123')
        ->call('iniciar')
        ->assertHasErrors('numero');

    Http::assertNothingSent();
});

it('reaproveita a conversa quando o contato ja existe', function () {
    Http::fake([
        '*/chat/whatsappNumbers/*' => Http::response([[
            'exists' => true, 'jid' => '5584996143373@s.whatsapp.net', 'number' => '5584996143373',
        ]], 200),
    ]);

    [$t, $u, $c] = cenarioNova('nc4');
    $ct = Contact::create(['telefone_e164' => '+5584996143373', 'nome' => 'Ja existia']);
    Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->set('numero', '+5584996143373')
        ->call('iniciar')
        ->assertHasNoErrors();

    expect(Contact::count())->toBe(1)
        ->and(Conversation::count())->toBe(1);
});

it('envia a primeira mensagem quando informada', function () {
    Queue::fake();
    Http::fake([
        '*/chat/whatsappNumbers/*' => Http::response([[
            'exists' => true, 'jid' => '5584996143373@s.whatsapp.net', 'number' => '5584996143373',
        ]], 200),
    ]);

    [, $u] = cenarioNova('nc5');

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->set('numero', '+5584996143373')
        ->set('primeiraMensagem', 'ola, tudo bem?')
        ->call('iniciar')
        ->assertHasNoErrors();

    $m = Message::first();
    expect($m->corpo)->toBe('ola, tudo bem?')
        ->and($m->direcao)->toBe('out')
        ->and($m->status)->toBe(Message::STATUS_QUEUED);

    Queue::assertPushed(SendTextMessage::class);
});

it('exige um canal conectado', function () {
    Http::fake();

    $t = Tenant::create(['nome' => 'X', 'slug' => 'nc6']);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => 'u@nc6.test', 'password' => 'segredo123']);
    // canal existe mas esta desconectado
    Channel::create(['nome' => 'C']);

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->set('numero', '+5584996143373')
        ->call('iniciar')
        ->assertHasErrors('numero');

    Http::assertNothingSent();
});

it('a lista passa a mostrar a conversa criada pelo botao', function () {
    Http::fake([
        '*/chat/whatsappNumbers/*' => Http::response([[
            'exists' => true, 'jid' => '5584996143373@s.whatsapp.net', 'number' => '5584996143373',
        ]], 200),
    ]);

    [, $u] = cenarioNova('nc7');

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->set('numero', '+5584996143373')
        ->call('iniciar')
        ->assertDispatched('abrir-conversa');

    $conversa = Conversation::first();

    Livewire::actingAs($u)
        ->test(App\Livewire\Inbox\ConversationList::class)
        ->assertViewHas('conversas', fn ($c) => $c->count() === 1)
        ->call('marcarSelecionada', $conversa->id)
        ->assertSet('selecionada', $conversa->id);
});
