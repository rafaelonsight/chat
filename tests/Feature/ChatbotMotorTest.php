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

// ========================= A RESPOSTA DO CLIENTE VIRA CAMPO DO CADASTRO =====
//
// O ponto da funcionalidade: perguntar o CPF e nao guardar em lugar nenhum obriga
// a perguntar de novo no proximo atendimento. E guardar ERRADO e pior que nao
// guardar — cadastro torto faz o provedor cobrar a pessoa errada.

use App\Models\{ContactField, ContactFieldValue};
use App\Services\{CampoDoContato, ConsultaCep};

/** Monta um bloco com uma pergunta que grava no campo indicado. */
function perguntaQueGrava(string $chaveDoCampo, string $texto = 'Qual o seu CPF?'): ChatbotStep
{
    $bloco = test()->fluxo->criarPasso(test()->bot, 0, 0, 'Cadastro');
    test()->fluxo->adicionarAcao($bloco, ChatbotAction::PERGUNTA, [
        'texto' => $texto, 'campo_contato' => $chaveDoCampo,
    ]);
    test()->fluxo->ligar(test()->inicio, $bloco);
    publicar();

    return $bloco;
}

it('grava a resposta numa coluna do contato', function () {
    perguntaQueGrava('contato.email', 'Qual o seu e-mail?');

    recebe('oi');
    recebe('Rafael@Exemplo.COM.BR');

    // Minusculo: e-mail nao diferencia caixa, e guardar as duas formas seria duplicata.
    expect($this->contato->fresh()->email)->toBe('rafael@exemplo.com.br');
});

it('grava a resposta num campo personalizado, so os digitos do CPF', function () {
    $cpf = ContactField::create([
        'nome' => 'CPF', 'tipo' => ContactField::CPF_CNPJ, 'ordem' => 1,
    ]);

    perguntaQueGrava('personalizado.'.$cpf->id);

    recebe('oi');
    recebe('044.018.549-18');

    $valor = ContactFieldValue::where('contact_id', $this->contato->id)
        ->where('contact_field_id', $cpf->id)->value('valor');

    // Guardado normalizado: mascara e coisa de exibicao, nao de banco.
    expect($valor)->toBe('04401854918');
});

it('CPF invalido NAO e gravado, e o bot diz o que esta errado', function () {
    // O digito verificador e o que separa "cliente errou" de "cliente inventou".
    $cpf = ContactField::create([
        'nome' => 'CPF', 'tipo' => ContactField::CPF_CNPJ, 'ordem' => 1,
    ]);

    perguntaQueGrava('personalizado.'.$cpf->id);

    recebe('oi');
    recebe('111.111.111-11');

    expect(ContactFieldValue::where('contact_id', $this->contato->id)->count())->toBe(0)
        // Mensagem especifica, nao o "Não entendi." genérico: a generica nao ensina
        // a pessoa a consertar.
        ->and(ultimaDita())->toContain('não parece válido')
        // Continua esperando a MESMA pergunta.
        ->and($this->conversa->fresh()->chatbot_aguardando)->toBe(ChatbotMotor::AGUARDA_PERGUNTA);
});

it('depois de errar o CPF ate o limite, o cliente vai para uma pessoa', function () {
    // max_tentativas do cenario e 2. Prender alguem repetindo a mesma pergunta e o
    // pior resultado possivel.
    $cpf = ContactField::create([
        'nome' => 'CPF', 'tipo' => ContactField::CPF_CNPJ, 'ordem' => 1,
    ]);

    perguntaQueGrava('personalizado.'.$cpf->id);

    recebe('oi');
    recebe('111.111.111-11');
    recebe('222.222.222-22');

    expect($this->conversa->fresh()->chatbot_estado)->toBe(ChatbotMotor::ESCAPOU);
});

it('o marcador sai do proprio campo, sem precisar de apelido', function () {
    // Quem marcou CPF ja pode escrever {{cpf}} na mensagem seguinte.
    $cpf = ContactField::create([
        'nome' => 'CPF', 'tipo' => ContactField::CPF_CNPJ, 'ordem' => 1,
    ]);

    $bloco = perguntaQueGrava('personalizado.'.$cpf->id);
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, [
        'texto' => 'Confirmando o CPF {{cpf}}.',
    ]);

    recebe('oi');
    recebe('04401854918');

    expect(ultimaDita())->toBe('Confirmando o CPF 04401854918.');
});

it('o apelido explicito ganha do automatico', function () {
    $cpf = ContactField::create([
        'nome' => 'CPF', 'tipo' => ContactField::CPF_CNPJ, 'ordem' => 1,
    ]);

    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'Cadastro');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::PERGUNTA, [
        'texto' => 'CPF?', 'campo_contato' => 'personalizado.'.$cpf->id, 'guardar_em' => 'documento',
    ]);
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Anotei {{documento}}.']);
    $this->fluxo->ligar($this->inicio, $bloco);
    publicar();

    recebe('oi');
    recebe('04401854918');

    expect(ultimaDita())->toBe('Anotei 04401854918.');
});

it('data em formato brasileiro nao vira mes trocado', function () {
    // 03/12 e 3 de dezembro. O parse solto do PHP leria 12 de marco: nove meses de
    // diferenca sem erro nenhum aparecendo na tela.
    $nasc = ContactField::create([
        'nome' => 'Nascimento', 'tipo' => ContactField::DATA, 'ordem' => 1,
    ]);

    perguntaQueGrava('personalizado.'.$nasc->id, 'Data de nascimento?');

    recebe('oi');
    recebe('03/12/1991');

    $valor = ContactFieldValue::where('contact_field_id', $nasc->id)->value('valor');

    expect($valor)->toBe('1991-12-03');
});

it('data impossivel e recusada em vez de consertada em silencio', function () {
    $nasc = ContactField::create([
        'nome' => 'Nascimento', 'tipo' => ContactField::DATA, 'ordem' => 1,
    ]);

    perguntaQueGrava('personalizado.'.$nasc->id, 'Data de nascimento?');

    recebe('oi');
    recebe('32/13/2026');

    expect(ContactFieldValue::where('contact_field_id', $nasc->id)->count())->toBe(0)
        ->and(ultimaDita())->toContain('31/12/2026');
});

it('opcao de lista casa sem acento e sem caixa, e a invalida lista as validas', function () {
    $plano = ContactField::create([
        'nome' => 'Plano', 'tipo' => ContactField::LISTA, 'ordem' => 1,
        'opcoes' => ['Básico', 'Intermediário', 'Premium'],
    ]);

    perguntaQueGrava('personalizado.'.$plano->id, 'Qual plano?');

    recebe('oi');
    recebe('basico');

    // Guarda a opcao OFICIAL, com acento: senao o relatorio teria "basico" e
    // "Básico" como dois planos diferentes.
    expect(ContactFieldValue::where('contact_field_id', $plano->id)->value('valor'))->toBe('Básico');
});

it('opcao de lista que nao existe faz o bot listar as validas', function () {
    // Cenario proprio: depois de uma resposta ACEITA o fluxo segue e a conversa
    // deixa de aguardar a pergunta — a mensagem seguinte nao cairia mais aqui.
    $plano = ContactField::create([
        'nome' => 'Plano', 'tipo' => ContactField::LISTA, 'ordem' => 1,
        'opcoes' => ['Básico', 'Intermediário', 'Premium'],
    ]);

    perguntaQueGrava('personalizado.'.$plano->id, 'Qual plano?');

    recebe('oi');
    recebe('plano de ouro');

    // Listar as validas: o cliente nao adivinha o que o provedor cadastrou.
    expect(ContactFieldValue::where('contact_field_id', $plano->id)->count())->toBe(0)
        ->and(ultimaDita())->toContain('Intermediário');
});

it('CEP guarda os digitos e completa o endereco que estava vazio', function () {
    // Troca o SERVICO, nao o HTTP. Http::fake chamado de novo nao substitui o
    // stub '*' que o beforeEach registrou — os stubs se somam e o primeiro
    // atende, entao o motor receberia a resposta da Evolution no lugar da ViaCEP.
    // Alem disso o que esta sob teste aqui e a regra "completa so o que esta
    // vazio", nao o formato do JSON dos Correios.
    $this->app->instance(ConsultaCep::class, new class('http://teste', 1, 1) extends ConsultaCep
    {
        public function consultar(?string $cep): array
        {
            return ['ok' => true, 'erro' => null, 'dados' => [
                'cep'        => '59000000',
                'logradouro' => 'Rua das Flores',
                'bairro'     => 'Centro',
                'cidade'     => 'Natal',
                'uf'         => 'RN',
            ]];
        }
    });

    // Bairro digitado por uma pessoa: nao pode ser sobrescrito pelos Correios.
    $this->contato->update(['bairro' => 'Bairro que o cliente corrigiu']);

    perguntaQueGrava('contato.cep', 'Qual o seu CEP?');

    recebe('oi');
    recebe('59000-000');

    $c = $this->contato->fresh();

    expect($c->cep)->toBe('59000000')
        ->and($c->logradouro)->toBe('Rua das Flores')
        ->and($c->cidade)->toBe('Natal')
        ->and($c->uf)->toBe('RN')
        // O que a pessoa digitou fica: ela pode ter posto a referencia que o
        // entregador usa.
        ->and($c->bairro)->toBe('Bairro que o cliente corrigiu');
});

it('CEP com menos de 8 digitos e recusado', function () {
    perguntaQueGrava('contato.cep', 'Qual o seu CEP?');

    recebe('oi');
    recebe('5900');

    expect($this->contato->fresh()->cep)->toBeNull()
        ->and(ultimaDita())->toContain('8 dígitos');
});

it('campo apagado do cadastro nao trava o atendimento', function () {
    // O fluxo foi montado quando o campo existia. Travar aqui seria punir o cliente
    // por configuracao nossa — ele nao tem como consertar.
    $campo = ContactField::create([
        'nome' => 'Some', 'tipo' => ContactField::TEXTO_CURTO, 'ordem' => 1,
    ]);
    $id = $campo->id;

    $bloco = perguntaQueGrava('personalizado.'.$id, 'Qualquer coisa?');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Obrigado!']);

    $campo->delete();

    recebe('oi');
    recebe('resposta qualquer');

    expect(ultimaDita())->toBe('Obrigado!')
        ->and($this->conversa->fresh()->chatbot_estado)->not->toBe(ChatbotMotor::ESCAPOU);
});

it('o telefone NAO esta na lista de campos: e por ele que a conversa e achada', function () {
    // Deixar o cliente reescrever o proprio numero pelo chatbot apontaria a conversa
    // para outra pessoa — ou para ninguem.
    expect(CampoDoContato::PADRAO)->not->toHaveKey('telefone_e164')
        ->and(CampoDoContato::existe('contato.telefone_e164'))->toBeFalse();
});

it('campo personalizado criado fica disponivel para o chatbot na hora', function () {
    // "Disponivel para todos": o catalogo sai do banco a cada chamada, sem cache.
    expect(CampoDoContato::todas())->not->toHaveKey('personalizado.999');

    $novo = ContactField::create([
        'nome' => 'Código do cliente', 'tipo' => ContactField::TEXTO_CURTO, 'ordem' => 9,
    ]);

    $catalogo = CampoDoContato::agrupadas();

    expect($catalogo['Campos personalizados'])->toHaveKey('personalizado.'.$novo->id)
        ->and(CampoDoContato::rotulo('personalizado.'.$novo->id))->toBe('Código do cliente')
        // O marcador sai do nome, pronto para {{codigo_do_cliente}}.
        ->and(CampoDoContato::marcador('personalizado.'.$novo->id))->toBe('codigo_do_cliente');
});

it('publicar barra pergunta cujo campo foi apagado', function () {
    $campo = ContactField::create([
        'nome' => 'Vai sumir', 'tipo' => ContactField::TEXTO_CURTO, 'ordem' => 1,
    ]);

    $bloco = $this->fluxo->criarPasso($this->bot, 0, 0, 'Cadastro');
    $this->fluxo->adicionarAcao($bloco, ChatbotAction::PERGUNTA, [
        'texto' => 'Algo?', 'campo_contato' => 'personalizado.'.$campo->id,
    ]);
    $this->fluxo->ligar($this->inicio, $bloco);

    // Com o campo vivo, escolher o campo JA basta: nao precisa de apelido.
    expect($this->fluxo->validar($this->bot))->toBe([]);

    $campo->delete();

    // Sem isso, em producao a resposta do cliente seria descartada em silencio.
    expect(implode(' ', $this->fluxo->validar($this->bot)))->toContain('não existe mais');
});

// ============== A ESCOLHA DO MENU TAMBEM PREENCHE O CAMPO DO CADASTRO =======
//
// "1) Plano 300MB" e uma resposta do cliente, nao so um caminho no fluxo. Sem
// isso, saber qual plano ele disse exigiria reler a conversa.

/** Menu com duas opcoes que grava a escolha no campo indicado. */
function menuQueGrava(string $chaveDoCampo, array $rotulos = ['Básico', 'Premium']): array
{
    $passo = test()->fluxo->criarPasso(test()->bot, 0, 0, 'Qual plano');
    test()->fluxo->adicionarAcao($passo, ChatbotAction::MENU, [
        'texto'         => 'Qual o seu plano?',
        'campo_contato' => $chaveDoCampo,
        'opcoes'        => [
            ['gatilho' => '1', 'rotulo' => $rotulos[0]],
            ['gatilho' => '2', 'rotulo' => $rotulos[1]],
        ],
    ]);
    test()->fluxo->ligar(test()->inicio, $passo);

    $destinos = [];

    foreach (['1', '2'] as $i => $gatilho) {
        $destino = test()->fluxo->criarPasso(test()->bot, 300, $i * 200, 'Depois da '.$gatilho);
        test()->fluxo->adicionarAcao($destino, ChatbotAction::MENSAGEM, ['texto' => 'Anotado.']);
        test()->fluxo->ligar($passo, $destino, ChatbotEdge::opcao($gatilho));
        $destinos[$gatilho] = $destino;
    }

    publicar();

    return [$passo, $destinos];
}

it('a escolha do menu vira valor do campo personalizado', function () {
    $plano = ContactField::create([
        'nome' => 'Plano', 'tipo' => ContactField::LISTA, 'ordem' => 1,
        'opcoes' => ['Básico', 'Premium'],
    ]);

    menuQueGrava('personalizado.'.$plano->id);

    recebe('oi');
    recebe('2');

    // Guarda o TEXTO da opcao, nao o numero: "2" nao diz nada no cadastro daqui a
    // seis meses.
    expect(ContactFieldValue::where('contact_field_id', $plano->id)->value('valor'))->toBe('Premium');
});

it('depois do menu da para citar a escolha por marcador', function () {
    $plano = ContactField::create([
        'nome' => 'Plano', 'tipo' => ContactField::LISTA, 'ordem' => 1,
        'opcoes' => ['Básico', 'Premium'],
    ]);

    [, $destinos] = menuQueGrava('personalizado.'.$plano->id);

    $this->fluxo->adicionarAcao($destinos['1'], ChatbotAction::MENSAGEM, [
        'texto' => 'Vi aqui que você é {{plano}}.',
    ]);

    recebe('oi');
    recebe('1');

    expect(ultimaDita())->toBe('Vi aqui que você é Básico.');
});

it('a escolha do menu tambem serve para coluna do contato', function () {
    menuQueGrava('contato.cidade', ['Natal', 'Parnamirim']);

    recebe('oi');
    recebe('1');

    expect($this->contato->fresh()->cidade)->toBe('Natal');
});

it('rotulo que nao cabe no campo nao trava o cliente: o fluxo segue', function () {
    // O cliente escolheu de uma lista NOSSA. Se o rotulo nao serve para o campo, o
    // erro e de configuracao — cobrar dele seria trocar o culpado, e ele nao tem
    // outra opcao para dar.
    $plano = ContactField::create([
        'nome' => 'Plano', 'tipo' => ContactField::LISTA, 'ordem' => 1,
        'opcoes' => ['Básico', 'Premium'],
    ]);

    menuQueGrava('personalizado.'.$plano->id, ['Plano 300MB', 'Plano 600MB']);

    recebe('oi');
    recebe('1');

    expect(ContactFieldValue::where('contact_field_id', $plano->id)->count())->toBe(0)
        // seguiu para o destino da opcao
        ->and(ultimaDita())->toBe('Anotado.')
        ->and($this->conversa->fresh()->chatbot_estado)->not->toBe(ChatbotMotor::ESCAPOU);
});

it('publicar avisa quando o rotulo do menu nao serve para o campo', function () {
    // A validacao existe porque em producao a escolha seria descartada em silencio.
    $plano = ContactField::create([
        'nome' => 'Plano', 'tipo' => ContactField::LISTA, 'ordem' => 1,
        'opcoes' => ['Básico', 'Premium'],
    ]);

    menuQueGrava('personalizado.'.$plano->id, ['Básico', 'Plano de Ouro']);

    $problemas = implode(' ', $this->fluxo->validar($this->bot));

    expect($problemas)->toContain('Plano de Ouro')
        ->and($problemas)->toContain('não serve para o campo')
        // a opcao que CABE nao e reclamada
        ->and($problemas)->not->toContain('"Básico" não serve');
});

it('menu com rotulos que cabem publica sem reclamacao', function () {
    $plano = ContactField::create([
        'nome' => 'Plano', 'tipo' => ContactField::LISTA, 'ordem' => 1,
        'opcoes' => ['Básico', 'Premium'],
    ]);

    menuQueGrava('personalizado.'.$plano->id);

    expect($this->fluxo->validar($this->bot))->toBe([]);
});

it('publicar avisa quando o campo do menu foi apagado', function () {
    $plano = ContactField::create([
        'nome' => 'Plano', 'tipo' => ContactField::TEXTO_CURTO, 'ordem' => 1,
    ]);

    menuQueGrava('personalizado.'.$plano->id);
    $plano->delete();

    expect(implode(' ', $this->fluxo->validar($this->bot)))->toContain('não existe mais');
});

it('menu em campo de texto aceita qualquer rotulo', function () {
    // Campo de texto nao tem lista fechada: nao ha o que nao caber.
    $motivo = ContactField::create([
        'nome' => 'Motivo do contato', 'tipo' => ContactField::TEXTO_CURTO, 'ordem' => 1,
    ]);

    menuQueGrava('personalizado.'.$motivo->id, ['Financeiro', 'Suporte técnico']);

    expect($this->fluxo->validar($this->bot))->toBe([]);

    recebe('oi');
    recebe('2');

    expect(ContactFieldValue::where('contact_field_id', $motivo->id)->value('valor'))
        ->toBe('Suporte técnico');
});
