<?php

use App\Models\{Channel, MetaTemplate, Tenant};
use App\Services\Canais\SincronizarTemplatesMeta;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * Templates aprovados da Meta.
 *
 * O que esta sob prova nao e "sincroniza": e que o sistema saiba ANTES quais templates ele
 * consegue enviar, e diga o motivo dos outros. Descobrir isso na hora do envio significa
 * cliente esperando enquanto alguem investiga.
 */

/** Os cinco formatos que existem de verdade na WABA de teste do Rafael. */
function templatesDaMeta(): array
{
    return [
        [
            'id' => '1410992074532340', 'name' => 'hello_world', 'language' => 'en_US',
            'category' => 'UTILITY', 'status' => 'APPROVED',
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Hello World'],
                ['type' => 'BODY', 'text' => 'Welcome and congratulations!!'],
                ['type' => 'FOOTER', 'text' => 'WhatsApp Business Platform sample message'],
            ],
        ],
        [
            'id' => '1359147229144757', 'name' => 'jaspers_market_order_confirmation_v1',
            'language' => 'en_US', 'category' => 'UTILITY', 'status' => 'APPROVED',
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order confirmed'],
                ['type' => 'BODY', 'text' => "Hi {{1}},\n\nYour order number is {{2}}."],
                ['type' => 'FOOTER', 'text' => 'developers.facebook.com'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Visit order details', 'url' => 'https://developers.facebook.com'],
                ]],
            ],
        ],
        [
            'id' => '1714774603146177', 'name' => 'jaspers_market_image_cta_v1',
            'language' => 'en_US', 'category' => 'MARKETING', 'status' => 'APPROVED',
            'components' => [
                ['type' => 'HEADER', 'format' => 'IMAGE'],
                ['type' => 'BODY', 'text' => 'Free delivery for all online orders'],
            ],
        ],
        [
            'id' => '1369310781231743', 'name' => 'jaspers_market_media_carousel_v1',
            'language' => 'en_US', 'category' => 'MARKETING', 'status' => 'APPROVED',
            'components' => [
                ['type' => 'BODY', 'text' => 'Our chefs prepared some recipes.'],
                ['type' => 'CAROUSEL', 'cards' => [['components' => []]]],
            ],
        ],
        [
            'id' => '999', 'name' => 'aviso_de_manutencao', 'language' => 'pt_BR',
            'category' => 'UTILITY', 'status' => 'PENDING',
            'components' => [
                ['type' => 'BODY', 'text' => 'Prezado {{1}}, haverá manutenção hoje.'],
            ],
        ],
    ];
}

beforeEach(function () {
    config(['services.meta.token' => 'EAA-env', 'services.meta.versao' => 'v23.0']);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'tpl']);
    TenantContext::set($this->tenant->id);

    $this->canal = Channel::create([
        'nome'                 => 'Oficial',
        'tipo'                 => Channel::META_CLOUD,
        'meta_phone_number_id' => '111',
        'meta_waba_id'         => '362',
    ])->refresh();

    // O stub le de uma PROPRIEDADE do teste, e nao de um valor fixo.
    //
    // Motivo: Http::fake chamado uma segunda vez nao substitui o primeiro — a definicao
    // original vence e a nova e ignorada em silencio. Ja me pegou tres vezes, e o sintoma
    // e sempre um teste que passa pelo lado errado. Assim, o teste que precisa de outra
    // resposta troca a propriedade e nao mexe na fiacao.
    $this->respostaTemplates = ['data' => templatesDaMeta()];

    Http::fake([
        'graph.facebook.com/*message_templates*' => fn () => Http::response($this->respostaTemplates),
        '*'                                      => Http::response(['ok' => true]),
    ]);
});

afterEach(fn () => TenantContext::forget());

// =========================================================== classificar formato

it('reconhece template simples como enviavel', function () {
    $d = MetaTemplate::analisar(templatesDaMeta()[0]);

    expect($d['suportado'])->toBeTrue()
        ->and($d['cabecalho'])->toBe('Hello World')
        ->and($d['corpo'])->toBe('Welcome and congratulations!!')
        ->and($d['rodape'])->toBe('WhatsApp Business Platform sample message')
        ->and($d['variaveis'])->toBe(0);
});

it('conta as variaveis e aceita botao de URL fixa', function () {
    $d = MetaTemplate::analisar(templatesDaMeta()[1]);

    expect($d['suportado'])->toBeTrue()
        ->and($d['variaveis'])->toBe(2);
});

it('recusa cabecalho de midia, com o motivo escrito', function () {
    // "Nao suportado" sem dizer o que falta vira suporte na semana seguinte.
    $d = MetaTemplate::analisar(templatesDaMeta()[2]);

    expect($d['suportado'])->toBeFalse()
        ->and($d['motivo_nao_suportado'])->toContain('cabeçalho de image');
});

it('recusa carrossel', function () {
    $d = MetaTemplate::analisar(templatesDaMeta()[3]);

    expect($d['suportado'])->toBeFalse()
        ->and($d['motivo_nao_suportado'])->toContain('carousel');
});

it('conta o MAIOR indice e nao a quantidade de variaveis', function () {
    // A Meta recebe parametros posicionais: um corpo que usa so {{2}} ainda exige dois
    // valores. Contar ocorrencias daria 1 e o envio falharia com "number of parameters
    // does not match" — erro que parece nosso e nao e.
    $d = MetaTemplate::analisar(['components' => [['type' => 'BODY', 'text' => 'Ola {{2}}']]]);

    expect($d['variaveis'])->toBe(2);
});

it('componente desconhecido entra como nao suportado com o nome dele', function () {
    // O dia em que a Meta criar um formato novo, o motivo tem de dizer qual e.
    $d = MetaTemplate::analisar(['components' => [
        ['type' => 'BODY', 'text' => 'oi'],
        ['type' => 'LIMITED_TIME_OFFER'],
    ]]);

    expect($d['suportado'])->toBeFalse()
        ->and($d['motivo_nao_suportado'])->toContain('limited_time_offer');
});

// ================================================================== sincronizar

it('traz os templates da Meta para o banco', function () {
    $r = app(SincronizarTemplatesMeta::class)->paraCanal($this->canal);

    expect($r['ok'])->toBeTrue()
        ->and($r['total'])->toBe(5)
        ->and($r['novos'])->toBe(5)
        // imagem e carrossel
        ->and($r['nao_suportados'])->toBe(2)
        ->and(MetaTemplate::count())->toBe(5)
        ->and(MetaTemplate::enviaveis()->count())->toBe(2);
});

it('sincronizar duas vezes nao duplica', function () {
    $sincronizador = app(SincronizarTemplatesMeta::class);
    $sincronizador->paraCanal($this->canal);
    $r = $sincronizador->paraCanal($this->canal);

    expect(MetaTemplate::count())->toBe(5)
        ->and($r['novos'])->toBe(0);
});

it('template apagado na Meta sai da nossa lista', function () {
    // Se ficasse, o atendente escolheria e o envio falharia com "template does not exist"
    // — erro que parece nosso e nao e.
    app(SincronizarTemplatesMeta::class)->paraCanal($this->canal);

    // A Meta passa a listar so um: os outros quatro foram apagados no painel dela.
    $this->respostaTemplates = ['data' => [templatesDaMeta()[0]]];

    $r = app(SincronizarTemplatesMeta::class)->paraCanal($this->canal);

    expect($r['apagados'])->toBe(4)
        ->and(MetaTemplate::count())->toBe(1)
        ->and(MetaTemplate::first()->nome)->toBe('hello_world');
});

it('template em analise nao e oferecido, e o motivo aparece', function () {
    app(SincronizarTemplatesMeta::class)->paraCanal($this->canal);

    $pendente = MetaTemplate::where('nome', 'aviso_de_manutencao')->firstOrFail();

    expect($pendente->podeEnviar())->toBeFalse()
        ->and($pendente->porQueNaoPodeEnviar())->toBe('aguardando aprovação da Meta')
        // o formato dele esta ok: o que falta e a Meta aprovar
        ->and($pendente->suportado)->toBeTrue();
});

it('canal sem WABA ID falha dizendo o que falta, sem chamar a Meta', function () {
    $semWaba = Channel::create([
        'nome' => 'Sem waba', 'tipo' => Channel::META_CLOUD, 'meta_phone_number_id' => '222',
    ])->refresh();

    $r = app(SincronizarTemplatesMeta::class)->paraCanal($semWaba);

    expect($r['ok'])->toBeFalse()
        ->and($r['erro'])->toContain('WABA ID');
});

// ===================================================================== mostrar

it('monta o texto que o cliente vai ler, para conferir antes de enviar', function () {
    app(SincronizarTemplatesMeta::class)->paraCanal($this->canal);

    $modelo = MetaTemplate::where('nome', 'jaspers_market_order_confirmation_v1')->firstOrFail();

    expect($modelo->renderizar(['Rafael', '12345']))
        ->toBe("Order confirmed\n\nHi Rafael,\n\nYour order number is 12345.");
});
