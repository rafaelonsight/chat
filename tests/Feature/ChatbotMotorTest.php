<?php

use App\Jobs\ContinuarFluxo;
use App\Models\{BusinessHour, Channel, Chatbot, ChatbotAction, ChatbotEdge, ChatbotStep,
    Contact, Conversation, ConversationEvent, Message, Team, Tenant, User};
use App\Services\{ChatbotFluxo, ChatbotMotor};
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::fake(['*' => fn () => Http::response(['key' => ['id' => 'A-'.uniqid()]])]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    $this->tenant->forceFill(['fuso_horario' => 'America/Sao_Paulo'])->save();
    TenantContext::set($this->tenant->id);

    $this->channel = Channel::create(['nome' => 'Principal'])->refresh();
    $this->suporte = Team::create(['nome' => 'Suporte']);

    $this->bot = Chatbot::create([
        'nome' => 'Fluxo', 'ativo' => true,
        'mensagem_boas_vindas' => 'nao usado no grafo',
        'mensagem_nao_entendi' => 'Não entendi.',
        'max_tentativas' => 2, 'palavra_escape' => 'atendente',
    ]);

    $this->fluxo = app(ChatbotFluxo::class);
    $this->inicio = $this->fluxo->garantirInicio($this->bot);

    $this->contato = Contact::create([
        'nome' => 'Rafael', 'telefone_e164' => '+5511999998888',
        'jid' => '5511999998888@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::abertaOuNova($this->channel->id, $this->contato->id);
});

afterEach(function () {
    TenantContext::forget();
    Carbon::setTestNow();
});

/** Publica: so fluxo publicado atende. */
function publicar(): void
{
    test()->bot->forceFill(['status' => Chatbot::PUBLICADO])->save();
}

function recebe(string $texto): void
{
    static $n = 0;
    $n++;

    test()->postJson(
        "/webhooks/evolution/".test()->channel->id."/".test()->channel->webhook_secret,
        [
            'event' => 'messages.upsert',
            'data'  => [
                'key'      => ['remoteJid' => test()->contato->jid, 'fromMe' => false, 'id' => 'M'.$n.uniqid()],
                'pushName' => 'Rafael',
                'message'  => ['conversation' => $texto],
                'messageTimestamp' => now()->timestamp,
            ],
        ],
    )->assertOk();
}

/** end() recebe por referencia; passar retorno de funcao vira excecao no Laravel. */
function ultimaDita(): string
{
    $todas = ditas();

    return (string) end($todas);
}

/** @return array<int,string> corpos das automaticas, na ordem de criacao */
function ditas(): array
{
    return Message::where('conversation_id', test()->conversa->id)
        ->where('automatica', true)
        ->orderBy('id')
        ->pluck('corpo')
        ->all();
}

// ------------------------------------------------------------------ construcao

it('executa as acoes do bloco em ordem, numa corrente', function () {
    $bloco = $this->fluxo->criarPasso($this->bot, 300, 100, 'Recepção');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Olá!']);
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Sou o atendimento automático.']);
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENU, [
        'texto' => 'Como ajudo?', 'opcoes' => [['gatilho' => '1', 'rotulo' => 'Suporte']],
    ]);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    recebe('oi');

    // TRES mensagens de um unico recebimento, na ordem exata. Era isto que a regra
    // "uma saida por entrada" da v1 protegia; agora e a corrente que garante.
    $ditas = ditas();

    expect($ditas)->toHaveCount(3)
        ->and($ditas[0])->toBe('Olá!')
        ->and($ditas[1])->toBe('Sou o atendimento automático.')
        ->and($ditas[2])->toContain('1 - Suporte');
});

it('so o fluxo PUBLICADO atende; rascunho fica quieto', function () {
    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'B');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Olá!']);
    $this->fluxo->ligar($this->inicio, $bloco);

    // Sem publicar: rascunho existe para poder mexer sem afetar quem conversa agora.
    recebe('oi');

    expect(ditas())->toHaveCount(0);
});

it('a mensagem do bot nao tira a conversa de Novos', function () {
    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'B');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Olá!']);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    recebe('oi');

    $c = $this->conversa->fresh();

    expect($c->status)->toBe(Conversation::NOVA)
        ->and($c->atendente_id)->toBeNull()
        ->and($c->nao_lidas)->toBeGreaterThan(0);
});

// ----------------------------------------------------------------------- menu

function fluxoComMenu(): array
{
    $t = test();
    $recepcao = $t->fluxo->criarPasso($t->bot, 300, 100, 'Recepção');
    $t->fluxo->adicionarAcao($recepcao, ChatbotAction::MENU, [
        'texto'  => 'Escolha:',
        'opcoes' => [['gatilho' => '1', 'rotulo' => 'Suporte'], ['gatilho' => '2', 'rotulo' => 'Financeiro']],
    ]);
    $t->fluxo->ligar($t->inicio, $recepcao);

    $sup = $t->fluxo->criarPasso($t->bot, 600, 0, 'Suporte');
    $t->fluxo->adicionarAcao($sup, ChatbotAction::TRANSFERIR, [
        'team_id' => $t->suporte->id, 'aviso' => 'Encaminhando ao Suporte.',
    ]);
    $t->fluxo->ligar($recepcao, $sup, ChatbotEdge::opcao('1'));

    $fin = $t->fluxo->criarPasso($t->bot, 600, 200, 'Financeiro');
    $t->fluxo->adicionarAcao($fin, ChatbotAction::CONCLUIR, ['aviso' => 'Tchau!']);
    $t->fluxo->ligar($recepcao, $fin, ChatbotEdge::opcao('2'));

    publicar();

    return [$recepcao, $sup, $fin];
}

it('escolher uma opcao segue a aresta daquela opcao', function () {
    fluxoComMenu();

    recebe('oi');
    recebe('1');

    $c = $this->conversa->fresh();

    expect(ultimaDita())->toContain('Encaminhando ao Suporte')
        ->and($c->team_id)->toBe($this->suporte->id)
        ->and($c->status)->toBe(Conversation::NOVA)
        ->and($c->atendente_id)->toBeNull()
        ->and($c->chatbot_estado)->toBe(ChatbotMotor::CONCLUIDO);
});

it('aceita a escolha por extenso, sem acento e em maiuscula', function () {
    fluxoComMenu();

    recebe('oi');
    recebe('  FINANCEIRO ');

    expect(ultimaDita())->toContain('Tchau!');
});

it('nao entendi conta a tentativa e repete as opcoes', function () {
    fluxoComMenu();

    recebe('oi');
    recebe('abobrinha');

    expect($this->conversa->fresh()->chatbot_tentativas)->toBe(1)
        ->and(ultimaDita())->toContain('Não entendi')
        ->and(ultimaDita())->toContain('1 - Suporte');
});

it('estourando as tentativas entrega para uma pessoa', function () {
    fluxoComMenu();

    recebe('oi');
    recebe('xxx');
    recebe('yyy');

    // Prender o cliente num robo que nao entende e o pior resultado possivel.
    expect($this->conversa->fresh()->chatbot_estado)->toBe(ChatbotMotor::ESCAPOU)
        ->and($this->conversa->fresh()->status)->toBe(Conversation::NOVA);
});

it('a palavra de escape funciona a qualquer momento', function () {
    fluxoComMenu();

    recebe('oi');
    recebe('atendente');

    expect($this->conversa->fresh()->chatbot_estado)->toBe(ChatbotMotor::ESCAPOU);

    $trilha = ConversationEvent::where('conversation_id', $this->conversa->id)
        ->where('tipo', ConversationEvent::CHATBOT)->latest('id')->first();

    expect($trilha->descricao)->toContain('pediu atendente');
});

// ------------------------------------------------------------------- pergunta

it('pergunta guarda a resposta e continua no MESMO bloco', function () {
    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'Suporte');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::PERGUNTA, [
        'texto' => 'Qual o problema?', 'guardar_em' => 'problema',
    ]);
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, [
        'texto' => 'Anotei: {{problema}}',
    ]);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    recebe('oi');
    recebe('minha internet caiu');

    expect($this->conversa->fresh()->chatbot_respostas)->toBe(['problema' => 'minha internet caiu'])
        ->and(ultimaDita())->toBe('Anotei: minha internet caiu');
});

it('cita o nome do contato por marcador', function () {
    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'B');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Olá {{nome}}!']);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    recebe('oi');

    expect(ditas()[0])->toBe('Olá Rafael!');
});

// ---------------------------------------------------------------- condicional

it('o condicional escolhe o caminho pela resposta guardada', function () {
    $pergunta = $this->fluxo->criarPasso($this->bot, 0, 0, 'Pergunta');
    $this->fluxo->adicionarAcao($pergunta, ChatbotAction::PERGUNTA, [
        'texto' => 'O que houve?', 'guardar_em' => 'problema',
    ]);
    $this->fluxo->adicionarAcao($pergunta, ChatbotAction::CONDICIONAL, [
        'campo' => 'problema', 'operador' => 'contem', 'valor' => 'lento',
    ]);
    $this->fluxo->ligar($this->inicio, $pergunta);

    $sim = $this->fluxo->criarPasso($this->bot, 300, 0, 'É lentidão');
    $this->fluxo->adicionarAcao($sim, ChatbotAction::MENSAGEM, ['texto' => 'Reinicie o roteador.']);
    $this->fluxo->ligar($pergunta, $sim, ChatbotEdge::SIM);

    $nao = $this->fluxo->criarPasso($this->bot, 300, 200, 'Outro caso');
    $this->fluxo->adicionarAcao($nao, ChatbotAction::MENSAGEM, ['texto' => 'Vou chamar o técnico.']);
    $this->fluxo->ligar($pergunta, $nao, ChatbotEdge::NAO);

    publicar();

    recebe('oi');
    recebe('está muito LENTO hoje');

    expect(ultimaDita())->toBe('Reinicie o roteador.');
});

it('o condicional vai pelo nao quando a resposta nao casa', function () {
    $pergunta = $this->fluxo->criarPasso($this->bot, 0, 0, 'Pergunta');
    $this->fluxo->adicionarAcao($pergunta, ChatbotAction::PERGUNTA, ['texto' => 'O que houve?', 'guardar_em' => 'p']);
    $this->fluxo->adicionarAcao($pergunta, ChatbotAction::CONDICIONAL, ['campo' => 'p', 'operador' => 'contem', 'valor' => 'lento']);
    $this->fluxo->ligar($this->inicio, $pergunta);

    $sim = $this->fluxo->criarPasso($this->bot, 300, 0, 'Sim');
    $this->fluxo->adicionarAcao($sim, ChatbotAction::MENSAGEM, ['texto' => 'lento']);
    $this->fluxo->ligar($pergunta, $sim, ChatbotEdge::SIM);

    $nao = $this->fluxo->criarPasso($this->bot, 300, 200, 'Nao');
    $this->fluxo->adicionarAcao($nao, ChatbotAction::MENSAGEM, ['texto' => 'outro']);
    $this->fluxo->ligar($pergunta, $nao, ChatbotEdge::NAO);

    publicar();

    recebe('oi');
    recebe('sem sinal nenhum');

    expect(ultimaDita())->toBe('outro');
});

// -------------------------------------------------------------------- esperar

it('esperar interrompe o bloco e retoma depois de onde parou', function () {
    // Sem Queue::fake: fingir a fila fingiria tambem o job do webhook, e o motor
    // nem rodaria. Verifico pelo COMPORTAMENTO — parou antes da ultima mensagem e
    // guardou de onde retomar.
    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'B');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Só um instante...']);
    $espera = $this->fluxo->adicionarAcao($bloco, ChatbotAction::ESPERAR, ['segundos' => 8]);
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Pronto!']);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    recebe('oi');

    // Na fila sync o atraso nao e respeitado e a continuacao roda na hora — o que
    // torna este teste a prova do defeito que ele encontrou: o motor esvazia as
    // saidas ANTES de entregar o controle, entao a ordem sai certa mesmo assim.
    // Antes do conserto, "Pronto!" era criado primeiro.
    expect(ditas())->toBe(['Só um instante...', 'Pronto!']);

    $c = $this->conversa->fresh();
    expect($c->chatbot_step_id)->toBe($bloco->id);
});

it('a espera e agendada com atraso, sem prender worker', function () {
    Queue::fake();

    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'B');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Um instante...']);
    $espera = $this->fluxo->adicionarAcao($bloco, ChatbotAction::ESPERAR, ['segundos' => 8]);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    // Chamo o motor DIRETO: fingir a fila fingiria tambem o job do webhook, e o
    // motor nem rodaria.
    $entrada = Message::create([
        'conversation_id' => $this->conversa->id,
        'channel_id'      => $this->channel->id,
        'direcao'         => 'in',
        'tipo'            => 'text',
        'corpo'           => 'oi',
    ]);

    app(ChatbotMotor::class)->talvezAtender($this->channel, $entrada);

    Queue::assertPushed(ContinuarFluxo::class, function ($job) use ($bloco, $espera) {
        return $job->stepId === $bloco->id
            && $job->daOrdem === $espera->ordem
            && $job->delay !== null; // com atraso: nao ocupa worker esperando
    });
});

it('a continuacao nao fala se um humano assumiu durante a espera', function () {
    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'B');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::ESPERAR, ['segundos' => 1]);
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Voltei!']);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    $this->conversa->update([
        'chatbot_id' => $this->bot->id,
        'chatbot_estado' => ChatbotMotor::ATIVO,
        'chatbot_step_id' => $bloco->id,
    ]);

    $usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->conversa->assumir($usuario);

    app(ChatbotMotor::class)->retomarDepoisDaEspera($this->conversa->fresh(), $bloco->id, 0);

    // O bot sai de cena quando um humano assume, mesmo com continuacao agendada.
    expect(ditas())->toHaveCount(0);
});

// --------------------------------------------------------------------- travas

it('NUNCA atende em grupo', function () {
    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'B');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Olá!']);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    $grupo = Contact::create(['nome' => 'Bairro', 'tipo' => Contact::GRUPO, 'jid' => '123-987@g.us']);
    $conversaGrupo = Conversation::abertaOuNova($this->channel->id, $grupo->id);

    $this->postJson("/webhooks/evolution/{$this->channel->id}/{$this->channel->webhook_secret}", [
        'event' => 'messages.upsert',
        'data'  => [
            'key' => ['remoteJid' => $grupo->jid, 'fromMe' => false, 'id' => 'G1'],
            'pushName' => 'Alguém', 'message' => ['conversation' => 'bom dia'],
            'messageTimestamp' => now()->timestamp,
        ],
    ])->assertOk();

    expect(Message::where('conversation_id', $conversaGrupo->id)->where('automatica', true)->count())->toBe(0);
});

it('nao atende se um humano ja escreveu na conversa', function () {
    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'B');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Olá!']);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    Message::create([
        'conversation_id' => $this->conversa->id, 'channel_id' => $this->channel->id,
        'direcao' => 'out', 'automatica' => false, 'tipo' => 'text', 'corpo' => 'Pode falar',
    ]);

    recebe('oi');

    expect(ditas())->toHaveCount(0);
});

it('depois de concluido nao reabre a cada mensagem', function () {
    fluxoComMenu();

    recebe('oi');
    recebe('2'); // conclui

    $antes = count(ditas());
    recebe('e outra coisa');

    expect(ditas())->toHaveCount($antes);
});

it('fluxo publicado sem inicio ligado nao trava o cliente', function () {
    publicar(); // so o inicio, sem aresta

    recebe('oi');

    // Ficar quieto e deixar uma pessoa responder e melhor que travar.
    expect(ditas())->toHaveCount(0);
});

it('ciclo no fluxo nao gira para sempre', function () {
    // A -> B -> A sem nenhuma acao que espere: sem teto, o job giraria eternamente.
    $a = $this->fluxo->criarPasso($this->bot, 0, 0, 'A');
    $this->fluxo->adicionarAcao($a, ChatbotAction::MENSAGEM, ['texto' => 'a']);
    $b = $this->fluxo->criarPasso($this->bot, 200, 0, 'B');
    $this->fluxo->adicionarAcao($b, ChatbotAction::MENSAGEM, ['texto' => 'b']);

    $this->fluxo->ligar($this->inicio, $a);
    $this->fluxo->ligar($a, $b);
    $this->fluxo->ligar($b, $a);
    publicar();

    recebe('oi');

    expect(count(ditas()))->toBeLessThanOrEqual(26);

    $trilha = ConversationEvent::where('conversation_id', $this->conversa->id)
        ->where('descricao', 'like', '%ciclo%')->first();

    expect($trilha)->not->toBeNull();
});

it('fora do horario com aviso proprio manda so o aviso', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 22:00:00', 'America/Sao_Paulo'));

    $this->bot->update(['mensagem_fora_horario' => 'Estamos fechados, voltamos às 8h.']);

    BusinessHour::create([
        'dia_semana' => 1, 'ativo' => true,
        'intervalos' => [['inicio' => '08:00', 'fim' => '18:00']],
    ]);

    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'B');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENU, [
        'texto' => 'Escolha:', 'opcoes' => [['gatilho' => '1', 'rotulo' => 'Suporte']],
    ]);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    recebe('oi');

    $ditas = ditas();

    expect($ditas)->toHaveCount(1)
        ->and($ditas[0])->toContain('voltamos às 8h')
        ->and($ditas[0])->not->toContain('1 - Suporte');
});

it('com fluxo publicado a resposta automatica de fora do horario fica calada', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 22:00:00', 'America/Sao_Paulo'));

    $this->tenant->forceFill([
        'resposta_automatica_ativa' => true,
        'resposta_automatica_texto' => 'ESTAMOS FECHADOS',
    ])->save();

    BusinessHour::create([
        'dia_semana' => 1, 'ativo' => true,
        'intervalos' => [['inicio' => '08:00', 'fim' => '18:00']],
    ]);

    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'B');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Olá!']);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    recebe('oi');

    // Duas mensagens de robo seguidas e a pior experiencia possivel.
    expect(ditas())->toHaveCount(1)
        ->and(ditas()[0])->toBe('Olá!');
});

it('sem fluxo publicado a resposta automatica volta a valer', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 22:00:00', 'America/Sao_Paulo'));

    $this->tenant->forceFill([
        'resposta_automatica_ativa' => true,
        'resposta_automatica_texto' => 'ESTAMOS FECHADOS',
    ])->save();

    BusinessHour::create([
        'dia_semana' => 1, 'ativo' => true,
        'intervalos' => [['inicio' => '08:00', 'fim' => '18:00']],
    ]);

    recebe('oi');

    expect(ditas()[0])->toContain('ESTAMOS FECHADOS');
});
