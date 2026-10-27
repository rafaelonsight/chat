<?php

use App\Filament\Pages\Paineis;
use App\Models\{BoardItem, Channel, Contact, Conversation, FunnelStage, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * O funil.
 *
 * O CARTAO E A CONVERSA, e nao o contato. A mesma pessoa pode ter dois assuntos ao mesmo tempo
 * — um orcamento fechado em julho e outro em negociacao em agosto. Com o contato como cartao, o
 * segundo apagaria o primeiro.
 *
 * E A ETAPA GUARDA A DATA DE ENTRADA, nao so o nome. Sem ela nao da para responder "ha quanto
 * tempo isso esta parado em Negociacao?", que e a unica pergunta que faz um funil valer alguma
 * coisa. Sem ela, ele e uma lista bonita.
 */

beforeEach(function () {
    config(['boards.foundation_enabled' => true]);
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'funil']);
    TenantContext::set($this->conta->id);

    $this->admin = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Admin',
        'email' => 'admin@funil.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'fun',
    ]);

    $this->actingAs($this->admin);
});

afterEach(fn () => TenantContext::forget());

function conversaFunil($ctx, string $nome): Conversation
{
    static $n = 0;
    $n++;

    $contato = Contact::create([
        'tenant_id' => $ctx->conta->id, 'nome' => $nome,
        'telefone_e164' => '+55419600000'.$n, 'jid' => '55419600000'.$n.'@s.whatsapp.net',
    ]);

    return Conversation::create([
        'tenant_id' => $ctx->conta->id, 'channel_id' => $ctx->canal->id,
        'contact_id' => $contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'ultima_msg_em' => now(),
    ]);
}

// ============================================================== as colunas

it('cria as tres colunas de exemplo', function () {
    // Funil vazio nao ensina nada: a pessoa abre, ve um quadro em branco e fecha.
    Livewire::actingAs($this->admin)->test(Paineis::class)->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors();

    expect(FunnelStage::count())->toBe(3)
        ->and(FunnelStage::orderBy('ordem')->first()->nome)->toBe('A fazer')
        ->and(FunnelStage::where('nome', 'Concluído')->first()->encerra)->toBeTrue();
});

it('abrir um painel existente nao recria nem substitui suas colunas', function () {
    $funil = App\Models\Funnel::create(['tenant_id' => $this->conta->id, 'nome' => 'Meu']);
    $etapa = FunnelStage::create([
        'tenant_id' => $this->conta->id, 'funnel_id' => $funil->id,
        'nome' => 'Minha', 'ordem' => 0,
    ]);

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('abrirFunil', $funil->id)->call('configurar')
        ->assertSet('colunas.0.id', $etapa->id);

    expect(FunnelStage::count())->toBe(1)
        ->and($etapa->fresh()->nome)->toBe('Minha');
});

it('salva a ordem das colunas como esta na tela', function () {
    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors()
        ->call('configurar');
    $colunas = $tela->get('colunas');
    $colunas = [$colunas[1], $colunas[0], $colunas[2]];
    $colunas[0]['nome'] = 'Entrou';
    $colunas[2]['nome'] = 'Fechou';

    $tela->set('colunas', $colunas)->call('salvarConfiguracao')->assertHasNoErrors();
    $etapas = FunnelStage::orderBy('ordem')->get();

    expect($etapas)->toHaveCount(3)
        ->and($etapas->pluck('id')->all())->toBe(array_column($colunas, 'id'))
        ->and($etapas[0]->nome)->toBe('Entrou')
        ->and($etapas[2]->nome)->toBe('Fechou')
        ->and($etapas[2]->encerra)->toBeTrue();
});

it('remover uma coluna transfere e preserva suas conversas e tarefas', function () {
    $tela = Livewire::actingAs($this->admin)->test(Paineis::class)
        ->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors();
    $etapas = FunnelStage::orderBy('ordem')->get();
    $origem = $etapas[0];
    $destino = $etapas[1];
    $conversa = conversaFunil($this, 'Maria');
    $conversa->moverPara($origem);
    $item = BoardItem::create([
        'tenant_id' => $this->conta->id, 'funnel_id' => $origem->funnel_id,
        'funnel_stage_id' => $origem->id, 'titulo' => 'Preservar tarefa',
        'dados' => ['observacao' => 'Preservar conteúdo'],
    ]);

    $tela->call('configurar')
        ->set('etapaRemover', $origem->id)->set('destinoEtapa', $destino->id)
        ->call('removerColuna')->assertHasNoErrors();

    expect(FunnelStage::find($origem->id))->toBeNull()
        ->and(FunnelStage::count())->toBe(2)
        ->and(Conversation::find($conversa->id))->not->toBeNull()
        ->and($conversa->fresh()->funnel_stage_id)->toBe($destino->id)
        ->and(BoardItem::find($item->id))->not->toBeNull()
        ->and($item->fresh()->funnel_stage_id)->toBe($destino->id)
        ->and($item->fresh()->titulo)->toBe('Preservar tarefa')
        ->and($item->fresh()->dados)->toBe(['observacao' => 'Preservar conteúdo']);
});

it('nao salva coluna sem nome', function () {
    App\Models\Funnel::criarCom('Funil');
    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('configurar')
        ->set('colunas', [['id' => null, 'nome' => '', 'cor' => 'cinza', 'encerra' => false]])
        ->call('salvarConfiguracao')
        ->assertHasErrors('colunas.0.nome');
});

// ============================================================== os cartoes

it('move o cartao e guarda QUANDO ele entrou na etapa', function () {
    Livewire::actingAs($this->admin)->test(Paineis::class)->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors();

    $primeira = FunnelStage::orderBy('ordem')->first();
    $conversa = conversaFunil($this, 'Maria');

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('mover', $conversa->id, $primeira->id);

    $c = $conversa->fresh();

    expect($c->funnel_stage_id)->toBe($primeira->id)
        ->and($c->etapa_em)->not->toBeNull();
});

it('mover de novo atualiza a data da etapa', function () {
    // Se a data ficasse na primeira entrada, "parado ha 12 dias" continuaria dizendo 12 dias
    // depois de o cartao andar — e o numero que justifica o funil viraria mentira.
    Livewire::actingAs($this->admin)->test(Paineis::class)->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors();

    $etapas = FunnelStage::orderBy('ordem')->get();
    $conversa = conversaFunil($this, 'Maria');

    $conversa->moverPara($etapas[0]);
    $conversa->forceFill(['etapa_em' => now()->subDays(12)])->save();

    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('mover', $conversa->id, $etapas[1]->id);

    expect($conversa->fresh()->etapa_em->diffInMinutes(now()))->toBeLessThan(2);
});

it('tirar do funil limpa a etapa e a data', function () {
    Livewire::actingAs($this->admin)->test(Paineis::class)->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors();

    $conversa = conversaFunil($this, 'Maria');
    $conversa->moverPara(FunnelStage::orderBy('ordem')->first());

    Livewire::actingAs($this->admin)->test(Paineis::class)->call('mover', $conversa->id, null);

    expect($conversa->fresh()->funnel_stage_id)->toBeNull()
        ->and($conversa->fresh()->etapa_em)->toBeNull();
});

it('nao move para etapa de OUTRA conta', function () {
    App\Models\Funnel::criarCom('Meu');
    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-funil']);
    $funilAlheio = App\Models\Funnel::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'nome' => 'Alheio',
    ]);

    $alheia = FunnelStage::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'funnel_id' => $funilAlheio->id,
        'nome' => 'Alheia', 'ordem' => 0,
    ]);

    $conversa = conversaFunil($this, 'Maria');

    expect(fn () => Livewire::actingAs($this->admin)->test(Paineis::class)
        ->call('mover', $conversa->id, $alheia->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect($conversa->fresh()->funnel_stage_id)->toBeNull();
});

it('duas conversas do MESMO contato ficam em colunas diferentes', function () {
    // O motivo de o cartao ser a conversa e nao o contato: um assunto fechado e outro em
    // negociacao, ao mesmo tempo, da mesma pessoa.
    Livewire::actingAs($this->admin)->test(Paineis::class)->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors();
    $etapas = FunnelStage::orderBy('ordem')->get();

    $primeira = conversaFunil($this, 'Maria');
    $primeira->update(['status' => Conversation::ARQUIVADA]);

    $segunda = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $primeira->contact_id, 'status' => Conversation::EM_ATENDIMENTO,
        'ultima_msg_em' => now(),
    ]);

    $primeira->moverPara($etapas->firstWhere('nome', 'Concluído'));
    $segunda->moverPara($etapas->firstWhere('nome', 'Em andamento'));

    expect($primeira->fresh()->funnel_stage_id)->not->toBe($segunda->fresh()->funnel_stage_id);
});

// ================================================================== a tela

it('a lista de atendimentos preserva a coluna de cada cartao', function () {
    Livewire::actingAs($this->admin)->test(Paineis::class)->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors();
    $primeira = FunnelStage::orderBy('ordem')->first();

    conversaFunil($this, 'Maria')->moverPara($primeira);
    conversaFunil($this, 'Joao')->moverPara($primeira);

    $dados = Livewire::actingAs($this->admin)->test(Paineis::class)->viewData('atendimentos')->groupBy('funnel_stage_id');

    expect($dados[$primeira->id])->toHaveCount(2);
});

it('mostra as conversas que ainda nao entraram no funil', function () {
    // Sem esta coluna, por o primeiro cartao no quadro exigiria abrir a conversa e voltar — e
    // ninguem descobre sozinho que da para fazer isso.
    Livewire::actingAs($this->admin)->test(Paineis::class)->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors();
    conversaFunil($this, 'Ainda fora');

    $fora = Livewire::actingAs($this->admin)->test(Paineis::class)->viewData('foraDoFunil');

    expect($fora)->toHaveCount(1);
});

it('conversa arquivada nao aparece em "fora do funil"', function () {
    Livewire::actingAs($this->admin)->test(Paineis::class)->set('novoPainel', 'Funil')->call('criarFunil')->assertHasNoErrors();
    conversaFunil($this, 'Encerrada')->update(['status' => Conversation::ARQUIVADA]);

    expect(Livewire::actingAs($this->admin)->test(Paineis::class)->viewData('foraDoFunil'))
        ->toHaveCount(0);
});

it('o quadro vazio convida a criar as colunas', function () {
    Livewire::actingAs($this->admin)->test(Paineis::class)
        ->assertSee('Nenhum painel disponível')
        ->assertSee('Criar painel')
        ->set('novoPainel', 'Meu primeiro painel')->call('criarFunil')->assertHasNoErrors()
        ->assertSee('Meu primeiro painel');
});
