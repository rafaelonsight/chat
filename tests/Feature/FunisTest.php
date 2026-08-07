<?php

use App\Filament\Pages\Paineis;
use App\Models\{Channel, Contact, Conversation, Funnel, FunnelStage, Tenant, User};
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
