<?php

use App\Filament\Pages\Paineis;
use App\Models\{BoardField, BoardItem, Channel, Contact, Conversation, Funnel, FunnelStage, Tenant, User};
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

it('o primeiro funil nasce com as cinco colunas comuns', function () {
    // Quadro vazio nao ensina nada: a pessoa abre, ve o branco e fecha.
    Livewire::actingAs($this->admin)->test(Paineis::class)->call('criarPadrao');

    expect(Funnel::count())->toBe(1)
        ->and(Funnel::first()->stages)->toHaveCount(5);
});

it('da para ter mais de um funil, cada um com suas etapas', function () {
    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('criarPadrao')
        ->set('nomeDoNovoFunil', 'Suporte')
        ->call('criarFunil');

    expect(Funnel::count())->toBe(2)
        ->and(Funnel::where('nome', 'Suporte')->first()->stages)->toHaveCount(5)
        // criar ja abre o novo: quem acabou de criar quer mexer nele
        ->and($tela->get('funilId'))->toBe(Funnel::where('nome', 'Suporte')->value('id'));
});

it('a criacao dedicada preserva o painel atual e abre o novo', function () {
    $atual = Funnel::criarCom('Comercial');

    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirCriacao')
        ->assertSet('criandoFunil', true)
        ->set('nomeDoNovoFunil', 'Suporte')
        ->call('criarFunil')
        ->assertHasNoErrors()
        ->assertSet('criandoFunil', false);

    $novo = Funnel::where('nome', 'Suporte')->firstOrFail();

    expect(Funnel::pluck('nome')->all())->toContain('Comercial', 'Suporte')
        ->and($atual->fresh())->not->toBeNull()
        ->and($novo->stages)->toHaveCount(5)
        ->and($tela->get('funilId'))->toBe($novo->id);
});

it('funil sem nome nao e criado', function () {
    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->set('nomeDoNovoFunil', '   ')
        ->call('criarFunil')
        ->assertHasErrors('nomeDoNovoFunil');

    expect(Funnel::count())->toBe(0);
});

// ================================================ as etapas sao de cada funil

it('editar as colunas de um funil NAO mexe nas do outro', function () {
    // Sem o recorte por funil, salvar as colunas de um apagaria as dos outros — e os cartoes
    // deles cairiam todos para fora de uma vez.
    $vendas = Funnel::criarCom('Vendas');
    $suporte = Funnel::criarCom('Suporte');

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $vendas->id)
        ->set('colunas', [['id' => null, 'nome' => 'Só uma', 'cor' => 'azul', 'encerra' => false]])
        ->call('salvarColunas');

    expect($vendas->fresh()->stages)->toHaveCount(1)
        ->and($suporte->fresh()->stages)->toHaveCount(5);
});

it('coluna nova nasce no funil que esta aberto', function () {
    $vendas = Funnel::criarCom('Vendas');
    Funnel::criarCom('Suporte');

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $vendas->id)
        ->call('adicionarColuna')
        ->set('colunas.5.nome', 'Proposta enviada')
        ->call('salvarColunas');

    expect(FunnelStage::where('nome', 'Proposta enviada')->first()->funnel_id)->toBe($vendas->id);
});

// ========================================== cartoes genericos e personalizacao

it('cria item generico sem conversa e sem contato', function () {
    $painel = Funnel::criarCom('Tarefas');
    $etapa = $painel->stages()->first();

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $painel->id)
        ->call('abrirNovoItem', $etapa->id)
        ->set('tituloDoItem', 'Revisar proposta')
        ->set('prioridadeDoItem', 'alta')
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
        ->set('tituloDoItem', 'Plano empresarial')
        ->set('contatoDoItem', $contato->id)
        ->set('valoresDoItem.'.$valor->id, '1490.00')
        ->call('salvarItem')
        ->assertHasNoErrors();

    $item = BoardItem::first();

    expect($item->contact_id)->toBe($contato->id)
        ->and($item->dados[(string) $valor->id] ?? $item->dados[$valor->id])->toBe('1490.00');
});

it('item so se move entre colunas do proprio painel', function () {
    $comercial = Funnel::criarCom('Comercial');
    $suporte = Funnel::criarCom('Suporte');
    $item = BoardItem::create([
        'tenant_id' => $this->conta->id,
        'funnel_id' => $comercial->id,
        'funnel_stage_id' => $comercial->stages()->first()->id,
        'titulo' => 'Item',
    ]);

    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $comercial->id)
        ->call('moverItem', $item->id, $suporte->stages()->first()->id);

    expect($item->fresh()->funnel_stage_id)->toBe($comercial->stages()->first()->id);

    $tela->call('moverItem', $item->id, $comercial->stages()->skip(1)->first()->id);

    expect($item->fresh()->funnel_stage_id)->toBe($comercial->stages()->skip(1)->first()->id);
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

    $ids = collect($tela->viewData('conversas'))->flatten()->pluck('id')->all();

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

it('excluir o funil NAO apaga as conversas', function () {
    // Perder atendimento porque alguem reorganizou o quadro seria perder dado por arrumacao.
    $funil = Funnel::criarCom('Vendas');
    $conversa = conversaDoQuadro($this, 'Cliente');
    $conversa->moverPara($funil->stages()->first());

    Livewire::actingAs($this->admin)->test(Paineis::class)->call('excluirFunil', $funil->id);

    expect(Funnel::count())->toBe(0)
        ->and(FunnelStage::count())->toBe(0)
        ->and(Conversation::find($conversa->id))->not->toBeNull()
        ->and($conversa->fresh()->funnel_stage_id)->toBeNull();
});

it('funil de outra conta nao abre nem se apaga', function () {
    $meu = Funnel::criarCom('Meu');

    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-funis']);
    $alheio = Funnel::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'nome' => 'Alheio', 'ordem' => 0,
    ]);

    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $alheio->id);

    expect($tela->get('funilId'))->not->toBe($alheio->id);

    $tela->call('excluirFunil', $alheio->id);

    expect(Funnel::withoutGlobalScope('tenant')->whereKey($alheio->id)->exists())->toBeTrue()
        ->and(Funnel::count())->toBe(1)
        ->and(Funnel::first()->id)->toBe($meu->id);
});

it('renomear o funil vale, e nome vazio nao apaga o que havia', function () {
    $funil = Funnel::criarCom('Vendas');

    $tela = Livewire::actingAs($this->admin)->test(Paineis::class);

    $tela->call('renomearFunil', $funil->id, 'Comercial');
    expect($funil->fresh()->nome)->toBe('Comercial');

    $tela->call('renomearFunil', $funil->id, '   ');
    expect($funil->fresh()->nome)->toBe('Comercial');
});
