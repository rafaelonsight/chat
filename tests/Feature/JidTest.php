<?php

use App\Jobs\DeleteMessage;
use App\Jobs\SendReaction;
use App\Jobs\SendTextMessage;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Services\Canais\Enviadores;
use App\Services\EvolutionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * O remoteJid dentro de uma CHAVE de mensagem precisa ser um JID de verdade.
 *
 * ESTE ARQUIVO NASCE DE UM DEFEITO QUE CHEGOU AO USO REAL, e a forma como ele se escondeu e o
 * que vale registrar.
 *
 * O destino que o OnChat usa para ENVIAR e o telefone em E.164, com o sinal de mais:
 * "+554396386381". Para mandar mensagem a Evolution aceita isso e normaliza sozinha — e por
 * isso todo o envio sempre funcionou. Mas dentro da CHAVE de uma mensagem (citar, reagir,
 * apagar) o valor vai direto para o Baileys, que tenta decodificar como JID, nao consegue, e
 * devolve TypeError com HTTP 500.
 *
 * O SINTOMA ENGANAVA: em conversa de GRUPO tudo funcionava, porque ali o destino ja e um JID
 * (...@g.us). So quebrava no atendimento individual, que e a maioria dos casos. Meus testes
 * anteriores conferiam o id e o fromMe da chave e nao olhavam o remoteJid — passavam verdes
 * sobre um envio que o provedor recusava.
 *
 * A licao, e o motivo destes testes existirem: conferir os campos que EU escolhi olhar nao e
 * conferir a requisicao. O que prova e o campo que o outro lado usa.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'jid']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@jid.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'jid',
    ]);

    // Pessoa, nao grupo: e o caso que quebrava.
    $this->contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+554396386381', 'jid' => '554396386381@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => 'aberta', 'ultima_entrada_em' => now(),
    ]);

    $this->doCliente = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'oi', 'external_id' => 'WAMID-CLIENTE', 'status' => Message::STATUS_DELIVERED,
    ]);

    $this->nossa = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'ola', 'external_id' => 'WAMID-NOSSO', 'status' => Message::STATUS_SENT,
    ]);

    $this->actingAs($this->pessoa);
    Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 200)]);
});

// --------------------------------------------------------------- o conversor

it('transforma telefone em JID', function () {
    expect(EvolutionService::jid('+554396386381'))->toBe('554396386381@s.whatsapp.net')
        ->and(EvolutionService::jid('554396386381'))->toBe('554396386381@s.whatsapp.net')
        ->and(EvolutionService::jid('+55 43 9638-6381'))->toBe('554396386381@s.whatsapp.net');
});

it('nao mexe no que ja e JID', function () {
    // Grupo e o caso em que tudo sempre funcionou. Nao pode quebrar agora.
    expect(EvolutionService::jid('120363306110054167@g.us'))->toBe('120363306110054167@g.us')
        ->and(EvolutionService::jid('554396386381@s.whatsapp.net'))->toBe('554396386381@s.whatsapp.net');
});

// ------------------------------------------- os tres lugares que usam a chave

it('a citacao manda um JID, e nao o telefone com mais', function () {
    $resposta = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'respondendo', 'responde_a_id' => $this->doCliente->id,
        'status' => Message::STATUS_QUEUED,
    ]);

    (new SendTextMessage($resposta->id))->handle(app(Enviadores::class));

    Http::assertSent(function ($r) {
        $jid = $r->data()['quoted']['key']['remoteJid'] ?? '';

        return str_contains($r->url(), 'sendText')
            && str_contains($jid, '@')
            && ! str_contains($jid, '+');
    });
});

it('a reacao manda um JID', function () {
    (new SendReaction($this->doCliente->id, "\u{1F44D}"))->handle(app(Enviadores::class));

    Http::assertSent(function ($r) {
        $jid = $r->data()['key']['remoteJid'] ?? '';

        return str_contains($r->url(), 'sendReaction')
            && str_contains($jid, '@')
            && ! str_contains($jid, '+');
    });
});

it('o apagar manda um JID — o caso que estourou de verdade', function () {
    (new DeleteMessage($this->nossa->id))->handle(app(Enviadores::class));

    Http::assertSent(function ($r) {
        $jid = $r->data()['remoteJid'] ?? '';

        return str_contains($r->url(), 'deleteMessageForEveryone')
            && $jid === '554396386381@s.whatsapp.net';
    });
});

it('em grupo continua indo o JID do grupo', function () {
    $grupo = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Grupo', 'tipo' => 'grupo',
        'telefone_e164' => null, 'jid' => '120363306110054167@g.us',
    ]);

    $conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $grupo->id, 'status' => 'aberta', 'ultima_entrada_em' => now(),
    ]);

    $m = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'no grupo', 'external_id' => 'WAMID-GRUPO', 'status' => Message::STATUS_SENT,
    ]);

    (new DeleteMessage($m->id))->handle(app(Enviadores::class));

    Http::assertSent(fn ($r) => ($r->data()['remoteJid'] ?? '') === '120363306110054167@g.us');
});
