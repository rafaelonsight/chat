<?php

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ConsultaCep;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'ct']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@ct.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    \Filament\Facades\Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

/** Resposta da ViaCEP para 59020-000. */
function respostaCep(array $sobrescreve = []): array
{
    return array_merge([
        'cep'         => '59020-000',
        'logradouro'  => 'Avenida Hermes da Fonseca',
        // Na ViaCEP isto e faixa postal, nao complemento de endereco.
        'complemento' => 'até 600 - lado par',
        'unidade'     => '',
        'bairro'      => 'Petrópolis',
        'localidade'  => 'Natal',
        'uf'          => 'RN',
        'estado'      => 'Rio Grande do Norte',
        'ibge'        => '2408102',
        'ddd'         => '84',
    ], $sobrescreve);
}

it('guarda o instagram numa forma so, venha url, arroba ou usuario cru', function () {
    $casos = [
        'https://www.instagram.com/onsight.fibra/' => 'onsight.fibra',
        'https://instagram.com/OnSight'            => 'onsight',
        'instagram.com/onsight?igshid=abc123'      => 'onsight',
        '@Onsight'                                 => 'onsight',
        'onsight'                                  => 'onsight',
        '  @onsight  '                             => 'onsight',
        ''                                         => null,
        '   '                                      => null,
    ];

    foreach ($casos as $entrada => $esperado) {
        expect(Contact::normalizarInstagram((string) $entrada))->toBe($esperado, "entrada: {$entrada}");
    }
});

it('normaliza o instagram em qualquer caminho de escrita, nao so no formulario', function () {
    // Importacao e chatbot gravam pelo modelo: a normalizacao tem que estar la.
    $contato = Contact::create([
        'nome' => 'Rafael', 'telefone_e164' => '+5584999990001',
        'instagram' => 'https://www.instagram.com/Rafael.Paulino/',
    ]);

    expect($contato->fresh()->instagram)->toBe('rafael.paulino')
        ->and($contato->instagramUrl())->toBe('https://instagram.com/rafael.paulino');
});

it('as iniciais do avatar saem do nome, com um caso para quem nao tem nome', function () {
    $comSobrenome = new Contact(['nome' => 'Rafael Paulino Silva']);
    $soNome = new Contact(['nome' => 'Rafael']);
    $semNome = new Contact(['nome' => null, 'telefone_e164' => '+5584999990001']);
    $grupo = new Contact(['nome' => null, 'tipo' => Contact::GRUPO]);

    // Primeira e ULTIMA palavra: 'Rafael Paulino Silva' e RS, nao RP.
    expect($comSobrenome->iniciais())->toBe('RS')
        ->and($soNome->iniciais())->toBe('R')
        ->and($semNome->iniciais())->toBe('?')
        ->and($grupo->iniciais())->toBe('G');
});

it('a cor do avatar e sempre a mesma para o mesmo contato', function () {
    $contato = Contact::create(['nome' => 'Rafael', 'telefone_e164' => '+5584999990002']);

    expect($contato->corAvatar())->toBe($contato->fresh()->corAvatar())
        ->and($contato->corAvatar())->toContain('bg-');
});

it('o CEP preenche rua, bairro, cidade e UF', function () {
    Http::fake(['*' => Http::response(respostaCep())]);

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.cep', '59020-000')
        ->assertSet('data.logradouro', 'Avenida Hermes da Fonseca')
        ->assertSet('data.bairro', 'Petrópolis')
        ->assertSet('data.cidade', 'Natal')
        ->assertSet('data.uf', 'RN')
        // O 'complemento' da ViaCEP e faixa postal: nao pode encostar no campo
        // do contato, que e o apartamento de quem mora ali.
        ->assertSet('data.complemento', null)
        // Numero o CEP nao sabe.
        ->assertSet('data.numero', null);
});

it('o numero e o complemento digitados sobrevivem a busca do CEP', function () {
    Http::fake(['*' => Http::response(respostaCep())]);

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.numero', '384')
        ->set('data.complemento', 'Apto 302')
        ->set('data.cep', '59020000')
        ->assertSet('data.numero', '384')
        ->assertSet('data.complemento', 'Apto 302')
        ->assertSet('data.cidade', 'Natal');
});

it('CEP incompleto nao dispara consulta', function () {
    Http::fake();

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->set('data.cep', '5902')
        ->assertSet('data.cidade', null);

    Http::assertNothingSent();
});

it('salva o contato com CEP so em digitos, UF em maiuscula e instagram limpo', function () {
    Http::fake(['*' => Http::response(respostaCep())]);

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->fillForm([
            'nome'          => 'Rafael Paulino',
            'telefone_e164' => '(84) 99614-3373',
            'email'         => 'rafael@onsight.test',
            'instagram'     => 'https://instagram.com/OnSight.Fibra/',
            'cep'           => '59020-000',
            'logradouro'    => 'Avenida Hermes da Fonseca',
            'numero'        => '384',
            'complemento'   => 'Apto 302',
            'bairro'        => 'Petrópolis',
            'cidade'        => 'Natal',
            'uf'            => 'rn',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $contato = Contact::where('email', 'rafael@onsight.test')->firstOrFail();

    expect($contato->cep)->toBe('59020000')
        ->and($contato->uf)->toBe('RN')
        ->and($contato->instagram)->toBe('onsight.fibra')
        ->and($contato->numero)->toBe('384')
        ->and($contato->complemento)->toBe('Apto 302')
        ->and($contato->telefone_e164)->toBe('+5584996143373')
        ->and($contato->enderecoResumido())->toBe('Avenida Hermes da Fonseca, 384 — Petrópolis — Natal/RN');
});

it('recusa e-mail que nao e e-mail', function () {
    Http::fake();

    Livewire::actingAs($this->usuario)
        ->test(CreateContact::class)
        ->fillForm([
            'nome' => 'Rafael', 'telefone_e164' => '(84) 99614-3374', 'email' => 'nao-e-email',
        ])
        ->call('create')
        ->assertHasFormErrors(['email']);
});

it('CEP que nao existe volta com 200 e erro no corpo, e isso conta como falha', function () {
    // A pegadinha da ViaCEP: status 200 com {"erro":"true"} — e "true" e string.
    Http::fake(['*' => Http::response(['erro' => 'true'])]);

    // 59999998 passa na validacao local (nao e repetido) e chega na ViaCEP.
    $r = app(ConsultaCep::class)->consultar('59999998');

    expect($r['ok'])->toBeFalse()
        ->and($r['erro'])->toContain('não encontrado')
        ->and($r['dados'])->toBe([]);

    // E o resultado ruim nao vai para o cache: amanha o CEP pode existir.
    Http::assertSentCount(1);
    app(ConsultaCep::class)->consultar('59999998');
    Http::assertSentCount(2);
});

it('CEP malformado e barrado antes da rede', function () {
    Http::fake();

    $r = app(ConsultaCep::class)->consultar('123');

    expect($r['ok'])->toBeFalse()
        ->and($r['erro'])->toContain('inválido');

    Http::assertNothingSent();
});

it('CEP de digito repetido nao vai para a rede', function () {
    Http::fake();

    expect(ConsultaCep::valido('00000000'))->toBeFalse()
        ->and(ConsultaCep::valido('11111111'))->toBeFalse()
        ->and(ConsultaCep::valido('59020000'))->toBeTrue();

    app(ConsultaCep::class)->consultar('00000000');

    Http::assertNothingSent();
});

it('ViaCEP fora do ar nao derruba o formulario', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    $r = app(ConsultaCep::class)->consultar('59020000');

    expect($r['ok'])->toBeFalse()
        ->and($r['erro'])->toContain('à mão');
});

it('o mesmo CEP e consultado uma vez e reusado do cache', function () {
    Http::fake(['*' => Http::response(respostaCep())]);

    $servico = app(ConsultaCep::class);
    $servico->consultar('59020000');
    $segunda = $servico->consultar('59020-000');

    Http::assertSentCount(1);
    expect($segunda['dados']['cidade'])->toBe('Natal');
});

it('editar contato existente tambem preenche pelo CEP', function () {
    Http::fake(['*' => Http::response(respostaCep())]);

    $contato = Contact::create(['nome' => 'Rafael', 'telefone_e164' => '+5584999990003']);

    Livewire::actingAs($this->usuario)
        ->test(EditContact::class, ['record' => $contato->getKey()])
        ->set('data.cep', '59020000')
        ->assertSet('data.cidade', 'Natal')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contato->fresh()->cidade)->toBe('Natal');
});
