<?php

use App\Jobs\DeleteMessage;
use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Apagar uma mensagem ja enviada.
 *
 * A VERDADE DESCONFORTAVEL DESTE ARQUIVO: a API oficial da Meta NAO APAGA MENSAGEM. Nao existe
 * endpoint — nao e permissao que falta nem versao mais nova. Entao no canal oficial o botao nao
 * aparece. Oferecer e falhar depois seria pior do que nao oferecer, porque a pessoa ja teria
 * contado com isso; e apagar "so do nosso lado" seria pior ainda, porque ela iria embora
 * achando que o cliente nao ve mais.
 *
 * A LINHA CONTINUA NO BANCO. Apagar de verdade abriria buraco no historico: a conversa passaria
 * de "bom dia" para "combinado entao" sem nada no meio.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'apagar']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@apagar.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Por QR',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'apa',
    ]);

    $this->contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => 'aberta', 'ultima_entrada_em' => now(),
    ]);

    $this->nossa = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'valor errado, desconsidere', 'external_id' => 'WAMID-NOSSO',
        'status' => Message::STATUS_SENT,
    ]);

    $this->doCliente = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'quanto fica?', 'external_id' => 'WAMID-CLIENTE',
        'status' => Message::STATUS_DELIVERED,
    ]);

    $this->actingAs($this->pessoa);

    $this->resposta = ['status' => 'ok'];
    $this->status = 200;
    Http::fake(['*' => fn () => Http::response($this->resposta, $this->status)]);
});

// ------------------------------------------------------------------- a tela

it('marca como apagada na hora e manda apagar no provedor', function () {
    Queue::fake();

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('apagar', $this->nossa->id)
        ->assertHasNoErrors();

    expect($this->nossa->fresh()->apagada_em)->not->toBeNull();

    Queue::assertPushed(DeleteMessage::class);
});

it('a linha continua no banco, so perde o conteudo na tela', function () {
    // Apagar a linha abriria buraco no historico. O corpo tambem fica: o registro interno de
    // "o que foi dito e depois retirado" e o que salva numa discussao futura.
    Queue::fake();

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('apagar', $this->nossa->id);

    expect(Message::find($this->nossa->id))->not->toBeNull()
        ->and(Message::find($this->nossa->id)->corpo)->toBe('valor errado, desconsidere');
});

it('a tela mostra "Mensagem apagada" no lugar do texto', function () {
    Queue::fake();
    $this->nossa->update(['apagada_em' => now()]);

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->assertSee('Mensagem apagada')
        ->assertDontSee('valor errado, desconsidere');
});

it('nao deixa apagar mensagem do cliente', function () {
    // Nao existe no WhatsApp. Um botao aqui apagaria so do nosso lado e faria o atendente
    // achar que sumiu dos dois.
    Queue::fake();

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('apagar', $this->doCliente->id)
        ->assertHasErrors('apagar');

    expect($this->doCliente->fresh()->apagada_em)->toBeNull();
    Queue::assertNothingPushed();
});

it('nao deixa apagar mensagem de outra conversa', function () {
    Queue::fake();

    $outro = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Outro',
        'telefone_e164' => '+5541988880000', 'jid' => '5541988880000@s.whatsapp.net',
    ]);
    $outraConversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $outro->id, 'status' => 'aberta',
    ]);
    $alheia = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $outraConversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'de outra conversa', 'external_id' => 'WAMID-X', 'status' => Message::STATUS_SENT,
    ]);

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('apagar', $alheia->id);

    expect($alheia->fresh()->apagada_em)->toBeNull();
    Queue::assertNothingPushed();
});

// ------------------------------------------------------- o canal oficial nao apaga

it('no canal oficial o botao nem aparece', function () {
    $oficial = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Oficial', 'tipo' => 'meta_cloud',
        'status' => 'open', 'meta_phone_number_id' => '123', 'meta_waba_id' => '456',
        'meta_token' => 'tok',
    ]);

    $conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $oficial->id,
        'contact_id' => $this->contato->id, 'status' => 'aberta', 'ultima_entrada_em' => now(),
    ]);

    Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $conversa->id,
        'channel_id' => $oficial->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'oi', 'external_id' => 'WAMID-OF', 'status' => Message::STATUS_SENT,
    ]);

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $conversa->id])
        ->assertSet('conversationId', $conversa->id)
        ->assertDontSee('Apagar esta mensagem para todos');
});

it('e se alguem chamar mesmo assim, recusa com o motivo', function () {
    Queue::fake();

    $oficial = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Oficial 2', 'tipo' => 'meta_cloud',
        'status' => 'open', 'meta_phone_number_id' => '123', 'meta_waba_id' => '456',
        'meta_token' => 'tok',
    ]);
    $conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $oficial->id,
        'contact_id' => $this->contato->id, 'status' => 'aberta', 'ultima_entrada_em' => now(),
    ]);
    $m = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $conversa->id,
        'channel_id' => $oficial->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'oi', 'external_id' => 'WAMID-OF2', 'status' => Message::STATUS_SENT,
    ]);

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $conversa->id])
        ->call('apagar', $m->id)
        ->assertHasErrors('apagar');

    expect($m->fresh()->apagada_em)->toBeNull();
    Queue::assertNothingPushed();
});

// -------------------------------------------------------------------- o job

it('chama o apagar da Evolution com a chave completa', function () {
    (new DeleteMessage($this->nossa->id))->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertSent(function ($r) {
        return str_contains($r->url(), 'deleteMessageForEveryone')
            && $r->data()['id'] === 'WAMID-NOSSO'
            && $r->data()['fromMe'] === true;
    });
});

it('a mensagem VOLTA quando o provedor recusa', function () {
    // O caso real: passou do prazo que o WhatsApp permite. Sumir aqui e continuar no aparelho
    // do cliente e a pior das saidas — o atendente iria embora achando que resolveu.
    $this->nossa->update(['apagada_em' => now()]);
    $this->status = 400;
    $this->resposta = ['error' => 'tarde demais'];

    (new DeleteMessage($this->nossa->id))->handle(app(App\Services\Canais\Enviadores::class));

    expect($this->nossa->fresh()->apagada_em)->toBeNull();
});

it('nao tenta apagar mensagem que nunca saiu', function () {
    $soDaqui = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'presa na fila', 'external_id' => null, 'status' => Message::STATUS_QUEUED,
    ]);

    (new DeleteMessage($soDaqui->id))->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertNothingSent();
});
