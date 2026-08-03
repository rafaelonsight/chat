<?php

use App\Jobs\SendMediaMessage;
use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function cenarioVoz(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);
    $ct = Contact::create(['telefone_e164' => '+5584996143373']);
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);

    return [$t, $u, $c, $cv];
}

afterEach(fn () => TenantContext::forget());

// O finfo detecta conteiner webm com so faixa de audio como video/webm. Sem
// tratar isso, a nota de voz vira mensagem de video e vai pelo endpoint errado.
it('gravacao webm declarada como audio nao vira video', function () {
    Storage::fake('local');
    [, , , $cv] = cenarioVoz('vz1');

    $arquivo = UploadedFile::fake()->create('nota-de-voz.webm', 20, 'audio/webm');

    $meta = app(MediaService::class)->guardarUpload($cv, $arquivo);

    expect($meta['tipo'])->toBe('audio')
        ->and($meta['mime'])->toStartWith('audio/');
});

it('arquivo de video de verdade continua sendo video', function () {
    Storage::fake('local');
    [, , , $cv] = cenarioVoz('vz2');

    $arquivo = UploadedFile::fake()->create('filme.mp4', 20, 'video/mp4');

    expect(app(MediaService::class)->guardarUpload($cv, $arquivo)['tipo'])->toBe('video');
});

it('documento nao e confundido com audio', function () {
    Storage::fake('local');
    [, , , $cv] = cenarioVoz('vz3');

    $arquivo = UploadedFile::fake()->create('boleto.pdf', 20, 'application/pdf');

    expect(app(MediaService::class)->guardarUpload($cv, $arquivo)['tipo'])->toBe('document');
});

it('o compositor cria mensagem de audio para a gravacao do navegador', function () {
    Storage::fake('local');
    Queue::fake();
    [, $u, , $cv] = cenarioVoz('vz4');

    Livewire::actingAs($u)
        ->test(MessageComposer::class, ['conversationId' => $cv->id])
        ->set('anexo', UploadedFile::fake()->create('nota-de-voz.webm', 20, 'audio/webm'))
        ->call('enviar');

    $m = Message::first();
    expect($m->tipo)->toBe('audio')
        ->and($m->media_mime)->toStartWith('audio/');

    Queue::assertPushed(SendMediaMessage::class);
});

it('ffprobe manda mais que o mime declarado: video com mime de audio segue video', function () {
    Storage::fake('local');
    [, , , $cv] = cenarioVoz('vz5');

    // gera um mp4 real, com faixa de video, mas mentindo que e audio
    $caminho = sys_get_temp_dir().'/vz-real-'.uniqid().'.mp4';
    exec(sprintf(
        'ffmpeg -y -f lavfi -i testsrc=duration=1:size=64x64:rate=5 -pix_fmt yuv420p %s 2>/dev/null',
        escapeshellarg($caminho)
    ), $s, $c);

    if ($c !== 0 || ! is_file($caminho)) {
        $this->markTestSkipped('ffmpeg nao gerou o arquivo de teste');
    }

    $arquivo = new UploadedFile($caminho, 'mentira.mp4', 'audio/webm', null, true);

    expect(app(MediaService::class)->guardarUpload($cv, $arquivo)['tipo'])->toBe('video');

    @unlink($caminho);
});

// UploadedFile::fake() devolve o mime que a gente declarou, entao nao exercita a
// deteccao por conteudo — o bug real passava por ele. Este teste usa um webm
// REAL, so com faixa de audio, que e justamente o que o finfo chama de video.
it('webm real com so faixa de audio e tratado como nota de voz', function () {
    Storage::fake('local');
    [, , , $cv] = cenarioVoz('vz6');

    $caminho = sys_get_temp_dir().'/vz-audio-'.uniqid().'.webm';
    exec(sprintf(
        'ffmpeg -y -f lavfi -i "sine=frequency=440:duration=1" -c:a libopus -b:a 32k -ar 48000 -ac 1 %s 2>/dev/null',
        escapeshellarg($caminho)
    ), $saida, $codigo);

    if ($codigo !== 0 || ! is_file($caminho)) {
        $this->markTestSkipped('ffmpeg nao gerou o webm de teste');
    }

    $arquivo = new UploadedFile($caminho, 'nota-de-voz.webm', 'audio/webm', null, true);

    // a deteccao por conteudo erra de proposito aqui: e esse o bug
    expect(app(MediaService::class)->tipoPorMime($arquivo->getMimeType()))->toBe('video');

    // e o guardarUpload precisa corrigir via ffprobe
    expect(app(MediaService::class)->guardarUpload($cv, $arquivo)['tipo'])->toBe('audio');

    @unlink($caminho);
});
