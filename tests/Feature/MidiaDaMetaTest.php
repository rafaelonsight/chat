<?php

use App\Jobs\{ProcessMetaWebhook, TranscribeAudio};
use App\Models\{Channel, Message, Tenant, WebhookEvent};
use App\Support\TenantContext;
use Illuminate\Support\Facades\{Http, Queue, Storage};

/*
 * Midia que chega pelo canal oficial.
 *
 * O webhook da Meta entrega apenas um ID: nao vem bytes, nao vem URL. Sem baixar, a
 * conversa mostra "audio" e o play nao toca nada — que era o estado antes disto.
 */

const MIDIA_SEGREDO = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
const MIDIA_PNID    = '1235849066282498';

/** Bytes de um ogg minimo: o suficiente para provar que o arquivo chegou ao disco. */
function bytesDeAudio(): string
{
    return "OggS\x00\x02".str_repeat("\x00", 40).'conteudo-de-audio';
}

function payloadDeMidia(string $tipo, array $extra = [], string $wamid = 'wamid.MIDIA1'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'id'      => '362',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata'          => ['phone_number_id' => MIDIA_PNID],
                    'contacts'          => [['profile' => ['name' => 'Rafael'], 'wa_id' => '5541984919939']],
                    'messages'          => [[
                        'from' => '5541984919939', 'id' => $wamid,
                        'timestamp' => (string) now()->timestamp, 'type' => $tipo,
                        $tipo => $extra,
                    ]],
                ],
            ]],
        ]],
    ];
}

function postDeMidia(array $payload)
{
    $corpo = json_encode($payload);

    return test()->call('POST', '/webhooks/meta/whatsapp', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, MIDIA_SEGREDO),
        'CONTENT_TYPE'             => 'application/json',
    ], $corpo);
}

beforeEach(function () {
    config([
        'services.meta.app_secret'   => MIDIA_SEGREDO,
        'services.meta.verify_token' => 'tk',
        'services.meta.token'        => 'EAA-env',
        'services.meta.versao'       => 'v23.0',
    ]);

    Storage::fake('local');
    Queue::fake([TranscribeAudio::class]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'midia']);
    TenantContext::set($this->tenant->id);

    $this->canal = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => MIDIA_PNID, 'meta_waba_id' => '362',
    ])->refresh();

    // Duas chamadas, dois stubs: a Meta diz ONDE esta, o lookaside entrega os bytes.
    //
    // Os stubs leem de PROPRIEDADES do teste. Http::fake chamado uma segunda vez nao
    // substitui o primeiro: a definicao original vence e a nova e ignorada em silencio.
    // Quem precisa de outra resposta troca a propriedade, e nao a fiacao.
    $this->urlDaMidia = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=abc';

    $this->metaCorpo = [
        'url' => $this->urlDaMidia, 'mime_type' => 'audio/ogg; codecs=opus',
        'file_size' => strlen(bytesDeAudio()), 'id' => '999',
    ];
    $this->metaStatus = 200;

    $this->arquivoCorpo = bytesDeAudio();
    $this->arquivoStatus = 200;

    Http::fake([
        'lookaside.fbsbx.com/*' => fn () => Http::response($this->arquivoCorpo, $this->arquivoStatus),
        'graph.facebook.com/*'  => fn () => Http::response($this->metaCorpo, $this->metaStatus),
    ]);
});

afterEach(fn () => TenantContext::forget());

it('baixa o audio e guarda o arquivo no disco', function () {
    postDeMidia(payloadDeMidia('audio', [
        'id' => '999', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true,
    ]))->assertOk();

    $m = Message::first();

    expect($m->tipo)->toBe('audio')
        ->and($m->media_path)->not->toBeNull()
        ->and($m->media_mime)->toBe('audio/ogg')
        ->and($m->media_tamanho)->toBe(strlen(bytesDeAudio()))
        ->and($m->erro)->toBeNull();

    Storage::disk('local')->assertExists($m->media_path);
});

it('pede as duas chamadas, e as duas com a credencial', function () {
    // O lookaside e de outro dominio e PARECE publico, mas sem o Authorization devolve
    // 401 — e o erro nao diz que falta credencial. Este teste trava isso.
    postDeMidia(payloadDeMidia('audio', ['id' => '999', 'mime_type' => 'audio/ogg']))->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com/v23.0/999')
        && $r->hasHeader('Authorization', 'Bearer EAA-env'));

    Http::assertSent(fn ($r) => str_contains($r->url(), 'lookaside.fbsbx.com')
        && $r->hasHeader('Authorization', 'Bearer EAA-env'));
});

it('audio que entra vai para a transcricao', function () {
    // Ganho de brinde: o canal oficial passa a transcrever igual ao outro.
    postDeMidia(payloadDeMidia('audio', ['id' => '999', 'mime_type' => 'audio/ogg']))->assertOk();

    expect(Message::first()->transcricao_status)->toBe('pendente');

    Queue::assertPushed(TranscribeAudio::class);
});

it('guarda o nome do arquivo quando e documento', function () {
    // O mime que vale e o que a Meta informa na consulta da midia, e nao o declarado no
    // webhook: e ele que decide a extensao com que o arquivo fica no disco.
    $this->metaCorpo['mime_type'] = 'application/pdf';

    postDeMidia(payloadDeMidia('document', [
        'id' => '999', 'mime_type' => 'application/pdf', 'filename' => 'contrato.pdf',
    ]))->assertOk();

    expect(Message::first()->media_nome)->toBe('contrato.pdf')
        ->and(Message::first()->media_path)->toEndWith('.pdf');
});

it('legenda da imagem nao se perde', function () {
    postDeMidia(payloadDeMidia('image', [
        'id' => '999', 'mime_type' => 'image/jpeg', 'caption' => 'olha o poste',
    ]))->assertOk();

    expect(Message::first()->legenda)->toBe('olha o poste');
});

// ================================================================= quando falha

it('download que falha NAO derruba a mensagem, e grava o motivo', function () {
    // Legenda, remetente e hora valem mais que o arquivo: a conversa continua legivel, e
    // o id segue no payload cru para refazer depois.
    $this->arquivoCorpo = 'nao autorizado';
    $this->arquivoStatus = 401;

    postDeMidia(payloadDeMidia('audio', ['id' => '999', 'mime_type' => 'audio/ogg']))->assertOk();

    $m = Message::first();

    expect($m)->not->toBeNull()
        ->and($m->tipo)->toBe('audio')
        ->and($m->media_path)->toBeNull()
        ->and($m->erro)->toStartWith('midia:')
        // o evento foi processado: a falha do arquivo nao vira webhook travado
        ->and(WebhookEvent::first()->processado_em)->not->toBeNull()
        ->and(WebhookEvent::first()->erro)->toBeNull();
});

it('midia sem URL na resposta da Meta grava o motivo', function () {
    $this->metaCorpo = ['mime_type' => 'audio/ogg']; // sem a chave url

    postDeMidia(payloadDeMidia('audio', ['id' => '999', 'mime_type' => 'audio/ogg']))->assertOk();

    expect(Message::first()->erro)->toContain('nao devolveu a URL');
});

it('texto continua sem chamar a Meta', function () {
    // Regressao: o caminho de texto nao pode ganhar uma chamada de rede por engano.
    $p = payloadDeMidia('text');
    data_set($p, 'entry.0.changes.0.value.messages.0.text', ['body' => 'oi']);
    data_set($p, 'entry.0.changes.0.value.messages.0.type', 'text');

    postDeMidia($p)->assertOk();

    expect(Message::first()->corpo)->toBe('oi');

    Http::assertNothingSent();
});
