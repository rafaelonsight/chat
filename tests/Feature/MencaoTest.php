<?php

use App\Jobs\ProcessEvolutionWebhook;
use App\Livewire\Inbox\ConversationList;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User, WebhookEvent};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * "Te chamaram no grupo."
 *
 * Num grupo movimentado, a unica mensagem que e SUA e aquela em que te mencionam. Sem separar
 * isso, o atendente le duzentas mensagens para achar uma — ou desiste e nao le nenhuma, que e
 * o que acontece de verdade.
 *
 * ESTE ARQUIVO TAMBEM GUARDA UM DEFEITO QUE PASSOU DIAS DESPERCEBIDO. Esta versao da Evolution
 * manda o contextInfo em data.contextInfo — IRMAO de "message", nao dentro dele. O codigo so
 * olhava dentro do conteudo, e o resultado foi silencioso: cinco clientes citaram mensagens e
 * nenhuma citacao apareceu. Nada quebrou, nada foi para o log.
 */

beforeEach(function () {
    // Abrir a conversa avisa o WhatsApp que foi lida. Sem o fake, o teste bate na
    // Evolution de verdade e falha por uma instancia que so existe em producao.
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'mencao']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'a@mencao.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    // O numero do canal chega da Evolution SEM o nono digito — e assim que o Baileys informa
    // o proprio numero no Brasil. O casamento tem de aguentar as duas formas.
    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Meu numero',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'men',
        'telefone_e164' => '+554184919939',
    ]);

    $this->grupo = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Grupo da obra', 'tipo' => 'grupo',
        'telefone_e164' => null, 'jid' => '120363287727031438@g.us',
    ]);

    $this->pessoaFisica = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);
});

/** Entrega um webhook de mensagem recebida, no formato que a Evolution manda de verdade. */
function chegou(array $ctx, array $data): void
{
    $evento = WebhookEvent::create([
        'tenant_id'   => $ctx['conta']->id,
        'channel_id'  => $ctx['canal']->id,
        'evento'      => 'messages.upsert',
        'payload'     => ['data' => $data],
        'recebido_em' => now(),
    ]);

    (new ProcessEvolutionWebhook($evento->id))->handle();
}

function baseGrupo(array $ctx, string $texto, array $extra = []): array
{
    return array_merge([
        'key' => [
            'id'          => 'W-'.uniqid(),
            'fromMe'      => false,
            'remoteJid'   => $ctx['grupo']->jid,
            'participant' => '5511930911945@s.whatsapp.net',
        ],
        'pushName' => 'Fulano',
        'message'  => ['conversation' => $texto],
    ], $extra);
}

// ============================================================== a mencao

it('reconhece a mencao pelo jid, mesmo com o nono digito de diferenca', function () {
    // O Baileys informa nosso numero sem o 9; a mencao vem com ele. Comparar texto puro
    // falharia, e a marca simplesmente nunca apareceria.
    $ctx = ['conta' => $this->conta, 'canal' => $this->canal, 'grupo' => $this->grupo];

    chegou($ctx, baseGrupo($ctx, 'alguem resolve isso', [
        'contextInfo' => ['mentionedJid' => ['5541984919939@s.whatsapp.net']],
    ]));

    $m = Message::latest('id')->first();

    expect($m->mencao)->toBeTrue()
        ->and($m->conversation->mencao_em)->not->toBeNull();
});

it('reconhece a mencao escrita no texto, quando o jid vem no formato novo', function () {
    // O WhatsApp esta trocando o identificador por @lid, que nao casa com telefone nenhum.
    // O texto continua trazendo "@numero" — e a rede que evita perder a mencao.
    $ctx = ['conta' => $this->conta, 'canal' => $this->canal, 'grupo' => $this->grupo];

    chegou($ctx, baseGrupo($ctx, '@5541984919939 consegue ver isso?', [
        'contextInfo' => ['mentionedJid' => ['228535880945688@lid']],
    ]));

    expect(Message::latest('id')->first()->mencao)->toBeTrue();
});

it('mensagem comum de grupo NAO vira mencao', function () {
    // Marcar mencao onde nao houve treinaria o atendente a ignorar a marca — e ai ela deixa
    // de servir justamente quando for verdade.
    $ctx = ['conta' => $this->conta, 'canal' => $this->canal, 'grupo' => $this->grupo];

    chegou($ctx, baseGrupo($ctx, 'bom dia pessoal', [
        'contextInfo' => ['mentionedJid' => ['5511999998888@s.whatsapp.net']],
    ]));

    $m = Message::latest('id')->first();

    expect($m->mencao)->toBeFalse()
        ->and($m->conversation->mencao_em)->toBeNull();
});

it('conversa de UMA pessoa nunca marca mencao', function () {
    // Ali toda mensagem ja e para nos; a marca nao separaria nada.
    $ctx = ['conta' => $this->conta, 'canal' => $this->canal, 'grupo' => $this->grupo];

    chegou($ctx, [
        'key' => ['id' => 'W-pf', 'fromMe' => false, 'remoteJid' => $this->pessoaFisica->jid],
        'pushName' => 'Cliente',
        'message' => ['conversation' => '@5541984919939 oi'],
        'contextInfo' => ['mentionedJid' => ['5541984919939@s.whatsapp.net']],
    ]);

    expect(Message::latest('id')->first()->mencao)->toBeFalse();
});

it('abrir a conversa limpa a mencao', function () {
    // A mencao e um pedido de atencao, e a atencao acabou de acontecer.
    $ctx = ['conta' => $this->conta, 'canal' => $this->canal, 'grupo' => $this->grupo];

    chegou($ctx, baseGrupo($ctx, 'e ai', [
        'contextInfo' => ['mentionedJid' => ['5541984919939@s.whatsapp.net']],
    ]));

    $conversa = Conversation::where('contact_id', $this->grupo->id)->firstOrFail();
    expect($conversa->mencao_em)->not->toBeNull();

    Livewire::actingAs($this->pessoa)->test(ConversationList::class)
        ->call('selecionar', $conversa->id);

    expect($conversa->fresh()->mencao_em)->toBeNull()
        ->and($conversa->fresh()->nao_lidas)->toBe(0);
});

it('a lista marca a conversa com mencao', function () {
    $ctx = ['conta' => $this->conta, 'canal' => $this->canal, 'grupo' => $this->grupo];

    chegou($ctx, baseGrupo($ctx, 'preciso de voce', [
        'contextInfo' => ['mentionedJid' => ['5541984919939@s.whatsapp.net']],
    ]));

    Livewire::actingAs($this->pessoa)->test(ConversationList::class)
        ->set('equipe', 'todas')->set('balde', 'grupos')
        ->assertSee('te chamaram');
});

// ================================================ a citacao que estava perdida

it('citacao do cliente e registrada quando o contextInfo vem no nivel de data', function () {
    // O DEFEITO QUE ESTE ARQUIVO EXISTE PARA GUARDAR. Cinco clientes citaram mensagens e
    // nenhuma foi registrada, porque o codigo procurava dentro de "message" e a Evolution
    // manda ao lado dele.
    $ctx = ['conta' => $this->conta, 'canal' => $this->canal, 'grupo' => $this->grupo];

    $nossa = Message::create([
        'tenant_id' => $this->conta->id,
        'conversation_id' => Conversation::create([
            'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
            'contact_id' => $this->pessoaFisica->id, 'status' => 'aberta',
        ])->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'segue o orcamento', 'external_id' => 'WAMID-NOSSO',
        'status' => Message::STATUS_SENT,
    ]);

    chegou($ctx, [
        'key' => ['id' => 'W-cit', 'fromMe' => false, 'remoteJid' => $this->pessoaFisica->jid],
        'pushName' => 'Cliente',
        'message' => ['conversation' => 'fechado'],
        'contextInfo' => ['stanzaId' => 'WAMID-NOSSO'],
    ]);

    expect(Message::where('corpo', 'fechado')->first()->responde_a_id)->toBe($nossa->id);
});

it('o formato antigo, com o contextInfo dentro do conteudo, continua valendo', function () {
    // Versoes diferentes da Evolution mandam de jeitos diferentes, e nao ha como saber qual
    // esta do outro lado.
    $ctx = ['conta' => $this->conta, 'canal' => $this->canal, 'grupo' => $this->grupo];

    $nossa = Message::create([
        'tenant_id' => $this->conta->id,
        'conversation_id' => Conversation::create([
            'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
            'contact_id' => $this->pessoaFisica->id, 'status' => 'aberta',
        ])->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'x', 'external_id' => 'WAMID-ANTIGO', 'status' => Message::STATUS_SENT,
    ]);

    chegou($ctx, [
        'key' => ['id' => 'W-ant', 'fromMe' => false, 'remoteJid' => $this->pessoaFisica->jid],
        'pushName' => 'Cliente',
        'message' => [
            'extendedTextMessage' => [
                'text' => 'ok',
                'contextInfo' => ['stanzaId' => 'WAMID-ANTIGO'],
            ],
        ],
    ]);

    expect(Message::where('corpo', 'ok')->first()->responde_a_id)->toBe($nossa->id);
});
