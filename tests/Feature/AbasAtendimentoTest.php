<?php

use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ConversationWindow;
use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function cenarioAbas(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'Atendente', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);

    return [$t, $u, $c];
}

function conversaCom(Channel $c, string $telefone = '+5584996143373'): Conversation
{
    $ct = Contact::firstOrCreate(['telefone_e164' => $telefone]);

    return Conversation::firstOrCreate(['channel_id' => $c->id, 'contact_id' => $ct->id]);
}

function mensagem(Conversation $cv, string $direcao, string $corpo = 'oi'): Message
{
    return Message::create([
        'conversation_id' => $cv->id,
        'channel_id'      => $cv->channel_id,
        'direcao'         => $direcao,
        'tipo'            => 'text',
        'corpo'           => $corpo,
        'status'          => $direcao === 'in' ? Message::STATUS_DELIVERED : Message::STATUS_QUEUED,
    ]);
}

afterEach(fn () => TenantContext::forget());

// -------------------------------------------------------------- estados

it('conversa nasce como nova', function () {
    [, , $c] = cenarioAbas('ab1');
    $cv = conversaCom($c);

    expect($cv->status)->toBe(Conversation::NOVA)
        ->and($cv->atendente_id)->toBeNull();
});

it('mensagem que entra mantem a conversa como nova', function () {
    [, , $c] = cenarioAbas('ab2');
    $cv = conversaCom($c);

    mensagem($cv, 'in');

    expect($cv->refresh()->status)->toBe(Conversation::NOVA);
});

it('nossa resposta move para em atendimento e registra quem respondeu', function () {
    [, $u, $c] = cenarioAbas('ab3');
    $cv = conversaCom($c);
    mensagem($cv, 'in');

    $this->actingAs($u);
    mensagem($cv, 'out', 'ja verifico');

    $cv->refresh();
    expect($cv->status)->toBe(Conversation::EM_ATENDIMENTO)
        ->and($cv->atendente_id)->toBe($u->id);
});

it('resposta sem usuario autenticado (automacao) tambem move para em atendimento', function () {
    [, , $c] = cenarioAbas('ab4');
    $cv = conversaCom($c);

    mensagem($cv, 'out', 'mensagem automatica');

    $cv->refresh();
    expect($cv->status)->toBe(Conversation::EM_ATENDIMENTO)
        ->and($cv->atendente_id)->toBeNull(); // sem humano: espaco da IA
});

it('finalizar arquiva a conversa', function () {
    [, $u, $c] = cenarioAbas('ab5');
    $cv = conversaCom($c);
    $this->actingAs($u);
    mensagem($cv, 'out');

    $cv->refresh()->arquivar();

    expect($cv->refresh()->status)->toBe(Conversation::ARQUIVADA);
});

it('mensagem nova em conversa arquivada devolve para novas', function () {
    [, $u, $c] = cenarioAbas('ab6');
    $cv = conversaCom($c);
    $this->actingAs($u);
    mensagem($cv, 'out');
    $cv->refresh()->arquivar();

    mensagem($cv->refresh(), 'in', 'voltei com outro problema');

    $cv->refresh();
    expect($cv->status)->toBe(Conversation::NOVA)
        ->and($cv->atendente_id)->toBeNull();
});

it('assumir move para em atendimento sem precisar responder', function () {
    [, $u, $c] = cenarioAbas('ab7');
    $cv = conversaCom($c);
    mensagem($cv, 'in');

    $this->actingAs($u);
    $cv->refresh()->assumir($u);

    $cv->refresh();
    expect($cv->status)->toBe(Conversation::EM_ATENDIMENTO)
        ->and($cv->atendente_id)->toBe($u->id);
});

// -------------------------------------------------------------- abas

it('cada aba mostra so as conversas do seu estado', function () {
    [, $u, $c] = cenarioAbas('ab8');

    $nova = conversaCom($c, '+5584911111111');
    mensagem($nova, 'in');

    $emAt = conversaCom($c, '+5584922222222');
    $this->actingAs($u);
    mensagem($emAt, 'out');

    $arq = conversaCom($c, '+5584933333333');
    mensagem($arq, 'out');
    $arq->refresh()->arquivar();

    $lista = fn (string $aba) => Livewire::actingAs($u)
        ->test(ConversationList::class)
        ->set('aba', $aba);

    $lista(Conversation::NOVA)->assertViewHas('conversas', fn ($cs) => $cs->count() === 1 && $cs->first()->id === $nova->id);
    $lista(Conversation::EM_ATENDIMENTO)->assertViewHas('conversas', fn ($cs) => $cs->count() === 1 && $cs->first()->id === $emAt->id);
    $lista(Conversation::ARQUIVADA)->assertViewHas('conversas', fn ($cs) => $cs->count() === 1 && $cs->first()->id === $arq->id);
});

it('os contadores das abas batem', function () {
    [, $u, $c] = cenarioAbas('ab9');

    mensagem(conversaCom($c, '+5584911111111'), 'in');
    mensagem(conversaCom($c, '+5584944444444'), 'in');

    $this->actingAs($u);
    mensagem(conversaCom($c, '+5584922222222'), 'out');

    Livewire::actingAs($u)
        ->test(ConversationList::class)
        ->assertViewHas('contadores', fn ($ct) => $ct[Conversation::NOVA] === 2
            && $ct[Conversation::EM_ATENDIMENTO] === 1
            && $ct[Conversation::ARQUIVADA] === 0);
});

it('a aba padrao e Novas', function () {
    [, $u] = cenarioAbas('aba');

    Livewire::actingAs($u)
        ->test(ConversationList::class)
        ->assertSet('aba', Conversation::NOVA);
});

it('contador nao vaza conversa de outro tenant', function () {
    [, , $cA] = cenarioAbas('abb');
    mensagem(conversaCom($cA, '+5584911111111'), 'in');

    [, $uB, $cB] = cenarioAbas('abc');
    mensagem(conversaCom($cB, '+5584922222222'), 'in');
    TenantContext::forget();

    Livewire::actingAs($uB)
        ->test(ConversationList::class)
        ->assertViewHas('contadores', fn ($ct) => $ct[Conversation::NOVA] === 1);
});

// -------------------------------------------------------- acoes na janela

it('a janela finaliza e reabre a conversa', function () {
    [, $u, $c] = cenarioAbas('abd');
    $cv = conversaCom($c);
    $this->actingAs($u);
    mensagem($cv, 'out');

    Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->call('finalizar');

    expect($cv->refresh()->status)->toBe(Conversation::ARQUIVADA);

    Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->call('reabrir');

    expect($cv->refresh()->status)->toBe(Conversation::EM_ATENDIMENTO);
});

it('responder pelo compositor tira a conversa de Novas', function () {
    Queue::fake();
    [, $u, $c] = cenarioAbas('abe');
    $cv = conversaCom($c);
    mensagem($cv, 'in');

    expect($cv->refresh()->status)->toBe(Conversation::NOVA);

    Livewire::actingAs($u)
        ->test(MessageComposer::class, ['conversationId' => $cv->id])
        ->set('corpo', 'respondendo')
        ->call('enviar');

    $cv->refresh();
    expect($cv->status)->toBe(Conversation::EM_ATENDIMENTO)
        ->and($cv->atendente_id)->toBe($u->id);
});
