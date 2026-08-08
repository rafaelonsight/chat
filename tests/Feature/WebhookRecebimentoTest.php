<?php

use App\Models\{Channel, Contact, Conversation, Message, Tenant, WebhookEvent};
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

it('ignora eco da mensagem que NOS mesmos enviamos', function () {
    /*
     * O QUE SEPARA ECO DE MENSAGEM NOVA E O BANCO, e nao o payload.
     *
     * Toda mensagem que sai por aqui e gravada com o id do provedor antes de existir eco. Se o
     * id ja e conhecido, o evento e so o retorno do que ja esta na tela.
     */
    $contato = Contact::acharOuCriarPorTelefone('+5511999998888');
    $conversa = Conversation::abertaOuNova($this->channel->id, $contato->id, $this->tenant->id);

    Message::create([
        'tenant_id'       => $this->tenant->id,
        'conversation_id' => $conversa->id,
        'channel_id'      => $this->channel->id,
        'direcao'         => 'out',
        'tipo'            => 'text',
        'corpo'           => 'minha',
        'external_id'     => 'OUT9',
        'status'          => Message::STATUS_SENT,
    ]);

    $p = payloadRecebida('5511999998888', 'minha', 'OUT9');
    $p['data']['key']['fromMe'] = true;

    $this->postJson($this->url, $p)->assertOk();

    expect(Message::count())->toBe(1)
        ->and(Message::first()->por_fora)->toBeFalse();
});

it('grava a mensagem que o atendente mandou pelo proprio celular', function () {
    /*
     * ANTES ISSO SUMIA. Todo fromMe era descartado como eco, e o sistema perdia metade da
     * conversa: no painel o atendimento ficava parado na pergunta do cliente, e quem abrisse
     * depois concluiria que ele estava sem resposta — e responderia de novo.
     */
    $p = payloadRecebida('5511999998888', 'respondi pelo celular', 'FORA1');
    $p['data']['key']['fromMe'] = true;

    $this->postJson($this->url, $p)->assertOk();

    $m = Message::first();

    expect($m)->not->toBeNull()
        ->and($m->direcao)->toBe('out')
        ->and($m->corpo)->toBe('respondi pelo celular')
        ->and($m->por_fora)->toBeTrue()
        ->and($m->status)->toBe(Message::STATUS_DELIVERED);
});

it('a mensagem mandada por fora nao conta como nao lida nem reabre a janela', function () {
    // A janela de 24h pertence a quem PROCUROU, e quem falou aqui fomos nos. E nao lidas conta
    // mensagem de cliente: somar a propria resposta acusaria trabalho que nao existe.
    $p = payloadRecebida('5511999998888', 'oi', 'FORA2');
    $p['data']['key']['fromMe'] = true;

    $this->postJson($this->url, $p)->assertOk();

    $conversa = Conversation::first();

    expect($conversa->nao_lidas)->toBe(0)
        ->and($conversa->ultima_entrada_em)->toBeNull()
        ->and($conversa->ultima_msg_em)->not->toBeNull();
});

it('mensagem por fora para numero novo nao batiza o contato com o nosso nome', function () {
    /*
     * Em mensagem nossa, o pushName do payload e o NOSSO nome. Sem cuidado, mandar do celular
     * para um numero desconhecido criaria o contato chamado "Atendente" — e o erro so
     * apareceria semanas depois, numa lista cheia de contatos com o mesmo nome.
     */
    $p = payloadRecebida('5511777776666', 'oi', 'FORA3');
    $p['data']['key']['fromMe'] = true;
    $p['data']['pushName'] = 'Atendente da Loja';

    $this->postJson($this->url, $p)->assertOk();

    expect(Contact::where('telefone_e164', '+5511777776666')->first()->nome)
        ->not->toBe('Atendente da Loja');
});

it('o mesmo evento reentregue nao duplica a mensagem por fora', function () {
    $p = payloadRecebida('5511999998888', 'oi', 'FORA4');
    $p['data']['key']['fromMe'] = true;

    $this->postJson($this->url, $p)->assertOk();
    $this->postJson($this->url, $p)->assertOk();

    expect(Message::count())->toBe(1);
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
