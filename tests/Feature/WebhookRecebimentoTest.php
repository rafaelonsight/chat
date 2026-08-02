<?php

use App\Models\{Channel, Conversation, Message, Tenant, WebhookEvent};
use App\Support\TenantContext;

function payloadRecebida(string $de, string $texto, string $id): array
{
    return [
        'event' => 'messages.upsert',
        'data'  => [
            'key'      => ['remoteJid' => $de.'@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
            'pushName' => 'Cliente Teste',
            'message'  => ['conversation' => $texto],
            'messageTimestamp' => 1785648000,
        ],
    ];
}

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);
    $this->channel = Channel::create(['nome' => 'Principal'])->refresh();
    $this->url = "/webhooks/evolution/{$this->channel->id}/{$this->channel->webhook_secret}";
});

afterEach(fn () => TenantContext::forget());

it('cria contato, conversa e mensagem a partir do webhook', function () {
    $this->postJson($this->url, payloadRecebida('5511999998888', 'ola mundo', 'MSG1'))->assertOk();

    expect(Message::count())->toBe(1);

    $m = Message::first();
    expect($m->corpo)->toBe('ola mundo')
        ->and($m->direcao)->toBe('in')
        ->and($m->external_id)->toBe('MSG1')
        ->and($m->tenant_id)->toBe($this->tenant->id)
        ->and($m->conversation->contact->telefone_e164)->toBe('+5511999998888')
        ->and($m->conversation->contact->nome)->toBe('Cliente Teste')
        ->and($m->conversation->nao_lidas)->toBe(1);
});

it('nao duplica quando o webhook e reentregue', function () {
    $payload = payloadRecebida('5511999998888', 'ola', 'MSG1');

    $this->postJson($this->url, $payload)->assertOk();
    $this->postJson($this->url, $payload)->assertOk();

    expect(Message::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and(Conversation::first()->nao_lidas)->toBe(1);
});

it('recusa segredo invalido', function () {
    $this->postJson(
        "/webhooks/evolution/{$this->channel->id}/segredo-errado",
        payloadRecebida('5511999998888', 'ola', 'X')
    )->assertNotFound();

    expect(Message::count())->toBe(0)
        ->and(WebhookEvent::count())->toBe(0);
});

it('ignora eco das mensagens que nos mesmos enviamos', function () {
    $p = payloadRecebida('5511999998888', 'minha', 'OUT9');
    $p['data']['key']['fromMe'] = true;

    $this->postJson($this->url, $p)->assertOk();

    expect(Message::count())->toBe(0);
});

it('atualiza status pelo evento de update', function () {
    $this->postJson($this->url, payloadRecebida('5511999998888', 'ola', 'MSG1'))->assertOk();

    $this->postJson($this->url, [
        'event' => 'messages.update',
        'data'  => ['keyId' => 'MSG1', 'status' => 'READ'],
    ])->assertOk();

    expect(Message::first()->status)->toBe(Message::STATUS_READ);
});

it('atualiza o status do canal na mudanca de conexao', function () {
    $this->postJson($this->url, [
        'event' => 'connection.update',
        'data'  => ['state' => 'open'],
    ])->assertOk();

    $this->channel->refresh();
    expect($this->channel->status)->toBe('open')
        ->and($this->channel->conectado())->toBeTrue();
});

it('registra o erro sem derrubar a fila quando o payload e inesperado', function () {
    $this->postJson($this->url, ['event' => 'messages.upsert', 'data' => ['nada' => 'aqui']])->assertOk();

    $evento = WebhookEvent::first();
    expect($evento->processado_em)->not->toBeNull()
        ->and($evento->erro)->toContain('remetente');
    expect(Message::count())->toBe(0);
});
