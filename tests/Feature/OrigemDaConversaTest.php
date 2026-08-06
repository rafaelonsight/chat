<?php

use App\Models\{Channel, Conversation, Tenant};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * De onde a conversa veio.
 *
 * A Meta manda o bloco "referral" junto da PRIMEIRA mensagem de quem chegou por anuncio
 * Click-to-WhatsApp, e somente junto dela. Nao existe consulta depois que devolva isso: nao
 * guardar no momento em que chega e perder para sempre.
 */

const ORIGEM_SEGREDO = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
const ORIGEM_PNID    = '1235849066282498';

/** O referral como a Meta manda de verdade num anuncio Click-to-WhatsApp. */
function referralDeAnuncio(array $troca = []): array
{
    return array_merge([
        'source_url'  => 'https://fb.me/2abcXYZ',
        'source_type' => 'ad',
        'source_id'   => '120210000000123456',
        'headline'    => 'Internet fibra 500 MB sem fidelidade',
        'body'        => 'Assine hoje e ganhe a instalação',
        'media_type'  => 'image',
        'image_url'   => 'https://scontent.xx.fbcdn.net/v/anuncio.jpg',
        'ctwa_clid'   => 'ARBxyz123cliqueUnico',
    ], $troca);
}

function postComOrigem(?array $referral, string $wamid = 'wamid.ORIGEM1', string $texto = 'oi, quero assinar')
{
    $mensagem = [
        'from' => '5541984919939', 'id' => $wamid,
        'timestamp' => (string) now()->timestamp, 'type' => 'text',
        'text' => ['body' => $texto],
    ];

    if ($referral !== null) {
        $mensagem['referral'] = $referral;
    }

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'id'      => '362',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata'          => ['phone_number_id' => ORIGEM_PNID],
                    'contacts'          => [['profile' => ['name' => 'Rafael'], 'wa_id' => '5541984919939']],
                    'messages'          => [$mensagem],
                ],
            ]],
        ]],
    ];

    $corpo = json_encode($payload);

    return test()->call('POST', '/webhooks/meta/whatsapp', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, ORIGEM_SEGREDO),
        'CONTENT_TYPE'             => 'application/json',
    ], $corpo);
}

beforeEach(function () {
    config([
        'services.meta.app_secret'   => ORIGEM_SEGREDO,
        'services.meta.verify_token' => 'tk',
        'services.meta.token'        => 'EAA-env',
        'services.meta.versao'       => 'v23.0',
    ]);

    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'origem']);
    TenantContext::set($this->tenant->id);

    $this->canal = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => ORIGEM_PNID, 'meta_waba_id' => '362',
    ])->refresh();
});

afterEach(fn () => TenantContext::forget());

it('guarda o anuncio que trouxe a conversa', function () {
    postComOrigem(referralDeAnuncio())->assertOk();

    $c = Conversation::first();

    expect($c->origem_tipo)->toBe('ad')
        ->and($c->origem_id)->toBe('120210000000123456')
        ->and(data_get($c->origem, 'titulo'))->toBe('Internet fibra 500 MB sem fidelidade')
        ->and(data_get($c->origem, 'texto'))->toBe('Assine hoje e ganhe a instalação')
        ->and(data_get($c->origem, 'url'))->toBe('https://fb.me/2abcXYZ')
        ->and(data_get($c->origem, 'imagem'))->toContain('anuncio.jpg');
});

it('guarda o identificador do clique, que e o que liga a conversa ao gasto', function () {
    // Sem o ctwa_clid, "veio de anuncio" nao liga a dinheiro nenhum e a conta de custo por
    // conversa nao fecha.
    postComOrigem(referralDeAnuncio())->assertOk();

    expect(data_get(Conversation::first()->origem, 'clique'))->toBe('ARBxyz123cliqueUnico');
});

it('a tela do atendente recebe uma frase pronta', function () {
    postComOrigem(referralDeAnuncio())->assertOk();

    expect(Conversation::first()->origemResumo())
        ->toBe('Anúncio: Internet fibra 500 MB sem fidelidade');
});

it('publicacao aparece como publicacao, e nao como anuncio', function () {
    postComOrigem(referralDeAnuncio([
        'source_type' => 'post', 'headline' => null, 'body' => 'Promoção de julho',
    ]))->assertOk();

    expect(Conversation::first()->origem_tipo)->toBe('post')
        ->and(Conversation::first()->origemResumo())->toBe('Publicação: Promoção de julho');
});

it('conversa sem anuncio fica sem origem, e a tela nao mostra nada', function () {
    postComOrigem(null)->assertOk();

    $c = Conversation::first();

    expect($c->origem_tipo)->toBeNull()
        ->and($c->veioDeAnuncio())->toBeFalse()
        ->and($c->origemResumo())->toBeNull();
});

it('segundo anuncio na MESMA conversa nao troca a atribuicao', function () {
    // A decisao que este teste protege: relatorio de "conversas por anuncio" nao pode mudar
    // de atribuicao retroativamente. Numero que muda sozinho no passado nao serve para
    // decidir orcamento.
    postComOrigem(referralDeAnuncio())->assertOk();

    postComOrigem(
        referralDeAnuncio(['source_id' => '999999', 'headline' => 'Outro anúncio']),
        'wamid.ORIGEM2',
        'clicando de novo',
    )->assertOk();

    $c = Conversation::first();

    expect(Conversation::count())->toBe(1)
        ->and($c->origem_id)->toBe('120210000000123456')
        ->and(data_get($c->origem, 'titulo'))->toBe('Internet fibra 500 MB sem fidelidade');
});

it('conversa encerrada e reaberta por outro anuncio ganha origem propria', function () {
    // O segundo anuncio nao se perde: e assim que a segunda campanha aparece, e no lugar
    // certo.
    postComOrigem(referralDeAnuncio())->assertOk();

    Conversation::first()->update(['status' => Conversation::ARQUIVADA]);

    postComOrigem(
        referralDeAnuncio(['source_id' => '777', 'headline' => 'Campanha de agosto']),
        'wamid.ORIGEM3',
        'voltei',
    )->assertOk();

    expect(Conversation::count())->toBe(2)
        ->and(Conversation::orderByDesc('id')->first()->origem_id)->toBe('777');
});

it('titulo enorme nao estoura a linha da tela', function () {
    postComOrigem(referralDeAnuncio(['headline' => str_repeat('Fibra ', 40)]))->assertOk();

    // 80 caracteres do titulo mais o rotulo: cabe numa linha do painel.
    expect(mb_strlen((string) Conversation::first()->origemResumo()))->toBeLessThanOrEqual(90);
});

it('relatorio consegue agrupar por anuncio', function () {
    // A razao de origem_id ser coluna e nao chave dentro do json.
    postComOrigem(referralDeAnuncio())->assertOk();
    Conversation::first()->update(['status' => Conversation::ARQUIVADA]);
    postComOrigem(referralDeAnuncio(), 'wamid.X2', 'de novo')->assertOk();

    $porAnuncio = Conversation::query()
        ->whereNotNull('origem_id')
        ->selectRaw('origem_id, count(*) as total')
        ->groupBy('origem_id')
        ->pluck('total', 'origem_id');

    expect($porAnuncio['120210000000123456'])->toBe(2);
});
