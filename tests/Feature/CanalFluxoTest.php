<?php

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\{Channel, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * Conectar um canal.
 *
 * O QUE MUDOU E POR QUE. Antes: um botao "Novo", um formulario unico com um Select "tipo"
 * mostrando e escondendo campos, e depois de salvar a pessoa voltava para a LISTA e tinha de
 * descobrir sozinha qual botao abria o QR Code. Tres telas para fazer a unica coisa que ela
 * veio fazer.
 *
 * Agora: escolhe o caminho primeiro, cada caminho tem sua tela, e salvar leva direto para onde
 * falta agir.
 *
 * E o formulario do canal oficial passou a dizer, ANTES do botao, que o numero deixa de
 * funcionar no aplicativo do WhatsApp. Isso e irreversivel e surpreende — quem descobre depois
 * descobre tentando abrir o WhatsApp da empresa e nao conseguindo mais.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'canal-fluxo']);
    TenantContext::set($this->conta->id);

    $this->admin = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Admin',
        'email' => 'admin@canal.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->actingAs($this->admin);

    Http::fake(['*' => Http::response(['instance' => ['state' => 'close']], 200)]);
});

// ============================================================ escolher antes

it('um botao so, com as opcoes que existem hoje', function () {
    // Dois botoes lado a lado davam o mesmo peso a caminhos que nao tem o mesmo peso — e no
    // dia em que Instagram e Messenger entrarem seriam quatro disputando o cabecalho.
    $this->get(ChannelResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Novo canal')
        ->assertSee('WhatsApp por QR Code')
        ->assertSee('WhatsApp oficial');
});

it('o tipo escolhido chega preenchido no formulario', function () {
    // Sem isto a pessoa escolhe "QR Code" e cai num formulario perguntando de novo qual e o
    // tipo — o que faz a escolha anterior parecer que nao valeu nada.
    Livewire\Livewire::actingAs($this->admin)
        ->test(App\Filament\Resources\Channels\Pages\CreateChannel::class, ['tipo' => Channel::META_CLOUD])
        ->assertOk();

    $this->get(ChannelResource::getUrl('create').'?tipo='.Channel::EVOLUTION)
        ->assertSuccessful();
});

// ================================================================== o aviso

it('o formulario avisa que o numero sai do aplicativo do WhatsApp', function () {
    // O aviso mais importante do fluxo, e ele nao existia.
    $this->get(ChannelResource::getUrl('create').'?tipo='.Channel::META_CLOUD)
        ->assertSuccessful()
        ->assertSee('deixa de funcionar no aplicativo do WhatsApp', false);
});

it('o aviso NAO aparece no caminho do QR Code', function () {
    // La o numero continua no celular — e justamente por isso ele precisa ficar ligado.
    $this->get(ChannelResource::getUrl('create').'?tipo='.Channel::EVOLUTION)
        ->assertSuccessful()
        ->assertDontSee('deixa de funcionar no aplicativo do WhatsApp', false);
});

// ============================================================= a tela do QR

it('a tela de conectar tem endereco proprio', function () {
    // Com endereco proprio o link pode ser mandado para o cliente conectar o numero dele
    // sozinho, e recarregar a pagina nao perde o lugar.
    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Comercial',
        'tipo' => Channel::EVOLUTION, 'status' => 'close', 'instance_name' => 'cf1',
    ]);

    $this->get(ChannelResource::getUrl('conectar', ['record' => $canal]))
        ->assertSuccessful()
        ->assertSee('Conectar Comercial')
        ->assertSee('Aparelhos conectados');
});

it('a tela mostra os tres passos e onde a pessoa esta', function () {
    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'C', 'tipo' => Channel::EVOLUTION,
        'status' => 'close', 'instance_name' => 'cf2',
    ]);

    $this->get(ChannelResource::getUrl('conectar', ['record' => $canal]))
        ->assertSee('Canal criado')
        ->assertSee('Ler o QR Code')
        ->assertSee('Pronto');
});

it('canal ja conectado nao repete as instrucoes do celular', function () {
    // Instrucao para quem ja terminou e ruido.
    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'C', 'tipo' => Channel::EVOLUTION,
        'status' => 'open', 'instance_name' => 'cf3',
    ]);

    $this->get(ChannelResource::getUrl('conectar', ['record' => $canal]))
        ->assertSuccessful()
        ->assertDontSee('Aparelhos conectados');
});

it('canal de outra conta nao abre', function () {
    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-canal-fluxo']);
    $alheio = Channel::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'nome' => 'Alheio', 'tipo' => Channel::EVOLUTION,
        'status' => 'close', 'instance_name' => 'cf4',
    ]);

    $this->get(ChannelResource::getUrl('conectar', ['record' => $alheio]))
        ->assertNotFound();
});

// ======================================================= o caminho depois de criar

it('escolher QR Code cria o canal e abre o codigo, sem passar por formulario', function () {
    // A MUDANCA: pedir "nome do canal" antes do QR e pedir uma decisao que a pessoa ainda nao
    // tem como tomar — ela veio conectar um numero. O nome bom so aparece depois de conectar.
    expect(Channel::count())->toBe(0);

    Livewire\Livewire::actingAs($this->admin)
        ->test(App\Filament\Resources\Channels\Pages\ListChannels::class)
        ->callAction('evolution');

    $canal = Channel::firstOrFail();

    expect($canal->tipo)->toBe(Channel::EVOLUTION)
        ->and($canal->temNomeProvisorio())->toBeTrue()
        ->and($canal->instance_name)->not->toBeNull();
});

it('o canal nasce com nome provisorio, e o segundo nao repete o primeiro', function () {
    Channel::create([
        'tenant_id' => $this->conta->id, 'tipo' => Channel::EVOLUTION,
        'nome' => Channel::nomeProvisorio(),
    ]);

    expect(Channel::nomeProvisorio())->toBe('WhatsApp 2');
});

it('canal ja batizado nao e considerado provisorio', function () {
    // Quem ja deu nome ao canal nao pode ver o nome dele sumir sozinho quando conectar.
    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'tipo' => Channel::EVOLUTION,
        'nome' => 'Comercial', 'instance_name' => 'cfx',
    ]);

    expect($canal->temNomeProvisorio())->toBeFalse();
});

it('da para trocar o nome na propria tela do QR', function () {
    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'tipo' => Channel::EVOLUTION,
        'nome' => Channel::nomeProvisorio(), 'status' => 'open', 'instance_name' => 'cfn',
    ]);

    Livewire\Livewire::actingAs($this->admin)
        ->test(App\Filament\Resources\Channels\Pages\ConectarChannel::class, ['record' => $canal->id])
        ->set('nome', 'Comercial')
        ->call('salvarNome');

    expect($canal->fresh()->nome)->toBe('Comercial');
});

it('nome em branco nao apaga o que ja existe', function () {
    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'tipo' => Channel::EVOLUTION,
        'nome' => 'Comercial', 'status' => 'open', 'instance_name' => 'cfv',
    ]);

    Livewire\Livewire::actingAs($this->admin)
        ->test(App\Filament\Resources\Channels\Pages\ConectarChannel::class, ['record' => $canal->id])
        ->set('nome', '   ')
        ->call('salvarNome');

    expect($canal->fresh()->nome)->toBe('Comercial');
});

it('a lista leva para a tela do QR, e nao para um modal', function () {
    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'C', 'tipo' => Channel::EVOLUTION,
        'status' => 'close', 'instance_name' => 'cf5',
    ]);

    $this->get(ChannelResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee(ChannelResource::getUrl('conectar', ['record' => $canal]), false);
});

it('canal oficial nao mostra botao de QR: la nao existe QR', function () {
    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'status' => 'open', 'meta_phone_number_id' => '1', 'meta_waba_id' => '2', 'meta_token' => 't',
    ]);

    $this->get(ChannelResource::getUrl('index'))
        ->assertSuccessful()
        ->assertDontSee(ChannelResource::getUrl('conectar', ['record' => $canal]), false);
});
