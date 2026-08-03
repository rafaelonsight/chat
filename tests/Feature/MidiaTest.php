<?php

use App\Jobs\SendMediaMessage;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Services\EvolutionService;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function cenarioMidia(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);
    $ct = Contact::create(['telefone_e164' => '+5584996143373', 'nome' => 'Cliente']);
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);

    return [$t, $u, $c, $cv];
}

afterEach(fn () => TenantContext::forget());

// ---------------------------------------------------------------- MediaService

it('classifica o tipo pelo mime', function (string $mime, string $esperado) {
    expect(app(MediaService::class)->tipoPorMime($mime))->toBe($esperado);
})->with([
    ['image/jpeg',        'image'],
    ['image/png',         'image'],
    ['image/webp',        'sticker'],
    ['video/mp4',         'video'],
    ['audio/ogg',         'audio'],
    ['audio/mpeg',        'audio'],
    ['application/pdf',   'document'],
    ['text/plain',        'document'],
    ['coisa/estranha',    'document'],
]);

it('guarda base64 no disco privado e nunca no publico', function () {
    Storage::fake('local');
    [, , , $cv] = cenarioMidia('md1');

    $meta = app(MediaService::class)->guardarBase64($cv, base64_encode('conteudo-falso'), 'image/jpeg', 'foto.jpg');

    expect($meta['tipo'])->toBe('image')
        ->and($meta['mime'])->toBe('image/jpeg')
        ->and($meta['tamanho'])->toBe(strlen('conteudo-falso'))
        ->and($meta['path'])->toStartWith('media/')
        ->and($meta['path'])->toContain((string) $cv->tenant_id);

    Storage::disk('local')->assertExists($meta['path']);
});

it('recusa arquivo acima do limite do tipo', function () {
    Storage::fake('local');
    [, , , $cv] = cenarioMidia('md2');

    $grande = base64_encode(str_repeat('x', 17 * 1024 * 1024)); // 17 MB de imagem

    expect(fn () => app(MediaService::class)->guardarBase64($cv, $grande, 'image/jpeg', 'g.jpg'))
        ->toThrow(RuntimeException::class);
});

// ------------------------------------------------------------- entrada (webhook)

function payloadMidia(string $tipoChave, array $conteudo, string $id): array
{
    return [
        'event' => 'messages.upsert',
        'data'  => [
            'key'      => ['remoteJid' => '5584996143373@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
            'pushName' => 'Cliente',
            'message'  => [$tipoChave => $conteudo],
            'messageTimestamp' => 1785648000,
        ],
    ];
}

it('recebe imagem com legenda e guarda o arquivo', function () {
    Storage::fake('local');
    [, , $c] = cenarioMidia('md3');

    Http::fake([
        '*/chat/getBase64FromMediaMessage/*' => Http::response([
            'base64'   => base64_encode('bytes-da-imagem'),
            'mimetype' => 'image/jpeg',
            'fileName' => 'recebida.jpg',
        ], 200),
    ]);

    $this->postJson(
        "/webhooks/evolution/{$c->id}/{$c->webhook_secret}",
        payloadMidia('imageMessage', ['mimetype' => 'image/jpeg', 'caption' => 'olha isso'], 'IMG1')
    )->assertOk();

    $m = Message::first();
    expect($m->tipo)->toBe('image')
        ->and($m->legenda)->toBe('olha isso')
        ->and($m->media_mime)->toBe('image/jpeg')
        ->and($m->media_path)->not->toBeNull()
        ->and($m->corpo)->toBeNull();

    Storage::disk('local')->assertExists($m->media_path);
});

it('recebe nota de voz marcando duracao', function () {
    Storage::fake('local');
    [, , $c] = cenarioMidia('md4');

    Http::fake([
        '*/chat/getBase64FromMediaMessage/*' => Http::response([
            'base64' => base64_encode('bytes-audio'), 'mimetype' => 'audio/ogg',
        ], 200),
    ]);

    $this->postJson(
        "/webhooks/evolution/{$c->id}/{$c->webhook_secret}",
        payloadMidia('audioMessage', ['mimetype' => 'audio/ogg; codecs=opus', 'seconds' => 7, 'ptt' => true], 'AUD1')
    )->assertOk();

    $m = Message::first();
    expect($m->tipo)->toBe('audio')
        ->and($m->media_duracao)->toBe(7);
});

it('recebe documento preservando o nome do arquivo', function () {
    Storage::fake('local');
    [, , $c] = cenarioMidia('md5');

    Http::fake([
        '*/chat/getBase64FromMediaMessage/*' => Http::response([
            'base64' => base64_encode('%PDF-falso'), 'mimetype' => 'application/pdf',
        ], 200),
    ]);

    $this->postJson(
        "/webhooks/evolution/{$c->id}/{$c->webhook_secret}",
        payloadMidia('documentMessage', ['mimetype' => 'application/pdf', 'fileName' => 'boleto.pdf'], 'DOC1')
    )->assertOk();

    expect(Message::first()->media_nome)->toBe('boleto.pdf');
});

it('nao perde a mensagem quando o download da midia falha', function () {
    Storage::fake('local');
    [, , $c] = cenarioMidia('md6');

    Http::fake(['*/chat/getBase64FromMediaMessage/*' => Http::response(['erro' => 'nao achei'], 500)]);

    $this->postJson(
        "/webhooks/evolution/{$c->id}/{$c->webhook_secret}",
        payloadMidia('imageMessage', ['mimetype' => 'image/jpeg', 'caption' => 'legenda salva'], 'IMG9')
    )->assertOk();

    // a mensagem existe, marcada com o erro, para poder reprocessar depois
    $m = Message::first();
    expect($m)->not->toBeNull()
        ->and($m->tipo)->toBe('image')
        ->and($m->legenda)->toBe('legenda salva')
        ->and($m->media_path)->toBeNull()
        ->and($m->erro)->not->toBeNull();
});

// -------------------------------------------------------------------- saida

it('envia imagem pelo endpoint de midia da Evolution', function () {
    Storage::fake('local');
    [, , $c, $cv] = cenarioMidia('md7');

    Http::fake(['*/message/sendMedia/*' => Http::response(['key' => ['id' => 'OUT-IMG']], 201)]);

    $meta = app(MediaService::class)->guardarBase64($cv, base64_encode('img'), 'image/jpeg', 'x.jpg');

    $m = Message::create([
        'conversation_id' => $cv->id, 'channel_id' => $c->id,
        'direcao' => 'out', 'tipo' => 'image', 'legenda' => 'segue',
        'media_path' => $meta['path'], 'media_mime' => 'image/jpeg', 'media_nome' => 'x.jpg',
        'status' => Message::STATUS_QUEUED,
    ]);

    (new SendMediaMessage($m->id))->handle(app(EvolutionService::class), app(MediaService::class));

    expect($m->refresh()->status)->toBe(Message::STATUS_SENT)
        ->and($m->external_id)->toBe('OUT-IMG');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendMedia/')
        && $r['mediatype'] === 'image'
        && $r['caption'] === 'segue');
});

it('envia audio pelo endpoint de nota de voz, nao pelo de midia', function () {
    Storage::fake('local');
    [, , $c, $cv] = cenarioMidia('md8');

    Http::fake(['*/message/sendWhatsAppAudio/*' => Http::response(['key' => ['id' => 'OUT-AUD']], 201)]);

    $meta = app(MediaService::class)->guardarBase64($cv, base64_encode('ogg'), 'audio/ogg', 'v.ogg');

    $m = Message::create([
        'conversation_id' => $cv->id, 'channel_id' => $c->id,
        'direcao' => 'out', 'tipo' => 'audio',
        'media_path' => $meta['path'], 'media_mime' => 'audio/ogg',
        'status' => Message::STATUS_QUEUED,
    ]);

    (new SendMediaMessage($m->id))->handle(app(EvolutionService::class), app(MediaService::class));

    expect($m->refresh()->status)->toBe(Message::STATUS_SENT);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendWhatsAppAudio/'));
});

it('marca failed quando a Evolution recusa a midia', function () {
    Storage::fake('local');
    [, , $c, $cv] = cenarioMidia('md9');

    Http::fake(['*' => Http::response(['message' => 'arquivo invalido'], 400)]);

    $meta = app(MediaService::class)->guardarBase64($cv, base64_encode('img'), 'image/jpeg', 'x.jpg');
    $m = Message::create([
        'conversation_id' => $cv->id, 'channel_id' => $c->id,
        'direcao' => 'out', 'tipo' => 'image',
        'media_path' => $meta['path'], 'media_mime' => 'image/jpeg',
        'status' => Message::STATUS_QUEUED,
    ]);

    try {
        (new SendMediaMessage($m->id))->handle(app(EvolutionService::class), app(MediaService::class));
    } catch (\Throwable) {
    }

    expect($m->refresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($m->erro)->not->toBeNull();
});

// ----------------------------------------------------- rota que serve o arquivo

it('serve a midia apenas para quem e do tenant', function () {
    Storage::fake('local');
    [, $uA, $cA, $cvA] = cenarioMidia('mda');
    $meta = app(MediaService::class)->guardarBase64($cvA, base64_encode('segredo'), 'image/jpeg', 'a.jpg');
    $m = Message::create([
        'conversation_id' => $cvA->id, 'channel_id' => $cA->id,
        'direcao' => 'in', 'tipo' => 'image',
        'media_path' => $meta['path'], 'media_mime' => 'image/jpeg',
        'status' => Message::STATUS_DELIVERED,
    ]);

    [, $uB] = cenarioMidia('mdb');
    TenantContext::forget();

    $this->actingAs($uA)->get("/media/{$m->id}")->assertOk();
    $this->actingAs($uB)->get("/media/{$m->id}")->assertNotFound();
    auth()->logout();
    $this->get("/media/{$m->id}")->assertRedirect('/login');
});

// ------------------------------------------------------------- envio pela tela

it('anexa arquivo pelo compositor e enfileira o envio', function () {
    Storage::fake('local');
    Queue::fake();
    [, $u, , $cv] = cenarioMidia('mdc');

    Livewire\Livewire::actingAs($u)
        ->test(App\Livewire\Inbox\MessageComposer::class, ['conversationId' => $cv->id])
        ->set('anexo', UploadedFile::fake()->image('foto.jpg', 10, 10))
        ->set('corpo', 'olha a foto')
        ->call('enviar');

    $m = Message::first();
    expect($m->tipo)->toBe('image')
        ->and($m->legenda)->toBe('olha a foto')
        ->and($m->media_path)->not->toBeNull();

    Queue::assertPushed(SendMediaMessage::class);
});
