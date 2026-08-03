<?php

use App\Jobs\SendTextMessage;
use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ConversationWindow;
use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function cenario(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C'])->refresh();
    $ct = Contact::create(['telefone_e164' => '+5511999998888', 'nome' => 'Cliente']);
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id, 'ultima_msg_em' => now()]);

    return [$t, $u, $cv];
}

afterEach(fn () => TenantContext::forget());

// O inbox virou a home do painel; /inbox continua valendo por redirecionamento.
it('a rota antiga do inbox redireciona para o painel', function () {
    $this->get('/inbox')->assertRedirect('/admin');
});

it('o painel exige autenticacao', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('abre o atendimento para quem esta logado', function () {
    [, $u] = cenario('aa');

    $this->actingAs($u)->get('/admin')->assertOk();
});

it('lista apenas conversas do proprio tenant', function () {
    [$tA, $uA] = cenario('bb');
    cenario('cc');

    // cenario() deixa o TenantContext apontando para o ultimo tenant criado, e
    // TenantContext::get() prioriza o contexto explicito sobre o usuario logado.
    // Numa requisicao HTTP real nada seta contexto explicito — o tenant vem do
    // usuario. Limpamos aqui para exercitar exatamente esse caminho.
    TenantContext::forget();

    Livewire::actingAs($uA)
        ->test(ConversationList::class)
        ->assertViewHas('conversas', fn ($c) => $c->count() === 1 && $c->first()->tenant_id === $tA->id);
});

it('zera as nao lidas ao selecionar a conversa', function () {
    [, $u, $cv] = cenario('dd');
    $cv->update(['nao_lidas' => 4]);

    Livewire::actingAs($u)
        ->test(ConversationList::class)
        ->call('selecionar', $cv->id)
        ->assertSet('selecionada', $cv->id);

    expect($cv->refresh()->nao_lidas)->toBe(0);
});

it('enfileira o envio e mostra a mensagem na hora', function () {
    Queue::fake();
    [, $u, $cv] = cenario('ee');

    Livewire::actingAs($u)
        ->test(MessageComposer::class, ['conversationId' => $cv->id])
        ->set('corpo', 'ola cliente')
        ->call('enviar')
        ->assertSet('corpo', '');

    $m = Message::where('conversation_id', $cv->id)->first();

    expect($m->corpo)->toBe('ola cliente')
        ->and($m->direcao)->toBe('out')
        ->and($m->status)->toBe(Message::STATUS_QUEUED);

    Queue::assertPushed(SendTextMessage::class);
});

it('nao envia mensagem vazia', function () {
    Queue::fake();
    [, $u, $cv] = cenario('ff');

    Livewire::actingAs($u)
        ->test(MessageComposer::class, ['conversationId' => $cv->id])
        ->set('corpo', '')
        ->call('enviar')
        ->assertHasErrors('corpo');

    Queue::assertNothingPushed();
});

it('mostra o historico da conversa aberta', function () {
    [, $u, $cv] = cenario('gg');

    Message::create([
        'conversation_id' => $cv->id, 'channel_id' => $cv->channel_id,
        'direcao' => 'in', 'corpo' => 'oi do cliente', 'status' => Message::STATUS_DELIVERED,
    ]);

    Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->assertSee('oi do cliente');
});
