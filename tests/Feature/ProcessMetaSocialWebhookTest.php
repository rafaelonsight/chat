<?php

use App\Jobs\ProcessMetaSocialWebhook;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, WebhookEvent};
use App\Support\TenantContext;

/*
 * Webhook de Messenger e Instagram (direct e comentario de post).
 *
 * Mesmo espirito do WebhookMetaTest (WhatsApp): a fila nos testes e sync, entao o POST
 * ja executa o job — o que estes testes provam e o caminho inteiro, nao cada peca em
 * isolamento.
 */

const SOCIAL_SEGREDO = 'segredo-de-teste-do-social-abc123';
const SOCIAL_URL      = '/webhooks/meta/social';
const PAGE_ID         = '778899001122';
const IG_ACCOUNT_ID   = '556677889900';

beforeEach(function () {
    config(['services.meta.app_secret' => SOCIAL_SEGREDO]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'social-webhook']);
    TenantContext::set($this->tenant->id);

    $this->messenger = Channel::create([
        'nome' => 'Messenger', 'tipo' => Channel::MESSENGER, 'meta_page_id' => PAGE_ID,
    ])->refresh();

    $this->instagram = Channel::create([
        'nome' => 'Instagram', 'tipo' => Channel::INSTAGRAM, 'meta_ig_account_id' => IG_ACCOUNT_ID,
    ])->refresh();
});

afterEach(fn () => TenantContext::forget());

function socialPost(array $payload)
{
    $corpo = json_encode($payload);

    return test()->call(
        'POST',
        SOCIAL_URL,
        [], [], [],
        [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, SOCIAL_SEGREDO),
            'CONTENT_TYPE'             => 'application/json',
        ],
        $corpo,
    );
}

function messengerTexto(string $texto = 'oi', string $mid = 'm_TESTE1', string $de = 'psid-do-cliente'): array
{
    return [
        'object' => 'page',
        'entry'  => [[
            'id'        => PAGE_ID,
            'messaging' => [[
                'sender'    => ['id' => $de],
                'recipient' => ['id' => PAGE_ID],
                'message'   => ['mid' => $mid, 'text' => $texto],
            ]],
        ]],
    ];
}

function instagramDirect(string $texto = 'oi', string $mid = 'ig_TESTE1', string $de = 'igsid-do-cliente'): array
{
    return [
        'object' => 'instagram',
        'entry'  => [[
            'id'        => IG_ACCOUNT_ID,
            'messaging' => [[
                'sender'    => ['id' => $de],
                'recipient' => ['id' => IG_ACCOUNT_ID],
                'message'   => ['mid' => $mid, 'text' => $texto],
            ]],
        ]],
    ];
}

function instagramComentario(string $texto = 'que post legal', string $commentId = 'comment_TESTE1', string $de = 'igsid-do-comentarista', string $username = 'fulano'): array
{
    return [
        'object' => 'instagram',
        'entry'  => [[
            'id'      => IG_ACCOUNT_ID,
            'changes' => [[
                'field' => 'comments',
                'value' => [
                    'id'    => $commentId,
                    'text'  => $texto,
                    'from'  => ['id' => $de, 'username' => $username],
                    'media' => ['id' => 'media_1'],
                ],
            ]],
        ]],
    ];
}

// ============================================================ ASSINATURA

it('recusa evento sem assinatura', function () {
    $this->postJson(SOCIAL_URL, messengerTexto())->assertForbidden();

    expect(Message::count())->toBe(0)->and(WebhookEvent::count())->toBe(0);
});

// =================================================== MENSAGEM DIRETA (MESSENGER)

it('cria contato, conversa e mensagem a partir de um direct do Messenger', function () {
    socialPost(messengerTexto('quero saber o preço'))->assertOk();

    expect(Message::count())->toBe(1);

    $m = Message::first();
    expect($m->corpo)->toBe('quero saber o preço')
        ->and($m->direcao)->toBe('in')
        ->and($m->tipo)->toBe('text')
        ->and($m->external_id)->toBe('m_TESTE1')
        ->and($m->conversation->channel_id)->toBe($this->messenger->id)
        ->and($m->conversation->contact->jid)->toBe("messenger_{$this->messenger->id}_psid-do-cliente")
        ->and($m->conversation->nao_lidas)->toBe(1);
});

it('não duplica quando o Messenger reentrega o mesmo mid', function () {
    socialPost(messengerTexto('oi', 'm_IGUAL'))->assertOk();
    socialPost(messengerTexto('oi', 'm_IGUAL'))->assertOk();

    expect(Message::count())->toBe(1)
        ->and(Conversation::first()->nao_lidas)->toBe(1);
});

it('ignora o eco da mensagem que nós mesmos enviamos pelo Messenger', function () {
    $p = messengerTexto('resposta nossa');
    data_set($p, 'entry.0.messaging.0.message.is_echo', true);

    socialPost($p)->assertOk();

    expect(Message::count())->toBe(0);
});

it('mensagem de anexo (sem texto) ainda não tratada não cria mensagem pela metade', function () {
    $p = messengerTexto();
    unset($p['entry'][0]['messaging'][0]['message']['text']);
    data_set($p, 'entry.0.messaging.0.message.attachments', [['type' => 'image', 'payload' => ['url' => 'https://x/img.jpg']]]);

    socialPost($p)->assertOk();

    expect(Message::count())->toBe(0)
        ->and(WebhookEvent::first()->processado_em)->not->toBeNull()
        ->and(WebhookEvent::first()->erro)->toBeNull();
});

// ================================================== MENSAGEM DIRETA (INSTAGRAM)

it('cria contato, conversa e mensagem a partir de um direct do Instagram', function () {
    socialPost(instagramDirect('oi do instagram'))->assertOk();

    $m = Message::first();
    expect($m->corpo)->toBe('oi do instagram')
        ->and($m->conversation->channel_id)->toBe($this->instagram->id)
        ->and($m->conversation->contact->jid)->toBe("instagram_{$this->instagram->id}_igsid-do-cliente");
});

// ===================================================== COMENTARIO (INSTAGRAM)

it('cria mensagem do tipo comentario_ig a partir de um comentário de post', function () {
    socialPost(instagramComentario('adorei o produto!'))->assertOk();

    expect(Message::count())->toBe(1);

    $m = Message::first();
    expect($m->tipo)->toBe('comentario_ig')
        ->and($m->corpo)->toBe('adorei o produto!')
        ->and($m->external_id)->toBe('comment_TESTE1')
        ->and($m->remetente_nome)->toBe('fulano')
        ->and($m->conversation->channel_id)->toBe($this->instagram->id)
        ->and($m->conversation->contact->jid)->toBe("instagram_{$this->instagram->id}_igsid-do-comentarista");
});

it('agrupa dois comentários da mesma pessoa no mesmo post na mesma conversa', function () {
    socialPost(instagramComentario('primeiro comentário', 'comment_A'))->assertOk();
    socialPost(instagramComentario('segundo comentário', 'comment_B'))->assertOk();

    expect(Message::count())->toBe(2)
        ->and(Conversation::count())->toBe(1);
});

it('não duplica quando o comentário é reentregue', function () {
    socialPost(instagramComentario('oi', 'comment_IGUAL'))->assertOk();
    socialPost(instagramComentario('oi', 'comment_IGUAL'))->assertOk();

    expect(Message::count())->toBe(1);
});

it('ignora o próprio comentário de resposta que nós mesmos postamos', function () {
    $p = instagramComentario('resposta nossa', 'comment_NOSSO', IG_ACCOUNT_ID);

    socialPost($p)->assertOk();

    expect(Message::count())->toBe(0);
});

// ===================================================== CANAL NAO ENCONTRADO

it('registra o evento com o motivo quando não acha canal para o id recebido', function () {
    $p = messengerTexto();
    data_set($p, 'entry.0.messaging.0.recipient.id', 'pagina-que-nao-existe');
    data_set($p, 'entry.0.id', 'pagina-que-nao-existe');

    socialPost($p)->assertOk();

    $e = WebhookEvent::first();
    expect($e->erro)->toContain('nenhum canal')
        ->and($e->channel_id)->toBeNull()
        ->and(Message::count())->toBe(0)
        ->and($e->processado_em)->not->toBeNull();
});

it('evento já processado não roda de novo', function () {
    socialPost(messengerTexto())->assertOk();

    (new ProcessMetaSocialWebhook(WebhookEvent::first()->id))->handle();

    expect(Message::count())->toBe(1);
});
