<?php

use App\Filament\Revenda\Pages\Clientes as Tela;
use App\Models\{Channel, Tenant, User};
use App\Notifications\ConviteDeAcesso;
use App\Services\CriarCliente;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Cadastrar cliente pela tela.
 *
 * Ate aqui, cliente novo nascia no banco pela minha mao. Isso nao e conforto: e o que impede
 * o Rafael de vender sem me chamar. Produto que so o desenvolvedor consegue provisionar nao e
 * produto, e servico com o desenvolvedor dentro.
 *
 * DUAS ARMADILHAS DE MULTI-INQUILINO GOVERNAM ESTE ARQUIVO, as duas vindas do mesmo fato:
 * User usa BelongsToTenant.
 *
 * 1. Ao CRIAR: o hook do trait preenche tenant_id com o tenant de quem esta logado quando o
 *    campo vem vazio. Quem esta logado e o operador. Sem tenant_id explicito, o usuario do
 *    cliente novo nasce DENTRO DA CONTA DA CASA — enxergando as conversas dela.
 * 2. Ao LER: o escopo global filtra pelo tenant de quem esta logado. Contar usuarios e canais
 *    de outro cliente devolve zero, e a lista mentiria dizendo que todo cliente esta vazio.
 *
 * A primeira e vazamento entre clientes. A segunda e numero errado na tela. As duas tem teste.
 */

beforeEach(function () {
    // 'array' e o transporte que engole a mensagem sem rede: nenhum teste manda e-mail de
    // verdade por acidente. O teste que PRECISA de falha real troca isto explicitamente.
    config(['mail.default' => 'array']);

    // A conta da casa, onde o operador vive.
    $this->casa = Tenant::create(['nome' => 'Casa', 'slug' => 'casa']);
    TenantContext::set($this->casa->id);

    $this->operador = User::create([
        'tenant_id' => $this->casa->id, 'name' => 'Dono',
        'email' => 'dono@casa.test', 'password' => 'segredo123', 'admin' => true,
    ]);
    // forceFill porque 'operador' NAO e preenchivel em massa — ver o teste logo abaixo.
    $this->operador->forceFill(['operador' => true])->save();

    // Administrador de um cliente: manda na conta DELE, e nada alem dela.
    $this->adminDeCliente = User::create([
        'tenant_id' => $this->casa->id, 'name' => 'Admin',
        'email' => 'admin@casa.test', 'password' => 'segredo123', 'admin' => true,
    ]);
});

function dadosDeCliente(array $troca = []): array
{
    return array_merge([
        'nome'              => 'Padaria Aurora',
        'documento'         => '',
        'email'             => 'contato@aurora.test',
        'telefone'          => '41999990000',
        'fuso_horario'      => 'America/Sao_Paulo',
        'responsavel_nome'  => 'Marina',
        'responsavel_email' => 'marina@aurora.test',
    ], $troca);
}

// ---------------------------------------------------------------- quem entra

it('nao concede operador por atribuicao em massa', function () {
    // 'operador' fica FORA do fillable de proposito. E a chave da casa: se ela puder ser
    // preenchida em massa, basta um formulario futuro aceitar o campo — ou um payload com um
    // par a mais — para um administrador de cliente virar dono do produto. Concede-se pelo
    // comando de console, que exige acesso ao servidor.
    $u = User::create([
        'tenant_id' => $this->casa->id, 'name' => 'Esperto',
        'email' => 'esperto@casa.test', 'password' => 'segredo123',
        'admin' => true, 'operador' => true,
    ]);

    expect($u->fresh()->operador)->toBeFalse();
});

it('esconde a tela de quem nao e operador, mesmo sendo administrador', function () {
    $this->actingAs($this->adminDeCliente);

    expect(Tela::canAccess())->toBeFalse();
});

it('nega o acesso direto pela URL a quem nao e operador', function () {
    // A tela mora no painel 'revenda' (admin.virtus.chat), dominio proprio — e nao mais
    // em /admin/clientes.
    $this->actingAs($this->adminDeCliente)
        ->get('http://admin.virtus.chat/clientes')
        ->assertForbidden();
});

it('abre para o operador', function () {
    $this->actingAs($this->operador);

    expect(Tela::canAccess())->toBeTrue();

    Livewire::actingAs($this->operador)->test(Tela::class)->assertOk();
});

it('concede e revoga operador pelo comando de console', function () {
    $this->artisan('onchat:operador', ['email' => 'admin@casa.test'])->assertSuccessful();
    expect($this->adminDeCliente->fresh()->operador)->toBeTrue();

    $this->artisan('onchat:operador', ['email' => 'admin@casa.test', '--remover' => true])
        ->assertSuccessful();
    expect($this->adminDeCliente->fresh()->operador)->toBeFalse();
});

it('reclama quando o comando recebe e-mail que nao existe', function () {
    $this->artisan('onchat:operador', ['email' => 'ninguem@lugar.test'])->assertFailed();
});

// ------------------------------------------------------------ criar de fato

it('cria o cliente e o primeiro usuario dele', function () {
    $this->actingAs($this->operador);

    $r = app(CriarCliente::class)->criar(dadosDeCliente());

    expect($r['cliente']->nome)->toBe('Padaria Aurora')
        ->and($r['cliente']->slug)->toBe('padaria-aurora')
        ->and($r['cliente']->email)->toBe('contato@aurora.test')
        ->and($r['responsavel']->name)->toBe('Marina')
        ->and($r['responsavel']->admin)->toBeTrue()
        ->and($r['responsavel']->operador)->toBeFalse();
});

it('poe o usuario no tenant NOVO, e nao no do operador', function () {
    // Armadilha 1. Sem tenant_id explicito no create, o hook do BelongsToTenant usaria o
    // tenant do operador e a Marina entraria na conta da casa.
    $this->actingAs($this->operador);

    $r = app(CriarCliente::class)->criar(dadosDeCliente());

    expect($r['responsavel']->tenant_id)->toBe($r['cliente']->id)
        ->and($r['responsavel']->tenant_id)->not->toBe($this->casa->id);

    $naCasa = User::withoutGlobalScope('tenant')
        ->where('tenant_id', $this->casa->id)->pluck('email')->all();

    expect($naCasa)->not->toContain('marina@aurora.test');
});

it('nasce com senha aleatoria, nunca com uma senha padrao', function () {
    $this->actingAs($this->operador);

    $a = app(CriarCliente::class)->criar(dadosDeCliente());
    $b = app(CriarCliente::class)->criar(dadosDeCliente([
        'nome' => 'Outra', 'responsavel_email' => 'outra@aurora.test',
    ]));

    expect($a['responsavel']->password)->not->toBe($b['responsavel']->password);
});

it('desempata o slug quando dois clientes tem o mesmo nome', function () {
    $this->actingAs($this->operador);

    $a = app(CriarCliente::class)->criar(dadosDeCliente());
    $b = app(CriarCliente::class)->criar(dadosDeCliente(['responsavel_email' => 'b@aurora.test']));

    expect($a['cliente']->slug)->toBe('padaria-aurora')
        ->and($b['cliente']->slug)->toBe('padaria-aurora-2');
});

it('guarda o e-mail do responsavel em minusculas e sem espaco', function () {
    $this->actingAs($this->operador);

    $r = app(CriarCliente::class)->criar(dadosDeCliente([
        'responsavel_email' => '  Marina@Aurora.TEST ',
    ]));

    expect($r['responsavel']->email)->toBe('marina@aurora.test');
});

// ----------------------------------------------------------------- o convite

it('manda o convite e devolve o link', function () {
    Notification::fake();
    $this->actingAs($this->operador);

    $r = app(CriarCliente::class)->criar(dadosDeCliente());

    expect($r['email_enviado'])->toBeTrue()
        ->and($r['link'])->toContain('password-reset');

    Notification::assertSentTo($r['responsavel'], ConviteDeAcesso::class);
});

it('devolve o link mesmo quando o envio de e-mail falha', function () {
    // O ponto inteiro. Se o e-mail cair, o cliente nao pode ficar sem acesso: o link aparece
    // na tela e o Rafael manda por WhatsApp. Falha de e-mail atrasa, nao trava.
    //
    // Sem Notification::fake aqui de proposito: o objetivo e a excecao REAL do transporte.
    config([
        'mail.default'             => 'smtp',
        'mail.mailers.smtp.host'   => '127.0.0.1',
        'mail.mailers.smtp.port'   => 2,      // ninguem escuta nesta porta: recusa imediata
        'mail.mailers.smtp.scheme' => 'smtp',
    ]);

    $this->actingAs($this->operador);

    $r = app(CriarCliente::class)->criar(dadosDeCliente());

    expect($r['email_enviado'])->toBeFalse()
        ->and($r['falha_de_email'])->not->toBeNull()
        ->and($r['link'])->toContain('password-reset');

    // E, apesar da falha, o cliente EXISTE e o usuario dele tambem.
    expect(Tenant::where('slug', 'padaria-aurora')->exists())->toBeTrue()
        ->and(User::withoutGlobalScope('tenant')->where('email', 'marina@aurora.test')->exists())
        ->toBeTrue();
});

it('reenvia o convite para quem ja existe', function () {
    Notification::fake();
    $this->actingAs($this->operador);

    $r = app(CriarCliente::class)->criar(dadosDeCliente());
    $novo = app(CriarCliente::class)->convidar($r['responsavel']);

    expect($novo['link'])->toContain('password-reset');

    Notification::assertSentToTimes($r['responsavel'], ConviteDeAcesso::class, 2);
});

it('reenvia pela tela para o responsavel de outro cliente', function () {
    Notification::fake();
    $this->actingAs($this->operador);

    $r = app(CriarCliente::class)->criar(dadosDeCliente());

    Livewire::actingAs($this->operador)->test(Tela::class)
        ->call('reenviar', $r['cliente']->id)
        ->assertSee('password-reset');

    Notification::assertSentToTimes($r['responsavel'], ConviteDeAcesso::class, 2);
});

// -------------------------------------------------------------------- a lista

it('conta usuarios e canais de outro cliente apesar do escopo global', function () {
    // Armadilha 2. Sem withoutGlobalScope tudo daria zero, e a lista diria que o cliente
    // esta vazio quando nao esta.
    $this->actingAs($this->operador);

    $r = app(CriarCliente::class)->criar(dadosDeCliente());

    Channel::withoutGlobalScope('tenant')->create([
        'tenant_id' => $r['cliente']->id, 'nome' => 'Canal', 'tipo' => 'meta_cloud',
    ]);

    $linha = Livewire::actingAs($this->operador)->test(Tela::class)
        ->instance()->clientes()->firstWhere('id', $r['cliente']->id);

    expect($linha['usuarios'])->toBe(1)
        ->and($linha['canais'])->toBe(1);
});

it('marca qual da lista e a conta da casa', function () {
    $this->actingAs($this->operador);

    app(CriarCliente::class)->criar(dadosDeCliente());

    $lista = Livewire::actingAs($this->operador)->test(Tela::class)->instance()->clientes();

    expect($lista->firstWhere('id', $this->casa->id)['eu'])->toBeTrue()
        ->and($lista->firstWhere('nome', 'Padaria Aurora')['eu'])->toBeFalse();
});

// --------------------------------------------------------------------- a tela

it('cria pela tela e mostra o link do convite', function () {
    Notification::fake();

    Livewire::actingAs($this->operador)->test(Tela::class)
        ->set('nome', 'Clinica Sol')
        ->set('email', 'contato@sol.test')
        ->set('responsavel_nome', 'Joana')
        ->set('responsavel_email', 'joana@sol.test')
        ->call('criar')
        ->assertHasNoErrors()
        ->assertSet('clienteCriado', 'Clinica Sol')
        ->assertSee('password-reset');

    expect(Tenant::where('slug', 'clinica-sol')->exists())->toBeTrue();
});

it('recusa e-mail de responsavel ja em uso, em qualquer cliente', function () {
    // users.email e unico globalmente porque o login e global. Deixar a tela tentar e
    // estourar erro de banco daria tela branca no lugar de mensagem.
    Livewire::actingAs($this->operador)->test(Tela::class)
        ->set('nome', 'Outro')
        ->set('responsavel_nome', 'Alguem')
        ->set('responsavel_email', 'admin@casa.test')   // ja existe, em outro tenant
        ->call('criar')
        ->assertHasErrors(['responsavel_email']);

    expect(Tenant::where('slug', 'outro')->exists())->toBeFalse();
});

it('exige nome do cliente e do responsavel', function () {
    Livewire::actingAs($this->operador)->test(Tela::class)
        ->call('criar')
        ->assertHasErrors(['nome', 'responsavel_nome', 'responsavel_email']);
});

it('recusa CNPJ que nao fecha nos verificadores', function () {
    Livewire::actingAs($this->operador)->test(Tela::class)
        ->set('nome', 'Teste')
        ->set('documento', '11111111111111')
        ->set('responsavel_nome', 'Alguem')
        ->set('responsavel_email', 'alguem@teste.test')
        ->call('criar')
        ->assertHasErrors(['documento']);
});

it('limpa o formulario depois de criar, para o proximo cadastro nao herdar o anterior', function () {
    Notification::fake();

    Livewire::actingAs($this->operador)->test(Tela::class)
        ->set('nome', 'Primeiro')
        ->set('responsavel_nome', 'A')
        ->set('responsavel_email', 'a@t.test')
        ->call('criar')
        ->assertSet('nome', '')
        ->assertSet('responsavel_email', '');
});
