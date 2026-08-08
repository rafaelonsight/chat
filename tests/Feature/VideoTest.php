<?php

use App\Filament\Pages\Reunioes;
use App\Livewire\Inbox\ConversationWindow;
use App\Livewire\Video\Sala;
use App\Models\{Channel, Contact, Conversation, Meeting, MeetingMessage, MeetingParticipant, MeetingRequest, Message, Tenant, User};
use App\Services\Video\Livekit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Reuniao por video.
 *
 * O CASO QUE MANDA E O ATENDIMENTO QUE EMPACOU NO TEXTO: alguem descreve por quinze minutos um
 * problema que a camera resolve em trinta segundos. Por isso a chamada nasce DENTRO da
 * conversa e o link sai por onde a pessoa ja estava falando.
 *
 * O LINK E A CREDENCIAL. Quem o tem entra sem login, entao ele e aleatorio, unico no banco
 * inteiro e VENCE — porque encerrar e acao de quem convidou, e quem convida esquece.
 *
 * ANFITRIAO SE DECIDE NO TOKEN, e nao na tela: botao escondido qualquer um faz aparecer. Quem
 * cumpre e o servidor de midia.
 */

beforeEach(function () {
    config()->set('services.livekit', [
        'url'    => 'wss://video.teste',
        'key'    => 'chave-de-teste',
        'secret' => 'segredo-de-teste-com-tamanho-suficiente',
    ]);

    Http::fake(['*' => Http::response([], 200)]);

    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'video']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@video.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'vid',
    ]);

    $this->contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541966660000', 'jid' => '5541966660000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'atendente_id' => $this->pessoa->id, 'ultima_msg_em' => now(),
        'ultima_entrada_em' => now(),
    ]);

    $this->actingAs($this->pessoa);
});

afterEach(fn () => TenantContext::forget());

function reuniaoDe($ctx, array $extra = []): Meeting
{
    return Meeting::abrir($extra + [
        'tenant_id'  => $ctx->conta->id,
        'criada_por' => $ctx->pessoa->id,
        'contact_id' => $ctx->contato->id,
        'titulo'     => 'Chamada',
    ]);
}

// ------------------------------------------------------------------ o token

it('o token e um JWT assinado com o segredo do servidor de midia', function () {
    $token = app(Livekit::class)->tokenDeSala('sala_x', 'convidado_1', 'Fulano');

    [$cabecalho, $carga, $assinatura] = explode('.', $token);

    $decodificar = fn ($p) => json_decode(base64_decode(strtr($p, '-_', '+/')), true);

    expect($decodificar($cabecalho)['alg'])->toBe('HS256');

    $dados = $decodificar($carga);

    expect($dados['iss'])->toBe('chave-de-teste')
        ->and($dados['sub'])->toBe('convidado_1')
        ->and($dados['name'])->toBe('Fulano')
        ->and($dados['video']['room'])->toBe('sala_x')
        ->and($dados['video']['roomJoin'])->toBeTrue();

    // a assinatura fecha com o segredo, senao o servidor de midia recusa sem dizer por que
    $esperada = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $cabecalho.'.'.$carga, 'segredo-de-teste-com-tamanho-suficiente', true)
    ), '+/', '-_'), '=');

    expect($assinatura)->toBe($esperada);
});

it('so o anfitriao ganha o direito de encerrar para todos', function () {
    // A diferenca mora no token: botao escondido qualquer um faz aparecer.
    $carga = fn (string $t) => json_decode(base64_decode(strtr(explode('.', $t)[1], '-_', '+/')), true);

    $convidado = $carga(app(Livekit::class)->tokenDeSala('s', 'i', 'n', anfitriao: false));
    $anfitriao = $carga(app(Livekit::class)->tokenDeSala('s', 'i', 'n', anfitriao: true));

    expect($convidado['video'])->not->toHaveKey('roomAdmin')
        ->and($anfitriao['video']['roomAdmin'])->toBeTrue();
});

it('o token vence, porque link vazado nao pode valer para sempre', function () {
    $carga = json_decode(base64_decode(strtr(
        explode('.', app(Livekit::class)->tokenDeSala('s', 'i', 'n'))[1], '-_', '+/'
    )), true);

    expect($carga['exp'] - time())->toBeLessThanOrEqual(Livekit::MINUTOS_DO_TOKEN * 60)
        ->and($carga['exp'])->toBeGreaterThan(time())
        // nbf no passado: relogio de servidor e de cliente nunca batem no milissegundo
        ->and($carga['nbf'])->toBeLessThan(time() + 1);
});

it('sem credenciais o video simplesmente nao existe', function () {
    // Chamada de video e recurso a mais: nao pode impedir ninguem de atender pelo chat.
    config()->set('services.livekit', ['url' => null, 'key' => null, 'secret' => null]);

    expect(app(Livekit::class)->configurado())->toBeFalse();
});

it('a sala e criada com teto e tempo de sala vazia', function () {
    // Sem isto o servidor cria a sala no primeiro participante, sem teto nenhum.
    app(Livekit::class)->criarSala('sala_y', 8);

    Http::assertSent(function ($pedido) {
        return str_contains($pedido->url(), '/twirp/livekit.RoomService/CreateRoom')
            // wss:// vira https:// — a API de administracao fala HTTP no mesmo host
            && str_starts_with($pedido->url(), 'https://video.teste')
            && $pedido['name'] === 'sala_y'
            && $pedido['maxParticipants'] === 8
            && $pedido['emptyTimeout'] === Livekit::SEGUNDOS_SALA_VAZIA
            && $pedido['departureTimeout'] === Livekit::SEGUNDOS_SALA_VAZIA;
    });
});

it('mexer numa sala existente exige o nome dela no token', function () {
    /*
     * Criar sala e permissao geral; apagar e listar quem esta dentro e permissao POR SALA.
     * Sem o nome no token o servidor responde "permissions denied" sem dizer qual permissao
     * faltou — e foi exatamente assim que a primeira versao disto quebrou em producao.
     */
    $lk = app(Livekit::class);

    $lk->criarSala('sala_w', 8);
    $lk->contarParticipantes('sala_w');

    $escopo = function ($pedido) {
        $carga = json_decode(base64_decode(strtr(
            explode('.', substr($pedido->header('Authorization')[0], 7))[1], '-_', '+/'
        )), true);

        return $carga['video'];
    };

    Http::assertSent(function ($pedido) use ($escopo) {
        if (! str_contains($pedido->url(), 'ListParticipants')) {
            return false;
        }

        return ($escopo($pedido)['room'] ?? null) === 'sala_w'
            && $escopo($pedido)['roomAdmin'] === true;
    });
});

it('sala que ninguem abriu nao e erro', function () {
    // Sala so existe no servidor de midia depois que alguem conecta.
    Http::fake(['*' => Http::response(['code' => 'not_found', 'msg' => 'room not found'], 404)]);

    app(Livekit::class)->encerrarSala('sala_z');

    expect(app(Livekit::class)->contarParticipantes('sala_z'))->toBe(0);
});

// ------------------------------------------------------------------ a sala

it('a sala nasce com nome e link aleatorios', function () {
    $a = reuniaoDe($this);
    $b = reuniaoDe($this);

    expect($a->sala)->not->toBe($b->sala)
        ->and($a->token_convidado)->not->toBe($b->token_convidado)
        ->and(strlen($a->token_convidado))->toBeGreaterThanOrEqual(32)
        // Nao e o id nem o nome da sala: o link circula em grupo de WhatsApp, e adivinhar um
        // deles nao pode dar acesso ao outro.
        ->and($a->token_convidado)->not->toBe((string) $a->id)
        ->and($a->token_convidado)->not->toBe($a->sala)
        ->and(ctype_digit($a->token_convidado))->toBeFalse();
});

it('o link vence em doze horas, mesmo sem ninguem encerrar', function () {
    // Encerrar e acao de quem convidou, e quem convida esquece.
    $r = reuniaoDe($this);

    expect($r->podeEntrar())->toBeTrue();

    $r->update(['comecou_em' => now()->subHours(Meeting::HORAS_ATE_EXPIRAR + 1)]);

    expect($r->refresh()->expirada())->toBeTrue()
        ->and($r->podeEntrar())->toBeFalse()
        // continua "aberta": expirou nao e a mesma coisa que encerrada
        ->and($r->aberta())->toBeTrue();
});

it('a tela publica abre sem login', function () {
    $r = reuniaoDe($this);

    auth()->logout();
    TenantContext::forget();

    $this->withoutExceptionHandling();
    $this->get('/sala/'.$r->token_convidado)
        ->assertSuccessful()
        ->assertSee('Entrar na chamada');
});

it('token que nao existe da pagina nao encontrada', function () {
    TenantContext::forget();

    $this->get('/sala/nao-existe')->assertNotFound();
});

it('o convidado entra, e a entrada dele fica registrada', function () {
    // Sem portaria: este teste e sobre registrar quem entrou, e nao sobre quem libera.
    $r = reuniaoDe($this, ['sala_de_espera' => false]);

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->set('nome', 'Joana')
        ->call('entrar')
        ->assertSet('entrou', true);

    $p = MeetingParticipant::withoutGlobalScope('tenant')->first();

    expect($p->nome)->toBe('Joana')
        ->and($p->user_id)->toBeNull()
        ->and($p->tenant_id)->toBe($this->conta->id);
});

it('sem nome nao entra', function () {
    $r = reuniaoDe($this, ['sala_de_espera' => false]);

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->set('nome', '')
        ->call('entrar')
        ->assertHasErrors('nome')
        ->assertSet('entrou', false);
});

it('quem e da equipe entra como anfitriao', function () {
    $r = reuniaoDe($this);

    $tela = Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado]);

    expect($tela->instance()->souDaEquipe())->toBeTrue();

    // o nome ja vem preenchido: pedir de novo a quem estamos logados e trabalho de graça
    $tela->assertSet('nome', 'Atendente');

    $tela->call('entrar')->assertSet('entrou', true);

    expect(MeetingParticipant::first()->user_id)->toBe($this->pessoa->id);
});

it('gente de outra conta nao vira anfitria', function () {
    $r = reuniaoDe($this);

    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'video-outra']);
    TenantContext::set($outra->id);
    $dela = User::create([
        'tenant_id' => $outra->id, 'name' => 'Dela',
        'email' => 'dela@video.test', 'password' => 'segredo123',
    ]);
    TenantContext::forget();

    $tela = Livewire::actingAs($dela)->test(Sala::class, ['token' => $r->token_convidado]);

    expect($tela->instance()->souDaEquipe())->toBeFalse();
});

it('reuniao encerrada nao deixa mais ninguem entrar', function () {
    $r = reuniaoDe($this);
    $r->encerrar();

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->set('nome', 'Joana')
        ->call('entrar')
        ->assertSet('entrou', false)
        ->assertSee('já foi encerrada');
});

it('link expirado avisa diferente de encerrado', function () {
    // Para quem recebeu o link, as duas coisas pedem providencias diferentes.
    $r = reuniaoDe($this);
    $r->update(['comecou_em' => now()->subHours(Meeting::HORAS_ATE_EXPIRAR + 1)]);

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->set('nome', 'Joana')
        ->call('entrar')
        ->assertSet('entrou', false)
        ->assertSee('expirou');
});

it('so a equipe encerra para todos', function () {
    $r = reuniaoDe($this);

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])->call('encerrar');

    expect($r->refresh()->aberta())->toBeTrue();

    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])->call('encerrar');

    expect($r->refresh()->aberta())->toBeFalse();
});

it('sem credenciais a sala nao deixa entrar, e avisa', function () {
    $r = reuniaoDe($this);
    config()->set('services.livekit', ['url' => null, 'key' => null, 'secret' => null]);

    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar')
        ->assertSet('entrou', false)
        ->assertSee('não está disponível');
});

// ------------------------------------------------------- a partir da conversa

it('chamar por video abre a sala e manda o link na conversa', function () {
    // O link vai por onde a pessoa ja estava falando: link em outro lugar e link de amanha.
    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class)
        ->call('abrir', $this->conversa->id)
        ->call('chamarPorVideo')
        ->assertDispatched('abrir-sala');

    $reuniao = Meeting::first();

    expect($reuniao->conversation_id)->toBe($this->conversa->id)
        ->and($reuniao->contact_id)->toBe($this->contato->id)
        ->and($reuniao->criada_por)->toBe($this->pessoa->id);

    $mensagem = Message::where('direcao', 'out')->latest('id')->first();

    expect($mensagem->corpo)->toContain($reuniao->token_convidado)
        ->and($mensagem->tipo)->toBe('text');
});

it('nao abre duas salas na mesma conversa', function () {
    // Duas salas fariam o cliente entrar numa e o atendente esperar na outra.
    $tela = Livewire::actingAs($this->pessoa)->test(ConversationWindow::class)
        ->call('abrir', $this->conversa->id);

    $tela->call('chamarPorVideo');
    $tela->call('chamarPorVideo');

    expect(Meeting::count())->toBe(1);
});

it('sala vencida da lugar a uma nova', function () {
    $tela = Livewire::actingAs($this->pessoa)->test(ConversationWindow::class)
        ->call('abrir', $this->conversa->id);

    $tela->call('chamarPorVideo');

    Meeting::first()->update(['comecou_em' => now()->subHours(Meeting::HORAS_ATE_EXPIRAR + 1)]);

    $tela->call('chamarPorVideo');

    expect(Meeting::count())->toBe(2);
});

it('sem credenciais, a conversa avisa em vez de abrir sala', function () {
    config()->set('services.livekit', ['url' => null, 'key' => null, 'secret' => null]);

    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class)
        ->call('abrir', $this->conversa->id)
        ->call('chamarPorVideo')
        ->assertHasErrors('video');

    expect(Meeting::count())->toBe(0);
});

it('fora da janela de 24h a sala abre mesmo assim, com o link na tela', function () {
    // A sala existe e o atendente ja esta indo para ela: falha ao avisar nao desmancha nada.
    $this->conversa->update(['ultima_entrada_em' => now()->subHours(30)]);
    $this->canal->update(['tipo' => 'meta_cloud']);

    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class)
        ->call('abrir', $this->conversa->id)
        ->call('chamarPorVideo')
        ->assertHasErrors('video')
        ->assertDispatched('abrir-sala');

    expect(Meeting::count())->toBe(1)
        ->and(Message::where('direcao', 'out')->count())->toBe(0);
});

it('nao mistura conta', function () {
    reuniaoDe($this);

    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'video-alheia']);
    TenantContext::set($outra->id);

    expect(Meeting::count())->toBe(0);
});

// ------------------------------------------------------------ a tela do menu

it('fica em CRM e a tela abre', function () {
    expect(Reunioes::getNavigationGroup())->toBe('CRM');

    $this->withoutExceptionHandling();
    $this->withSession(['login_web_'.sha1('Illuminate\Auth\SessionGuard') => $this->pessoa->id])
        ->get('/admin/reunioes')
        ->assertSuccessful()
        ->assertSee('Nova reunião');
});

it('o numero no menu conta so as salas abertas', function () {
    // Sala aberta e sala que pode ter alguem esperando dentro; e o unico numero que pede acao.
    expect(Reunioes::getNavigationBadge())->toBeNull();

    $r = reuniaoDe($this);

    expect(Reunioes::getNavigationBadge())->toBe('1');

    $r->encerrar();

    expect(Reunioes::getNavigationBadge())->toBeNull();
});

it('sala vencida nao acende o menu', function () {
    // O link dela ja nao abre: dizer que esta aberta seria mentira.
    reuniaoDe($this)->update(['comecou_em' => now()->subHours(Meeting::HORAS_ATE_EXPIRAR + 1)]);

    expect(Reunioes::getNavigationBadge())->toBeNull();
});

it('sem credencial o menu nao acende nem promete nada', function () {
    reuniaoDe($this);
    config()->set('services.livekit', ['url' => null, 'key' => null, 'secret' => null]);

    expect(Reunioes::getNavigationBadge())->toBeNull();

    Livewire::actingAs($this->pessoa)->test(Reunioes::class)
        ->assertViewHas('disponivel', false)
        ->assertSee('desligada');
});

it('abre sala avulsa pelo menu, sem conversa nenhuma', function () {
    Livewire::actingAs($this->pessoa)->test(Reunioes::class)
        ->set('titulo', 'Reunião de equipe')
        ->call('abrir')
        ->assertDispatched('abrir-sala');

    $r = Meeting::first();

    expect($r->titulo)->toBe('Reunião de equipe')
        ->and($r->conversation_id)->toBeNull()
        ->and($r->criada_por)->toBe($this->pessoa->id);

    // Sem cliente do outro lado, nao ha conversa para mandar link nenhum.
    expect(Message::count())->toBe(0);
});

it('sala sem assunto ainda tem nome', function () {
    Livewire::actingAs($this->pessoa)->test(Reunioes::class)->call('abrir');

    expect(Meeting::first()->titulo)->toBe('Reunião');
});

it('com contato escolhido, o link sai pela conversa dele', function () {
    // Mesmo caminho do botao dentro do atendimento, pelas mesmas regras.
    Livewire::actingAs($this->pessoa)->test(Reunioes::class)
        ->set('buscaContato', 'Cliente')
        ->assertViewHas('candidatos', fn ($c) => $c->count() === 1)
        ->call('escolherContato', $this->contato->id)
        ->call('abrir')
        ->assertDispatched('abrir-sala');

    $r = Meeting::first();

    expect($r->conversation_id)->toBe($this->conversa->id);

    expect(Message::where('direcao', 'out')->latest('id')->first()->corpo)
        ->toContain($r->token_convidado);
});

it('contato sem conversa aberta ganha sala avulsa, e nao conversa nova', function () {
    // Abrir conversa por conta propria poria na caixa de entrada um atendimento que ninguem
    // pediu.
    $this->conversa->update(['status' => Conversation::ARQUIVADA]);

    Livewire::actingAs($this->pessoa)->test(Reunioes::class)
        ->call('escolherContato', $this->contato->id)
        ->call('abrir');

    expect(Meeting::first()->conversation_id)->toBeNull()
        ->and(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(0);
});

it('reaproveita a sala que ja estava aberta na conversa', function () {
    $tela = Livewire::actingAs($this->pessoa)->test(Reunioes::class);

    $tela->call('escolherContato', $this->contato->id)->call('abrir');
    $tela->call('escolherContato', $this->contato->id)->call('abrir');

    expect(Meeting::count())->toBe(1);
});

it('a tela separa o que esta aberto do que ja passou', function () {
    $aberta = reuniaoDe($this, ['titulo' => 'Agora']);
    $velha = reuniaoDe($this, ['titulo' => 'Ontem']);
    $velha->encerrar();

    Livewire::actingAs($this->pessoa)->test(Reunioes::class)
        ->assertViewHas('abertas', fn ($a) => $a->count() === 1 && $a->first()->id === $aberta->id)
        ->assertViewHas('passadas', fn ($p) => $p->count() === 1 && $p->first()->id === $velha->id);
});

it('encerrar pela tela fecha o link', function () {
    $r = reuniaoDe($this);

    Livewire::actingAs($this->pessoa)->test(Reunioes::class)->call('encerrar', $r->id);

    expect($r->refresh()->aberta())->toBeFalse()
        ->and($r->podeEntrar())->toBeFalse();
});

it('nao encerra reuniao de outra conta nem sabendo o id', function () {
    // A defesa esta na consulta, e nao no menu: o id chega de fora.
    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'video-menu']);

    $alheia = Meeting::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'sala' => 'sala_alheia',
        'token_convidado' => 'token-alheio-com-tamanho-suficiente', 'titulo' => 'Alheia',
        'comecou_em' => now(),
    ]);

    Livewire::actingAs($this->pessoa)->test(Reunioes::class)->call('encerrar', $alheia->id);

    expect($alheia->refresh()->aberta())->toBeTrue();
});

// -------------------------------------------------------------- o bate-papo

it('o recado da sala fica gravado', function () {
    /*
     * GRAVADO, e nao so ao vivo. Num sistema de atendimento, o que se digita durante a chamada
     * e justamente o que nao pode sumir: o numero de serie que o cliente leu do aparelho, o
     * endereco que ele corrigiu. Chat que evapora ao fechar a aba faz a pessoa pedir tudo de
     * novo depois -- ou pior, nao pedir e errar a visita.
     */
    $r = reuniaoDe($this, ['sala_de_espera' => false]);

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->set('nome', 'Joana')
        ->call('entrar')
        ->call('gravarMensagem', 'O número de série é XPTO-4471');

    $recado = MeetingMessage::withoutGlobalScope('tenant')->first();

    expect($recado->corpo)->toBe('O número de série é XPTO-4471')
        ->and($recado->nome)->toBe('Joana')
        ->and($recado->user_id)->toBeNull()
        ->and($recado->meeting_id)->toBe($r->id);
});

it('quem e da equipe fica identificado no recado', function () {
    $r = reuniaoDe($this);

    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar')
        ->call('gravarMensagem', 'já estou vendo aqui');

    expect(MeetingMessage::first()->user_id)->toBe($this->pessoa->id);
});

it('quem chega depois le o que ja foi dito', function () {
    // Entrou dez minutos atrasado e se situa, em vez de parar a reuniao perguntando "o que eu
    // perdi?".
    $r = reuniaoDe($this);

    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar')
        ->call('gravarMensagem', 'primeiro recado');

    $historico = Livewire::actingAs($this->pessoa)
        ->test(Sala::class, ['token' => $r->token_convidado])
        ->instance()
        ->historico();

    expect($historico)->toHaveCount(1)
        ->and($historico[0]['corpo'])->toBe('primeiro recado')
        ->and($historico[0]['daEquipe'])->toBeTrue();
});

it('quem nao entrou na sala nao escreve nela', function () {
    // O token abre a porta; escrever exige ter passado por ela.
    $r = reuniaoDe($this);

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->call('gravarMensagem', 'oi');

    expect(MeetingMessage::withoutGlobalScope('tenant')->count())->toBe(0);
});

it('reuniao encerrada nao recebe mais recado', function () {
    $r = reuniaoDe($this);

    $tela = Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar');

    $r->encerrar();

    $tela->call('gravarMensagem', 'tarde demais');

    expect(MeetingMessage::count())->toBe(0);
});

it('recado vazio nao vira linha no banco', function () {
    $r = reuniaoDe($this);

    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar')
        ->call('gravarMensagem', '   ');

    expect(MeetingMessage::count())->toBe(0);
});

it('recado enorme e cortado, e nao recusado', function () {
    // Quem colou um texto gigante nao pode simplesmente ver o recado sumir.
    $r = reuniaoDe($this);

    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar')
        ->call('gravarMensagem', str_repeat('a', MeetingMessage::LIMITE + 500));

    expect(mb_strlen(MeetingMessage::first()->corpo))->toBe(MeetingMessage::LIMITE);
});

it('o recado morre junto com a reuniao', function () {
    $r = reuniaoDe($this);

    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar')
        ->call('gravarMensagem', 'oi');

    $r->delete();

    expect(MeetingMessage::withoutGlobalScope('tenant')->count())->toBe(0);
});

// --------------------------------------------------------- a sala de espera

it('o convidado bate na porta e fica do lado de fora', function () {
    /*
     * O link e a credencial, e link de reuniao circula em grupo de WhatsApp: sem a portaria,
     * basta um encaminhamento para alguem entrar sem que ninguem perceba. Com ela, entrar
     * deixa de ser silencioso.
     */
    $r = reuniaoDe($this);

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->set('nome', 'Joana')
        ->call('entrar')
        ->assertSet('aguardando', true)
        ->assertSet('entrou', false)
        // sem token nenhum emitido enquanto ninguem liberou
        ->assertSet('tokenDeVideo', null)
        ->assertSee('Esperando liberarem');

    $pedido = MeetingRequest::withoutGlobalScope('tenant')->first();

    expect($pedido->nome)->toBe('Joana')
        ->and($pedido->aguardando())->toBeTrue()
        // ainda nao entrou: participante so existe do lado de dentro
        ->and(MeetingParticipant::withoutGlobalScope('tenant')->count())->toBe(0);
});

it('quem e da equipe nao pede licenca', function () {
    // O atendente que abriu a sala nao vai bater na porta dela.
    $r = reuniaoDe($this);

    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar')
        ->assertSet('aguardando', false)
        ->assertSet('entrou', true);

    expect(MeetingRequest::count())->toBe(0);
});

it('liberado, o convidado entra sozinho na proxima olhada', function () {
    $r = reuniaoDe($this);

    auth()->logout();
    TenantContext::forget();

    $fora = Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->set('nome', 'Joana')
        ->call('entrar');

    // o anfitriao libera
    TenantContext::set($this->conta->id);
    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar')
        ->call('aceitar', MeetingRequest::first()->id);
    TenantContext::forget();

    $fora->call('verificarPedido')
        ->assertSet('aguardando', false)
        ->assertSet('entrou', true);

    expect(MeetingParticipant::withoutGlobalScope('tenant')->where('nome', 'Joana')->exists())->toBeTrue();
});

it('recusado, o convidado sabe que foi recusado', function () {
    // "Ainda nao responderam" e "recusaram" pedem coisas diferentes de quem esta esperando.
    $r = reuniaoDe($this);

    auth()->logout();
    TenantContext::forget();

    $fora = Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->set('nome', 'Joana')
        ->call('entrar');

    TenantContext::set($this->conta->id);
    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar')
        ->call('recusar', MeetingRequest::first()->id);
    TenantContext::forget();

    $fora->call('verificarPedido')
        ->assertSet('aguardando', false)
        ->assertSet('entrou', false)
        ->assertSee('não liberou');
});

it('pedido esquecido vence, e a fila nao enche de quem desistiu', function () {
    // Quem bateu na porta e foi almocar nao pode ser aceito uma hora depois e cair numa sala
    // onde ninguem o espera.
    $r = reuniaoDe($this);

    auth()->logout();
    TenantContext::forget();

    $fora = Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->set('nome', 'Joana')
        ->call('entrar');

    $this->travel(MeetingRequest::MINUTOS_ATE_VENCER + 1)->minutes();

    $fora->call('verificarPedido')
        ->assertSet('aguardando', false)
        ->assertSee('Ninguém respondeu a tempo');

    TenantContext::set($this->conta->id);

    expect(MeetingRequest::pendentes()->count())->toBe(0);
});

it('a fila so aparece para quem e da equipe', function () {
    // A lista tem o nome de quem espera, e nome de terceiro nao se mostra para outro terceiro.
    $r = reuniaoDe($this);

    MeetingRequest::create([
        'tenant_id' => $this->conta->id, 'meeting_id' => $r->id, 'nome' => 'Joana',
    ]);

    $daEquipe = Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar');

    expect($daEquipe->instance()->pedidos())->toHaveCount(1);

    auth()->logout();
    TenantContext::forget();

    $deFora = Livewire::test(Sala::class, ['token' => $r->token_convidado]);

    expect($deFora->instance()->pedidos())->toHaveCount(0);
});

it('quem esta de fora nao libera ninguem, nem sabendo o id', function () {
    // A defesa esta no metodo, e nao no botao escondido.
    $r = reuniaoDe($this);

    $pedido = MeetingRequest::create([
        'tenant_id' => $this->conta->id, 'meeting_id' => $r->id, 'nome' => 'Joana',
    ]);

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])->call('aceitar', $pedido->id);

    TenantContext::set($this->conta->id);

    expect($pedido->refresh()->aguardando())->toBeTrue();
});

it('desligar a portaria libera quem ja estava na fila', function () {
    // Deixar gente esperando por uma porta que acabou de ser destrancada seria esquecimento,
    // nao decisao.
    $r = reuniaoDe($this);

    MeetingRequest::create([
        'tenant_id' => $this->conta->id, 'meeting_id' => $r->id, 'nome' => 'Joana',
    ]);

    Livewire::actingAs($this->pessoa)->test(Sala::class, ['token' => $r->token_convidado])
        ->call('entrar')
        ->call('alternarSalaDeEspera');

    expect($r->refresh()->sala_de_espera)->toBeFalse()
        ->and(MeetingRequest::first()->aceito())->toBeTrue();
});

it('com a portaria desligada o convidado entra direto', function () {
    $r = reuniaoDe($this, ['sala_de_espera' => false]);

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])
        ->set('nome', 'Joana')
        ->call('entrar')
        ->assertSet('aguardando', false)
        ->assertSet('entrou', true);
});

it('quem esta de fora nao destranca a porta', function () {
    $r = reuniaoDe($this);

    auth()->logout();
    TenantContext::forget();

    Livewire::test(Sala::class, ['token' => $r->token_convidado])->call('alternarSalaDeEspera');

    TenantContext::set($this->conta->id);

    expect($r->refresh()->sala_de_espera)->toBeTrue();
});

it('a portaria nasce ligada', function () {
    // Quem esquece de ligar uma protecao descobre o problema pelo estrago; quem acha a espera
    // chata desliga no primeiro uso e nunca mais pensa nisso.
    expect(reuniaoDe($this)->sala_de_espera)->toBeTrue();
});
