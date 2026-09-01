<?php

use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'editar-msg']);
    TenantContext::set($this->conta->id);
    $this->pessoa = User::create(['tenant_id' => $this->conta->id, 'name' => 'Rafael', 'email' => 'rafael@editar.test', 'password' => 'segredo123', 'admin' => true]);
    $this->canal = Channel::create(['tenant_id' => $this->conta->id, 'nome' => 'Evolution', 'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'editar']);
    $this->contato = Contact::create(['tenant_id' => $this->conta->id, 'nome' => 'Cliente', 'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net']);
    $this->conversa = Conversation::create(['tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id, 'contact_id' => $this->contato->id, 'status' => 'aberta']);
    $this->mensagem = Message::create(['tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id, 'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text', 'corpo' => 'texto errado', 'external_id' => 'MSG-EDIT', 'status' => Message::STATUS_SENT]);
    $this->actingAs($this->pessoa);
    $this->httpStatus = 200;
    Http::fake(fn () => Http::response(
        $this->httpStatus === 200 ? ['status' => 'ok'] : ['error' => 'fora do prazo'],
        $this->httpStatus,
    ));
});

it('edita texto proprio no provedor antes de mudar o historico', function () {
    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('iniciarEdicaoMensagem', $this->mensagem->id)->assertSet('textoMensagem', 'texto errado')
        ->set('textoMensagem', "texto certo\nsegunda linha")->call('salvarEdicaoMensagem')->assertHasNoErrors();
    expect($this->mensagem->fresh()->corpo)->toBe("texto certo\nsegunda linha");
    Http::assertSent(fn ($r) => str_contains($r->url(), '/chat/updateMessage/editar') && $r['key']['id'] === 'MSG-EDIT' && $r['key']['fromMe'] === true && $r['text'] === "texto certo\nsegunda linha");
});

it('nao altera o historico quando o provedor recusa', function () {
    $this->httpStatus = 400;
    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('iniciarEdicaoMensagem', $this->mensagem->id)->set('textoMensagem', 'novo')
        ->call('salvarEdicaoMensagem')->assertHasErrors('editarMensagem');
    expect($this->mensagem->fresh()->corpo)->toBe('texto errado');
});

it('recusa mensagem fora dos quinze minutos', function () {
    $this->mensagem->forceFill(['created_at' => now()->subMinutes(16)])->save();
    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('iniciarEdicaoMensagem', $this->mensagem->id)->assertHasErrors('editarMensagem');
    Http::assertNothingSent();
});

it('compositor comum usa textarea e reserva ctrl enter para nova linha', function () {
    $fonte = file_get_contents(resource_path('views/livewire/inbox/message-composer.blade.php'));
    expect($fonte)->toContain('<textarea wire:model="corpo" rows="1"')->toContain('x-on:keydown.ctrl.enter.prevent.stop="aberto = false; novaLinha($el)"')->toContain('! $event.ctrlKey && ! $event.metaKey');
});
