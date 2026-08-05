<?php

use App\Jobs\SendTextMessage;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Services\EvolutionService;
use App\Support\Jid;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

function cenarioGrupo(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);

    return [$t, $u, $c];
}

function payloadGrupo(string $texto, string $id, string $grupoJid = '120363012345678901@g.us', string $participante = '5584996143373@s.whatsapp.net'): array
{
    return [
        'event' => 'messages.upsert',
        'data'  => [
            'key' => [
                'remoteJid'   => $grupoJid,
                'fromMe'      => false,
                'id'          => $id,
                'participant' => $participante,
            ],
            'pushName' => 'Joao do Grupo',
            'message'  => ['conversation' => $texto],
            'messageTimestamp' => 1785648000,
        ],
    ];
}

afterEach(fn () => TenantContext::forget());

// ------------------------------------------------------------------- suporte

it('reconhece JID de grupo', function () {
    expect(Jid::eGrupo('120363012345678901@g.us'))->toBeTrue()
        ->and(Jid::eGrupo('5584996143373@s.whatsapp.net'))->toBeFalse()
        ->and(Jid::eGrupo(null))->toBeFalse();
});

it('limpa sufixo de dispositivo do JID', function () {
    expect(Jid::limpar('5584996143373:12@s.whatsapp.net'))->toBe('5584996143373@s.whatsapp.net')
        ->and(Jid::limpar('  120363012345678901@G.US '))->toBe('120363012345678901@g.us');
});

// Multi-dispositivo manda o JID com sufixo :N. Sem cortar no ':' o numero fica
// com digitos demais e a mensagem era descartada como payload invalido.
it('normaliza telefone mesmo com sufixo de dispositivo', function () {
    expect(PhoneNumber::toE164('5584996143373:12@s.whatsapp.net'))->toBe('+5584996143373')
        ->and(PhoneNumber::toE164('5584996143373:3'))->toBe('+5584996143373');
});

// ----------------------------------------------------------------- webhook

it('mensagem de grupo cria contato do tipo grupo, nao pessoa', function () {
    Http::fake([
        '*/group/findGroupInfos*' => Http::response(['subject' => 'Suporte Bairro Centro', 'size' => 12], 200),
    ]);

    [, , $c] = cenarioGrupo('gp1');

    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}", payloadGrupo('alguem sem net?', 'G1'))
        ->assertOk();

    expect(Contact::count())->toBe(1);

    $contato = Contact::first();
    expect($contato->tipo)->toBe(Contact::GRUPO)
        ->and($contato->eGrupo())->toBeTrue()
        ->and($contato->jid)->toBe('120363012345678901@g.us')
        ->and($contato->telefone_e164)->toBeNull()
        ->and($contato->nome)->toBe('Suporte Bairro Centro');
});

it('guarda quem falou dentro do grupo', function () {
    Http::fake(['*' => Http::response(['subject' => 'Grupo X'], 200)]);
    [, , $c] = cenarioGrupo('gp2');

    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}", payloadGrupo('oi', 'G1'))->assertOk();

    $m = Message::first();
    expect($m->remetente_nome)->toBe('Joao do Grupo')
        ->and($m->remetente_jid)->toBe('5584996143373@s.whatsapp.net')
        ->and($m->corpo)->toBe('oi');
});

it('mensagens de pessoas diferentes ficam na mesma conversa do grupo', function () {
    Http::fake(['*' => Http::response(['subject' => 'Grupo X'], 200)]);
    [, , $c] = cenarioGrupo('gp3');

    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}",
        payloadGrupo('eu', 'G1', participante: '5584911111111@s.whatsapp.net'))->assertOk();
    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}",
        payloadGrupo('eu tambem', 'G2', participante: '5584922222222@s.whatsapp.net'))->assertOk();

    expect(Conversation::count())->toBe(1)
        ->and(Contact::count())->toBe(1)
        ->and(Message::count())->toBe(2);
});

it('conversa de pessoa continua funcionando e ganha jid', function () {
    [, , $c] = cenarioGrupo('gp4');

    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}", [
        'event' => 'messages.upsert',
        'data'  => [
            'key'      => ['remoteJid' => '5584996143373@s.whatsapp.net', 'fromMe' => false, 'id' => 'P1'],
            'pushName' => 'Pessoa',
            'message'  => ['conversation' => 'oi'],
        ],
    ])->assertOk();

    $ct = Contact::first();
    expect($ct->tipo)->toBe(Contact::PESSOA)
        ->and($ct->telefone_e164)->toBe('+5584996143373')
        ->and($ct->jid)->toBe('5584996143373@s.whatsapp.net')
        ->and($ct->eGrupo())->toBeFalse();
});

it('grupo sem nome disponivel cai num rotulo legivel', function () {
    Http::fake(['*/group/findGroupInfos*' => Http::response([], 500)]);
    [, , $c] = cenarioGrupo('gp5');

    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}", payloadGrupo('oi', 'G1'))->assertOk();

    expect(Contact::first()->nomeExibicao())->toContain('Grupo');
});

// ------------------------------------------------------------------ envio

it('envio para grupo usa o JID do grupo, nao telefone', function () {
    Http::fake([
        '*/group/findGroupInfos*'  => Http::response(['subject' => 'Grupo X'], 200),
        '*/message/sendText/*'     => Http::response(['key' => ['id' => 'OUT1']], 201),
    ]);

    [, $u, $c] = cenarioGrupo('gp6');
    $this->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}", payloadGrupo('oi', 'G1'))->assertOk();

    $cv = Conversation::first();
    $this->actingAs($u);
    $m = Message::create([
        'conversation_id' => $cv->id, 'channel_id' => $c->id,
        'direcao' => 'out', 'tipo' => 'text', 'corpo' => 'respondendo o grupo',
        'status' => Message::STATUS_QUEUED,
    ]);

    (new SendTextMessage($m->id))->handle(app(\App\Services\Canais\Enviadores::class));

    Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/')
        && $r['number'] === '120363012345678901@g.us');

    expect($m->refresh()->status)->toBe(Message::STATUS_SENT);
});

it('envio para pessoa continua usando o telefone', function () {
    Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'OUT2']], 201)]);

    [, $u, $c] = cenarioGrupo('gp7');
    $ct = Contact::create(['jid' => '5584996143373@s.whatsapp.net', 'telefone_e164' => '+5584996143373']);
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);

    $this->actingAs($u);
    $m = Message::create([
        'conversation_id' => $cv->id, 'channel_id' => $c->id,
        'direcao' => 'out', 'tipo' => 'text', 'corpo' => 'oi',
        'status' => Message::STATUS_QUEUED,
    ]);

    (new SendTextMessage($m->id))->handle(app(\App\Services\Canais\Enviadores::class));

    Http::assertSent(fn ($r) => $r['number'] === '+5584996143373');
});

it('bolha de grupo mostra quem falou', function () {
    [, $u, $c] = cenarioGrupo('gp8');

    $ct = Contact::create(['jid' => '120363099999999999@g.us', 'tipo' => Contact::GRUPO, 'nome' => 'Bairro Centro']);
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);

    Message::create([
        'conversation_id' => $cv->id, 'channel_id' => $c->id,
        'direcao' => 'in', 'tipo' => 'text', 'corpo' => 'alguem sem net?',
        'remetente_nome' => 'Joao do Grupo', 'remetente_jid' => '5584911111111@s.whatsapp.net',
        'status' => Message::STATUS_DELIVERED,
    ]);
    TenantContext::forget();

    Livewire\Livewire::actingAs($u)
        ->test(App\Livewire\Inbox\ConversationWindow::class, ['conversationId' => $cv->id])
        ->assertSee('Joao do Grupo')
        ->assertSee('alguem sem net?');
});

it('contato criado sem jid ganha jid a partir do telefone', function () {
    cenarioGrupo('gp9');

    $ct = Contact::create(['telefone_e164' => '+5584999998888', 'nome' => 'Manual']);

    expect($ct->jid)->toBe('5584999998888@s.whatsapp.net')
        ->and($ct->tipo)->toBe(Contact::PESSOA);
});
