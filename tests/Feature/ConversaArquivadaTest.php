<?php

use App\Livewire\Inbox\ConversationWindow;
use App\Livewire\Inbox\NewConversation;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function cenarioArq(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'Atendente', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);
    $ct = Contact::create(['telefone_e164' => '+5584996143373', 'nome' => 'Cliente']);

    return [$t, $u, $c, $ct];
}

function msgArq(Conversation $cv, string $direcao): Message
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

function payloadArq(string $texto, string $id): array
{
    return [
        'event' => 'messages.upsert',
        'data'  => [
            'key'      => ['remoteJid' => '5584996143373@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
            'pushName' => 'Cliente',
            'message'  => ['conversation' => $texto],
            'messageTimestamp' => 1785648000,
        ],
    ];
}

afterEach(fn () => TenantContext::forget());

it('mensagem depois de arquivada abre conversa NOVA e nao reabre a antiga', function () {
    [, $u, $c] = cenarioArq('aq1');

    // primeiro atendimento, encerrado
    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}", payloadArq('primeiro problema', 'M1'))->assertOk();
    $primeira = Conversation::first();
    $this->actingAs($u);
    msgArq($primeira, 'out');
    $primeira->refresh()->arquivar();

    // cliente volta dias depois
    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}", payloadArq('outro problema', 'M2'))->assertOk();

    expect(Conversation::count())->toBe(2)
        ->and($primeira->refresh()->status)->toBe(Conversation::ARQUIVADA);

    $nova = Conversation::where('status', Conversation::NOVA)->first();
    expect($nova)->not->toBeNull()
        ->and($nova->id)->not->toBe($primeira->id)
        ->and($nova->messages()->count())->toBe(1)
        ->and($nova->messages()->first()->corpo)->toBe('outro problema');

    // o historico antigo fica intacto na conversa arquivada
    expect($primeira->messages()->count())->toBe(2);
});

it('mensagem em conversa aberta continua na mesma conversa', function () {
    [, , $c] = cenarioArq('aq2');

    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}", payloadArq('um', 'M1'))->assertOk();
    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}", payloadArq('dois', 'M2'))->assertOk();

    expect(Conversation::count())->toBe(1)
        ->and(Conversation::first()->messages()->count())->toBe(2);
});

it('o banco impede duas conversas abertas para o mesmo contato e canal', function () {
    [$t, , $c, $ct] = cenarioArq('aq3');

    Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);

    expect(fn () => Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]))
        ->toThrow(QueryException::class);
});

it('o banco permite varias arquivadas para o mesmo contato e canal', function () {
    [, , $c, $ct] = cenarioArq('aq4');

    foreach (range(1, 3) as $i) {
        $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);
        $cv->arquivar();
    }

    expect(Conversation::where('status', Conversation::ARQUIVADA)->count())->toBe(3);
});

it('o botao Nova conversa cria outra quando a unica existente esta arquivada', function () {
    Http::fake([
        '*/chat/whatsappNumbers/*' => Http::response([[
            'exists' => true, 'jid' => '5584996143373@s.whatsapp.net', 'number' => '5584996143373',
        ]], 200),
    ]);

    [, $u, $c, $ct] = cenarioArq('aq5');
    $antiga = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);
    $antiga->arquivar();

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->set('numero', '+5584996143373')
        ->call('iniciar')
        ->assertHasNoErrors();

    expect(Conversation::count())->toBe(2)
        ->and(Conversation::where('status', '!=', Conversation::ARQUIVADA)->count())->toBe(1);
});

it('o botao Nova conversa reaproveita a conversa aberta', function () {
    Http::fake([
        '*/chat/whatsappNumbers/*' => Http::response([[
            'exists' => true, 'jid' => '5584996143373@s.whatsapp.net', 'number' => '5584996143373',
        ]], 200),
    ]);

    [, $u, $c, $ct] = cenarioArq('aq6');
    Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->set('numero', '+5584996143373')
        ->call('iniciar')
        ->assertHasNoErrors();

    expect(Conversation::count())->toBe(1);
});

it('reabrir e bloqueado quando ja existe conversa aberta com o contato', function () {
    [, $u, $c, $ct] = cenarioArq('aq7');

    $arquivada = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);
    $arquivada->arquivar();
    Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]); // a aberta atual

    expect($arquivada->refresh()->podeReabrir())->toBeFalse();

    Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $arquivada->id])
        ->call('reabrir');

    expect($arquivada->refresh()->status)->toBe(Conversation::ARQUIVADA);
});

it('reabrir funciona quando nao ha outra conversa aberta', function () {
    [, $u, $c, $ct] = cenarioArq('aq8');

    $arquivada = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);
    $arquivada->arquivar();

    expect($arquivada->refresh()->podeReabrir())->toBeTrue();

    Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $arquivada->id])
        ->call('reabrir');

    expect($arquivada->refresh()->status)->toBe(Conversation::EM_ATENDIMENTO);
});

it('conversa arquivada nao volta para Novas nem com mensagem direta', function () {
    [, $u, $c, $ct] = cenarioArq('aq9');

    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);
    $cv->arquivar();

    msgArq($cv->refresh(), 'in');

    expect($cv->refresh()->status)->toBe(Conversation::ARQUIVADA);
});
