<?php

use App\Filament\Pages\Cadastro;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ConsultaCnpj;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();

    $this->tenant = Tenant::create(['nome' => '', 'slug' => 'prov']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@prov.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    \Filament\Facades\Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

/** Resposta da BrasilAPI, recortada nos campos que a tela usa. */
function respostaReceita(array $sobrescreve = []): array
{
    return array_merge([
        'cnpj'                          => '19131243000197',
        'razao_social'                  => 'ONSIGHT TELECOM LTDA',
        'nome_fantasia'                 => 'ONSIGHT',
        'email'                         => 'contato@onsight.test',
        'ddd_telefone_1'                => '8433334444',
        'cep'                           => '59020000',
        'descricao_tipo_de_logradouro'  => 'AVENIDA',
        'logradouro'                    => 'HERMES DA FONSECA',
        'numero'                        => '384',
        'complemento'                   => 'SALA 5',
        'bairro'                        => 'PETROPOLIS',
        'municipio'                     => 'NATAL',
        'uf'                            => 'RN',
        'natureza_juridica'             => 'Sociedade Empresária Limitada',
        'cnae_fiscal'                   => 6110803,
        'cnae_fiscal_descricao'         => 'Serviços de comunicação multimídia - SCM',
        'descricao_situacao_cadastral'  => 'ATIVA',
        'porte'                         => 'DEMAIS',
        'data_inicio_atividade'         => '2014-01-15',
    ], $sobrescreve);
}

it('valida o CNPJ pelos digitos verificadores antes de sair da maquina', function () {
    expect(ConsultaCnpj::valido('19.131.243/0001-97'))->toBeTrue()
        ->and(ConsultaCnpj::valido('33000167000101'))->toBeTrue()
        ->and(ConsultaCnpj::valido('19131243000198'))->toBeFalse()
        ->and(ConsultaCnpj::valido('11111111111111'))->toBeFalse()
        ->and(ConsultaCnpj::valido('1913124300019'))->toBeFalse()
        ->and(ConsultaCnpj::valido(''))->toBeFalse();
});

it('nao gasta chamada de rede com CNPJ invalido', function () {
    Http::fake();

    $r = app(ConsultaCnpj::class)->consultar('19131243000198');

    expect($r['ok'])->toBeFalse()
        ->and($r['erro'])->toContain('inválido');

    Http::assertNothingSent();
});

it('sair do campo do CNPJ preenche razao social, fantasia e endereco', function () {
    Http::fake(['*' => Http::response(respostaReceita())]);

    Livewire::actingAs($this->usuario)
        ->test(Cadastro::class)
        ->set('documento', '19.131.243/0001-97')
        ->assertSet('razao_social', 'ONSIGHT TELECOM LTDA')
        ->assertSet('nome_fantasia', 'ONSIGHT')
        ->assertSet('cep', '59020000')
        // A Receita separa o tipo do nome da rua; a tela mostra junto.
        ->assertSet('logradouro', 'AVENIDA HERMES DA FONSECA')
        ->assertSet('numero', '384')
        ->assertSet('complemento', 'SALA 5')
        ->assertSet('bairro', 'PETROPOLIS')
        ->assertSet('cidade', 'NATAL')
        ->assertSet('uf', 'RN')
        ->assertSet('natureza_juridica', 'Sociedade Empresária Limitada')
        ->assertSet('cnae_principal', '6110803 — Serviços de comunicação multimídia - SCM')
        ->assertSet('situacao_cadastral', 'ATIVA')
        ->assertSet('porte', 'DEMAIS')
        ->assertSet('data_abertura', '2014-01-15')
        // Nome da conta estava vazio: entra o fantasia. Telefone vem formatado.
        ->assertSet('nome', 'ONSIGHT')
        ->assertSet('telefone', '(84) 3333-4444')
        ->assertSet('email', 'contato@onsight.test');
});

it('nao apaga o contato que o provedor ja tinha posto', function () {
    Http::fake(['*' => Http::response(respostaReceita())]);

    // Telefone da Receita costuma ser antigo: sobrescrever o contato certo seria
    // pior que nao preencher.
    Livewire::actingAs($this->usuario)
        ->test(Cadastro::class)
        ->set('nome', 'Onsight Fibra')
        ->set('email', 'suporte@onsight.com.br')
        ->set('telefone', '(84) 99999-0000')
        ->set('documento', '19131243000197')
        ->assertSet('nome', 'Onsight Fibra')
        ->assertSet('email', 'suporte@onsight.com.br')
        ->assertSet('telefone', '(84) 99999-0000')
        // Endereco e razao social, sim: e o dado oficial e o motivo da consulta.
        ->assertSet('razao_social', 'ONSIGHT TELECOM LTDA')
        ->assertSet('cidade', 'NATAL');
});

it('salva o que veio da Receita, com o CEP so em digitos e a UF em maiuscula', function () {
    Http::fake(['*' => Http::response(respostaReceita(['cep' => '59.020-000', 'uf' => 'rn']))]);

    Livewire::actingAs($this->usuario)
        ->test(Cadastro::class)
        ->set('documento', '19131243000197')
        ->call('salvar')
        ->assertHasNoErrors();

    $conta = $this->tenant->fresh();

    expect($conta->razao_social)->toBe('ONSIGHT TELECOM LTDA')
        ->and($conta->nome_fantasia)->toBe('ONSIGHT')
        ->and($conta->cep)->toBe('59020000')
        ->and($conta->uf)->toBe('RN')
        ->and($conta->cidade)->toBe('NATAL')
        ->and($conta->cnae_principal)->toContain('6110803')
        ->and($conta->data_abertura)->toBe('2014-01-15')
        ->and($conta->cnpj_consultado_em)->not->toBeNull();
});

it('recusa salvar CNPJ com digito errado', function () {
    Http::fake();

    Livewire::actingAs($this->usuario)
        ->test(Cadastro::class)
        ->set('nome', 'Onsight')
        ->set('documento', '19131243000198')
        ->call('salvar')
        ->assertHasErrors('documento');

    expect($this->tenant->fresh()->documento)->toBeNull();
});

it('CPF nao dispara consulta nenhuma', function () {
    Http::fake();

    Livewire::actingAs($this->usuario)
        ->test(Cadastro::class)
        ->set('documento', '529.982.247-25')
        ->assertSet('razao_social', '');

    Http::assertNothingSent();
});

it('diz o que aconteceu quando a Receita nao acha o CNPJ', function () {
    Http::fake(['*' => Http::response(['message' => 'CNPJ não encontrado'], 404)]);

    $r = app(ConsultaCnpj::class)->consultar('19131243000197');

    expect($r['ok'])->toBeFalse()
        ->and($r['erro'])->toContain('não encontrado');
});

it('avisa sobre o limite por IP em vez de dizer erro generico', function () {
    Http::fake(['*' => Http::response([], 429)]);

    $r = app(ConsultaCnpj::class)->consultar('19131243000197');

    expect($r['ok'])->toBeFalse()
        ->and($r['erro'])->toContain('Espere um minuto');
});

it('nao derruba a tela quando a Receita esta fora do ar', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    $r = app(ConsultaCnpj::class)->consultar('19131243000197');

    expect($r['ok'])->toBeFalse()
        ->and($r['erro'])->toContain('Tente de novo');
});

it('consulta a Receita uma vez e reusa do cache', function () {
    Http::fake(['*' => Http::response(respostaReceita())]);

    $servico = app(ConsultaCnpj::class);

    $servico->consultar('19131243000197');
    $segunda = $servico->consultar('19.131.243/0001-97');

    // A BrasilAPI limita por IP: reabrir a tela nao pode gastar consulta.
    Http::assertSentCount(1);
    expect($segunda['ok'])->toBeTrue()
        ->and($segunda['dados']['razao_social'])->toBe('ONSIGHT TELECOM LTDA');
});

it('o mesmo CNPJ nao e consultado de novo a cada saida do campo', function () {
    Http::fake(['*' => Http::response(respostaReceita())]);

    $tela = Livewire::actingAs($this->usuario)->test(Cadastro::class);

    $tela->set('documento', '19131243000197');
    $tela->set('documento', '19131243000197');

    Http::assertSentCount(1);
});

it('nao repete o numero quando a Receita ja o poe dentro do nome da rua', function () {
    // Caso real do CNPJ 19131243000197: logradouro 'PAULISTA 37' com numero '37'.
    Http::fake(['*' => Http::response(respostaReceita([
        'descricao_tipo_de_logradouro' => 'AVENIDA',
        'logradouro' => 'PAULISTA 37',
        'numero' => '37',
    ]))]);

    $r = app(ConsultaCnpj::class)->consultar('19131243000197');

    expect($r['dados']['logradouro'])->toBe('AVENIDA PAULISTA')
        ->and($r['dados']['numero'])->toBe('37');
});

it('nao mutila o nome da rua que termina em numero diferente', function () {
    // 'RUA 25 DE MARCO' numero 100: nada a tirar. E o caso que uma regex
    // apressada quebraria.
    Http::fake(['*' => Http::response(respostaReceita([
        'descricao_tipo_de_logradouro' => 'RUA',
        'logradouro' => '25 DE MARCO',
        'numero' => '100',
    ]))]);

    $r = app(ConsultaCnpj::class)->consultar('19131243000197');

    expect($r['dados']['logradouro'])->toBe('RUA 25 DE MARCO');
});

it('campo vazio na Receita nao vira string vazia no banco', function () {
    Http::fake(['*' => Http::response(respostaReceita([
        'nome_fantasia' => '', 'complemento' => '', 'email' => null,
    ]))]);

    $r = app(ConsultaCnpj::class)->consultar('19131243000197');

    expect($r['dados']['nome_fantasia'])->toBeNull()
        ->and($r['dados']['complemento'])->toBeNull()
        ->and($r['dados']['email'])->toBeNull();
});
