<?php

use App\Models\{Channel, Contact, Conversation, Message, Tenant};
use App\Services\Canais\Enviadores;
use App\Services\Canais\SiteEnviador;
use App\Support\TenantContext;

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

    TenantContext::forget();
});

afterEach(fn () => TenantContext::forget());

it('o visitante abre a conversa e ganha uma identidade', function () {
    $r = $this->postJson($this->url.'/abrir', ['nome' => 'Joana'])->assertOk();

    $token = $r->json('token');

    expect(strlen($token))->toBeGreaterThanOrEqual(24)
        ->and($r->json('saudacao'))->toBe('Manda sua duvida.');

    TenantContext::set($this->conta->id);

    // Contato de verdade, na tabela de sempre — so que sem telefone.
    $contato = Contact::first();

    expect($contato->jid)->toBe('site_'.$token)
        ->and($contato->telefone_e164)->toBeNull()
        ->and($contato->nome)->toBe('Joana');
});

it('quem volta com a mesma chave cai na mesma conversa', function () {
    // E o que impede o visitante virar um contato novo a cada visita.
    $token = $this->postJson($this->url.'/abrir', [])->json('token');

    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'primeira'])->assertOk();
    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'segunda'])->assertOk();

    TenantContext::set($this->conta->id);

    expect(Contact::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(2);
});

it('token inventado nao vira jid, ganha um novo', function () {
    // O token monta um jid: aceitar texto de fora ali seria plantar identidade alheia.
    $token = $this->postJson($this->url.'/abrir', ['token' => 'site_../../outro@s.whatsapp.net'])
        ->json('token');

    expect($token)->toMatch('/^[A-Za-z0-9]{24,48}$/');

    TenantContext::set($this->conta->id);

    expect(Contact::first()->jid)->toBe('site_'.$token);
});

it('a mensagem do visitante conta como palavra de cliente', function () {
    $token = $this->postJson($this->url.'/abrir', [])->json('token');

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
    $token = $this->postJson($this->url.'/abrir', [])->json('token');

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
    $token = $this->postJson($this->url.'/abrir', [])->json('token');
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
    $token = $this->postJson($this->url.'/abrir', [])->json('token');
    $ultimo = $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'oi'])
        ->json('mensagens.0.id');

    expect($this->getJson($this->url.'/mensagens?token='.$token.'&desde='.$ultimo)->json('mensagens'))
        ->toBe([]);
});

it('mensagem apagada nao volta para quem viu sumir', function () {
    $token = $this->postJson($this->url.'/abrir', [])->json('token');
    $this->postJson($this->url.'/mandar', ['token' => $token, 'corpo' => 'oi'])->assertOk();

    TenantContext::set($this->conta->id);
    Message::first()->update(['apagada_em' => now()]);
    TenantContext::forget();

    expect($this->getJson($this->url.'/mensagens?token='.$token.'&desde=0')->json('mensagens'))->toBe([]);
});

it('nao mistura conta', function () {
    $token = $this->postJson($this->url.'/abrir', [])->json('token');
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
