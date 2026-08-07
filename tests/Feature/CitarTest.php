<?php

use App\Jobs\SendTextMessage;
use App\Livewire\Inbox\ConversationWindow;
use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Responder citando uma mensagem.
 *
 * Numa conversa movimentada, "pode sim" nao diz a que. O WhatsApp resolveu isso com a citacao e
 * todo mundo aprendeu a esperar por ela; sem, a resposta do atendente fica ambigua justo quando
 * ha mais coisa em jogo.
 *
 * TRES REGRAS QUE ESTE ARQUIVO PROTEGE:
 *
 * 1. CITAR SEM external_id NAO PODE DERRUBAR O ENVIO. Mensagem que ainda nao saiu, que falhou,
 *    ou nota interna nao existem do lado do WhatsApp. Citar um id que ele nao conhece faz ele
 *    recusar a mensagem INTEIRA — a resposta sumiria por causa do enfeite. Sem o id, vai sem
 *    citacao.
 *
 * 2. SO SE CITA MENSAGEM DA PROPRIA CONVERSA. O pedido vem do navegador; sem conferir, bastaria
 *    forjar um id para citar mensagem de outro cliente — e ela sairia citada no WhatsApp de
 *    quem nao devia ver.
 *
 * 3. TROCAR DE CONVERSA CANCELA A CITACAO ARMADA. A conferencia da regra 2 nao pega este caso:
 *    ali quem mudou foi a mensagem, aqui foi a conversa debaixo dela.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'citar']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@citar.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'cit',
    ]);

    $contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $contato->id, 'status' => 'aberta', 'ultima_entrada_em' => now(),
    ]);

    // A mensagem do cliente que sera citada. Com external_id: veio do WhatsApp.
    $this->doCliente = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'Vocês entregam no sábado?', 'external_id' => 'WAMID-CLIENTE',
        'status' => Message::STATUS_DELIVERED,
    ]);

    $this->actingAs($this->pessoa);

    // Stub que le de propriedades do teste — ver a armadilha do Http::fake no Pest.php.
    $this->respostaEnvio = ['key' => ['id' => 'WAMID-NOSSO']];
    Http::fake(['*' => fn () => Http::response($this->respostaEnvio, 200)]);
});

// ------------------------------------------------------------------ o vinculo

it('guarda a que mensagem a resposta se refere', function () {
    Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('responderA', $this->doCliente->id)
        ->set('corpo', 'Entregamos sim')
        ->call('enviar');

    $enviada = Message::where('direcao', 'out')->latest('id')->first();

    expect($enviada->responde_a_id)->toBe($this->doCliente->id)
        ->and($enviada->respondeA->corpo)->toBe('Vocês entregam no sábado?');
});

it('limpa a citacao depois de enviar, para a proxima nao sair citada sem querer', function () {
    Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('responderA', $this->doCliente->id)
        ->set('corpo', 'Entregamos sim')
        ->call('enviar')
        ->assertSet('respondendoA', null);
});

it('deixa cancelar a citacao antes de enviar', function () {
    Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('responderA', $this->doCliente->id)
        ->assertSet('respondendoA', $this->doCliente->id)
        ->call('cancelarResposta')
        ->assertSet('respondendoA', null);
});

it('citar volta do modo nota, porque nota nao existe do lado do cliente', function () {
    Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('alternarNota')
        ->assertSet('nota', true)
        ->call('responderA', $this->doCliente->id)
        ->assertSet('nota', false);
});

// ------------------------------------------------------------ o que nao passa

it('nao cita mensagem de outra conversa', function () {
    $outroContato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Outro',
        'telefone_e164' => '+5541988880000', 'jid' => '5541988880000@s.whatsapp.net',
    ]);

    $outraConversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $outroContato->id, 'status' => 'aberta',
    ]);

    $deOutro = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $outraConversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'Segredo do outro cliente', 'external_id' => 'WAMID-OUTRO',
        'status' => Message::STATUS_DELIVERED,
    ]);

    Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('responderA', $deOutro->id)
        ->assertSet('respondendoA', null);
});

it('trocar de conversa cancela a citacao armada', function () {
    Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('responderA', $this->doCliente->id)
        ->assertSet('respondendoA', $this->doCliente->id)
        ->call('abrir', $this->conversa->id)
        ->assertSet('respondendoA', null);
});

it('a janela nao arma citacao de mensagem de outra conversa', function () {
    $outroContato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Outro',
        'telefone_e164' => '+5541977770000', 'jid' => '5541977770000@s.whatsapp.net',
    ]);
    $outra = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $outroContato->id, 'status' => 'aberta',
    ]);
    $alheia = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $outra->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'nao e desta conversa', 'status' => Message::STATUS_DELIVERED,
    ]);

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('responder', $alheia->id)
        ->assertNotDispatched('responder-a');
});

// -------------------------------------------------------- o que sai no provedor

it('manda o quoted para a Evolution, com id, autoria e o texto citado', function () {
    $mensagem = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'Entregamos sim', 'responde_a_id' => $this->doCliente->id,
        'status' => Message::STATUS_QUEUED,
    ]);

    (new SendTextMessage($mensagem->id))->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertSent(function ($r) {
        $q = $r->data()['quoted'] ?? null;

        return str_contains($r->url(), 'sendText')
            && $q
            && $q['key']['id'] === 'WAMID-CLIENTE'
            // fromMe false: a citada e do CLIENTE. O mesmo id existe nos dois sentidos, e
            // errar isto faz o Baileys nao achar a mensagem e desenhar faixa vazia.
            && $q['key']['fromMe'] === false
            && $q['message']['conversation'] === 'Vocês entregam no sábado?';
    });
});

it('nao manda quoted quando nao ha citacao', function () {
    $mensagem = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'Bom dia', 'status' => Message::STATUS_QUEUED,
    ]);

    (new SendTextMessage($mensagem->id))->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertSent(fn ($r) => ! array_key_exists('quoted', $r->data()));
});

it('envia sem citacao quando a mensagem citada nunca chegou ao WhatsApp', function () {
    // A regra 1, que e a que protege o cliente de nao receber nada. Nota interna e o caso
    // real: existe aqui dentro, nunca existiu la fora.
    $nota = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'lembrete interno', 'external_id' => null,
        'status' => Message::STATUS_SENT,
    ]);

    $mensagem = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'resposta', 'responde_a_id' => $nota->id,
        'status' => Message::STATUS_QUEUED,
    ]);

    (new SendTextMessage($mensagem->id))->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertSent(fn ($r) => ! array_key_exists('quoted', $r->data()));

    // E a mensagem SAIU: nao foi recusada nem ficou presa.
    expect($mensagem->fresh()->status)->toBe(Message::STATUS_SENT)
        // O vinculo continua registrado aqui dentro, ainda que o WhatsApp nao o veja.
        ->and($mensagem->fresh()->responde_a_id)->toBe($nota->id);
});

// ---------------------------------------------------------------- o resumo

it('resume midia com o nome do tipo, porque citacao vazia parece defeito', function () {
    $audio = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'audio',
        'media_path' => 'x.ogg', 'status' => Message::STATUS_DELIVERED,
    ]);

    $doc = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'document',
        'media_path' => 'x.pdf', 'media_nome' => 'contrato.pdf',
        'status' => Message::STATUS_DELIVERED,
    ]);

    expect($audio->resumo())->toBe('Áudio')
        ->and($doc->resumo())->toBe('contrato.pdf')
        ->and($this->doCliente->resumo())->toBe('Vocês entregam no sábado?');
});

it('corta resumo longo em vez de estourar a faixa', function () {
    $longa = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => str_repeat('palavra ', 60), 'status' => Message::STATUS_DELIVERED,
    ]);

    expect(mb_strlen($longa->resumo(70)))->toBeLessThanOrEqual(73);
});

// ------------------------------------------------------------ achar pelo id externo

it('acha a mensagem citada pelo id do provedor, dentro do canal', function () {
    expect(Message::acharPorExternalId($this->canal->id, 'WAMID-CLIENTE')?->id)
        ->toBe($this->doCliente->id);
});

it('devolve nada quando o id citado nunca passou por aqui', function () {
    // O cliente cita mensagem anterior a instalacao do OnChat. Fica sem citacao e a conversa
    // segue: a alternativa seria recusar a mensagem inteira.
    expect(Message::acharPorExternalId($this->canal->id, 'WAMID-QUE-NAO-EXISTE'))->toBeNull()
        ->and(Message::acharPorExternalId($this->canal->id, null))->toBeNull();
});
