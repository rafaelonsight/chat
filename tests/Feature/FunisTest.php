<?php

use App\Filament\Pages\Paineis;
use App\Models\{BoardField, BoardItem, Channel, Contact, Conversation, Funnel, FunnelStage, Tag, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * MAIS DE UM FUNIL, cada um com as proprias etapas.
 *
 * Antes havia um quadro por conta. Uma empresa que vende e tambem faz suporte precisa de dois
 * processos diferentes: "Orcamento, Negociacao, Fechado" nao descreve um chamado tecnico, e
 * forcar os dois no mesmo quadro faz a pessoa inventar etapas que nao servem para nenhum.
 *
 * UMA CONVERSA FICA EM UM FUNIL DE CADA VEZ. E decisao, nao pressa: o cartao e a conversa, e
 * uma conversa esta num ponto de um processo. Se um dia o mesmo atendimento precisar viver em
 * dois quadros, isso vira tabela de ligacao — e ai o quadro deixa de responder "onde isto
 * esta" para responder "em quantos lugares isto esta", que e outra pergunta.
 */

beforeEach(function () {
    config(['boards.foundation_enabled' => true]);
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'funis']);
    TenantContext::set($this->conta->id);

    $this->admin = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Gestor',
        'email' => 'g@funis.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'C',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'fun',
    ]);

    $this->actingAs($this->admin);
});

afterEach(fn () => TenantContext::forget());

function conversaDoQuadro($ctx, string $nome): Conversation
{
    static $n = 0;
    $n++;

    $contato = Contact::create([
        'tenant_id' => $ctx->conta->id, 'nome' => $nome,
        'telefone_e164' => '+55419911100'.$n, 'jid' => '55419911100'.$n.'@s.whatsapp.net',
    ]);

    return Conversation::create([
        'tenant_id' => $ctx->conta->id, 'channel_id' => $ctx->canal->id,
        'contact_id' => $contato->id, 'status' => 'aberta', 'ultima_msg_em' => now(),
    ]);
}

// =========================================================== criar funis

it('o primeiro funil nasce com as tres colunas de tarefas', function () {
    // Quadro vazio nao ensina nada: a pessoa abre, ve o branco e fecha.
    Livewire::actingAs($this->admin)->test(Paineis::class)->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors();

    expect(Funnel::count())->toBe(1)
        ->and(Funnel::first()->stages)->toHaveCount(3);
});

it('da para ter mais de um funil, cada um com suas etapas', function () {
    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors()
        ->set('novoPainel', 'Suporte')
        ->call('criarFunil');

    expect(Funnel::count())->toBe(2)
        ->and(Funnel::where('nome', 'Suporte')->first()->stages)->toHaveCount(3)
        // criar ja abre o novo: quem acabou de criar quer mexer nele
        ->and($tela->get('funilId'))->toBe(Funnel::where('nome', 'Suporte')->value('id'));
});

it('a criacao dedicada preserva o painel atual e abre o novo', function () {
    $atual = Funnel::criarCom('Comercial');

    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->assertSet('funilId', $atual->id)
        ->set('novoPainel', 'Suporte')
        ->call('criarFunil')
        ->assertHasNoErrors()
        ->assertSet('novoPainel', '');

    $novo = Funnel::where('nome', 'Suporte')->firstOrFail();

    expect(Funnel::pluck('nome')->all())->toContain('Comercial', 'Suporte')
        ->and($atual->fresh())->not->toBeNull()
        ->and($novo->stages)->toHaveCount(3)
        ->and($tela->get('funilId'))->toBe($novo->id);
});

it('funil sem nome nao e criado', function () {
    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->set('novoPainel', '   ')
        ->call('criarFunil')
        ->assertHasErrors('novoPainel');

    expect(Funnel::count())->toBe(0);
});

// ================================================ as etapas sao de cada funil

it('editar as colunas de um funil NAO mexe nas do outro', function () {
    $vendas = Funnel::criarCom('Vendas');
    $suporte = Funnel::criarCom('Suporte');
    $originais = $vendas->stages()->get();
    $colunasSuporte = $suporte->stages()->orderBy('ordem')->get()->toArray();
    $conversa = conversaDoQuadro($this, 'Cliente de suporte');
    $conversa->moverPara($suporte->stages()->first());
    $item = BoardItem::create([
        'tenant_id' => $this->conta->id, 'funnel_id' => $suporte->id,
        'funnel_stage_id' => $suporte->stages()->first()->id, 'titulo' => 'Não alterar',
    ]);

    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $vendas->id)->call('configurar')
        ->call('adicionarColuna')->set('colunas.5.nome', 'Só uma')
        ->call('salvarConfiguracao')->assertHasNoErrors();
    $destino = $vendas->stages()->where('nome', 'Só uma')->firstOrFail();

    // A remoção agora exige transferência explícita, nunca omissão no formulário.
    foreach ($originais as $origem) {
        $tela->set('etapaRemover', $origem->id)->set('destinoEtapa', $destino->id)
            ->call('removerColuna')->assertHasNoErrors();
    }

    expect($vendas->fresh()->stages)->toHaveCount(1)
        ->and($suporte->fresh()->stages)->toHaveCount(5)
        ->and($suporte->stages()->orderBy('ordem')->get()->toArray())->toBe($colunasSuporte)
        ->and($conversa->fresh()->funnel_stage_id)->toBe($suporte->stages()->first()->id)
        ->and($item->fresh()->funnel_stage_id)->toBe($suporte->stages()->first()->id)
        ->and($item->fresh()->titulo)->toBe('Não alterar');
});

it('coluna nova nasce no funil que esta aberto', function () {
    $vendas = Funnel::criarCom('Vendas');
    Funnel::criarCom('Suporte');

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $vendas->id)
        ->call('configurar')
        ->call('adicionarColuna')
        ->set('colunas.5.nome', 'Proposta enviada')
        ->call('salvarConfiguracao');

    expect(FunnelStage::where('nome', 'Proposta enviada')->first()->funnel_id)->toBe($vendas->id);
});

// ========================================== cartoes genericos e personalizacao

it('cria item generico sem conversa e sem contato', function () {
    $painel = Funnel::criarCom('Tarefas');
    $etapa = $painel->stages()->first();

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $painel->id)
        ->call('abrirNovoItem', $etapa->id)
        ->set('form.titulo', 'Revisar proposta')
        ->set('form.prioridade', 'alta')
        ->call('salvarItem')
        ->assertHasNoErrors();

    expect(BoardItem::count())->toBe(1)
        ->and(BoardItem::first()->titulo)->toBe('Revisar proposta')
        ->and(BoardItem::first()->contact_id)->toBeNull()
        ->and(Conversation::count())->toBe(0);
});

it('item generico pode selecionar contato cadastrado e campos do painel', function () {
    $painel = Funnel::criarCom('Comercial');
    $etapa = $painel->stages()->first();
    $contato = Contact::create([
        'tenant_id' => $this->conta->id,
        'nome' => 'Cliente existente',
        'jid' => 'cliente-existente@teste.invalid',
    ]);
    $valor = BoardField::create([
        'tenant_id' => $this->conta->id,
        'funnel_id' => $painel->id,
        'nome' => 'Valor',
        'tipo' => 'dinheiro',
        'obrigatorio' => true,
    ]);

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $painel->id)
        ->call('abrirNovoItem', $etapa->id)
        ->set('form.titulo', 'Plano empresarial')
        ->set('form.contact_id', $contato->id)
        ->set('form.dados.'.$valor->id, '1490.00')
        ->call('salvarItem')
        ->assertHasNoErrors();

    $item = BoardItem::first();

    expect($item->contact_id)->toBe($contato->id)
        ->and($item->dados[(string) $valor->id] ?? $item->dados[$valor->id])->toBe('1490.00');
});

it('item aceita etiquetas proprias de painel', function () {
    $painel = Funnel::criarCom('Tarefas');
    $etapa = $painel->stages()->first();
    $etiqueta = Tag::create([
        'tenant_id' => $this->conta->id,
        'nome' => 'Aguardando retorno',
        'cor' => 'ambar',
        'contexto' => Tag::ITEM,
    ]);

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $painel->id)
        ->call('abrirNovoItem', $etapa->id)
        ->set('form.titulo', 'Confirmar visita')
        ->set('form.tags', [$etiqueta->id])
        ->call('salvarItem')
        ->assertHasNoErrors();

    expect(BoardItem::first()->tags)->toHaveCount(1)
        ->and(BoardItem::first()->tags->first()->nome)->toBe('Aguardando retorno');
});

it('checkbox de etiqueta alterna a selecao antes de salvar', function () {
    $painel = Funnel::criarCom('Tarefas');
    $etiqueta = Tag::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Importante',
        'cor' => 'vermelho', 'contexto' => Tag::ITEM,
    ]);

    // Os checkboxes fazem binding em form.tags; não há mais ação alternarEtiqueta.
    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $painel->id)->call('abrirNovoItem')
        ->set('form.titulo', 'Confirmar seleção')
        ->set('form.tags', [$etiqueta->id])->assertSet('form.tags', [$etiqueta->id]);
    expect(BoardItem::count())->toBe(0);

    $tela->set('form.tags', [])->assertSet('form.tags', [])
        ->call('salvarItem')->assertHasNoErrors();
    $item = BoardItem::firstOrFail();
    expect($item->tags)->toHaveCount(0);

    $tela->set('form.tags', [$etiqueta->id])->call('salvarItem')->assertHasNoErrors();
    expect($item->fresh()->tags->pluck('id')->all())->toBe([$etiqueta->id]);
});

it('seletor de contato mostra pessoas salvas mas nao grupos', function () {
    Funnel::criarCom('Suporte');
    $pessoa = Contact::create([
        'tenant_id' => $this->conta->id,
        'nome' => 'Cliente salvo',
        'jid' => 'cliente@teste.invalid',
        'tipo' => Contact::PESSOA,
    ]);
    $grupo = Contact::create([
        'tenant_id' => $this->conta->id,
        'nome' => 'Grupo interno',
        'jid' => '123@g.us',
        'tipo' => Contact::GRUPO,
    ]);

    $contatos = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->viewData('contatos')->pluck('id')->all();

    expect($contatos)->toContain($pessoa->id)->not->toContain($grupo->id);
});

it('item so se move entre colunas do proprio painel', function () {
    $comercial = Funnel::criarCom('Comercial');
    $suporte = Funnel::criarCom('Suporte');
    $origem = $comercial->stages()->orderBy('ordem')->first();
    $destino = $comercial->stages()->orderBy('ordem')->skip(1)->first();
    $item = BoardItem::create([
        'tenant_id' => $this->conta->id, 'funnel_id' => $comercial->id,
        'funnel_stage_id' => $origem->id, 'titulo' => 'Item',
    ]);
    $version = $item->fresh()->version;

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $comercial->id)
        ->call('moverItem', $item->id, $suporte->stages()->first()->id, $version)
        // Livewire 4.4: o findOrFail sob o escopo do tenant vira 404 no teste, em vez de
        // exceção. O que importa continua igual: o registro de outra conta não é carregado.
        ->assertStatus(404);

    expect($item->fresh()->funnel_stage_id)->toBe($origem->id)
        ->and($item->fresh()->version)->toBe($version);

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $comercial->id)
        ->call('moverItem', $item->id, $destino->id, $version)->assertHasNoErrors();

    expect($item->fresh()->funnel_stage_id)->toBe($destino->id)
        ->and($item->fresh()->version)->toBe($version + 1);
});

// ================================================= o quadro mostra so um funil

it('o quadro mostra so os cartoes do funil aberto', function () {
    $vendas = Funnel::criarCom('Vendas');
    $suporte = Funnel::criarCom('Suporte');

    $c1 = conversaDoQuadro($this, 'Cliente de vendas');
    $c1->moverPara($vendas->stages()->first());

    $c2 = conversaDoQuadro($this, 'Cliente de suporte');
    $c2->moverPara($suporte->stages()->first());

    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)->call('abrirFunil', $vendas->id);

    $ids = $tela->viewData('atendimentos')->pluck('id')->all();

    expect($ids)->toContain($c1->id)->not->toContain($c2->id);
});

it('trocar de aba troca as etapas mostradas', function () {
    $vendas = Funnel::criarCom('Vendas');
    $suporte = Funnel::criarCom('Suporte');
    $suporte->stages()->first()->update(['nome' => 'Aguardando peça']);

    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)->call('abrirFunil', $suporte->id);

    expect($tela->viewData('etapas')->pluck('funnel_id')->unique()->all())->toBe([$suporte->id]);

    $tela->call('abrirFunil', $vendas->id);

    expect($tela->viewData('etapas')->pluck('funnel_id')->unique()->all())->toBe([$vendas->id]);
});

it('abre diretamente o painel informado na url', function () {
    $vendas = Funnel::criarCom('Vendas');
    $suporte = Funnel::criarCom('Suporte');

    $tela = Livewire::withQueryParams(['painel' => $suporte->id])
        ->actingAs($this->admin)
        ->test(Paineis::class);

    expect($tela->get('funilId'))->toBe($suporte->id)
        ->and($tela->viewData('etapas')->pluck('funnel_id')->unique()->all())->toBe([$suporte->id])
        ->and($tela->get('funilId'))->not->toBe($vendas->id);
});

// ========================================================== apagar

it('arquivar e restaurar o painel preserva conversas e colunas', function () {
    // Perder atendimento porque alguem reorganizou o quadro seria perder dado por arrumacao.
    $funil = Funnel::criarCom('Vendas');
    $conversa = conversaDoQuadro($this, 'Cliente');
    $conversa->moverPara($funil->stages()->first());

    $tela=Livewire::actingAs($this->admin)->test(Paineis::class)->call('arquivarPainel')->assertHasNoErrors();

    expect(Funnel::count())->toBe(1)
        ->and($funil->fresh()->arquivado_em)->not->toBeNull()
        ->and(FunnelStage::count())->toBe(5)
        ->and(Conversation::find($conversa->id))->not->toBeNull()
        ->and($conversa->fresh()->funnel_stage_id)->toBe($funil->stages()->first()->id);
    $tela->set('paineisArquivados',true)->call('arquivarPainel')->assertHasNoErrors();
    expect($funil->fresh()->arquivado_em)->toBeNull();
});

it('funil de outra conta nao abre nem se apaga', function () {
    $meu = Funnel::criarCom('Meu');

    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-funis']);
    $alheio = Funnel::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'nome' => 'Alheio', 'ordem' => 0,
    ]);

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $alheio->id)
        // Livewire 4.4: o findOrFail sob o escopo do tenant vira 404 no teste, em vez de
        // exceção. O que importa continua igual: o registro de outra conta não é carregado.
        ->assertStatus(404);

    expect(Funnel::withoutGlobalScope('tenant')->whereKey($alheio->id)->exists())->toBeTrue();

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->set('funilId',$alheio->id)->call('arquivarPainel')
        // Livewire 4.4: o findOrFail sob o escopo do tenant vira 404 no teste, em vez de
        // exceção. O que importa continua igual: o registro de outra conta não é carregado.
        ->assertStatus(404);

    expect(Funnel::withoutGlobalScope('tenant')->whereKey($alheio->id)->exists())->toBeTrue()
        ->and(Funnel::count())->toBe(1)
        ->and(Funnel::first()->id)->toBe($meu->id);
});

it('renomear o funil vale, e nome vazio nao apaga o que havia', function () {
    $funil = Funnel::criarCom('Vendas');
    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $funil->id)->call('configurar');

    $tela->set('nomePainel', 'Comercial')->call('salvarConfiguracao')->assertHasNoErrors();
    expect($funil->fresh()->nome)->toBe('Comercial');

    $tela->call('configurar')->set('nomePainel', '   ')
        ->call('salvarConfiguracao')->assertHasErrors('nomePainel');
    expect($funil->fresh()->nome)->toBe('Comercial');
});
