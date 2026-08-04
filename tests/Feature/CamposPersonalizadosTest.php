<?php

use App\Filament\Resources\ContactFields\Pages\CreateContactField;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Support\CamposDoContato;
use App\Models\{Contact, ContactField, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'admin' => true]);

    $this->contato = Contact::create([
        'nome' => 'Rafael', 'telefone_e164' => '+5511999998888',
        'jid' => '5511999998888@s.whatsapp.net',
    ]);

    $this->be($this->admin);
});

afterEach(fn () => TenantContext::forget());

// ------------------------------------------------------------------- definicao

it('nao aceita dois campos com o mesmo nome, mesmo trocando maiuscula', function () {
    ContactField::create(['nome' => 'Contrato', 'tipo' => ContactField::TEXTO_CURTO]);

    // Dois campos com o mesmo rotulo sao indistinguiveis no formulario.
    expect(fn () => ContactField::create(['nome' => 'CONTRATO', 'tipo' => ContactField::INTEIRO]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('cobre os doze tipos que o produto de referencia oferece', function () {
    expect(ContactField::TIPOS)->toHaveCount(12)
        ->toHaveKeys(['texto_curto', 'texto_longo', 'inteiro', 'decimal', 'lista',
            'multiselecao', 'data', 'data_hora', 'booleano', 'link', 'cpf_cnpj', 'cep']);
});

// -------------------------------------------------------------- CPF e CNPJ

it('valida CPF e CNPJ pelo digito verificador, nao pela contagem de digitos', function () {
    // Campo que aceita 111.111.111-11 nao valida nada, e e justamente esse lixo que
    // entra em cadastro de provedor.
    expect(ContactField::cpfCnpjValido('529.982.247-25'))->toBeTrue()
        ->and(ContactField::cpfCnpjValido('52998224725'))->toBeTrue()
        ->and(ContactField::cpfCnpjValido('11.222.333/0001-81'))->toBeTrue();

    expect(ContactField::cpfCnpjValido('111.111.111-11'))->toBeFalse()
        ->and(ContactField::cpfCnpjValido('123.456.789-00'))->toBeFalse()
        ->and(ContactField::cpfCnpjValido('11.111.111/1111-11'))->toBeFalse()
        ->and(ContactField::cpfCnpjValido('529982247'))->toBeFalse()
        ->and(ContactField::cpfCnpjValido(''))->toBeFalse();
});

it('formata CPF, CNPJ e CEP para leitura', function () {
    expect(ContactField::formatarCpfCnpj('52998224725'))->toBe('529.982.247-25')
        ->and(ContactField::formatarCpfCnpj('11222333000181'))->toBe('11.222.333/0001-81')
        ->and(ContactField::formatarCep('01310930'))->toBe('01310-930');
});

// ---------------------------------------------------------------- valores

it('guarda e devolve cada tipo no formato certo', function () {
    $texto = ContactField::create(['nome' => 'Contrato', 'tipo' => ContactField::TEXTO_CURTO]);
    $numero = ContactField::create(['nome' => 'Dia de vencimento', 'tipo' => ContactField::INTEIRO]);
    $lista = ContactField::create(['nome' => 'Plano', 'tipo' => ContactField::LISTA, 'opcoes' => ['100MB', '300MB']]);
    $multi = ContactField::create(['nome' => 'Serviços', 'tipo' => ContactField::MULTISELECAO, 'opcoes' => ['TV', 'Fixo']]);
    $sim = ContactField::create(['nome' => 'Comodato', 'tipo' => ContactField::BOOLEANO]);
    $doc = ContactField::create(['nome' => 'CPF', 'tipo' => ContactField::CPF_CNPJ]);

    CamposDoContato::salvar($this->contato, [
        $texto->id  => 'C-4412',
        $numero->id => '10',
        $lista->id  => '300MB',
        $multi->id  => ['TV', 'Fixo'],
        $sim->id    => true,
        $doc->id    => '52998224725',
    ]);

    $estado = CamposDoContato::paraFormulario($this->contato->fresh());

    expect($estado[$texto->id])->toBe('C-4412')
        ->and($estado[$numero->id])->toBe('10')
        ->and($estado[$lista->id])->toBe('300MB')
        ->and($estado[$multi->id])->toBe(['TV', 'Fixo'])   // volta como vetor, nao json
        ->and($estado[$sim->id])->toBeTrue()               // volta como booleano
        ->and($estado[$doc->id])->toBe('52998224725');
});

it('campo vazio e apagado, nao guardado como string vazia', function () {
    $campo = ContactField::create(['nome' => 'Contrato', 'tipo' => ContactField::TEXTO_CURTO]);

    CamposDoContato::salvar($this->contato, [$campo->id => 'C-1']);
    expect($this->contato->fieldValues()->count())->toBe(1);

    CamposDoContato::salvar($this->contato, [$campo->id => '']);

    // Guardar string vazia faria "nunca preenchido" e "preenchido com nada" ficarem
    // iguais no banco, e o relatorio nao conseguiria distinguir.
    expect($this->contato->fresh()->fieldValues()->count())->toBe(0);
});

it('booleano falso e guardado, e diferente de nunca preenchido', function () {
    $campo = ContactField::create(['nome' => 'Comodato', 'tipo' => ContactField::BOOLEANO]);

    CamposDoContato::salvar($this->contato, [$campo->id => false]);

    expect($this->contato->fieldValues()->first()->valor)->toBe('0')
        ->and(CamposDoContato::paraFormulario($this->contato->fresh())[$campo->id])->toBeFalse();
});

it('salvar duas vezes nao duplica o valor', function () {
    $campo = ContactField::create(['nome' => 'Contrato', 'tipo' => ContactField::TEXTO_CURTO]);

    CamposDoContato::salvar($this->contato, [$campo->id => 'A']);
    CamposDoContato::salvar($this->contato, [$campo->id => 'B']);

    expect($this->contato->fresh()->fieldValues()->count())->toBe(1)
        ->and($this->contato->fresh()->fieldValues()->first()->valor)->toBe('B');
});

it('apagar o campo leva os valores embora', function () {
    $campo = ContactField::create(['nome' => 'Contrato', 'tipo' => ContactField::TEXTO_CURTO]);
    CamposDoContato::salvar($this->contato, [$campo->id => 'A']);

    $campo->delete();

    // Sem o cascade, sobrariam valores orfaos apontando para campo inexistente.
    expect(App\Models\ContactFieldValue::count())->toBe(0);
});

it('o valor nao vaza para outra conta', function () {
    $campo = ContactField::create(['nome' => 'Contrato', 'tipo' => ContactField::TEXTO_CURTO]);
    CamposDoContato::salvar($this->contato, [$campo->id => 'segredo']);

    $outra = Tenant::create(['nome' => 'X', 'slug' => 'x']);
    TenantContext::set($outra->id);

    expect(ContactField::count())->toBe(0);
});

it('exibe o valor de forma legivel para uma pessoa', function () {
    $sim = ContactField::create(['nome' => 'Comodato', 'tipo' => ContactField::BOOLEANO]);
    $multi = ContactField::create(['nome' => 'Serviços', 'tipo' => ContactField::MULTISELECAO, 'opcoes' => ['TV', 'Fixo']]);
    $doc = ContactField::create(['nome' => 'CPF', 'tipo' => ContactField::CPF_CNPJ]);

    expect($sim->exibir('1'))->toBe('Sim')
        ->and($sim->exibir('0'))->toBe('Não')
        ->and($multi->exibir(json_encode(['TV', 'Fixo'])))->toBe('TV, Fixo')
        ->and($doc->exibir('52998224725'))->toBe('529.982.247-25')
        ->and($doc->exibir(null))->toBe('—');
});

// ------------------------------------------------------------------- telas

it('a tela de campos personalizados abre para admin', function () {
    $chave = 'login_web_'.sha1('Illuminate\Auth\SessionGuard');

    $this->withSession([$chave => $this->admin->id])
        ->get('/admin/campos-personalizados')
        ->assertSuccessful()
        ->assertSee('Nenhum campo personalizado');
});

it('criar campo de lista exige as opcoes', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateContactField::class)
        ->fillForm(['nome' => 'Plano', 'tipo' => ContactField::LISTA, 'ordem' => 0, 'opcoes' => []])
        ->call('create')
        ->assertHasFormErrors(['opcoes']);
});

it('o cadastro do contato mostra o campo definido e salva o valor', function () {
    $campo = ContactField::create(['nome' => 'Contrato', 'tipo' => ContactField::TEXTO_CURTO]);

    $tela = Livewire::actingAs($this->admin)
        ->test(EditContact::class, ['record' => $this->contato->getKey()]);

    $tela->assertFormFieldExists('campos.'.$campo->id);

    $tela->fillForm(['campos' => [$campo->id => 'C-9001']])->call('save')->assertHasNoFormErrors();

    expect($this->contato->fresh()->fieldValues()->first()->valor)->toBe('C-9001');
});

it('CPF invalido no cadastro do contato e recusado', function () {
    $campo = ContactField::create(['nome' => 'CPF', 'tipo' => ContactField::CPF_CNPJ]);

    Livewire::actingAs($this->admin)
        ->test(EditContact::class, ['record' => $this->contato->getKey()])
        ->fillForm(['campos' => [$campo->id => '111.111.111-11']])
        ->call('save')
        ->assertHasFormErrors(['campos.'.$campo->id]);
});

it('nao mostra a secao quando nao ha campo definido', function () {
    // Secao vazia no formulario e ruido: faz o usuario procurar o que preencher.
    $tela = Livewire::actingAs($this->admin)
        ->test(EditContact::class, ['record' => $this->contato->getKey()]);

    $tela->assertDontSee('Campos personalizados');
});
