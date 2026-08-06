<?php

use App\Models\{Channel, Contact, Conversation, Message, Tenant};
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

/*
 * Duracao de audio e video.
 *
 * A Evolution informa no payload; a Meta NAO manda. Sem ler do arquivo, audio do canal
 * oficial aparece sem tempo na bolha — e o atendente nao sabe se vai ouvir cinco segundos ou
 * tres minutos antes de dar play.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'dur']);
    TenantContext::set($this->tenant->id);

    $this->canal = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD, 'meta_phone_number_id' => '111',
    ])->refresh();

    $this->contato = Contact::create(['nome' => 'R', 'telefone_e164' => '+5541984919939']);
    $this->conversa = Conversation::abertaOuNova($this->canal->id, $this->contato->id);
});

afterEach(fn () => TenantContext::forget());

function audioSemDuracao(string $path = 'media/1/1/a.ogg'): Message
{
    Storage::disk('local')->put($path, 'isto nao e um ogg de verdade');

    return Message::create([
        'conversation_id' => test()->conversa->id,
        'channel_id'      => test()->canal->id,
        'direcao'         => 'in',
        'tipo'            => 'audio',
        'status'          => Message::STATUS_DELIVERED,
        'media_path'      => $path,
        'media_mime'      => 'audio/ogg',
    ]);
}

it('arquivo que nao e midia devolve null, e nao zero', function () {
    // "0s" na tela pareceria defeito. Ausencia de informacao nao deve se disfarcar de
    // informacao.
    $arquivo = Storage::disk('local')->path('qualquer.ogg');
    Storage::disk('local')->put('qualquer.ogg', 'texto puro');

    expect(app(MediaService::class)->duracaoSegundos($arquivo))->toBeNull();
});

it('caminho que nao existe devolve null sem estourar', function () {
    expect(app(MediaService::class)->duracaoSegundos('/nao/existe/x.ogg'))->toBeNull()
        ->and(app(MediaService::class)->duracaoSegundos(null))->toBeNull();
});

it('o comando de reparo nao apaga o que nao consegue ler', function () {
    // Arquivo ilegivel continua sem duracao, e a mensagem continua intacta: o reparo nao
    // pode piorar o historico que tenta melhorar.
    $m = audioSemDuracao();

    $this->artisan('onchat:duracao-de-midia')->assertSuccessful();

    expect($m->fresh()->media_duracao)->toBeNull()
        ->and($m->fresh()->media_path)->not->toBeNull()
        ->and($m->fresh()->tipo)->toBe('audio');
});

it('o comando ignora imagem e documento', function () {
    // A pergunta "quanto tempo dura" nao existe para imagem.
    Storage::disk('local')->put('media/1/1/f.jpg', 'jpeg falso');

    $imagem = Message::create([
        'conversation_id' => $this->conversa->id, 'channel_id' => $this->canal->id,
        'direcao' => 'in', 'tipo' => 'image', 'status' => Message::STATUS_DELIVERED,
        'media_path' => 'media/1/1/f.jpg', 'media_mime' => 'image/jpeg',
    ]);

    $this->artisan('onchat:duracao-de-midia')->assertSuccessful();

    expect($imagem->fresh()->media_duracao)->toBeNull();
});

it('nao mexe em quem ja tem duracao', function () {
    $m = audioSemDuracao();
    $m->update(['media_duracao' => 42]);

    $this->artisan('onchat:duracao-de-midia')->assertSuccessful();

    expect($m->fresh()->media_duracao)->toBe(42);
});
