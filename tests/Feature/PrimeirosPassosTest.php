<?php

use App\Filament\Pages\PrimeirosPassos as Tela;
use App\Models\{BusinessHour, Channel, MessageTemplate, Tag, Team, Tenant, User};
use App\Services\PrimeirosPassos as Servico;
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * "O que falta configurar nesta conta."
 *
 * O problema que isto resolve e uma ambiguidade que so o cliente novo vive: caixa de entrada
 * vazia porque ninguem escreveu hoje e caixa de entrada vazia porque o WhatsApp nunca foi
 * conectado sao A MESMA TELA. Sem um lugar que diga a diferenca, o unico jeito de descobrir e
 * telefonar — e esse alguem sou eu, o que e exatamente o que estou tirando do caminho.
 *
 * A regra que este arquivo protege: SO O ESSENCIAL VIRA ALERTA. Um consultorio de uma pessoa
 * so legitimamente nao quer equipes; cobrar isso dele para sempre transformaria o numero
 * vermelho em enfeite, e no dia em que o canal caisse ninguem olharia.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'passos']);
    TenantContext::set($this->conta->id);

    $this->admin = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Admin',
        'email' => 'admin@passos.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->atendente = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@passos.test', 'password' => 'segredo123', 'admin' => false,
    ]);

    $this->actingAs($this->admin);
});

function canalConectado(int $tenantId, string $status = 'open'): Channel
{
    return Channel::withoutGlobalScope('tenant')->create([
        'tenant_id' => $tenantId, 'nome' => 'Canal', 'tipo' => 'meta_cloud', 'status' => $status,
    ]);
}

// ------------------------------------------------------------------ os atalhos

it('todo passo aponta para uma rota que existe de verdade', function () {
    // route() estoura se o nome mudar, entao chamar passos() ja e a prova. A assercao
    // explicita esta aqui porque link quebrado de tela de ajuda quebra em SILENCIO: a pessoa
    // clica, cai num 404 e conclui que o sistema esta com defeito.
    $passos = app(Servico::class)->passos();

    expect($passos)->not->toBeEmpty();

    foreach ($passos as $p) {
        expect($p['url'])->toStartWith('http')
            ->and($p['titulo'])->not->toBe('')
            ->and($p['porque'])->not->toBe('')
            ->and($p['acao'])->not->toBe('');
    }
});

it('nao tem passo com chave repetida', function () {
    $chaves = array_column(app(Servico::class)->passos(), 'chave');

    expect($chaves)->toBe(array_unique($chaves));
});

// ------------------------------------------------------------------ o essencial

it('conta nova tem o canal pendente e um alerta no menu', function () {
    expect(app(Servico::class)->faltamEssenciais())->toBe(1)
        ->and(Tela::getNavigationBadge())->toBe('1');
});

it('canal CRIADO mas desconectado nao conta como feito', function () {
    // A diferenca importa. Canal que existe e nao conecta e o estado mais comum de quem
    // acabou de configurar, e e justamente onde a pessoa acha que terminou.
    canalConectado($this->conta->id, 'close');

    expect(app(Servico::class)->faltamEssenciais())->toBe(1);
});

it('canal conectado apaga o alerta', function () {
    canalConectado($this->conta->id);

    expect(app(Servico::class)->faltamEssenciais())->toBe(0)
        ->and(Tela::getNavigationBadge())->toBeNull();
});

it('nao conta canal conectado de OUTRA conta', function () {
    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'outra']);
    canalConectado($outra->id);

    expect(app(Servico::class)->faltamEssenciais())->toBe(1);
});

// --------------------------------------------------------------- o recomendado

it('recomendado nao entra no numero do menu', function () {
    canalConectado($this->conta->id);

    expect(app(Servico::class)->faltamRecomendados())->toBeGreaterThan(0)
        ->and(Tela::getNavigationBadge())->toBeNull();
});

it('so marca usuarios como feito quando ha mais de um', function () {
    $so = fn () => collect(app(Servico::class)->passos())->firstWhere('chave', 'usuarios')['feito'];

    expect($so())->toBeTrue();  // ha dois no cenario: admin e atendente

    $this->atendente->delete();

    expect($so())->toBeFalse();
});

it('marca equipe, horario, etiqueta e modelo conforme sao criados', function () {
    $feito = fn (string $chave) => collect(app(Servico::class)->passos())
        ->firstWhere('chave', $chave)['feito'];

    expect($feito('equipes'))->toBeFalse()
        ->and($feito('etiquetas'))->toBeFalse()
        ->and($feito('modelos'))->toBeFalse();

    $equipe = Team::create(['tenant_id' => $this->conta->id, 'nome' => 'Vendas']);
    Tag::create(['tenant_id' => $this->conta->id, 'nome' => 'Orcamento', 'cor' => '#ff0000']);
    MessageTemplate::create([
        'tenant_id' => $this->conta->id, 'titulo' => 'Bom dia',
        'atalho' => 'bomdia', 'corpo' => 'Bom dia!',
    ]);

    expect($feito('equipes'))->toBeTrue()
        ->and($feito('etiquetas'))->toBeTrue()
        ->and($feito('modelos'))->toBeTrue()
        ->and($feito('horario'))->toBeFalse();

    BusinessHour::create([
        'tenant_id' => $this->conta->id, 'team_id' => $equipe->id,
        'dia_semana' => 1, 'ativo' => true,
        'intervalos' => [['inicio' => '08:00', 'fim' => '18:00']],
    ]);

    expect($feito('horario'))->toBeTrue();
});

it('marca o cadastro como feito quando o documento e preenchido', function () {
    $feito = fn () => collect(app(Servico::class)->passos())->firstWhere('chave', 'cadastro')['feito'];

    expect($feito())->toBeFalse();

    $this->conta->update(['documento' => '11222333000181']);

    expect($feito())->toBeTrue();
});

// -------------------------------------------------------------------- a tela

it('abre para administrador e explica o que falta', function () {
    Livewire::actingAs($this->admin)->test(Tela::class)
        ->assertOk()
        ->assertSee('Conectar o WhatsApp')
        ->assertSee('nenhuma mensagem entra nem sai');
});

it('esconde o porque do que ja esta feito, para a lista nao virar parede de texto', function () {
    canalConectado($this->conta->id);

    Livewire::actingAs($this->admin)->test(Tela::class)
        ->assertSee('Conectar o WhatsApp')
        ->assertDontSee('nenhuma mensagem entra nem sai');
});

it('atendente nao entra: nao tem o que fazer aqui', function () {
    $this->actingAs($this->atendente);

    expect(Tela::canAccess())->toBeFalse()
        ->and(Tela::getNavigationBadge())->toBeNull();

    $this->get('/admin/primeiros-passos')->assertForbidden();
});

// ------------------------------------------------------------------- o aviso

it('avisa em outras telas enquanto falta o essencial', function () {
    $this->actingAs($this->admin)
        ->get('/admin/tags')
        ->assertSee('O WhatsApp ainda não está conectado', false);
});

it('para de avisar quando o canal conecta', function () {
    canalConectado($this->conta->id);

    $this->actingAs($this->admin)
        ->get('/admin/tags')
        ->assertDontSee('O WhatsApp ainda não está conectado', false);
});

it('nao repete o aviso dentro da propria tela de primeiros passos', function () {
    $this->actingAs($this->admin)
        ->get('/admin/primeiros-passos')
        ->assertDontSee('Ver o que falta');
});
