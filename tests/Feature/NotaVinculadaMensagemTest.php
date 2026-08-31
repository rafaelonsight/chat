<?php

use App\Livewire\Inbox\{ConversationWindow, MessageComposer};
use App\Models\{Channel, Contact, Conversation, ConversationEvent, Message, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

function cenarioNotaVinculada(string $slug): array
{
    $tenant = Tenant::create(['nome' => $slug, 'slug' => $slug]);
    TenantContext::set($tenant->id);
    $autor = User::create(['tenant_id' => $tenant->id, 'name' => 'Rafael', 'email' => "r@{$slug}.test", 'password' => 'segredo123']);
    $colega = User::create(['tenant_id' => $tenant->id, 'name' => 'Celso', 'email' => "c@{$slug}.test", 'password' => 'segredo123']);
    $canal = Channel::create(['nome' => 'WhatsApp']);
    $contato = Contact::create(['nome' => 'Cliente', 'jid' => "5511{$tenant->id}999@s.whatsapp.net"]);
    $conversa = Conversation::create(['channel_id' => $canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now()]);
    $mensagem = Message::create([
        'conversation_id' => $conversa->id, 'channel_id' => $canal->id,
        'direcao' => 'in', 'tipo' => 'text', 'corpo' => 'Mensagem que precisa de contexto',
        'status' => Message::STATUS_DELIVERED,
    ]);

    return [$autor, $colega, $conversa, $mensagem];
}

afterEach(fn () => TenantContext::forget());

it('salva nota interna vinculada a mensagem sem enviar ao cliente', function () {
    [$autor, , $conversa, $mensagem] = cenarioNotaVinculada('nota-vinculada-1');

    Livewire::actingAs($autor)->test(MessageComposer::class, ['conversationId' => $conversa->id])
        ->call('anotarMensagem', $mensagem->id)
        ->assertSet('nota', true)
        ->assertSet('anotandoMensagem', $mensagem->id)
        ->set('corpo', 'Confirmar este dado antes de responder')
        ->call('enviar');

    $nota = ConversationEvent::where('tipo', ConversationEvent::NOTA)->first();
    expect($nota->dados['message_id'])->toBe($mensagem->id)
        ->and(Message::count())->toBe(1);
});

it('mostra contador recolhido e varias notas sobre a mensagem', function () {
    [$autor, , $conversa, $mensagem] = cenarioNotaVinculada('nota-vinculada-2');

    foreach (['Primeira nota', 'Segunda nota'] as $texto) {
        ConversationEvent::create([
            'conversation_id' => $conversa->id, 'user_id' => $autor->id,
            'tipo' => ConversationEvent::NOTA, 'descricao' => $texto,
            'dados' => ['message_id' => $mensagem->id],
        ]);
    }

    Livewire::actingAs($autor)->test(ConversationWindow::class, ['conversationId' => $conversa->id])
        ->assertSee('2 notas internas')
        ->assertSee('Primeira nota')
        ->assertSee('Segunda nota');
});

it('autor edita e exclui sua nota vinculada', function () {
    [$autor, , $conversa, $mensagem] = cenarioNotaVinculada('nota-vinculada-3');
    $nota = ConversationEvent::create([
        'conversation_id' => $conversa->id, 'user_id' => $autor->id,
        'tipo' => ConversationEvent::NOTA, 'descricao' => 'Texto antigo',
        'dados' => ['message_id' => $mensagem->id],
    ]);

    $tela = Livewire::actingAs($autor)->test(ConversationWindow::class, ['conversationId' => $conversa->id])
        ->call('editarNota', $nota->id)->set('textoNota', 'Texto corrigido')->call('salvarEdicaoNota');

    expect($nota->refresh()->descricao)->toBe('Texto corrigido');
    $tela->call('excluirNota', $nota->id);
    expect(ConversationEvent::find($nota->id))->toBeNull();
});

it('colega sem privilegio nao altera nota de outro autor', function () {
    [$autor, $colega, $conversa, $mensagem] = cenarioNotaVinculada('nota-vinculada-4');
    $nota = ConversationEvent::create([
        'conversation_id' => $conversa->id, 'user_id' => $autor->id,
        'tipo' => ConversationEvent::NOTA, 'descricao' => 'Privada da equipe',
        'dados' => ['message_id' => $mensagem->id],
    ]);

    Livewire::actingAs($colega)->test(ConversationWindow::class, ['conversationId' => $conversa->id])
        ->call('editarNota', $nota->id)->set('textoNota', 'Tentativa')->call('salvarEdicaoNota')
        ->call('excluirNota', $nota->id);

    expect($nota->refresh()->descricao)->toBe('Privada da equipe');
});
