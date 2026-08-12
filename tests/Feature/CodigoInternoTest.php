<?php

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Offerings\Pages\ListOfferings;
use App\Models\Contact;
use App\Models\Offering;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'Onsight', 'slug' => 'cod']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@cod.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

/**
 * Onde mora o estado do formulario que abre em modal.
 *
 * OS TESTES MEXEM CAMPO POR CAMPO, e nao com setActionData(): nesta versao do Filament o
 * setActionData nao chega ao estado — conferido com sonda — e o teste passaria a medir nada,
 * como aconteceu na primeira escrita deste arquivo. Campo por campo tambem e mais fiel: e o
 * que o navegador faz, e e o que dispara os ganchos de campo vivo.
 */
function campoDaOferta(string $nome): string
{
    return 'mountedActions.0.data.'.$nome;
}

// ==================================================== a conta do proximo

it('comeca em S-0001 e P-0001, e cada tipo tem a sua sequencia', function () {
    expect(Offering::proximoCodigo(Offering::SERVICO))->toBe('S-0001')
        ->and(Offering::proximoCodigo(Offering::PRODUTO))->toBe('P-0001');

    Offering::create(['nome' => 'Implantação', 'tipo' => Offering::SERVICO, 'codigo' => 'S-0001']);
    Offering::create(['nome' => 'Consultoria', 'tipo' => Offering::SERVICO, 'codigo' => 'S-0002']);

    // O produto nao herda a contagem do servico: sao duas sequencias.
    expect(Offering::proximoCodigo(Offering::SERVICO))->toBe('S-0003')
        ->and(Offering::proximoCodigo(Offering::PRODUTO))->toBe('P-0001');
});

it('codigo digitado a mao nao desarruma a conta', function () {
    // 'S-ANTIGO' ordena depois de 'S-0009' no texto. Se entrasse na conta, o proximo daria
    // 1 e bateria num codigo que ja existe.
    Offering::create(['nome' => 'Antigo', 'tipo' => Offering::SERVICO, 'codigo' => 'S-ANTIGO']);
    Offering::create(['nome' => 'Nono', 'tipo' => Offering::SERVICO, 'codigo' => 'S-0009']);
    Offering::create(['nome' => 'Sem codigo', 'tipo' => Offering::SERVICO]);

    expect(Offering::proximoCodigo(Offering::SERVICO))->toBe('S-0010');
});

it('passa de S-0009 para S-0010 sem voltar para tras', function () {
    // Ordenacao por texto puro colocaria 'S-0009' na frente de 'S-0010': por isso a ordem
    // e por tamanho primeiro.
    foreach (range(1, 9) as $n) {
        Offering::create([
            'nome' => 'Item '.$n, 'tipo' => Offering::SERVICO,
            'codigo' => 'S-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        ]);
    }

    expect(Offering::proximoCodigo(Offering::SERVICO))->toBe('S-0010');
});

it('a sequencia e de cada conta, e nao do sistema', function () {
    Offering::create(['nome' => 'Implantação', 'tipo' => Offering::SERVICO, 'codigo' => 'S-0001']);

    $outra = Tenant::create(['nome' => 'VEX', 'slug' => 'cod2']);

    // O S-0001 da Onsight nada tem com o da VEX: a conta nova comeca do comeco.
    expect(Offering::proximoCodigo(Offering::SERVICO, $outra->id))->toBe('S-0001');
});

// ======================================================== na tela

it('o cadastro ja abre com o codigo sugerido', function () {
    Livewire::actingAs($this->usuario)
        ->test(ListOfferings::class)
        ->mountAction('create')
        ->assertSet(campoDaOferta('codigo'), 'S-0001');
});

it('trocar para produto troca a sugestao', function () {
    Livewire::actingAs($this->usuario)
        ->test(ListOfferings::class)
        ->mountAction('create')
        ->assertSet(campoDaOferta('codigo'), 'S-0001')
        ->set(campoDaOferta('tipo'), Offering::PRODUTO)
        ->assertSet(campoDaOferta('codigo'), 'P-0001')
        // E voltar volta.
        ->set(campoDaOferta('tipo'), Offering::SERVICO)
        ->assertSet(campoDaOferta('codigo'), 'S-0001');
});

it('codigo digitado a mao sobrevive a troca de tipo', function () {
    // Sobrescrever aqui apagaria o que a pessoa escreveu para acertar um detalhe nosso.
    Livewire::actingAs($this->usuario)
        ->test(ListOfferings::class)
        ->mountAction('create')
        ->set(campoDaOferta('codigo'), 'PLAT-01')
        ->set(campoDaOferta('tipo'), Offering::PRODUTO)
        ->assertSet(campoDaOferta('codigo'), 'PLAT-01');
});

it('salva com o codigo sugerido, e o proximo cadastro sugere o seguinte', function () {
    Livewire::actingAs($this->usuario)
        ->test(ListOfferings::class)
        ->mountAction('create')
        ->set(campoDaOferta('nome'), 'Implantação')
        ->set(campoDaOferta('preco'), 3100)
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Offering::where('nome', 'Implantação')->value('codigo'))->toBe('S-0001');

    Livewire::actingAs($this->usuario)
        ->test(ListOfferings::class)
        ->mountAction('create')
        ->assertSet(campoDaOferta('codigo'), 'S-0002');
});

it('codigo vazio continua valendo: nem todo mundo usa codigo', function () {
    Livewire::actingAs($this->usuario)
        ->test(ListOfferings::class)
        ->mountAction('create')
        ->set(campoDaOferta('nome'), 'Hora avulsa')
        ->set(campoDaOferta('preco'), 250)
        ->set(campoDaOferta('codigo'), null)
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Offering::where('nome', 'Hora avulsa')->value('codigo'))->toBeNull();
});

it('codigo repetido na mesma conta avisa no campo, e nao derruba a tela', function () {
    Offering::create(['nome' => 'Implantação', 'tipo' => Offering::SERVICO, 'codigo' => 'S-0001']);

    Livewire::actingAs($this->usuario)
        ->test(ListOfferings::class)
        ->mountAction('create')
        ->set(campoDaOferta('nome'), 'Outra')
        ->set(campoDaOferta('preco'), 100)
        ->set(campoDaOferta('codigo'), 'S-0001')
        ->callMountedAction()
        ->assertHasActionErrors(['codigo']);
});

// ============================ repetido e sempre dentro da conta, nunca fora

it('o mesmo codigo em outra conta passa', function () {
    // A restricao do banco e (tenant_id, codigo). Se a conferencia da tela olhasse a tabela
    // inteira, a segunda empresa nao conseguiria usar o proprio S-0001 — e o erro apontaria
    // para uma ficha que ela nao pode nem ver.
    $outra = Tenant::create(['nome' => 'VEX', 'slug' => 'cod3']);

    TenantContext::runAs($outra->id, fn () => Offering::create([
        'nome' => 'Implantação da VEX', 'tipo' => Offering::SERVICO, 'codigo' => 'S-0001',
    ]));

    Livewire::actingAs($this->usuario)
        ->test(ListOfferings::class)
        ->mountAction('create')
        ->set(campoDaOferta('nome'), 'Implantação')
        ->set(campoDaOferta('preco'), 3100)
        ->set(campoDaOferta('codigo'), 'S-0001')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Offering::where('codigo', 'S-0001')->count())->toBe(1)
        ->and(Offering::withoutGlobalScope('tenant')->where('codigo', 'S-0001')->count())->toBe(2);
});

it('o mesmo telefone em outra conta passa', function () {
    // Mesma armadilha, no cadastro de pessoas: o cliente pode ser cliente das duas empresas
    // que usam o sistema, e o numero dele e o mesmo nos dois lugares.
    Http::fake();

    $outra = Tenant::create(['nome' => 'VEX', 'slug' => 'cod4']);

    TenantContext::runAs($outra->id, fn () => Contact::create([
        'nome' => 'Cliente das duas', 'telefone_e164' => '+5584996143373',
        'jid' => '5584996143373@s.whatsapp.net',
    ]));

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.nome', 'Cliente das duas')
        ->set('data.telefone_e164', '(84) 99614-3373')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contact::withoutGlobalScope('tenant')->where('telefone_e164', '+5584996143373')->count())
        ->toBe(2);
});

it('o mesmo telefone na mesma conta avisa no campo, e nao derruba a tela', function () {
    /*
     * ESTE TESTE ACHOU UM ERRO ANTIGO. O ->unique() do Filament comparava '(84) 99614-3373'
     * com '+5584996143373' e nunca casava: o repetido passava pela validacao e ia bater na
     * restricao do banco, que responde com erro 500. Quem cadastrava via a tela quebrar sem
     * saber que o numero ja existia.
     */
    Http::fake();

    Contact::create([
        'nome' => 'Ja existe', 'telefone_e164' => '+5584996143373',
        'jid' => '5584996143373@s.whatsapp.net',
    ]);

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.nome', 'De novo')
        // Escrito como se digita, e nao como o banco guarda: e assim que o erro aparecia.
        ->set('data.telefone_e164', '(84) 99614-3373')
        ->call('create')
        ->assertHasFormErrors(['telefone_e164']);

    expect(Contact::count())->toBe(1);
});

it('o mesmo telefone sem o nono digito tambem conta como repetido', function () {
    // O mesmo numero chega com e sem o nono digito dependendo de onde veio. Duas fichas para
    // a mesma linha partem o historico da conversa em duas.
    Http::fake();

    Contact::create([
        'nome' => 'Ja existe', 'telefone_e164' => '+5584996143373',
        'jid' => '5584996143373@s.whatsapp.net',
    ]);

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.nome', 'De novo')
        ->set('data.telefone_e164', '(84) 9614-3373')
        ->call('create')
        ->assertHasFormErrors(['telefone_e164']);

    expect(Contact::count())->toBe(1);
});
