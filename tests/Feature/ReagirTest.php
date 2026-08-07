<?php

use App\Jobs\SendReaction;
use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Reagir com emoji.
 *
 * Duas colunas na propria mensagem, e nao uma tabela de reacoes: no WhatsApp de empresa
 * existem exatamente DOIS lados — o cliente e nos — e cada lado tem no maximo uma reacao por
 * mensagem. Tabela ligada serviria para muitos reagentes, caso que este produto nao tem, e
 * cobraria um JOIN em toda listagem de conversa.
 *
 * A REGRA QUE MAIS IMPORTA AQUI: reacao NAO E MENSAGEM. Se o webhook a tratasse como mensagem,
 * a conversa ganharia um balao vazio a cada polegar levantado — e o contador de nao lidas
 * subiria por causa de um emoji.
 */

/**
 * O job recebe o ID de um evento JA GRAVADO, e nao o payload. Descobri passando o payload
 * direto: o PHP aceita argumento a mais em silencio, o job procurou um WebhookEvent com o id
 * do canal, nao achou, e nao fez nada — teste vermelho sem erro nenhum na tela.
 */
function eventoDaEvolution(App\Models\Channel $canal, array $data): int
{
    return App\Models\WebhookEvent::create([
        'tenant_id'   => $canal->tenant_id,
        'channel_id'  => $canal->id,
        'evento'      => 'messages.upsert',
        'payload'     => $data,
        'recebido_em' => now(),
    ])->id;
}

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'reagir']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@reagir.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'rea',
    ]);

    $contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $contato->id, 'status' => 'aberta', 'ultima_entrada_em' => now(),
    ]);

    $this->doCliente = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'obrigado!', 'external_id' => 'WAMID-CLIENTE',
        'status' => Message::STATUS_DELIVERED,
    ]);

    $this->actingAs($this->pessoa);

    // Http::fake chamado uma SEGUNDA vez nao substitui o primeiro — esta armadilha esta
    // escrita no tests/Pest.php e me pegou de novo aqui. O stub le de propriedades, e o
    // teste que precisa de outra resposta troca a propriedade.
    $this->resposta = ['key' => ['id' => 'X']];
    $this->status = 200;
    Http::fake(['*' => fn () => Http::response($this->resposta, $this->status)]);
});

// ------------------------------------------------------------ reagir da tela

it('guarda a reacao na hora do clique, sem esperar a fila', function () {
    // Reacao e gesto rapido. Esperar o job para ver o polegar aparecer faria a pessoa clicar
    // de novo, e o segundo clique tiraria a reacao que o primeiro pos.
    Queue::fake();

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('reagir', $this->doCliente->id, "\u{1F44D}");

    expect($this->doCliente->fresh()->reacao_nossa)->toBe("\u{1F44D}");

    Queue::assertPushed(SendReaction::class);
});

it('clicar no mesmo emoji de novo tira a reacao, como no WhatsApp', function () {
    Queue::fake();

    $tela = Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id]);

    $tela->call('reagir', $this->doCliente->id, "\u{1F44D}");
    expect($this->doCliente->fresh()->reacao_nossa)->toBe("\u{1F44D}");

    $tela->call('reagir', $this->doCliente->id, "\u{1F44D}");
    expect($this->doCliente->fresh()->reacao_nossa)->toBeNull();
});

it('trocar de emoji substitui, nao acumula', function () {
    Queue::fake();

    $tela = Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id]);

    $tela->call('reagir', $this->doCliente->id, "\u{1F44D}");
    $tela->call('reagir', $this->doCliente->id, "\u{2764}");

    expect($this->doCliente->fresh()->reacao_nossa)->toBe("\u{2764}");
});

it('nao reage a mensagem de outra conversa', function () {
    Queue::fake();

    $outroContato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Outro',
        'telefone_e164' => '+5541988880000', 'jid' => '5541988880000@s.whatsapp.net',
    ]);
    $outra = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $outroContato->id, 'status' => 'aberta',
    ]);
    $alheia = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $outra->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'de outro', 'external_id' => 'WAMID-OUTRO', 'status' => Message::STATUS_DELIVERED,
    ]);

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('reagir', $alheia->id, "\u{1F44D}");

    expect($alheia->fresh()->reacao_nossa)->toBeNull();
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------- o que sai na rede

it('manda a reacao para a Evolution com a chave completa', function () {
    (new SendReaction($this->doCliente->id, "\u{1F44D}"))
        ->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertSent(function ($r) {
        $d = $r->data();

        return str_contains($r->url(), 'sendReaction')
            && $d['key']['id'] === 'WAMID-CLIENTE'
            // fromMe false: a mensagem reagida e do CLIENTE. Sem isto o Baileys procura no
            // lado errado e a reacao nao aparece em lugar nenhum, sem erro.
            && $d['key']['fromMe'] === false
            && $d['reaction'] === "\u{1F44D}";
    });
});

it('emoji vazio e como se tira a reacao', function () {
    (new SendReaction($this->doCliente->id, ''))
        ->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendReaction') && $r->data()['reaction'] === '');
});

it('nao tenta reagir a mensagem que nunca saiu', function () {
    $so_daqui = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'nota', 'external_id' => null, 'status' => Message::STATUS_SENT,
    ]);

    (new SendReaction($so_daqui->id, "\u{1F44D}"))->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertNothingSent();
});

it('desfaz a reacao na tela quando o provedor recusa de vez', function () {
    // A tela mostrou antes de enviar, para o clique ser instantaneo. Se nao foi, tem de
    // sumir: reacao que aparece aqui e nao existe no aparelho do cliente e uma mentira
    // pequena que ninguem descobre.
    $this->doCliente->update(['reacao_nossa' => "\u{1F44D}"]);

    $this->status = 400;
    $this->resposta = ['error' => ['message' => 'nao pode', 'code' => 131047]];

    (new SendReaction($this->doCliente->id, "\u{1F44D}"))
        ->handle(app(App\Services\Canais\Enviadores::class));

    expect($this->doCliente->fresh()->reacao_nossa)->toBeNull();
});

// --------------------------------------------------- reacao que CHEGA do cliente

it('reacao do cliente nao vira mensagem nova', function () {
    // O ponto principal deste arquivo. Um balao vazio por polegar levantado, e o contador de
    // nao lidas subindo por causa de um emoji, seria pior que nao ter reacao nenhuma.
    $antes = Message::count();

    $nossa = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'de nada', 'external_id' => 'WAMID-NOSSO', 'status' => Message::STATUS_SENT,
    ]);

    (new App\Jobs\ProcessEvolutionWebhook(eventoDaEvolution($this->canal, [
        'data'  => [
            'key'     => ['id' => 'REACAO-1', 'fromMe' => false, 'remoteJid' => '5541999990000@s.whatsapp.net'],
            'message' => ['reactionMessage' => ['key' => ['id' => 'WAMID-NOSSO'], 'text' => "\u{2764}"]],
        ],
    ])))->handle();

    expect(Message::count())->toBe($antes + 1)   // so a que eu criei acima
        ->and($nossa->fresh()->reacao_cliente)->toBe("\u{2764}");
});

it('reacao removida pelo cliente limpa o emoji', function () {
    $nossa = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'de nada', 'external_id' => 'WAMID-NOSSO2', 'status' => Message::STATUS_SENT,
        'reacao_cliente' => "\u{2764}",
    ]);

    (new App\Jobs\ProcessEvolutionWebhook(eventoDaEvolution($this->canal, [
        'data'  => [
            'key'     => ['id' => 'REACAO-2', 'fromMe' => false, 'remoteJid' => '5541999990000@s.whatsapp.net'],
            'message' => ['reactionMessage' => ['key' => ['id' => 'WAMID-NOSSO2'], 'text' => '']],
        ],
    ])))->handle();

    expect($nossa->fresh()->reacao_cliente)->toBeNull();
});

it('reacao a mensagem que nunca passou por aqui nao quebra nada', function () {
    (new App\Jobs\ProcessEvolutionWebhook(eventoDaEvolution($this->canal, [
        'data'  => [
            'key'     => ['id' => 'REACAO-3', 'fromMe' => false, 'remoteJid' => '5541999990000@s.whatsapp.net'],
            'message' => ['reactionMessage' => ['key' => ['id' => 'NUNCA-VISTO'], 'text' => "\u{1F44D}"]],
        ],
    ])))->handle();

    expect(true)->toBeTrue();
});
