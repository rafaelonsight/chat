<?php

use App\Filament\Resources\Channels\Pages\CreateChannel;
use App\Filament\Resources\Channels\Pages\EditChannel;
use App\Models\{Channel, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * A tela de canais conhecendo os dois tipos.
 *
 * O defeito que motivou isto: qualquer canal criado pela tela nascia da Evolution — nao
 * havia campo de tipo, e a pagina de criacao chamava a Evolution direto. O canal oficial
 * so existia porque foi criado por script.
 */

const RESPOSTA_META = [
    'display_phone_number' => '+1 555-672-5603',
    'verified_name'        => 'Test Number',
    'quality_rating'       => 'GREEN',
    'status'               => 'CONNECTED',
    'id'                   => '1235849066282498',
];

beforeEach(function () {
    config(['services.meta.token' => 'EAA-token-do-env', 'services.meta.versao' => 'v23.0']);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'tela']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@tela.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    // Sem painel definido, a pagina do Filament nao monta e o erro sai como
    // "getDefaultTestingSchemaName() on null" — que nao diz nada sobre a causa.
    \Filament\Facades\Filament::setCurrentPanel('admin');

    // TODA a fiacao de rede deste arquivo mora aqui, e o padrao ESPECIFICO vem primeiro.
    // Motivo: Http::fake chamado duas vezes nao substitui — a primeira definicao vence. Um
    // segundo fake dentro do teste e silenciosamente ignorado, e o sintoma e um teste que
    // passa por engano. Ja me custou uma investigacao inteira.
    Http::fake([
        'graph.facebook.com/*id-errado*' => Http::response(
            ['error' => ['message' => 'Unsupported get request']], 400
        ),
        'graph.facebook.com/*'           => Http::response(RESPOSTA_META),
        '*'                              => Http::response(['ok' => true]),
    ]);
});

afterEach(fn () => TenantContext::forget());

it('cria canal oficial pela tela, sem tocar na Evolution', function () {
    Livewire::actingAs($this->usuario)
        ->test(CreateChannel::class)
        ->fillForm([
            'tipo'                 => Channel::META_CLOUD,
            'nome'                 => 'Oficial do cliente',
            'meta_phone_number_id' => '1235849066282498',
            'meta_waba_id'         => '3620023178150458',
            'meta_token'           => 'EAA-token-do-cliente',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $canal = Channel::where('nome', 'Oficial do cliente')->firstOrFail();

    expect($canal->tipo)->toBe(Channel::META_CLOUD)
        ->and($canal->meta_phone_number_id)->toBe('1235849066282498')
        ->and($canal->meta_token)->toBe('EAA-token-do-cliente');

    // Nenhuma chamada fora da Meta: era isso que criava instancia inutil na Evolution.
    Http::assertNotSent(fn ($r) => ! str_contains($r->url(), 'graph.facebook.com'));
});

it('confere na Meta ao cadastrar, e o canal ja nasce operando', function () {
    // Sem isto o diagnostico contaria o canal novo como desconectado e gritaria CRITICO
    // para sempre — no oficial nao ha QR Code para "conectar" depois.
    Livewire::actingAs($this->usuario)
        ->test(CreateChannel::class)
        ->fillForm([
            'tipo' => Channel::META_CLOUD, 'nome' => 'Oficial',
            'meta_phone_number_id' => '1235849066282498', 'meta_waba_id' => '362',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $canal = Channel::where('nome', 'Oficial')->firstOrFail();

    expect($canal->status)->toBe('open')
        ->and($canal->conectado_em)->not->toBeNull()
        ->and($canal->ultimo_erro)->toBeNull()
        // o numero vem da propria Meta quando nao foi informado
        ->and($canal->telefone_e164)->toBe('+15556725603');
});

it('quando a Meta recusa, o canal guarda o motivo e nao se diz conectado', function () {
    // O 'id-errado' e o que faz a Meta recusar: o stub dele esta no beforeEach.
    Livewire::actingAs($this->usuario)
        ->test(CreateChannel::class)
        ->fillForm([
            'tipo' => Channel::META_CLOUD, 'nome' => 'Oficial torto',
            'meta_phone_number_id' => 'id-errado', 'meta_waba_id' => '362',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $canal = Channel::where('nome', 'Oficial torto')->firstOrFail();

    // O canal existe — erro de configuracao nao apaga trabalho —, mas nao mente.
    expect($canal->ultimo_erro)->not->toBeNull()
        ->and($canal->status)->not->toBe('open');
});

it('exige Phone Number ID e WABA ID no canal oficial', function () {
    // Canal oficial sem esses ids nao envia nada, e a falha apareceria como 404 da Meta
    // muito depois. Erro de configuracao tem de aparecer no cadastro.
    Livewire::actingAs($this->usuario)
        ->test(CreateChannel::class)
        ->fillForm(['tipo' => Channel::META_CLOUD, 'nome' => 'Sem ids'])
        ->call('create')
        ->assertHasFormErrors(['meta_phone_number_id', 'meta_waba_id']);
});

it('canal da Evolution continua sem exigir os campos da Meta', function () {
    Livewire::actingAs($this->usuario)
        ->test(CreateChannel::class)
        ->fillForm(['tipo' => Channel::EVOLUTION, 'nome' => 'Pessoal'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Channel::where('nome', 'Pessoal')->firstOrFail()->tipo)->toBe(Channel::EVOLUTION);
});

it('o token do cliente nao vai para o navegador na tela de edicao', function () {
    // Se o token fosse devolvido ao formulario, estaria no HTML da pagina, no cache do
    // navegador e em qualquer print de tela que o cliente mandasse para o suporte.
    $canal = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => '111', 'meta_waba_id' => '222',
        'meta_token' => 'EAA-token-secreto-do-cliente',
    ])->refresh();

    Livewire::actingAs($this->usuario)
        ->test(EditChannel::class, ['record' => $canal->getKey()])
        ->assertDontSee('EAA-token-secreto-do-cliente');
});

it('salvar a edicao com o token em branco NAO apaga a credencial', function () {
    // Apagar a credencial de um cliente por descuido derrubaria o atendimento dele, e o
    // sintoma seria "parou de enviar" — sem ninguem ligar a causa a esta tela.
    $canal = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => '111', 'meta_waba_id' => '222',
        'meta_token' => 'EAA-token-do-cliente',
    ])->refresh();

    Livewire::actingAs($this->usuario)
        ->test(EditChannel::class, ['record' => $canal->getKey()])
        ->fillForm(['nome' => 'Oficial renomeado'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($canal->fresh()->nome)->toBe('Oficial renomeado')
        ->and($canal->fresh()->meta_token)->toBe('EAA-token-do-cliente');
});
