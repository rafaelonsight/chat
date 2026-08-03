<?php

use App\Jobs\SendTextMessage;
use App\Livewire\Inbox\ConversationWindow;
use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, ConversationEvent, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->channel = Channel::create(['nome' => 'Principal'])->refresh();

    $this->contact = Contact::create([
        'nome'          => 'Cliente',
        'telefone_e164' => '+5511999998888',
        'jid'           => '5511999998888@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::abertaOuNova($this->channel->id, $this->contact->id);

    $this->be($this->usuario);
});

afterEach(fn () => TenantContext::forget());

it('guarda a nota como evento e nao manda nada para o cliente', function () {
    Queue::fake();

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('alternarNota')
        ->set('corpo', 'cliente ja reclamou disso tres vezes')
        ->call('enviar')
        ->assertHasNoErrors();

    // A propriedade que importa: nenhuma mensagem, nenhum envio.
    expect(Message::where('conversation_id', $this->conversa->id)->count())->toBe(0);
    Queue::assertNothingPushed();

    $evento = ConversationEvent::where('conversation_id', $this->conversa->id)->first();

    expect($evento)->not->toBeNull()
        ->and($evento->tipo)->toBe(ConversationEvent::NOTA)
        ->and($evento->descricao)->toBe('cliente ja reclamou disso tres vezes')
        ->and($evento->user_id)->toBe($this->usuario->id);
});

it('nota nao sobe a conversa na fila nem muda o status', function () {
    Queue::fake();

    $this->conversa->update(['ultima_msg_em' => now()->subHours(3)]);
    $antes = $this->conversa->fresh();

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('alternarNota')
        ->set('corpo', 'nota qualquer')
        ->call('enviar');

    $depois = $this->conversa->fresh();

    // ultima_msg_em responde "quem espera ha mais tempo". Nota nossa subindo a
    // conversa faria a ordenacao mentir sobre o cliente.
    expect($depois->ultima_msg_em->timestamp)->toBe($antes->ultima_msg_em->timestamp)
        ->and($depois->status)->toBe($antes->status);
});

it('nota vazia da erro e nao grava nada', function () {
    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('alternarNota')
        ->set('corpo', '   ')
        ->call('enviar')
        ->assertHasErrors('corpo');

    expect(ConversationEvent::count())->toBe(0);
});

it('trocar de conversa desliga o modo nota', function () {
    // Esta e a protecao mais importante do recurso: se o modo ficasse ligado, o
    // atendente abriria a conversa seguinte e escreveria uma nota acreditando
    // estar respondendo o cliente — ou o contrario.
    $outro = Contact::create([
        'nome'          => 'Outro',
        'telefone_e164' => '+5511888887777',
        'jid'           => '5511888887777@s.whatsapp.net',
    ]);
    $segunda = Conversation::abertaOuNova($this->channel->id, $outro->id);

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('alternarNota')
        ->assertSet('nota', true)
        ->call('abrir', $segunda->id)
        ->assertSet('nota', false);
});

it('ligar o modo nota descarta anexo pendente', function () {
    // Anexo em nota interna nao existe: o arquivo iria para o WhatsApp.
    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->set('anexo', Illuminate\Http\UploadedFile::fake()->image('foto.jpg'))
        ->call('alternarNota')
        ->assertSet('nota', true)
        ->assertSet('anexo', null);
});

it('mistura mensagem e nota numa linha do tempo em ordem cronologica', function () {
    $primeira = Message::create([
        'conversation_id' => $this->conversa->id,
        'channel_id'      => $this->channel->id,
        'direcao'         => 'in',
        'tipo'            => 'text',
        'corpo'           => 'meu link caiu',
    ]);
    $primeira->forceFill(['created_at' => now()->subMinutes(10)])->save();

    $nota = ConversationEvent::create([
        'conversation_id' => $this->conversa->id,
        'user_id'         => $this->usuario->id,
        'tipo'            => ConversationEvent::NOTA,
        'descricao'       => 'chamado 4412 aberto',
    ]);
    $nota->forceFill(['created_at' => now()->subMinutes(5)])->save();

    $ultima = Message::create([
        'conversation_id' => $this->conversa->id,
        'channel_id'      => $this->channel->id,
        'direcao'         => 'out',
        'tipo'            => 'text',
        'corpo'           => 'tecnico a caminho',
    ]);
    $ultima->forceFill(['created_at' => now()->subMinute()])->save();

    $linha = Livewire::actingAs($this->usuario)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->viewData('linha');

    expect($linha)->toHaveCount(3);

    expect($linha[0])->toBeInstanceOf(Message::class)
        ->and($linha[0]->id)->toBe($primeira->id);

    expect($linha[1])->toBeInstanceOf(ConversationEvent::class)
        ->and($linha[1]->id)->toBe($nota->id);

    expect($linha[2])->toBeInstanceOf(Message::class)
        ->and($linha[2]->id)->toBe($ultima->id);
});

it('a transferencia continua aparecendo na linha do tempo', function () {
    $equipe = App\Models\Team::create(['nome' => 'Suporte']);

    Message::create([
        'conversation_id' => $this->conversa->id,
        'channel_id'      => $this->channel->id,
        'direcao'         => 'in',
        'tipo'            => 'text',
        'corpo'           => 'oi',
    ])->forceFill(['created_at' => now()->subMinutes(10)])->save();

    $this->conversa->transferir($equipe, $this->usuario);

    $linha = Livewire::actingAs($this->usuario)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->viewData('linha');

    $eventos = collect($linha)->filter(fn ($i) => $i instanceof ConversationEvent);

    expect($eventos)->toHaveCount(1)
        ->and($eventos->first()->tipo)->toBe(ConversationEvent::TRANSFERENCIA);
});
