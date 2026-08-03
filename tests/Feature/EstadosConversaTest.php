<?php

use App\Jobs\SendTextMessage;
use App\Livewire\Inbox\ConversationWindow;
use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function cenarioEstado(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'Atendente', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);

    return [$t, $u, $c];
}

function conversaEstado(Channel $c, string $telefone = '+5584996143373'): Conversation
{
    $ct = Contact::firstOrCreate(
        ['jid' => Contact::jidDoTelefone($telefone)],
        ['tipo' => Contact::PESSOA, 'telefone_e164' => $telefone],
    );

    return Conversation::firstOrCreate(['channel_id' => $c->id, 'contact_id' => $ct->id]);
}

function msgEstado(Conversation $cv, string $direcao, string $corpo = 'oi'): Message
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

it('conversa nasce como nova', function () {
    [, , $c] = cenarioEstado('et1');
    $cv = conversaEstado($c);

    expect($cv->status)->toBe(Conversation::NOVA)
        ->and($cv->atendente_id)->toBeNull();
});

it('mensagem que entra mantem a conversa como nova', function () {
    [, , $c] = cenarioEstado('et2');
    $cv = conversaEstado($c);

    msgEstado($cv, 'in');

    expect($cv->refresh()->status)->toBe(Conversation::NOVA);
});

it('nossa resposta move para em atendimento e registra quem respondeu', function () {
    [, $u, $c] = cenarioEstado('et3');
    $cv = conversaEstado($c);
    msgEstado($cv, 'in');

    $this->actingAs($u);
    msgEstado($cv, 'out', 'ja verifico');

    $cv->refresh();
    expect($cv->status)->toBe(Conversation::EM_ATENDIMENTO)
        ->and($cv->atendente_id)->toBe($u->id);
});

it('resposta sem usuario autenticado (automacao) tambem move para em atendimento', function () {
    [, , $c] = cenarioEstado('et4');
    $cv = conversaEstado($c);

    msgEstado($cv, 'out', 'mensagem automatica');

    $cv->refresh();
    expect($cv->status)->toBe(Conversation::EM_ATENDIMENTO)
        ->and($cv->atendente_id)->toBeNull();
});

it('finalizar arquiva a conversa', function () {
    [, $u, $c] = cenarioEstado('et5');
    $cv = conversaEstado($c);
    $this->actingAs($u);
    msgEstado($cv, 'out');

    $cv->refresh()->arquivar();

    expect($cv->refresh()->status)->toBe(Conversation::ARQUIVADA);
});

it('conversa arquivada permanece arquivada ao receber mensagem', function () {
    [, $u, $c] = cenarioEstado('et6');
    $cv = conversaEstado($c);
    $this->actingAs($u);
    msgEstado($cv, 'out');
    $cv->refresh()->arquivar();

    msgEstado($cv->refresh(), 'in', 'voltei com outro problema');

    expect($cv->refresh()->status)->toBe(Conversation::ARQUIVADA);
});

it('assumir move para em atendimento sem precisar responder', function () {
    [, $u, $c] = cenarioEstado('et7');
    $cv = conversaEstado($c);
    msgEstado($cv, 'in');

    $this->actingAs($u);
    $cv->refresh()->assumir($u);

    $cv->refresh();
    expect($cv->status)->toBe(Conversation::EM_ATENDIMENTO)
        ->and($cv->atendente_id)->toBe($u->id);
});

it('a janela finaliza e reabre a conversa', function () {
    [, $u, $c] = cenarioEstado('et8');
    $cv = conversaEstado($c);
    $this->actingAs($u);
    msgEstado($cv, 'out');

    Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->call('finalizar');

    expect($cv->refresh()->status)->toBe(Conversation::ARQUIVADA);

    Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->call('reabrir');

    expect($cv->refresh()->status)->toBe(Conversation::EM_ATENDIMENTO);
});

it('responder pelo compositor tira a conversa de Novos', function () {
    Queue::fake();
    [, $u, $c] = cenarioEstado('et9');
    $cv = conversaEstado($c);
    msgEstado($cv, 'in');

    expect($cv->refresh()->status)->toBe(Conversation::NOVA);

    Livewire::actingAs($u)
        ->test(MessageComposer::class, ['conversationId' => $cv->id])
        ->set('corpo', 'respondendo')
        ->call('enviar');

    $cv->refresh();
    expect($cv->status)->toBe(Conversation::EM_ATENDIMENTO)
        ->and($cv->atendente_id)->toBe($u->id);

    Queue::assertPushed(SendTextMessage::class);
});
