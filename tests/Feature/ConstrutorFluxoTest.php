<?php

use App\Filament\Resources\Chatbots\Pages\EditarFluxo;
use App\Models\{Chatbot, ChatbotAction, ChatbotEdge, ChatbotStep, Team, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'admin' => true]);
    $this->suporte = Team::create(['nome' => 'Suporte']);

    $this->bot = Chatbot::create([
        'nome' => 'Recepção', 'ativo' => false,
        'mensagem_boas_vindas' => 'oi', 'mensagem_nao_entendi' => 'nao entendi',
    ]);

    $this->be($this->admin);
});

afterEach(fn () => TenantContext::forget());

// Sem tipo de retorno: com `use Livewire\Livewire`, o nome Livewire virou alias da
// CLASSE, e Livewire\Features\... resolvia para Livewire\Livewire\Features\...
function construtor()
{
    return Livewire::actingAs(test()->admin)
        ->test(EditarFluxo::class, ['record' => test()->bot->getKey()]);
}

it('a rota do construtor abre para admin', function () {
    $chave = 'login_web_'.sha1('Illuminate\Auth\SessionGuard');

    $this->withSession([$chave => $this->admin->id])
        ->get("/admin/chatbot/{$this->bot->id}/fluxo")
        ->assertSuccessful()
        ->assertSee('Rascunho')
        ->assertSee('Publicar');
});

it('cria o passo de inicio sozinho ao abrir', function () {
    // Fluxo sem inicio nao roda. O usuario nao deveria precisar saber que existe
    // um passo especial.
    construtor();

    expect($this->bot->steps()->where('tipo', ChatbotStep::INICIO)->count())->toBe(1);
});

it('o desenho entregue a tela tem o inicio com uma saida', function () {
    $desenho = construtor()->instance()->desenho();

    expect($desenho)->toHaveCount(1)
        ->and($desenho[0]['inicio'])->toBeTrue()
        ->and($desenho[0]['handles'])->toHaveCount(1)
        ->and($desenho[0]['handles'][0]['handle'])->toBe(ChatbotEdge::SAIDA);
});

it('novo grupo nasce e abre a paleta', function () {
    // Grupo vazio nao faz nada; abrir a paleta na hora evita o cartao morto.
    $tela = construtor()->call('criarPasso', 500, 300);

    $passo = $this->bot->steps()->where('tipo', ChatbotStep::GRUPO)->first();

    expect($passo)->not->toBeNull()
        ->and($passo->x)->toBe(500)
        ->and($passo->y)->toBe(300);

    $tela->assertSet('paletaAberta', true)
        ->assertSet('passoAberto', $passo->id);
});

it('arrastar persiste a posicao sem redesenhar a tela', function () {
    $tela = construtor()->call('criarPasso', 100, 100);
    $passo = $this->bot->steps()->where('tipo', ChatbotStep::GRUPO)->first();

    $tela->call('moverPasso', $passo->id, 640, 220);

    expect($passo->fresh()->x)->toBe(640)
        ->and($passo->fresh()->y)->toBe(220);
});

it('nao remove o passo de inicio', function () {
    $tela = construtor();
    $inicio = $this->bot->inicio();

    $tela->call('removerPasso', $inicio->id);

    // Sem inicio o fluxo nao tem por onde comecar.
    expect($this->bot->steps()->whereKey($inicio->id)->exists())->toBeTrue();
});

it('adiciona acao e ja abre a configuracao dela', function () {
    $tela = construtor()->call('criarPasso', 400, 200);

    $tela->call('adicionarAcao', ChatbotAction::MENSAGEM);

    $acao = $this->bot->actions()->first();

    expect($acao)->not->toBeNull()
        ->and($acao->tipo)->toBe(ChatbotAction::MENSAGEM);

    $tela->assertSet('acaoAberta', $acao->id)
        ->assertSet('paletaAberta', false);
});

it('recusa tipo de acao que nao existe', function () {
    $tela = construtor()->call('criarPasso', 0, 0);

    $tela->call('adicionarAcao', 'apagar_o_banco');

    expect($this->bot->actions()->count())->toBe(0);
});

it('salvar grava a config da acao', function () {
    $tela = construtor()->call('criarPasso', 0, 0);
    $tela->call('adicionarAcao', ChatbotAction::MENSAGEM);

    $tela->set('form.texto', 'Bom dia! Sou o atendimento automático.')->call('salvarAcao');

    expect($this->bot->actions()->first()->cfg('texto'))
        ->toBe('Bom dia! Sou o atendimento automático.');
});

it('cada opcao de menu vira uma saida propria no cartao', function () {
    $tela = construtor()->call('criarPasso', 400, 200);
    $tela->call('adicionarAcao', ChatbotAction::MENU);

    $tela->set('form.texto', 'Escolha:')
        ->set('form.opcoes', [
            ['gatilho' => '1', 'rotulo' => 'Financeiro'],
            ['gatilho' => '2', 'rotulo' => 'Suporte'],
        ])
        ->call('salvarAcao');

    $passo = $this->bot->steps()->where('tipo', ChatbotStep::GRUPO)->first();
    $desenho = collect($tela->instance()->desenho())->firstWhere('id', $passo->id);

    expect(collect($desenho['handles'])->pluck('handle')->all())
        ->toBe([ChatbotEdge::opcao('1'), ChatbotEdge::opcao('2')])
        ->and(collect($desenho['handles'])->pluck('rotulo')->all())
        ->toBe(['Financeiro', 'Suporte']);
});

it('opcao sem gatilho e descartada em vez de virar lixo no menu', function () {
    $tela = construtor()->call('criarPasso', 0, 0);
    $tela->call('adicionarAcao', ChatbotAction::MENU);

    $tela->set('form.texto', 'Escolha:')
        ->set('form.opcoes', [
            ['gatilho' => '1', 'rotulo' => 'Vale'],
            ['gatilho' => '', 'rotulo' => 'Sem gatilho'],
        ])
        ->call('salvarAcao');

    expect($this->bot->actions()->first()->cfg('opcoes'))->toHaveCount(1);
});

it('remover um gatilho apaga a ligacao que ficou orfa', function () {
    // Este e o defeito mais traicoeiro do editor visual: a aresta continuaria
    // apontando para um caminho que nao existe mais, a validacao acusaria, e o
    // usuario nao teria como entender de onde vem o problema.
    $tela = construtor()->call('criarPasso', 400, 200);
    $tela->call('adicionarAcao', ChatbotAction::MENU);
    $tela->set('form.texto', 'Escolha:')
        ->set('form.opcoes', [['gatilho' => '1', 'rotulo' => 'A'], ['gatilho' => '2', 'rotulo' => 'B']])
        ->call('salvarAcao');

    $origem = $this->bot->steps()->where('tipo', ChatbotStep::GRUPO)->first();
    $tela->call('criarPasso', 800, 100);
    $destino = $this->bot->steps()->where('tipo', ChatbotStep::GRUPO)->orderByDesc('id')->first();

    $tela->call('ligar', $origem->id, ChatbotEdge::opcao('2'), $destino->id);
    expect(ChatbotEdge::where('from_handle', ChatbotEdge::opcao('2'))->count())->toBe(1);

    // Agora a opcao 2 deixa de existir.
    $tela->call('abrirAcao', $this->bot->actions()->first()->id)
        ->set('form.opcoes', [['gatilho' => '1', 'rotulo' => 'A']])
        ->call('salvarAcao');

    expect(ChatbotEdge::where('from_handle', ChatbotEdge::opcao('2'))->count())->toBe(0);
});

it('ligar e desligar funcionam pelo construtor', function () {
    $tela = construtor();
    $inicio = $this->bot->inicio();

    $tela->call('criarPasso', 400, 200);
    $grupo = $this->bot->steps()->where('tipo', ChatbotStep::GRUPO)->first();

    $tela->call('ligar', $inicio->id, ChatbotEdge::SAIDA, $grupo->id);
    expect($inicio->fresh()->destino()->id)->toBe($grupo->id);

    $tela->call('desligar', $inicio->id, ChatbotEdge::SAIDA);
    expect($inicio->fresh()->destino())->toBeNull();
});

it('nao publica fluxo quebrado, e diz o que falta', function () {
    $tela = construtor();

    $tela->call('publicar');

    expect($this->bot->fresh()->status)->toBe(Chatbot::RASCUNHO);

    // A tela precisa mostrar o motivo, senao o botao parece nao funcionar.
    expect($tela->instance()->problemas())->not->toBeEmpty();
});

it('publica o fluxo de exemplo montado pelo proprio construtor', function () {
    Team::create(['nome' => 'Financeiro']);

    $tela = construtor()->call('criarExemplo');

    expect($tela->instance()->problemas())->toBe([]);

    $tela->call('publicar');

    expect($this->bot->fresh()->status)->toBe(Chatbot::PUBLICADO)
        ->and($this->bot->fresh()->versao)->toBe(2);
});

it('nao cria exemplo em cima de um fluxo que ja tem grupos', function () {
    $tela = construtor()->call('criarPasso', 0, 0);

    $antes = $this->bot->steps()->count();
    $tela->call('criarExemplo');

    expect($this->bot->steps()->count())->toBe($antes);
});

it('o desenho nao vaza fluxo de outro tenant', function () {
    $outro = Tenant::create(['nome' => 'X', 'slug' => 'x']);
    TenantContext::set($outro->id);
    $botAlheio = Chatbot::create([
        'nome' => 'Alheio', 'ativo' => false,
        'mensagem_boas_vindas' => 'a', 'mensagem_nao_entendi' => 'b',
    ]);
    TenantContext::set($this->tenant->id);

    // findOrFail sob o escopo global: fluxo de outra conta simplesmente nao existe.
    expect(fn () => Livewire::actingAs($this->admin)
        ->test(EditarFluxo::class, ['record' => $botAlheio->getKey()]))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
