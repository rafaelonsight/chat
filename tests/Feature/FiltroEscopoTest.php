<?php

use App\Livewire\Inbox\ConversationList;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

function cenarioEscopo(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $eu = User::create(['tenant_id' => $t->id, 'name' => 'Eu', 'email' => "eu@{$slug}.test", 'password' => 'segredo123']);
    $outro = User::create(['tenant_id' => $t->id, 'name' => 'Outro', 'email' => "ot@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);

    return [$t, $eu, $outro, $c];
}

function conversaDe(Channel $c, string $jid, string $tipo = Contact::PESSOA, ?int $atendente = null, ?string $status = null): Conversation
{
    $ct = Contact::firstOrCreate(
        ['jid' => $jid],
        ['tipo' => $tipo, 'telefone_e164' => $tipo === Contact::PESSOA ? '+'.explode('@', $jid)[0] : null],
    );

    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id, 'ultima_msg_em' => now()]);

    if ($atendente || $status) {
        $cv->forceFill(array_filter([
            'atendente_id' => $atendente,
            'status'       => $status,
        ]))->save();
    }

    return $cv;
}

afterEach(fn () => TenantContext::forget());

it('o escopo padrao e Todos', function () {
    [, $eu] = cenarioEscopo('es1');

    Livewire::actingAs($eu)
        ->test(ConversationList::class)
        ->assertSet('escopo', 'todos');
});

it('Meus mostra so as minhas', function () {
    [, $eu, $outro, $c] = cenarioEscopo('es2');

    $minha = conversaDe($c, '5584911111111@s.whatsapp.net', atendente: $eu->id, status: Conversation::EM_ATENDIMENTO);
    conversaDe($c, '5584922222222@s.whatsapp.net', atendente: $outro->id, status: Conversation::EM_ATENDIMENTO);
    TenantContext::forget();

    Livewire::actingAs($eu)
        ->test(ConversationList::class)
        ->set('aba', Conversation::EM_ATENDIMENTO)
        ->set('escopo', 'meus')
        ->assertViewHas('conversas', fn ($cs) => $cs->count() === 1 && $cs->first()->id === $minha->id);
});

it('Outros mostra as de outra pessoa, nunca as minhas', function () {
    [, $eu, $outro, $c] = cenarioEscopo('es3');

    conversaDe($c, '5584911111111@s.whatsapp.net', atendente: $eu->id, status: Conversation::EM_ATENDIMENTO);
    $dela = conversaDe($c, '5584922222222@s.whatsapp.net', atendente: $outro->id, status: Conversation::EM_ATENDIMENTO);
    TenantContext::forget();

    Livewire::actingAs($eu)
        ->test(ConversationList::class)
        ->set('aba', Conversation::EM_ATENDIMENTO)
        ->set('escopo', 'outros')
        ->assertViewHas('conversas', fn ($cs) => $cs->count() === 1 && $cs->first()->id === $dela->id);
});

it('conversa sem atendente nao aparece em Meus nem em Outros, mas aparece em Todos', function () {
    [, $eu, , $c] = cenarioEscopo('es4');

    conversaDe($c, '5584911111111@s.whatsapp.net'); // Nova, sem atendente
    TenantContext::forget();

    $lista = fn (string $escopo) => Livewire::actingAs($eu)
        ->test(ConversationList::class)
        ->set('aba', Conversation::NOVA)
        ->set('escopo', $escopo);

    $lista('meus')->assertViewHas('conversas', fn ($cs) => $cs->count() === 0);
    $lista('outros')->assertViewHas('conversas', fn ($cs) => $cs->count() === 0);
    $lista('todos')->assertViewHas('conversas', fn ($cs) => $cs->count() === 1);
});

it('Grupos mostra so conversa de grupo', function () {
    [, $eu, , $c] = cenarioEscopo('es5');

    conversaDe($c, '5584911111111@s.whatsapp.net');
    $grupo = conversaDe($c, '120363012345678901@g.us', Contact::GRUPO);
    TenantContext::forget();

    Livewire::actingAs($eu)
        ->test(ConversationList::class)
        ->set('aba', Conversation::NOVA)
        ->set('escopo', 'grupos')
        ->assertViewHas('conversas', fn ($cs) => $cs->count() === 1 && $cs->first()->id === $grupo->id);
});

it('os contadores do escopo respeitam a aba', function () {
    [, $eu, $outro, $c] = cenarioEscopo('es6');

    conversaDe($c, '5584911111111@s.whatsapp.net', atendente: $eu->id, status: Conversation::EM_ATENDIMENTO);
    conversaDe($c, '5584922222222@s.whatsapp.net', atendente: $outro->id, status: Conversation::EM_ATENDIMENTO);
    conversaDe($c, '120363012345678901@g.us', Contact::GRUPO, atendente: $eu->id, status: Conversation::EM_ATENDIMENTO);
    conversaDe($c, '5584933333333@s.whatsapp.net'); // Nova
    TenantContext::forget();

    Livewire::actingAs($eu)
        ->test(ConversationList::class)
        ->set('aba', Conversation::EM_ATENDIMENTO)
        ->assertViewHas('escopos', fn ($e) => $e['todos'] === 3
            && $e['meus'] === 2
            && $e['outros'] === 1
            && $e['grupos'] === 1);
});

it('os contadores das abas respeitam o escopo', function () {
    [, $eu, $outro, $c] = cenarioEscopo('es7');

    conversaDe($c, '5584911111111@s.whatsapp.net', atendente: $eu->id, status: Conversation::EM_ATENDIMENTO);
    conversaDe($c, '5584922222222@s.whatsapp.net', atendente: $outro->id, status: Conversation::EM_ATENDIMENTO);
    TenantContext::forget();

    Livewire::actingAs($eu)
        ->test(ConversationList::class)
        ->set('escopo', 'meus')
        ->assertViewHas('contadores', fn ($ct) => $ct[Conversation::EM_ATENDIMENTO] === 1);
});

it('o escopo nao vaza conversa de outro tenant', function () {
    [, , , $cA] = cenarioEscopo('es8');
    conversaDe($cA, '5584911111111@s.whatsapp.net');
    TenantContext::forget();

    [, $euB, , $cB] = cenarioEscopo('es9');
    conversaDe($cB, '5584922222222@s.whatsapp.net');
    TenantContext::forget();

    Livewire::actingAs($euB)
        ->test(ConversationList::class)
        ->assertViewHas('escopos', fn ($e) => $e['todos'] === 1);
});

it('bolha de grupo mostra quem falou', function () {
    [, $eu, , $c] = cenarioEscopo('esa');
    $cv = conversaDe($c, '120363012345678901@g.us', Contact::GRUPO);

    Message::create([
        'conversation_id' => $cv->id, 'channel_id' => $c->id,
        'direcao' => 'in', 'tipo' => 'text', 'corpo' => 'alguem sem net?',
        'remetente_nome' => 'Joao do Grupo', 'remetente_jid' => '5584911111111@s.whatsapp.net',
        'status' => Message::STATUS_DELIVERED,
    ]);
    TenantContext::forget();

    Livewire::actingAs($eu)
        ->test(App\Livewire\Inbox\ConversationWindow::class, ['conversationId' => $cv->id])
        ->assertSee('Joao do Grupo')
        ->assertSee('alguem sem net?');
});

it('contato criado pelo painel ganha jid automaticamente', function () {
    cenarioEscopo('esb');

    $ct = Contact::create(['telefone_e164' => '+5584999998888', 'nome' => 'Manual']);

    expect($ct->jid)->toBe('5584999998888@s.whatsapp.net')
        ->and($ct->tipo)->toBe(Contact::PESSOA);
});
