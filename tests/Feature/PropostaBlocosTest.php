<?php

use App\Models\Offering;
use App\Models\Proposal;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'Virtus', 'slug' => 'blc']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Rafael', 'email' => 'r@blc.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    Filament::setCurrentPanel('admin');
});

afterEach(function () {
    TenantContext::forget();
    Carbon::setTestNow();
});

/** Uma proposta ja enviada — rascunho nao abre pelo link, e isso tem teste proprio. */
function propostaNoAr(array $extra = []): Proposal
{
    $p = Proposal::create(array_merge([
        'titulo' => 'Atendimento organizado',
        'cliente_nome' => 'Ótica Central',
        'validade' => now()->addDays(10),
        'criada_por' => test()->usuario->id,
    ], $extra));

    $p->marcarEnviada();

    return $p->fresh();
}

function abrir(Proposal $p)
{
    return test()->get(route('proposta', $p->token));
}

// ================================================== os blocos com tipo

it('o diagnostico e o plano de acao numeram na mesma ordem', function () {
    /*
     * A NUMERACAO E INFORMACAO: a dor 01 se le com a solucao 01. Se um dos dois blocos numerasse
     * diferente, o par se desfaria e a proposta perderia o argumento que ela existe para fazer.
     */
    $p = propostaNoAr(['blocos' => [
        ['type' => 'diagnostico', 'data' => ['titulo' => 'O que trava hoje', 'itens' => [
            ['titulo' => 'Tres celulares', 'corpo' => 'Cada uma atende do proprio aparelho.'],
            ['titulo' => 'Historico que sai de ferias', 'corpo' => 'Some com a pessoa.'],
        ]]],
        ['type' => 'solucao', 'data' => ['titulo' => 'Como resolvemos', 'itens' => [
            ['titulo' => 'Caixa compartilhada', 'corpo' => 'Um lugar so.'],
            ['titulo' => 'Historico na ficha', 'corpo' => 'Fica com o cliente.'],
        ]]],
    ]]);

    $html = abrir($p)
        ->assertOk()
        ->assertSee('O que trava hoje')
        ->assertSee('Como resolvemos')
        ->assertSee('Tres celulares')
        ->assertSee('Caixa compartilhada')
        // Os dois blocos numeram com dois digitos, do 01 em diante.
        ->assertSee('01')
        ->assertSee('02')
        // Um cabecalho so para os dois: eles se leem em par, e nao em duas secoes distantes.
        ->assertSee('Diagnóstico e plano de ação')
        ->content();

    /*
     * A DOR 01 VEM ANTES DA SOLUCAO 01 NO DOCUMENTO, e as duas antes da dor 02.
     *
     * E o que sustenta a leitura em par: no desktop as duas colunas ficam lado a lado, e no
     * celular a coluna das dores vem inteira antes da das solucoes. Nos dois casos a ordem dentro
     * de cada lado tem de ser a mesma — se uma lista inverter, o par se desfaz.
     */
    expect(strpos($html, 'Tres celulares'))->toBeLessThan(strpos($html, 'Historico que sai de ferias'))
        ->and(strpos($html, 'Caixa compartilhada'))->toBeLessThan(strpos($html, 'Historico na ficha'));
});

it('o cronograma numera correndo, e nao reinicia em cada etapa', function () {
    // O cliente esta lendo UM projeto, e nao tres listas soltas.
    $p = propostaNoAr(['blocos' => [
        ['type' => 'cronograma', 'data' => ['titulo' => 'Etapas', 'etapas' => [
            ['periodo' => 'Semana 1', 'foco' => 'Ligar', 'itens' => ['Conectar o numero', 'Importar contatos']],
            ['periodo' => 'Semana 2', 'foco' => 'Automatizar', 'itens' => ['Chatbot de triagem']],
        ]]],
    ]]);

    $html = abrir($p)->assertOk()->assertSee('Semana 1')->assertSee('Semana 2')->content();

    // O terceiro passo esta na SEGUNDA etapa: se a numeracao reiniciasse, ele seria "01".
    // Dois digitos porque a coluna de numeros so alinha se todos tiverem a mesma largura.
    $posicaoDoTres = strpos($html, '>03<');

    expect($posicaoDoTres)->not->toBeFalse()
        ->and(strpos($html, 'Chatbot de triagem'))->toBeGreaterThan($posicaoDoTres);
});

it('quem assina aparece com cargo e numeros de credibilidade', function () {
    $p = propostaNoAr(['blocos' => [
        ['type' => 'assinante', 'data' => [
            'nome' => 'Rafael Paulino',
            'cargo' => 'Fundador',
            'texto' => 'Dez anos montando atendimento de provedor por dentro.',
            'numeros' => [
                ['valor' => '10+', 'rotulo' => 'anos no setor'],
                ['valor' => '100%', 'rotulo' => 'foco em atendimento'],
            ],
        ]],
    ]]);

    abrir($p)
        ->assertOk()
        ->assertSee('Quem assina')
        ->assertSee('Rafael Paulino')
        ->assertSee('Fundador')
        ->assertSee('10+')
        ->assertSee('anos no setor');
});

it('o bloco de texto que ja existia continua aparecendo', function () {
    // Formato convertido pela migracao: {type: texto, data: {titulo, corpo}}.
    $p = propostaNoAr(['blocos' => [
        ['type' => 'texto', 'data' => ['titulo' => 'Condições', 'corpo' => "Sem fidelidade.\nAviso de 30 dias."]],
    ]]);

    abrir($p)->assertOk()->assertSee('Condições')->assertSee('Sem fidelidade.');
});

it('bloco sem conteudo nao vira secao vazia na pagina', function () {
    $p = propostaNoAr(['blocos' => [
        ['type' => 'diagnostico', 'data' => ['titulo' => 'Dores', 'itens' => []]],
        ['type' => 'cronograma', 'data' => ['titulo' => 'Etapas', 'etapas' => []]],
        ['type' => 'assinante', 'data' => ['nome' => null]],
    ]]);

    abrir($p)
        ->assertOk()
        ->assertDontSee('Dores')
        ->assertDontSee('Etapas')
        ->assertDontSee('Quem assina');
});

// ======================================================== o preco-ancora

it('o valor cheio aparece riscado quando e maior que o proposto', function () {
    $p = propostaNoAr(['valor_cheio_recorrente' => 3500, 'valor_cheio_unico' => 4000]);
    $p->itens()->create(['descricao' => 'Plataforma', 'quantidade' => 1, 'valor_unitario' => 3299, 'recorrente' => true]);
    $p->itens()->create(['descricao' => 'Implantação', 'quantidade' => 1, 'valor_unitario' => 3100, 'recorrente' => false]);
    $p->recalcular();

    expect($p->ancora('recorrente'))->toMatchArray(['cheio' => 3500.0, 'agora' => 3299.0, 'economia' => 201.0]);

    abrir($p->fresh())
        ->assertOk()
        ->assertSee('3.500,00')
        ->assertSee('3.299,00')
        ->assertSee('line-through', false);
});

it('valor cheio menor que o proposto NAO aparece: seria anunciar aumento', function () {
    /*
     * A guarda vive no modelo, e nao na tela. Ancora menor e erro de digitacao; mostrada, ela
     * viraria "de R$ 300 por R$ 500" — e o cliente que le isso nao pede correcao, ele desiste.
     */
    $p = propostaNoAr(['valor_cheio_recorrente' => 300]);
    $p->itens()->create(['descricao' => 'Plataforma', 'quantidade' => 1, 'valor_unitario' => 500, 'recorrente' => true]);
    $p->recalcular();

    expect($p->ancora('recorrente'))->toBeNull();

    abrir($p->fresh())->assertOk()->assertDontSee('line-through', false);
});

it('valor cheio igual ao proposto tambem nao aparece', function () {
    $p = propostaNoAr(['valor_cheio_unico' => 500]);
    $p->itens()->create(['descricao' => 'Implantação', 'quantidade' => 1, 'valor_unitario' => 500, 'recorrente' => false]);
    $p->recalcular();

    expect($p->ancora('unico'))->toBeNull();
});

// ============================================= prazo, contador e condicao

it('o prazo conta ate o FIM do dia da validade', function () {
    // Quem le "valida ate 15/08" espera aceitar no dia 15. Zerar a meia-noite do 14 cobraria
    // um dia que foi prometido.
    Carbon::setTestNow('2026-08-10 09:00:00');

    $p = propostaNoAr(['validade' => '2026-08-15']);

    expect($p->venceEm()->format('Y-m-d H:i:s'))->toBe('2026-08-15 23:59:59');
});

it('o contador aparece com a proposta em aberto, e desaparece depois do aceite', function () {
    $p = propostaNoAr();

    abrir($p)->assertOk()->assertSee('Esta condição vale até');

    $p->aceitar('Maria da Ótica', '203.0.113.9', 'teste');

    // Numa proposta aceita, contador seria ameaca sem sentido.
    abrir($p->fresh())->assertOk()->assertDontSee('Esta condição vale até');
});

it('a condicao de pagamento aparece quando informada', function () {
    $p = propostaNoAr(['vencimento_dia' => 10, 'primeiro_pagamento' => '2026-09-10']);
    $p->itens()->create(['descricao' => 'Plataforma', 'quantidade' => 1, 'valor_unitario' => 480, 'recorrente' => true]);
    $p->recalcular();

    abrir($p->fresh())
        ->assertOk()
        ->assertSee('Vencimento todo dia')
        ->assertSee('10/09/2026');
});

it('o banco recusa dia de vencimento que nao existe em todo mes', function () {
    // 29, 30 e 31 viram "ultimo dia" em onze meses sem ninguem ter combinado isso.
    expect(fn () => propostaNoAr(['vencimento_dia' => 31]))
        ->toThrow(QueryException::class);
});

it('a capa mostra no maximo tres selos', function () {
    // Quatro selos ja e ruido: quem le para de ler todos.
    $p = propostaNoAr(['selos' => ['Sem fidelidade', 'Treinamento incluído', 'Suporte por WhatsApp', 'Quarto selo']]);

    abrir($p)
        ->assertOk()
        ->assertSee('Sem fidelidade')
        ->assertSee('Suporte por WhatsApp')
        ->assertDontSee('Quarto selo');
});

// ================================================= o catalogo na linha

it('escolher do catalogo copia descricao, valor e cobranca mensal para a linha', function () {
    /*
     * COPIA, e nao aponta. A proposta e um documento: se o preco do catalogo subir amanha, a
     * proposta que o cliente tem na mao nao pode subir com ele. Fica guardada a LIGACAO, para
     * relatorio por item vendido; o preco congela na linha.
     */
    $oferta = Offering::create([
        'nome' => 'Plataforma de atendimento', 'tipo' => Offering::SERVICO,
        'preco' => 480, 'recorrente' => true, 'codigo' => 'S-0001',
    ]);

    $p = propostaNoAr();
    $item = $p->itens()->create([
        'descricao' => 'a trocar', 'quantidade' => 1, 'valor_unitario' => 0, 'recorrente' => false,
        'offering_id' => $oferta->id,
    ]);

    expect($item->offering_id)->toBe($oferta->id);

    // O preco congela: mexer no catalogo depois nao mexe na proposta enviada.
    $oferta->update(['preco' => 990]);

    expect($item->fresh()->valor_unitario)->toEqual('0.00');
});
