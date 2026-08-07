<?php

use App\Models\{Channel, Contact, Conversation, Tenant, User};
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * NOTA DE VOZ NAO PODE SER CLASSIFICADA COMO VIDEO.
 *
 * ESTE ARQUIVO NASCE DE UM DEFEITO QUE CHEGOU AO CLIENTE. O Rafael gravou um audio, o painel
 * mostrou "enviada", e no WhatsApp do destinatario nao chegou nada. O audio tinha ido como
 * VIDEO sem imagem, e o WhatsApp descartou em silencio.
 *
 * A CAUSA: webm, matroska, ogg e mp4 servem para video E para audio. O finfo do servidor
 * responde pelo CONTEINER, nao pelas faixas — uma nota de voz gravada pelo navegador chega
 * como "video/webm" mesmo tendo uma unica faixa Opus dentro.
 *
 * O PIOR DETALHE: a defesa contra isso JA EXISTIA. Ela olhava as faixas com o ffprobe, mas so
 * agia quando o NAVEGADOR declarava audio/ — e o Livewire descarta o mime do navegador ao
 * guardar o upload temporario. Defesa certa, ligada num sinal que nao chega. Por isso o teste
 * abaixo passa o mime de VIDEO de proposito: e exatamente o que o Livewire entrega.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'voz-tipo']);
    TenantContext::set($this->conta->id);

    User::create([
        'tenant_id' => $this->conta->id, 'name' => 'A',
        'email' => 'a@voz.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'C',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'voz',
    ]);

    $contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $canal->id,
        'contact_id' => $contato->id, 'status' => 'aberta',
    ]);

    $this->media = app(MediaService::class);
});

/** Gera um arquivo de verdade: e a unica forma de testar deteccao por CONTEUDO. */
function arquivoReal(string $comando, string $extensao): ?string
{
    $caminho = tempnam(sys_get_temp_dir(), 'onchat').'.'.$extensao;

    exec(sprintf($comando, escapeshellarg($caminho)).' 2>/dev/null', $saida, $codigo);

    return ($codigo === 0 && is_file($caminho) && filesize($caminho) > 0) ? $caminho : null;
}

it('webm com uma faixa de audio e AUDIO, mesmo o servidor dizendo video', function () {
    $caminho = arquivoReal(
        'ffmpeg -v error -f lavfi -i sine=frequency=440:duration=1 -c:a libopus -y %s',
        'webm'
    );

    if (! $caminho) {
        $this->markTestSkipped('ffmpeg com libopus nao disponivel nesta maquina');
    }

    // 'video/webm' de proposito: e o que o Livewire entrega, porque descarta o mime do
    // navegador e o finfo responde pelo conteiner.
    $upload = new UploadedFile($caminho, 'nota-de-voz.webm', 'video/webm', null, true);

    $meta = $this->media->guardarUpload($this->conversa, $upload);

    expect($meta['tipo'])->toBe('audio')
        ->and($meta['mime'])->toStartWith('audio/');

    @unlink($caminho);
});

it('webm COM imagem continua sendo video', function () {
    // O conserto nao pode transformar todo video em audio.
    $caminho = arquivoReal(
        'ffmpeg -v error -f lavfi -i testsrc=duration=1:size=64x64:rate=5 '
        .'-f lavfi -i sine=frequency=440:duration=1 -c:v libvpx -c:a libopus -y %s',
        'webm'
    );

    if (! $caminho) {
        $this->markTestSkipped('ffmpeg com libvpx nao disponivel nesta maquina');
    }

    $upload = new UploadedFile($caminho, 'video.webm', 'video/webm', null, true);

    expect($this->media->guardarUpload($this->conversa, $upload)['tipo'])->toBe('video');

    @unlink($caminho);
});

it('foto continua foto', function () {
    $upload = UploadedFile::fake()->image('foto.jpg', 20, 20);

    expect($this->media->guardarUpload($this->conversa, $upload)['tipo'])->toBe('image');
});

it('o classificador por mime nao mudou de comportamento', function () {
    // A correcao foi na DETECCAO, nao na classificacao. Se alguem "simplificar" o mimeEfetivo
    // depois, estes continuam valendo e o de cima quebra — que e o sinal certo.
    expect($this->media->tipoPorMime('audio/ogg'))->toBe('audio')
        ->and($this->media->tipoPorMime('video/mp4'))->toBe('video')
        ->and($this->media->tipoPorMime('image/webp'))->toBe('sticker')
        ->and($this->media->tipoPorMime('application/pdf'))->toBe('document');
});

it('arquivo ILEGIVEL nao vira audio: "nao sei" nao e "e audio"', function () {
    // Defeito que eu mesmo introduzi no primeiro conserto: negar "tem faixa de video" tratava
    // arquivo corrompido como se fosse audio, e um .mp4 quebrado virava nota de voz. Um teste
    // que ja existia pegou na hora — este fica para o caso nao depender de outro arquivo.
    $upload = Illuminate\Http\UploadedFile::fake()->create('quebrado.mp4', 20, 'video/mp4');

    expect($this->media->guardarUpload($this->conversa, $upload)['tipo'])->toBe('video');
});
