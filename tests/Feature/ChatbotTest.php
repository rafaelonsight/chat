<?php

use App\Models\{BusinessHour, Channel, Chatbot, ChatbotNode, Contact, Conversation,
    ConversationEvent, Message, Team, Tenant, User};
use App\Services\ChatbotEngine;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['*' => fn () => Http::response(['key' => ['id' => 'A-'.uniqid()]])]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    $this->tenant->forceFill(['fuso_horario' => 'America/Sao_Paulo'])->save();
    TenantContext::set($this->tenant->id);

    $this->channel = Channel::create(['nome' => 'Principal'])->refresh();
    $this->financeiro = Team::create(['nome' => 'Financeiro']);
    $this->suporte = Team::create(['nome' => 'Suporte']);

    // Arvore do provedor: dois destinos de equipe, um submenu e dois textos.
    $this->bot = Chatbot::create([
        'nome'                 => 'Recepção',
        'ativo'                => true,
        'mensagem_boas_vindas' => 'Olá! Como podemos ajudar?',
        'mensagem_nao_entendi' => 'Não entendi.',
        'max_tentativas'       => 2,
        'palavra_escape'       => 'atendente',
    ]);

    $no = fn (array $attr) => ChatbotNode::create(array_merge(['chatbot_id' => $this->bot->id], $attr));

    $this->opFinanceiro = $no([
        'gatilho' => '1', 'rotulo' => 'Financeiro', 'ordem' => 1,
        'tipo' => ChatbotNode::EQUIPE, 'team_id' => $this->financeiro->id,
    ]);

    $this->opSuporte = $no([
        'gatilho' => '2', 'rotulo' => 'Suporte', 'ordem' => 2,
        'tipo' => ChatbotNode::MENU, 'mensagem' => 'Qual o problema?',
    ]);

    $this->semInternet = $no([
        'parent_id' => $this->opSuporte->id,
        'gatilho' => '1', 'rotulo' => 'Sem internet', 'ordem' => 1,
        'tipo' => ChatbotNode::EQUIPE, 'team_id' => $this->suporte->id,
    ]);

    $this->lentidao = $no([
        'parent_id' => $this->opSuporte->id,
        'gatilho' => '2', 'rotulo' => 'Lentidão', 'ordem' => 2,
        'tipo' => ChatbotNode::MENSAGEM, 'mensagem' => 'Reinicie o roteador e aguarde 2 minutos.',
    ]);

    $this->contato = Contact::create([
        'nome' => 'Cliente', 'telefone_e164' => '+5511999998888',
        'jid' => '5511999998888@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::abertaOuNova($this->channel->id, $this->contato->id);
});

afterEach(function () {
    TenantContext::forget();
    Carbon::setTestNow();
});

/** Simula o cliente escrevendo, passando pelo webhook de verdade. */
function recebe(string $texto): void
{
    static $n = 0;
    $n++;

    test()->postJson(
        "/webhooks/evolution/{$_ENV['__canal_id']}/{$_ENV['__canal_secret']}",
        [
            'event' => 'messages.upsert',
            'data'  => [
                'key'      => ['remoteJid' => $_ENV['__jid'], 'fromMe' => false, 'id' => 'MSG'.$n.uniqid()],
                'pushName' => 'Cliente',
                'message'  => ['conversation' => $texto],
                'messageTimestamp' => now()->timestamp,
            ],
        ],
    )->assertOk();
}

function automaticas(int $conversaId)
{
    return Message::where('conversation_id', $conversaId)
        ->where('automatica', true)
        ->orderBy('id')
        ->get();
}

beforeEach(function () {
    $_ENV['__canal_id'] = $this->channel->id;
    $_ENV['__canal_secret'] = $this->channel->webhook_secret;
    $_ENV['__jid'] = $this->contato->jid;
});

it('no primeiro contato manda boas-vindas e menu numa UNICA mensagem', function () {
    recebe('oi');

    $saidas = automaticas($this->conversa->id);

    // Uma bolha, nao duas: dois jobs na fila nao tem ordem garantida e o menu
    // poderia chegar antes da saudacao.
    expect($saidas)->toHaveCount(1);

    $corpo = $saidas->first()->corpo;

    expect($corpo)->toContain('Olá! Como podemos ajudar?')
        ->toContain('1 - Financeiro')
        ->toContain('2 - Suporte')
        ->toContain('atendente');

    // No menu raiz nao existe "voltar" para oferecer.
    expect($corpo)->not->toContain('0 - Voltar');

    $this->conversa->refresh();
    expect($this->conversa->chatbot_estado)->toBe(ChatbotEngine::ATIVO)
        ->and($this->conversa->chatbot_id)->toBe($this->bot->id);
});

it('a mensagem do bot nao tira a conversa de Novos', function () {
    recebe('oi');

    $this->conversa->refresh();

    // Se saisse de Novos, o cliente esperaria e ninguem veria a fila crescer.
    expect($this->conversa->status)->toBe(Conversation::NOVA)
        ->and($this->conversa->atendente_id)->toBeNull()
        ->and($this->conversa->nao_lidas)->toBeGreaterThan(0);
});

it('escolher equipe transfere e deixa a trilha para quem receber', function () {
    recebe('oi');
    recebe('1');

    $this->conversa->refresh();

    expect($this->conversa->team_id)->toBe($this->financeiro->id)
        ->and($this->conversa->status)->toBe(Conversation::NOVA)
        ->and($this->conversa->atendente_id)->toBeNull()
        ->and($this->conversa->chatbot_estado)->toBe(ChatbotEngine::CONCLUIDO);

    // Quem abre a conversa precisa ver o que o cliente escolheu, senao pergunta
    // tudo de novo e o bot nao economizou nada.
    $trilha = ConversationEvent::where('conversation_id', $this->conversa->id)
        ->where('tipo', ConversationEvent::CHATBOT)
        ->first();

    expect($trilha)->not->toBeNull()
        ->and($trilha->descricao)->toContain('Financeiro');
});

it('submenu mostra as opcoes filhas e oferece voltar', function () {
    recebe('oi');
    recebe('2');

    $corpo = automaticas($this->conversa->id)->last()->corpo;

    expect($corpo)->toContain('Qual o problema?')
        ->toContain('1 - Sem internet')
        ->toContain('2 - Lentidão')
        ->toContain('0 - Voltar');

    $this->conversa->refresh();
    expect($this->conversa->chatbot_node_id)->toBe($this->opSuporte->id);
});

it('opcao de texto responde e repete o menu na mesma mensagem', function () {
    recebe('oi');
    recebe('2');
    recebe('2');

    $antes = automaticas($this->conversa->id)->count();
    $corpo = automaticas($this->conversa->id)->last()->corpo;

    expect($antes)->toBe(3) // uma por mensagem recebida, nunca duas
        ->and($corpo)->toContain('Reinicie o roteador')
        ->toContain('1 - Sem internet');

    // Continua no mesmo submenu: responder um texto nao move o cliente.
    $this->conversa->refresh();
    expect($this->conversa->chatbot_node_id)->toBe($this->opSuporte->id);
});

it('voltar sobe um nivel', function () {
    recebe('oi');
    recebe('2');
    recebe('0');

    $this->conversa->refresh();
    expect($this->conversa->chatbot_node_id)->toBeNull();

    expect(automaticas($this->conversa->id)->last()->corpo)->toContain('1 - Financeiro');
});

it('aceita a escolha digitada por extenso, com maiuscula e acento', function () {
    recebe('oi');
    recebe('  LENTIDAO  ');

    // O cliente digita como fala. "Lentidão", "lentidao", "LENTIDAO " sao a mesma
    // escolha — mas so dentro do submenu, entao aqui deve ser "nao entendi".
    expect(automaticas($this->conversa->id)->last()->corpo)->toContain('Não entendi');

    recebe('SUPORTE');
    expect(automaticas($this->conversa->id)->last()->corpo)->toContain('Qual o problema?');

    recebe('Lentidão');
    expect(automaticas($this->conversa->id)->last()->corpo)->toContain('Reinicie o roteador');
});

it('nao entendi conta a tentativa e repete o menu', function () {
    recebe('oi');
    recebe('abobrinha');

    $this->conversa->refresh();

    expect($this->conversa->chatbot_tentativas)->toBe(1)
        ->and($this->conversa->chatbot_estado)->toBe(ChatbotEngine::ATIVO);

    expect(automaticas($this->conversa->id)->last()->corpo)
        ->toContain('Não entendi')
        ->toContain('1 - Financeiro');
});

it('depois do limite de tentativas entrega para uma pessoa', function () {
    recebe('oi');
    recebe('abobrinha');
    recebe('outra coisa');

    $this->conversa->refresh();

    // Prender o cliente num robô que nao entende e o pior resultado possivel.
    expect($this->conversa->chatbot_estado)->toBe(ChatbotEngine::ESCAPOU)
        ->and($this->conversa->status)->toBe(Conversation::NOVA)
        ->and($this->conversa->atendente_id)->toBeNull();

    expect(automaticas($this->conversa->id)->last()->corpo)->toContain('atendente');
});

it('a palavra de escape funciona a qualquer momento', function () {
    recebe('oi');
    recebe('2');
    recebe('atendente');

    $this->conversa->refresh();
    expect($this->conversa->chatbot_estado)->toBe(ChatbotEngine::ESCAPOU);

    $trilha = ConversationEvent::where('conversation_id', $this->conversa->id)
        ->where('tipo', ConversationEvent::CHATBOT)
        ->latest('id')->first();

    expect($trilha->descricao)->toContain('pediu atendente');
});

it('depois de concluido o bot nao volta a atender', function () {
    recebe('oi');
    recebe('1');

    $depoisDaEntrega = automaticas($this->conversa->id)->count();

    recebe('e o meu boleto?');

    // Sem esta trava, cada mensagem do cliente reabriria o menu e ele nunca
    // conseguiria falar.
    expect(automaticas($this->conversa->id)->count())->toBe($depoisDaEntrega);
});

it('NUNCA atende em grupo', function () {
    $grupo = Contact::create([
        'nome' => 'Bairro Centro',
        'tipo' => Contact::GRUPO,
        'jid'  => '123456789-987@g.us',
    ]);

    $conversaGrupo = Conversation::abertaOuNova($this->channel->id, $grupo->id);
    $_ENV['__jid'] = $grupo->jid;

    recebe('bom dia pessoal');

    // Bairro com 40 mensagens a noite viraria 40 menus na frente de todos.
    expect(automaticas($conversaGrupo->id))->toHaveCount(0);
});

it('nao atende se um humano ja escreveu na conversa', function () {
    Message::create([
        'conversation_id' => $this->conversa->id,
        'channel_id'      => $this->channel->id,
        'direcao'         => 'out',
        'automatica'      => false,
        'tipo'            => 'text',
        'corpo'           => 'Oi, pode falar',
    ]);

    recebe('oi');

    // Quem respondeu tomou a conversa para si, mesmo sem assumir formalmente.
    expect(automaticas($this->conversa->id))->toHaveCount(0);
});

it('nao atende se a conversa tem atendente', function () {
    $usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->conversa->assumir($usuario);

    recebe('oi');

    expect(automaticas($this->conversa->id))->toHaveCount(0);
});

it('com bot ativo a resposta automatica de fora do horario fica calada', function () {
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

    $saidas = automaticas($this->conversa->id);

    // Uma mensagem de robô, nao duas. E e a do bot.
    expect($saidas)->toHaveCount(1)
        ->and($saidas->first()->corpo)->not->toContain('ESTAMOS FECHADOS')
        ->and($saidas->first()->corpo)->toContain('Como podemos ajudar');
});

it('fora do horario com aviso proprio o bot avisa e encerra, sem menu', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 22:00:00', 'America/Sao_Paulo'));

    $this->bot->update(['mensagem_fora_horario' => 'Estamos fechados, voltamos às 8h.']);

    BusinessHour::create([
        'dia_semana' => 1, 'ativo' => true,
        'intervalos' => [['inicio' => '08:00', 'fim' => '18:00']],
    ]);

    recebe('oi');

    $saidas = automaticas($this->conversa->id);

    // Menu que encaminha para equipe que ninguem esta olhando e pior que dizer
    // "estamos fechados".
    expect($saidas)->toHaveCount(1)
        ->and($saidas->first()->corpo)->toContain('voltamos às 8h')
        ->and($saidas->first()->corpo)->not->toContain('1 - Financeiro');

    $this->conversa->refresh();
    expect($this->conversa->chatbot_estado)->toBe(ChatbotEngine::CONCLUIDO);
});

it('foto no meio do menu nao quebra o bot', function () {
    recebe('oi');

    $this->postJson("/webhooks/evolution/{$this->channel->id}/{$this->channel->webhook_secret}", [
        'event' => 'messages.upsert',
        'data'  => [
            'key'      => ['remoteJid' => $this->contato->jid, 'fromMe' => false, 'id' => 'FOTO1'],
            'pushName' => 'Cliente',
            'message'  => ['imageMessage' => ['mimetype' => 'image/jpeg']],
            'messageTimestamp' => now()->timestamp,
        ],
    ])->assertOk();

    $this->conversa->refresh();

    expect($this->conversa->chatbot_estado)->toBe(ChatbotEngine::ATIVO)
        ->and($this->conversa->chatbot_tentativas)->toBe(1);
});

it('ativar um bot desliga o outro do mesmo canal, em vez de dar erro', function () {
    // Quem ativa o segundo bot quer TROCAR de bot. Deixar estourar a constraint
    // seria transformar uma intencao obvia num erro 500.
    $outro = Chatbot::create([
        'nome' => 'Outro', 'ativo' => true,
        'mensagem_boas_vindas' => 'a', 'mensagem_nao_entendi' => 'b',
    ]);

    expect($this->bot->fresh()->ativo)->toBeFalse()
        ->and($outro->fresh()->ativo)->toBeTrue()
        ->and(Chatbot::where('ativo', true)->count())->toBe(1);
});

it('o banco continua sendo a ultima linha de defesa contra dois bots ativos', function () {
    // Se algum caminho futuro escapar do modelo, o indice parcial ainda barra:
    // dois bots ativos no mesmo canal nao tem resposta definida para "quem atende".
    Chatbot::create([
        'nome' => 'Outro', 'ativo' => false,
        'mensagem_boas_vindas' => 'a', 'mensagem_nao_entendi' => 'b',
    ]);

    expect(fn () => Illuminate\Support\Facades\DB::table('chatbots')->update(['ativo' => true]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('a previa mostra ao configurador o texto exato que o cliente recebe', function () {
    $previa = app(ChatbotEngine::class)->previa($this->bot);

    expect($previa)->toContain('Olá! Como podemos ajudar?')
        ->toContain('1 - Financeiro')
        ->toContain('2 - Suporte');

    $noSubmenu = app(ChatbotEngine::class)->previa($this->bot, $this->opSuporte);

    expect($noSubmenu)->toContain('Qual o problema?')
        ->toContain('1 - Sem internet')
        ->toContain('0 - Voltar');
});

it('o banco impede dois gatilhos iguais no mesmo menu', function () {
    // Duas opcoes "1" no mesmo nivel: qual atenderia? O banco decide que nao pode.
    expect(fn () => ChatbotNode::create([
        'chatbot_id' => $this->bot->id,
        'gatilho' => '1', 'rotulo' => 'Repetido',
        'tipo' => ChatbotNode::MENSAGEM, 'mensagem' => 'x',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('sem bot ativo nada muda no fluxo antigo', function () {
    $this->bot->update(['ativo' => false]);

    $this->tenant->forceFill([
        'resposta_automatica_ativa' => true,
        'resposta_automatica_texto' => 'ESTAMOS FECHADOS',
    ])->save();

    Carbon::setTestNow(Carbon::parse('2026-08-03 22:00:00', 'America/Sao_Paulo'));

    BusinessHour::create([
        'dia_semana' => 1, 'ativo' => true,
        'intervalos' => [['inicio' => '08:00', 'fim' => '18:00']],
    ]);

    recebe('oi');

    // A resposta automatica volta a ser a dona da vez.
    expect(automaticas($this->conversa->id)->first()->corpo)->toContain('ESTAMOS FECHADOS');
});
