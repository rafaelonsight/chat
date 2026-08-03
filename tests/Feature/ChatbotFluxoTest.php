<?php

use App\Models\{Chatbot, ChatbotAction, ChatbotEdge, ChatbotStep, Team, Tenant};
use App\Services\ChatbotFluxo;
use App\Support\TenantContext;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);

    $this->bot = Chatbot::create([
        'nome' => 'Fluxo', 'ativo' => false,
        'mensagem_boas_vindas' => 'oi', 'mensagem_nao_entendi' => 'nao entendi',
    ]);

    $this->fluxo = app(ChatbotFluxo::class);
});

afterEach(fn () => TenantContext::forget());

it('cria um unico passo de inicio, mesmo chamando duas vezes', function () {
    $a = $this->fluxo->garantirInicio($this->bot);
    $b = $this->fluxo->garantirInicio($this->bot);

    expect($a->id)->toBe($b->id)
        ->and($this->bot->steps()->where('tipo', ChatbotStep::INICIO)->count())->toBe(1);
});

it('o banco recusa dois inicios no mesmo fluxo', function () {
    $this->fluxo->garantirInicio($this->bot);

    // Dois inicios nao tem resposta para "por onde comeca".
    expect(fn () => ChatbotStep::create([
        'chatbot_id' => $this->bot->id,
        'tipo'       => ChatbotStep::INICIO,
        'nome'       => 'Outro inicio',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('ligar de novo no mesmo handle troca o destino em vez de duplicar', function () {
    $inicio = $this->fluxo->garantirInicio($this->bot);
    $a = $this->fluxo->criarPasso($this->bot, 100, 100, 'A');
    $b = $this->fluxo->criarPasso($this->bot, 200, 200, 'B');

    $this->fluxo->ligar($inicio, $a);
    $this->fluxo->ligar($inicio, $b);

    // Quem arrasta uma linha nova quer TROCAR o destino, nao criar ambiguidade.
    expect(ChatbotEdge::where('from_step_id', $inicio->id)->count())->toBe(1)
        ->and($inicio->destino()->id)->toBe($b->id);
});

it('nao liga um passo a ele mesmo', function () {
    $a = $this->fluxo->criarPasso($this->bot, 0, 0, 'A');

    expect($this->fluxo->ligar($a, $a))->toBeNull()
        ->and(ChatbotEdge::count())->toBe(0);
});

it('o banco tambem recusa autoligacao', function () {
    $a = $this->fluxo->criarPasso($this->bot, 0, 0, 'A');

    // Laco infinito garantido; barrado no banco alem do servico.
    expect(fn () => ChatbotEdge::create([
        'chatbot_id'   => $this->bot->id,
        'from_step_id' => $a->id,
        'to_step_id'   => $a->id,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('cada opcao de menu tem sua propria saida', function () {
    $recepcao = $this->fluxo->criarPasso($this->bot, 0, 0, 'Recepção');
    $fin = $this->fluxo->criarPasso($this->bot, 100, 0, 'Financeiro');
    $sup = $this->fluxo->criarPasso($this->bot, 100, 100, 'Suporte');

    $this->fluxo->ligar($recepcao, $fin, ChatbotEdge::opcao('1'));
    $this->fluxo->ligar($recepcao, $sup, ChatbotEdge::opcao('2'));

    expect($recepcao->destino(ChatbotEdge::opcao('1'))->id)->toBe($fin->id)
        ->and($recepcao->destino(ChatbotEdge::opcao('2'))->id)->toBe($sup->id)
        ->and($recepcao->destino())->toBeNull();
});

it('acoes entram em ordem', function () {
    $p = $this->fluxo->criarPasso($this->bot, 0, 0, 'P');

    $this->fluxo->adicionarAcao($p, ChatbotAction::MENSAGEM, ['texto' => 'primeiro']);
    $this->fluxo->adicionarAcao($p, ChatbotAction::MENSAGEM, ['texto' => 'segundo']);

    expect($p->actions()->pluck('ordem')->all())->toBe([1, 2])
        ->and($p->actions()->get()->pluck('config.texto')->all())->toBe(['primeiro', 'segundo']);
});

it('reordenar acoes muda a ordem de execucao', function () {
    $p = $this->fluxo->criarPasso($this->bot, 0, 0, 'P');
    $a = $this->fluxo->adicionarAcao($p, ChatbotAction::MENSAGEM, ['texto' => 'a']);
    $b = $this->fluxo->adicionarAcao($p, ChatbotAction::MENSAGEM, ['texto' => 'b']);

    $this->fluxo->reordenarAcoes($p, [$b->id, $a->id]);

    expect($p->actions()->get()->pluck('config.texto')->all())->toBe(['b', 'a']);
});

// ------------------------------------------------------------------- validacao

it('nao publica fluxo sem inicio ligado', function () {
    $this->fluxo->garantirInicio($this->bot);

    $problemas = $this->fluxo->publicar($this->bot);

    expect($problemas)->toContain('O início não está ligado a nenhum grupo.')
        ->and($this->bot->fresh()->status)->toBe(Chatbot::RASCUNHO);
});

it('nao publica grupo vazio', function () {
    $inicio = $this->fluxo->garantirInicio($this->bot);
    $p = $this->fluxo->criarPasso($this->bot, 0, 0, 'Vazio');
    $this->fluxo->ligar($inicio, $p);

    expect($this->fluxo->publicar($this->bot))
        ->toContain('O grupo "Vazio" não tem nenhuma ação.');
});

it('nao publica menu com opcao que nao leva a lugar nenhum', function () {
    // Beco sem saida: o cliente escolhe e o bot nao tem para onde levar. E o
    // defeito mais facil de criar num editor visual.
    $inicio = $this->fluxo->garantirInicio($this->bot);
    $p = $this->fluxo->criarPasso($this->bot, 0, 0, 'Recepção');
    $this->fluxo->ligar($inicio, $p);

    $this->fluxo->adicionarAcao($p, ChatbotAction::MENU, [
        'texto'  => 'Escolha:',
        'opcoes' => [['gatilho' => '1', 'rotulo' => 'Financeiro']],
    ]);

    expect($this->fluxo->publicar($this->bot))
        ->toContain('"Recepção" → Enviar menu: a opção "Financeiro" não leva a nenhum grupo.');
});

it('nao publica menu com gatilho repetido', function () {
    $inicio = $this->fluxo->garantirInicio($this->bot);
    $p = $this->fluxo->criarPasso($this->bot, 0, 0, 'Recepção');
    $destino = $this->fluxo->criarPasso($this->bot, 100, 0, 'Destino');
    $this->fluxo->adicionarAcao($destino, ChatbotAction::CONCLUIR, ['aviso' => 'tchau']);
    $this->fluxo->ligar($inicio, $p);

    $this->fluxo->adicionarAcao($p, ChatbotAction::MENU, [
        'texto'  => 'Escolha:',
        'opcoes' => [
            ['gatilho' => '1', 'rotulo' => 'A'],
            ['gatilho' => '1', 'rotulo' => 'B'],
        ],
    ]);
    $this->fluxo->ligar($p, $destino, ChatbotEdge::opcao('1'));

    expect($this->fluxo->publicar($this->bot))
        ->toContain('"Recepção" → Enviar menu: há opções repetidas.');
});

it('nao publica condicional sem os dois caminhos', function () {
    $inicio = $this->fluxo->garantirInicio($this->bot);
    $p = $this->fluxo->criarPasso($this->bot, 0, 0, 'Decide');
    $this->fluxo->ligar($inicio, $p);
    $this->fluxo->adicionarAcao($p, ChatbotAction::CONDICIONAL, [
        'campo' => 'problema', 'operador' => 'contem', 'valor' => 'lento',
    ]);

    $problemas = $this->fluxo->publicar($this->bot);

    expect($problemas)->toContain('"Decide" → Enviar condicional: falta ligar o caminho "sim".')
        ->and($problemas)->toContain('"Decide" → Enviar condicional: falta ligar o caminho "nao".');
});

it('publica o fluxo de exemplo, que nasce valido', function () {
    Team::create(['nome' => 'Financeiro']);
    Team::create(['nome' => 'Suporte']);

    $this->fluxo->criarExemplo($this->bot);

    // Canvas vazio nao ensina nada. O exemplo tem que nascer publicavel, senao
    // ensina errado.
    expect($this->fluxo->validar($this->bot))->toBe([]);
    expect($this->fluxo->publicar($this->bot))->toBe([]);

    $bot = $this->bot->fresh();

    expect($bot->status)->toBe(Chatbot::PUBLICADO)
        ->and($bot->versao)->toBe(2)
        ->and($bot->publicado_em)->not->toBeNull()
        ->and($bot->steps()->count())->toBe(4);
});

it('cada publicacao soma uma versao', function () {
    Team::create(['nome' => 'Financeiro']);
    Team::create(['nome' => 'Suporte']);

    $this->fluxo->criarExemplo($this->bot);

    // Nasce em 1. Duas publicacoes levam a 3. Calcular a versao em PHP a partir de
    // um atributo nao hidratado dava 1 nas duas vezes.
    expect($this->bot->fresh()->versao)->toBe(1);

    $this->fluxo->publicar($this->bot);
    expect($this->bot->fresh()->versao)->toBe(2);

    $this->fluxo->publicar($this->bot->fresh());
    expect($this->bot->fresh()->versao)->toBe(3);
});

it('o exemplo liga cada opcao do menu a um grupo de verdade', function () {
    Team::create(['nome' => 'Financeiro']);
    Team::create(['nome' => 'Suporte']);

    $this->fluxo->criarExemplo($this->bot);

    $recepcao = $this->bot->steps()->where('nome', 'Recepção')->first();

    expect($recepcao->destino(ChatbotEdge::opcao('1'))->nome)->toBe('Financeiro')
        ->and($recepcao->destino(ChatbotEdge::opcao('2'))->nome)->toBe('Suporte');
});
