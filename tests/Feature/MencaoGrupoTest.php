<?php

use App\Jobs\SendTextMessage;
use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

afterEach(fn () => TenantContext::forget());

it('lista participantes e persiste somente mencoes validas do grupo', function () {
    $tenant = Tenant::create(['nome' => 'Menções', 'slug' => 'mencoes-grupo']);
    TenantContext::set($tenant->id);
    $usuario = User::create([
        'tenant_id' => $tenant->id, 'name' => 'Rafael', 'email' => 'rafael@mencoes.test',
        'password' => 'segredo123',
    ]);
    $canal = Channel::create([
        'tipo' => Channel::EVOLUTION, 'nome' => 'WhatsApp', 'instance_name' => 'instancia',
    ]);
    $grupo = Contact::create([
        'tipo' => Contact::GRUPO, 'nome' => 'Grupo suporte', 'jid' => '120363000000000000@g.us',
    ]);
    $conversa = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $grupo->id, 'ultima_msg_em' => now(),
    ]);

    Http::fake(['*/group/participants/*' => Http::response([
        ['id' => '5541984606033@s.whatsapp.net', 'name' => 'Celso'],
        ['id' => '5541984919939@s.whatsapp.net', 'name' => 'Rafael'],
    ])]);
    Queue::fake();

    $tela = Livewire::actingAs($usuario)
        ->test(MessageComposer::class, ['conversationId' => $conversa->id]);

    $tela->call('carregarParticipantesGrupo');
    Http::assertSent(fn ($req) => str_contains($req->url(), '/group/participants/instancia'));

    $tela->set('corpo', '@Celso veja isto')
        ->set('mencionadosWhatsapp', [
            '5541984606033@s.whatsapp.net',
            '9999999999999@s.whatsapp.net',
        ])->call('enviar');

    expect(Message::latest('id')->first()->mencionados)
        ->toBe(['5541984606033@s.whatsapp.net']);
    Queue::assertPushed(SendTextMessage::class);
});
