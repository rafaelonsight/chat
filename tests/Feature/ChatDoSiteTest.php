<?php

use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Jobs\EncerrarChatDoSitePorInatividade;
use App\Services\Canais\Enviadores;
use App\Services\Canais\SiteEnviador;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
 * O chat que mora no site do cliente.
 *
 * A DECISAO QUE SUSTENTA TUDO: o visitante nao e um tipo novo de coisa. E um CONTATO, numa
 * CONVERSA, num CANAL — os mesmos do WhatsApp. Caixa de entrada separada so para o site seriam
 * dois lugares para olhar, duas filas e dois relatorios, e o atendente esquecendo de um deles
 * na primeira semana movimentada.
 *
 * O QUE MUDA E QUE NAO HA TELEFONE. A identidade e uma chave aleatoria guardada no navegador do
 * visitante, e ela vira o jid do contato: quem volta amanha cai na mesma conversa, quem troca
 * de aparelho vira outra pessoa. E honesto — nao temos como saber que e a mesma.
 *
 * E A CHAVE DO CANAL E PUBLICA de proposito: ela viaja no HTML de quem instalou o widget. O que
 * segura abuso e o teto por IP, e nao o segredo dela.
 */

beforeEach(function () {
    Queue::fake();
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'site']);
    TenantContext::set($this->conta->id);

    $this->canal = Channel::create([
        'tenant_id'     => $this->conta->id,
        'tipo'          => Channel::SITE,
        'nome'          => 'Site',
        'status'        => 'open',
        'site_key'      => 'sk_chavedetestecomtamanhobom',
        'site_saudacao' => 'Manda sua duvida.',
    ]);

    $this->url = '/chat-do-site/'.$this->canal->site_key;
    RateLimiter::clear('site:'.$this->canal->site_key.':127.0.0.1');

    TenantContext::forget();
});

afterEach(fn () => TenantContext::forget());

it('nome e telefone sao obrigatorios para gerar o lead', function () {
    $semDados = $this->postJson($this->url.'/abrir', [])->assertOk();
    expect($semDados->json('cadastrado'))->toBeFalse();

    TenantContext::set($this->conta->id);
    expect(Contact::count())->toBe(0)->and(Conversation::count())->toBe(0);
    TenantContext::forget();

    $this->postJson($this->url.'/abrir', ['token' => $semDados->json('token'), 'nome' => 'Joana'])
        ->assertUnprocessable();

    $r = $this->postJson($this->url.'/abrir', [
        'token' => $semDados->json('token'), 'nome' => 'Joana', 'telefone' => '(41) 99999-0000',
    ])->assertOk()->assertJsonPath('cadastrado', true);

    $token = $r->json('token');

    expect(strlen($token))->toBeGreaterThanOrEqual(24)
        ->and($r->json('saudacao'))->toBe('Manda sua duvida.');

    TenantContext::set($this->conta->id);

    // O cadastro do widget ja entra como lead identificavel na tabela de contatos.
    $contato = Contact::first();

    expect($contato->jid)->toBe('site_'.$token)
        ->and($contato->telefone_e164)->toBe('+5541999990000')
        ->and($contato->nome)->toBe('Joana');
});

it('quem volta com a mesma chave cai na mesma conversa', function () {
    // E o que impede o visitante virar um contato novo a cada visita.
    $token = $this->postJson($this->url.'/abrir', ['nome' => 'Joana', 'telefone' => '(41) 99999-0000'])->json('token');

    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'primeira'])->assertOk();
    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'segunda'])->assertOk();

    TenantContext::set($this->conta->id);

    expect(Contact::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(2);
});

it('token inventado nao vira jid, ganha um novo', function () {
    // O token monta um jid: aceitar texto de fora ali seria plantar identidade alheia.
    $token = $this->postJson($this->url.'/abrir', ['token' => 'site_../../outro@s.whatsapp.net', 'nome' => 'Joana', 'telefone' => '(41) 99999-0000'])
        ->json('token');

    expect($token)->toMatch('/^[A-Za-z0-9]{24,48}$/');

    TenantContext::set($this->conta->id);

    expect(Contact::first()->jid)->toBe('site_'.$token);
});

it('a mensagem do visitante conta como palavra de cliente', function () {
    $token = $this->postJson($this->url.'/abrir', ['nome' => 'Joana', 'telefone' => '(41) 99999-0000'])->json('token');

    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'Voces atendem em Curitiba?'])
        ->assertOk()
        ->assertJsonPath('mensagens.0.de', 'visitante');

    TenantContext::set($this->conta->id);

    $conversa = Conversation::first();

    expect($conversa->nao_lidas)->toBe(1)
        ->and($conversa->ultima_entrada_em)->not->toBeNull()
        ->and(Message::first()->direcao)->toBe('in');
});

it('mensagem vazia nao vira linha no banco', function () {
    $token = $this->postJson($this->url.'/abrir', ['nome' => 'Joana', 'telefone' => '(41) 99999-0000'])->json('token');

    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => '   '])->assertStatus(422);

    TenantContext::set($this->conta->id);

    expect(Message::count())->toBe(0);
});

it('chave que nao existe nao abre nada', function () {
    $this->postJson('/chat-do-site/sk_naoexisteessachaveaqui/abrir', [])->assertNotFound();
});

it('a chave posta num canal de WhatsApp nao serve', function () {
    // So canal de site atende aqui: canal de WhatsApp nao pode virar porta anonima.
    TenantContext::set($this->conta->id);

    Channel::create([
        'tenant_id' => $this->conta->id, 'tipo' => Channel::EVOLUTION, 'nome' => 'Zap',
        'instance_name' => 'zap', 'status' => 'open', 'site_key' => 'sk_chavenocanaldezapzap',
    ]);

    TenantContext::forget();

    $this->postJson('/chat-do-site/sk_chavenocanaldezapzap/abrir', [])->assertNotFound();
});

it('a resposta do atendimento chega ao visitante', function () {
    $token = $this->postJson($this->url.'/abrir', ['nome' => 'Joana', 'telefone' => '(41) 99999-0000'])->json('token');
    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'oi'])->assertOk();

    TenantContext::set($this->conta->id);

    Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => Conversation::first()->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'Atendemos sim!', 'status' => Message::STATUS_SENT,
    ]);

    TenantContext::forget();

    $r = $this->getJson($this->url.'/mensagens?token='.$token.'&desde=0')->assertOk();

    expect($r->json('mensagens.1.de'))->toBe('atendimento')
        ->and($r->json('mensagens.1.corpo'))->toBe('Atendemos sim!');
});

it('o desde evita reentregar o que ja esta na tela', function () {
    $token = $this->postJson($this->url.'/abrir', ['nome' => 'Joana', 'telefone' => '(41) 99999-0000'])->json('token');
    $ultimo = $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'oi'])
        ->json('mensagens.0.id');

    expect($this->getJson($this->url.'/mensagens?token='.$token.'&desde='.$ultimo)->json('mensagens'))
        ->toBe([]);
});

it('mensagem apagada nao volta para quem viu sumir', function () {
    $token = $this->postJson($this->url.'/abrir', ['nome' => 'Joana', 'telefone' => '(41) 99999-0000'])->json('token');
    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'oi'])->assertOk();

    TenantContext::set($this->conta->id);
    Message::first()->update(['apagada_em' => now()]);
    TenantContext::forget();

    expect($this->getJson($this->url.'/mensagens?token='.$token.'&desde=0')->json('mensagens'))->toBe([]);
});

it('nao mistura conta', function () {
    $token = $this->postJson($this->url.'/abrir', ['nome' => 'Joana', 'telefone' => '(41) 99999-0000'])->json('token');
    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'segredo'])->assertOk();

    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'site-outra']);
    TenantContext::set($outra->id);

    $canalDela = Channel::create([
        'tenant_id' => $outra->id, 'tipo' => Channel::SITE, 'nome' => 'Site dela',
        'status' => 'open', 'site_key' => 'sk_chavedaoutraempresaaqui',
    ]);

    TenantContext::forget();

    // Mesmo token, chave de outra empresa: nao pode achar nada.
    expect($this->getJson('/chat-do-site/'.$canalDela->site_key.'/mensagens?token='.$token.'&desde=0')
        ->json('mensagens'))->toBe([]);
});

it('o job de envio nao precisou saber que existe canal de site', function () {
    /*
     * Era este o objetivo de ter tirado a Evolution de dentro do envio: quando entrou um canal
     * que nem provedor tem, coube sem tocar no job.
     */
    TenantContext::set($this->conta->id);

    expect(app(Enviadores::class)->para($this->canal))->toBeInstanceOf(SiteEnviador::class);

    expect(app(Enviadores::class)->para($this->canal)->texto($this->canal, 'x', 'oi')['external_id'])
        ->toStartWith('site_');
});

it('o canal do site nao tem janela de 24 horas', function () {
    TenantContext::set($this->conta->id);

    expect($this->canal->exigeJanela())->toBeFalse()
        ->and($this->canal->ehSite())->toBeTrue();
});

it('arquivo avisa que ainda nao vai, em vez de sumir', function () {
    // O arquivo vive numa rota que exige login, e o visitante nao tem conta. Estourar faz a
    // mensagem aparecer FALHADA para o atendente, com o motivo escrito.
    TenantContext::set($this->conta->id);

    expect(fn () => app(SiteEnviador::class)->midia($this->canal, 'x', []))
        ->toThrow(RuntimeException::class);
});

it('o trecho para colar carrega a chave do canal', function () {
    TenantContext::set($this->conta->id);

    expect($this->canal->trechoDoSite())
        ->toContain('/widget.js')
        ->toContain($this->canal->site_key);
});

it('o widget e servido como javascript', function () {
    $this->get('/widget.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
});

it('encerra uma vez depois de cinco minutos sem nova mensagem do visitante', function () {
    $token = $this->postJson($this->url.'/abrir', ['nome' => 'Joana', 'telefone' => '(41) 99999-0000'])->json('token');
    $mensagemId = $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'Olá'])->assertOk()->json('mensagens.0.id');

    TenantContext::set($this->conta->id);
    $conversa = Conversation::first();
    (new EncerrarChatDoSitePorInatividade($conversa->id, $mensagemId))->handle();

    expect($conversa->fresh()->status)->toBe(Conversation::ARQUIVADA)
        ->and(Message::where('direcao', 'out')->count())->toBe(1)
        ->and(Message::where('direcao', 'out')->first()->corpo)->toContain('inatividade');
});

it('temporizador antigo nao encerra se o visitante falou novamente', function () {
    $token = $this->postJson($this->url.'/abrir', ['nome' => 'Joana', 'telefone' => '(41) 99999-0000'])->json('token');
    $primeira = $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'Primeira'])->json('mensagens.0.id');
    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'Ainda estou aqui']);

    TenantContext::set($this->conta->id);
    $conversa = Conversation::first();
    (new EncerrarChatDoSitePorInatividade($conversa->id, $primeira))->handle();

    expect($conversa->fresh()->status)->not->toBe(Conversation::ARQUIVADA)
        ->and(Message::where('direcao', 'out')->count())->toBe(0);
});

it('finalizar no painel encerra o widget e exige nova conversa explicita', function () {
    $token = $this->postJson($this->url.'/abrir', ['nome' => 'Joana', 'telefone' => '(41) 99999-0000'])->json('token');
    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'Preciso de ajuda'])->assertOk();

    TenantContext::set($this->conta->id);
    $conversa = Conversation::first();
    $atendente = User::create([
        'tenant_id' => $this->conta->id,
        'name' => 'Rafael',
        'email' => 'rafael@site-close.test',
        'password' => 'segredo123',
    ]);

    Livewire::actingAs($atendente)
        ->test(ConversationWindow::class, ['conversationId' => $conversa->id])
        ->call('encerrar');

    expect($conversa->fresh()->status)->toBe(Conversation::ARQUIVADA);
    TenantContext::forget();

    $this->getJson($this->url.'/mensagens?token='.$token.'&desde=0')
        ->assertOk()
        ->assertJsonPath('encerrada', true)
        ->assertJsonPath('mensagens.1.de', 'atendimento');

    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'Ainda estou aqui'])
        ->assertStatus(409)
        ->assertJsonPath('encerrada', true);

    TenantContext::set($this->conta->id);
    expect(Conversation::count())->toBe(1)
        ->and(Message::where('direcao', 'in')->count())->toBe(1);
    TenantContext::forget();

    $this->postJson($this->url.'/abrir', ['token' => $token, 'nova' => true])
        ->assertOk()
        ->assertJsonPath('encerrada', false)
        ->assertJsonCount(0, 'mensagens');

    TenantContext::set($this->conta->id);
    expect(Conversation::count())->toBe(2)
        ->and(Conversation::latest('id')->first()->status)->not->toBe(Conversation::ARQUIVADA);
});
