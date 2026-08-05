<?php

use App\Jobs\ProcessMetaWebhook;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, WebhookEvent};
use App\Services\ChatbotMotor;
use App\Support\TenantContext;

/*
 * Webhook do WhatsApp oficial.
 *
 * A fila nos testes e sync, entao o POST ja executa o job: o que estes testes provam e o
 * caminho inteiro — assinatura, descoberta do canal, gravacao do evento e criacao da
 * mensagem — e nao cada peca em isolamento.
 */

const META_SEGREDO = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
const META_VERIFY  = 'token-de-verificacao-do-teste';
const META_PNID    = '1235849066282498';
const META_URL     = '/webhooks/meta/whatsapp';

beforeEach(function () {
    config([
        'services.meta.app_secret'   => META_SEGREDO,
        'services.meta.verify_token' => META_VERIFY,
    ]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'meta']);
    TenantContext::set($this->tenant->id);

    $this->canal = Channel::create([
        'nome'                 => 'Oficial',
        'tipo'                 => Channel::META_CLOUD,
        'meta_phone_number_id' => META_PNID,
    ])->refresh();
});

afterEach(fn () => TenantContext::forget());

/** Mensagem de texto no formato que a Meta manda de verdade. */
function metaTexto(string $corpo = 'oi', string $wamid = 'wamid.TESTE1', string $de = '5541984919939'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'id'      => '3620023178150458',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata'          => ['display_phone_number' => '15556725603', 'phone_number_id' => META_PNID],
                    'contacts'          => [['profile' => ['name' => 'Rafael'], 'wa_id' => $de]],
                    'messages'          => [[
                        'from'      => $de,
                        'id'        => $wamid,
                        'timestamp' => (string) now()->timestamp,
                        'type'      => 'text',
                        'text'      => ['body' => $corpo],
                    ]],
                ],
            ]],
        ]],
    ];
}

/** Recibo de entrega de uma mensagem que saiu por aqui. */
function metaRecibo(string $wamid, string $situacao, array $erros = []): array
{
    $recibo = ['id' => $wamid, 'status' => $situacao, 'timestamp' => (string) now()->timestamp];

    if ($erros) {
        $recibo['errors'] = $erros;
    }

    return [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'id'      => '3620023178150458',
            'changes' => [[
                'field' => 'statuses',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata'          => ['phone_number_id' => META_PNID],
                    'statuses'          => [$recibo],
                ],
            ]],
        ]],
    ];
}

/**
 * POST assinado como a Meta assina: HMAC-SHA256 do corpo CRU com o App Secret.
 *
 * Nao usa postJson de proposito: postJson reserializa, e a assinatura precisa casar com os
 * bytes exatos que o servidor recebe.
 */
function metaPost(array $payload, ?string $assinatura = null)
{
    $corpo = json_encode($payload);

    return test()->call(
        'POST',
        META_URL,
        [], [], [],
        [
            'HTTP_X_HUB_SIGNATURE_256' => $assinatura ?? 'sha256='.hash_hmac('sha256', $corpo, META_SEGREDO),
            'CONTENT_TYPE'             => 'application/json',
        ],
        $corpo,
    );
}

/** Cria uma mensagem que saiu por aqui, para os recibos terem o que casar. */
function metaMensagemEnviada(string $wamid, string $status): Message
{
    $contato = Contact::create(['nome' => 'Rafael', 'telefone_e164' => '+5541984919939']);
    $conversa = Conversation::abertaOuNova(test()->canal->id, $contato->id);

    return Message::create([
        'conversation_id' => $conversa->id,
        'channel_id'      => test()->canal->id,
        'direcao'         => 'out',
        'tipo'            => 'text',
        'corpo'           => 'ola',
        'status'          => $status,
        'external_id'     => $wamid,
        'enviada_em'      => now(),
    ]);
}

// ======================================================= VERIFICACAO DA INSCRICAO (GET)

it('devolve o desafio em texto puro quando o token de verificacao casa', function () {
    // Se responder JSON, ou com o token errado, a Meta nao completa a inscricao e depois
    // nao chega mensagem nenhuma — sem erro nenhum aparecer em lugar nenhum.
    $r = $this->get(META_URL.'?hub_mode=subscribe&hub_verify_token='.META_VERIFY.'&hub_challenge=1234567890');

    $r->assertOk()->assertSee('1234567890', false);

    expect($r->headers->get('Content-Type'))->toContain('text/plain');
});

it('aceita a verificacao no formato que a Meta manda de verdade, com ponto', function () {
    // A Meta chama hub.mode / hub.verify_token / hub.challenge COM PONTO. Isso so funciona
    // porque o PHP troca ponto por sublinhado no nome do parametro. Se algum dia alguem
    // "arrumar" o controller para ler exatamente hub.mode, a inscricao para de completar e
    // o sintoma e nao chegar mensagem — sem erro em log nenhum.
    $this->get(META_URL.'?hub.mode=subscribe&hub.verify_token='.META_VERIFY.'&hub.challenge=987654321')
        ->assertOk()
        ->assertSee('987654321', false);
});

it('recusa a verificacao com token errado', function () {
    $this->get(META_URL.'?hub_mode=subscribe&hub_verify_token=chute&hub_challenge=123')
        ->assertForbidden();
});

it('recusa a verificacao sem hub_mode subscribe', function () {
    $this->get(META_URL.'?hub_verify_token='.META_VERIFY.'&hub_challenge=123')
        ->assertForbidden();
});

// ================================================================ ASSINATURA DO EVENTO

it('recusa evento sem assinatura', function () {
    // Sem esta trava, quem descobrir a URL injeta mensagem falsa na caixa de entrada de um
    // cliente. E URL nao e segredo: aparece em log, em proxy e em print de tela.
    $this->postJson(META_URL, metaTexto())->assertForbidden();

    expect(Message::count())->toBe(0)
        ->and(WebhookEvent::count())->toBe(0);
});

it('recusa evento assinado com outro segredo', function () {
    $falsa = 'sha256='.hash_hmac('sha256', json_encode(metaTexto()), 'segredo-do-atacante');

    metaPost(metaTexto(), $falsa)->assertForbidden();

    expect(Message::count())->toBe(0);
});

it('recusa assinatura no formato certo mas de outro corpo', function () {
    // Assinatura de um payload nao serve para outro: e o que impede reenviar um evento
    // legitimo capturado, com o texto trocado.
    $outra = 'sha256='.hash_hmac('sha256', json_encode(metaTexto('texto original')), META_SEGREDO);

    metaPost(metaTexto('texto trocado'), $outra)->assertForbidden();

    expect(Message::count())->toBe(0);
});

// ============================================================ MENSAGEM QUE CHEGA

it('cria contato, conversa e mensagem a partir do evento', function () {
    metaPost(metaTexto('minha internet caiu'))->assertOk();

    expect(Message::count())->toBe(1);

    $m = Message::first();

    expect($m->corpo)->toBe('minha internet caiu')
        ->and($m->direcao)->toBe('in')
        ->and($m->external_id)->toBe('wamid.TESTE1')
        ->and($m->status)->toBe(Message::STATUS_DELIVERED)
        ->and($m->tenant_id)->toBe($this->tenant->id)
        ->and($m->conversation->channel_id)->toBe($this->canal->id)
        ->and($m->conversation->contact->telefone_e164)->toBe('+5541984919939')
        ->and($m->conversation->contact->nome)->toBe('Rafael')
        ->and($m->conversation->nao_lidas)->toBe(1);
});

it('grava o evento cru e marca como processado', function () {
    metaPost(metaTexto())->assertOk()->assertJson(['ok' => true]);

    $e = WebhookEvent::first();

    expect($e->channel_id)->toBe($this->canal->id)
        ->and($e->tenant_id)->toBe($this->tenant->id)
        ->and($e->evento)->toBe('meta:messages')
        ->and($e->erro)->toBeNull()
        ->and($e->processado_em)->not->toBeNull()
        ->and(data_get($e->payload, 'entry.0.changes.0.value.messages.0.text.body'))->toBe('oi');
});

it('a mensagem do cliente abre a janela de 24h', function () {
    // E o elo que faz a fase 1 valer no canal oficial: sem ultima_entrada_em a janela
    // nasceria fechada e o atendente nunca conseguiria mandar texto livre.
    metaPost(metaTexto())->assertOk();

    $conversa = Conversation::first();

    expect($conversa->ultima_entrada_em)->not->toBeNull()
        ->and($conversa->janelaAberta())->toBeTrue()
        ->and($conversa->podeEnviarLivre())->toBeTrue();
});

it('nao duplica quando a Meta reentrega o mesmo wamid', function () {
    // A Meta reentrega quando nao recebe 200 rapido. Sem idempotencia, uma lentidao nossa
    // viraria mensagem repetida na tela do atendente.
    metaPost(metaTexto('oi', 'wamid.IGUAL'))->assertOk();
    metaPost(metaTexto('oi', 'wamid.IGUAL'))->assertOk();

    expect(Message::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and(Conversation::first()->nao_lidas)->toBe(1)
        ->and(WebhookEvent::count())->toBe(2); // os dois eventos ficam registrados
});

it('evento ja processado nao roda de novo', function () {
    metaPost(metaTexto())->assertOk();

    (new ProcessMetaWebhook(WebhookEvent::first()->id))->handle();

    expect(Message::count())->toBe(1);
});

it('chama o chatbot quando a mensagem chega pelo canal oficial', function () {
    // O bot precisa ter a primeira palavra igual ao canal nao oficial. A logica do fluxo
    // tem os testes dela; aqui o que esta sob prova e a fiacao do caminho oficial.
    $this->mock(ChatbotMotor::class)
        ->shouldReceive('talvezAtender')
        ->once()
        ->andReturn(false);

    metaPost(metaTexto())->assertOk();
});

it('numero desconhecido fica registrado com o motivo, sem derrubar nada', function () {
    // "A Meta mandou e nada aconteceu" precisa ter onde ser investigado.
    $p = metaTexto();
    data_set($p, 'entry.0.changes.0.value.metadata.phone_number_id', '999999999999999');

    metaPost($p)->assertOk();

    $e = WebhookEvent::first();

    expect($e->erro)->toContain('nenhum canal')
        ->and($e->channel_id)->toBeNull()
        ->and(Message::count())->toBe(0);
});

it('tipo ainda nao tratado nao cria mensagem pela metade', function () {
    $p = metaTexto();
    data_set($p, 'entry.0.changes.0.value.messages.0.type', 'location');
    data_set($p, 'entry.0.changes.0.value.messages.0.location', ['latitude' => -25.4, 'longitude' => -49.2]);

    metaPost($p)->assertOk();

    // Nada de mensagem vazia na tela, e o evento fica gravado para virar suporte depois.
    expect(Message::count())->toBe(0)
        ->and(WebhookEvent::first()->processado_em)->not->toBeNull()
        ->and(WebhookEvent::first()->erro)->toBeNull();
});

it('resposta de botao chega como texto, para o menu do chatbot casar', function () {
    $p = metaTexto();
    data_set($p, 'entry.0.changes.0.value.messages.0.type', 'interactive');
    data_set($p, 'entry.0.changes.0.value.messages.0.interactive', [
        'type'         => 'button_reply',
        'button_reply' => ['id' => 'op_1', 'title' => 'Financeiro'],
    ]);

    metaPost($p)->assertOk();

    expect(Message::first()->corpo)->toBe('Financeiro')
        ->and(Message::first()->tipo)->toBe('text');
});

it('midia chega registrada com o mime, mesmo sem o arquivo', function () {
    // O download oficial exige uma segunda chamada com o id da midia. Enquanto isso, o
    // atendente ve que existe um audio em vez de uma mensagem vazia.
    $p = metaTexto();
    data_set($p, 'entry.0.changes.0.value.messages.0.type', 'audio');
    data_set($p, 'entry.0.changes.0.value.messages.0.audio', [
        'id' => '1234', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true,
    ]);

    metaPost($p)->assertOk();

    expect(Message::first()->tipo)->toBe('audio')
        ->and(Message::first()->media_mime)->toContain('audio/ogg');
});

// ===================================================================== RECIBOS

it('o recibo avanca o status da mensagem que saiu por aqui', function () {
    $m = metaMensagemEnviada('wamid.SAIU', Message::STATUS_SENT);

    metaPost(metaRecibo('wamid.SAIU', 'delivered'))->assertOk();
    expect($m->refresh()->status)->toBe(Message::STATUS_DELIVERED);

    metaPost(metaRecibo('wamid.SAIU', 'read'))->assertOk();
    expect($m->refresh()->status)->toBe(Message::STATUS_READ);
});

it('o status NAO retrocede quando o recibo chega fora de ordem', function () {
    // A Meta pode entregar "sent" depois de "read". Ver a mensagem voltar de lida para
    // enviada faria o atendente reenviar algo que o cliente ja leu.
    $m = metaMensagemEnviada('wamid.LIDA', Message::STATUS_READ);

    metaPost(metaRecibo('wamid.LIDA', 'sent'))->assertOk();

    expect($m->refresh()->status)->toBe(Message::STATUS_READ);
});

it('recibo de falha guarda o motivo, e falha vence status melhor', function () {
    $m = metaMensagemEnviada('wamid.FALHOU', Message::STATUS_DELIVERED);

    metaPost(metaRecibo('wamid.FALHOU', 'failed', [
        ['code' => 131047, 'title' => 'Re-engagement message'],
    ]))->assertOk();

    expect($m->refresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($m->erro)->toContain('Re-engagement');
});

it('recibo de mensagem que nao saiu por aqui e ignorado sem erro', function () {
    metaPost(metaRecibo('wamid.DEOUTROSISTEMA', 'read'))->assertOk();

    expect(WebhookEvent::first()->erro)->toBeNull()
        ->and(WebhookEvent::first()->processado_em)->not->toBeNull();
});
