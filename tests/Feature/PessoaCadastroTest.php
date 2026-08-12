<?php

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Documento;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'pes']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@pes.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

/** Resposta da BrasilAPI para a ficha de pessoa. Nome proprio para nao colidir com CadastroCnpjTest. */
function respostaReceitaDaPessoa(array $sobrescreve = []): array
{
    return array_merge([
        'cnpj' => '19131243000197',
        'razao_social' => 'ONSIGHT TELECOM LTDA',
        'nome_fantasia' => 'ONSIGHT',
        'email' => 'contato@onsight.test',
        'ddd_telefone_1' => '8433334444',
        'cep' => '59020000',
        'descricao_tipo_de_logradouro' => 'AVENIDA',
        'logradouro' => 'HERMES DA FONSECA',
        'numero' => '384',
        'complemento' => 'SALA 5',
        'bairro' => 'PETROPOLIS',
        'municipio' => 'NATAL',
        'uf' => 'RN',
        'descricao_situacao_cadastral' => 'ATIVA',
        'porte' => 'DEMAIS',
        'data_inicio_atividade' => '2014-01-15',
    ], $sobrescreve);
}

// =========================================================== o documento

it('conhece CPF e CNPJ pelos digitos verificadores', function () {
    expect(Documento::valido('529.982.247-25'))->toBeTrue()
        ->and(Documento::valido('111.444.777-35'))->toBeTrue()
        ->and(Documento::valido('19.131.243/0001-97'))->toBeTrue()
        // Um digito trocado em cada um: e exatamente o erro que passa sem conferencia.
        ->and(Documento::valido('52998224726'))->toBeFalse()
        ->and(Documento::valido('19131243000198'))->toBeFalse()
        // Sequencia repetida fecha a conta e nao existe.
        ->and(Documento::valido('11111111111'))->toBeFalse()
        ->and(Documento::valido('11111111111111'))->toBeFalse()
        // Nem CPF nem CNPJ: quantidade de digitos que nao e documento nenhum.
        ->and(Documento::valido('1234567'))->toBeFalse();
});

it('documento em branco nao e documento invalido', function () {
    // Quem exige o documento e a tela, nao a conta dos digitos: um cliente do WhatsApp
    // entra sem CPF, e isso nao pode virar erro de validacao.
    expect(Documento::valido(null))->toBeTrue()
        ->and(Documento::valido(''))->toBeTrue()
        ->and(Documento::valido('   '))->toBeTrue();
});

it('mostra pontuado o que guarda em digitos', function () {
    expect(Documento::formatar('52998224725'))->toBe('529.982.247-25')
        ->and(Documento::formatar('19131243000197'))->toBe('19.131.243/0001-97')
        // Meio caminho digitado volta como veio: some-lo assustaria mais que ajudaria.
        ->and(Documento::formatar('5299822'))->toBe('5299822')
        ->and(Documento::formatar(null))->toBeNull();
});

// ================================================== o preenchimento pelo CNPJ

it('o CNPJ preenche razao social, fantasia, contato e endereco', function () {
    Http::fake(['*' => Http::response(respostaReceitaDaPessoa())]);

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.natureza', Contact::JURIDICA)
        ->set('data.documento', '19.131.243/0001-97')
        ->assertSet('data.razao_social', 'ONSIGHT TELECOM LTDA')
        ->assertSet('data.nome_fantasia', 'ONSIGHT')
        ->assertSet('data.email', 'contato@onsight.test')
        ->assertSet('data.telefone_e164', '(84) 3333-4444')
        ->assertSet('data.logradouro', 'AVENIDA HERMES DA FONSECA')
        ->assertSet('data.numero', '384')
        ->assertSet('data.bairro', 'PETROPOLIS')
        ->assertSet('data.cidade', 'NATAL')
        ->assertSet('data.uf', 'RN');
});

it('o que ja estava escrito sobrevive a consulta da Receita', function () {
    // O telefone da Receita costuma ser o da abertura da empresa. Quem digitou o numero
    // certo antes de lembrar do CNPJ nao pode ver o proprio dado ser trocado.
    Http::fake(['*' => Http::response(respostaReceitaDaPessoa())]);

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.natureza', Contact::JURIDICA)
        ->set('data.telefone_e164', '(84) 99614-3373')
        ->set('data.nome_fantasia', 'Onsight Fibra')
        ->set('data.documento', '19131243000197')
        ->assertSet('data.telefone_e164', '(84) 99614-3373')
        ->assertSet('data.nome_fantasia', 'Onsight Fibra')
        // O que estava vazio, sim, foi preenchido.
        ->assertSet('data.razao_social', 'ONSIGHT TELECOM LTDA');
});

it('pessoa fisica nao gasta chamada de rede com o documento', function () {
    Http::fake();

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.natureza', Contact::FISICA)
        ->set('data.documento', '529.982.247-25')
        ->assertSet('data.razao_social', null);

    Http::assertNothingSent();
});

it('CNPJ pela metade nao dispara consulta', function () {
    Http::fake();

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.natureza', Contact::JURIDICA)
        ->set('data.documento', '19131243')
        ->assertSet('data.razao_social', null);

    Http::assertNothingSent();
});

it('avisa quando o CNPJ ja tem ficha, em vez de deixar nascer a segunda', function () {
    Http::fake(['*' => Http::response(respostaReceitaDaPessoa())]);

    Contact::create([
        'nome' => 'Onsight', 'natureza' => Contact::JURIDICA,
        'documento' => '19131243000197', 'telefone_e164' => '+5584333344444',
        'jid' => '5584333344444@s.whatsapp.net',
    ]);

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.natureza', Contact::JURIDICA)
        ->set('data.documento', '19131243000197')
        ->assertNotified('Esse CNPJ já está cadastrado');
});

// ============================================================ o que salva

it('salva a pessoa juridica com papeis e documento so em digitos', function () {
    Http::fake(['*' => Http::response(respostaReceitaDaPessoa())]);

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->fillForm([
            'natureza' => Contact::JURIDICA,
            'documento' => '19.131.243/0001-97',
            'razao_social' => 'ONSIGHT TELECOM LTDA',
            'nome_fantasia' => 'Onsight',
            'telefone_e164' => '(84) 3333-4444',
            'papeis' => ['cliente', 'fornecedor'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $pessoa = Contact::where('documento', '19131243000197')->firstOrFail();

    expect($pessoa->natureza)->toBe(Contact::JURIDICA)
        ->and($pessoa->ehJuridica())->toBeTrue()
        ->and($pessoa->papeis)->toBe(['cliente', 'fornecedor'])
        ->and($pessoa->papeisPorExtenso())->toBe(['Cliente', 'Fornecedor'])
        // Sem 'nome' digitado, quem aparece na tela e a fantasia.
        ->and($pessoa->nomeExibicao())->toBe('Onsight');
});

it('cadastra fornecedor sem WhatsApp: documento no lugar do telefone', function () {
    Http::fake();

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->fillForm([
            'natureza' => Contact::FISICA,
            'nome' => 'Seu Zé do Cabo',
            'documento' => '529.982.247-25',
            'papeis' => ['fornecedor'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $pessoa = Contact::where('documento', '52998224725')->firstOrFail();

    expect($pessoa->telefone_e164)->toBeNull()
        // Sem WhatsApp nao ha jid — e nulo e melhor que um jid inventado.
        ->and($pessoa->jid)->toBeNull()
        ->and($pessoa->nomeExibicao())->toBe('Seu Zé do Cabo');
});

it('sem telefone e sem documento a ficha nao nasce', function () {
    Http::fake();

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->fillForm(['natureza' => Contact::FISICA, 'nome' => 'Alguém'])
        ->call('create')
        ->assertHasFormErrors(['telefone_e164']);
});

it('recusa CPF com digito trocado', function () {
    Http::fake();

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->fillForm([
            'natureza' => Contact::FISICA,
            'nome' => 'Rafael',
            'telefone_e164' => '(84) 99614-3373',
            'documento' => '529.982.247-26',
        ])
        ->call('create')
        ->assertHasFormErrors(['documento']);
});

// ====================================================== quem aparece como quem

it('nomeExibicao prefere o nome digitado, depois a fantasia, depois a razao social', function () {
    $comNome = new Contact([
        'nome' => 'Ótica Central', 'nome_fantasia' => 'Central', 'razao_social' => 'COMERCIO LTDA',
    ]);
    $soFantasia = new Contact(['nome_fantasia' => 'Central', 'razao_social' => 'COMERCIO LTDA']);
    $soRazao = new Contact(['razao_social' => 'COMERCIO DE OCULOS CENTRAL LTDA']);

    expect($comNome->nomeExibicao())->toBe('Ótica Central')
        ->and($soFantasia->nomeExibicao())->toBe('Central')
        ->and($soRazao->nomeExibicao())->toBe('COMERCIO DE OCULOS CENTRAL LTDA');
});

// ============================================================== a lista

it('filtra por papel sem perder quem tem dois', function () {
    $cliente = Contact::create([
        'nome' => 'Só cliente', 'papeis' => ['cliente'],
        'telefone_e164' => '+5584900000001', 'jid' => '5584900000001@s.whatsapp.net',
    ]);

    $ambos = Contact::create([
        'nome' => 'Cliente e fornecedor', 'papeis' => ['cliente', 'fornecedor'],
        'telefone_e164' => '+5584900000002', 'jid' => '5584900000002@s.whatsapp.net',
    ]);

    // A coluna e json: o filtro pergunta se a lista CONTEM o papel. Se comparasse por
    // igualdade, quem tem dois papeis desapareceria dos dois filtros.
    expect(Contact::whereJsonContains('papeis', 'fornecedor')->pluck('id')->all())
        ->toBe([$ambos->id]);

    Livewire::actingAs($this->usuario)
        ->test(ListContacts::class)
        ->filterTable('papel', 'cliente')
        ->assertCanSeeTableRecords([$cliente, $ambos])
        ->filterTable('papel', 'fornecedor')
        ->assertCanSeeTableRecords([$ambos])
        ->assertCanNotSeeTableRecords([$cliente]);
});
