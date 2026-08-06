<?php

use App\Jobs\SendMediaMessage;
use App\Models\{Channel, Contact, Conversation, Message, Tenant};
use App\Services\Canais\Enviadores;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{Http, Storage};

/*
 * Envio de arquivo pelo canal oficial.
 *
 * O que esta sob prova e a diferenca que a API oficial impoe: NAO existe mandar bytes junto
 * da mensagem. Sobe-se o arquivo, a Meta devolve um id, e a mensagem referencia o id. Quem
 * espera um POST unico como na Evolution procura por horas o parametro que nao existe.
 */

beforeEach(function () {
    config(['services.meta.token' => 'EAA-env', 'services.meta.versao' => 'v23.0']);

    Storage::fake('local');

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'midiaenv']);
    TenantContext::set($this->tenant->id);

    $this->canal = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => '111222', 'meta_waba_id' => '362',
    ])->refresh();

    $this->contato = Contact::create(['nome' => 'Rafael', 'telefone_e164' => '+5541984919939']);
    $this->conversa = Conversation::abertaOuNova($this->canal->id, $this->contato->id);
    // Janela aberta: o que esta sob teste e o envio de arquivo, nao a trava da janela.
    $this->conversa->forceFill(['ultima_entrada_em' => now()])->save();

    // Stubs por propriedade: Http::fake chamado de novo nao substitui o primeiro.
    $this->idDaMidia = 'MEDIA-987';

    Http::fake([
        'graph.facebook.com/*/media'    => fn () => Http::response(['id' => $this->idDaMidia]),
        'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.SAIU']]]),
    ]);
});

afterEach(fn () => TenantContext::forget());

function arquivoNaFila(string $tipo, string $mime, ?string $nome = null, ?string $legenda = null): Message
{
    $path = 'media/1/1/arquivo.bin';
    Storage::disk('local')->put($path, 'conteudo-binario-do-arquivo');

    return Message::create([
        'conversation_id' => test()->conversa->id,
        'channel_id'      => test()->canal->id,
        'direcao'         => 'out',
        'tipo'            => $tipo,
        'legenda'         => $legenda,
        'status'          => Message::STATUS_QUEUED,
        'media_path'      => $path,
        'media_mime'      => $mime,
        'media_nome'      => $nome,
    ]);
}

function enviarArquivo(Message $m): void
{
    (new SendMediaMessage($m->id))->handle(app(Enviadores::class));
}

it('sobe o arquivo primeiro e depois manda a mensagem apontando para o id', function () {
    $m = arquivoNaFila('image', 'image/jpeg', 'poste.jpg', 'olha o poste');

    enviarArquivo($m);

    // Passo 1: multipart no endpoint de midia.
    Http::assertSent(fn ($r) => str_contains($r->url(), '111222/media') && $r->isMultipart());

    // Passo 2: a mensagem referencia o id que a Meta devolveu — nao os bytes.
    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '111222/messages')) {
            return false;
        }

        return $r['type'] === 'image'
            && $r['image']['id'] === 'MEDIA-987'
            && $r['image']['caption'] === 'olha o poste';
    });

    expect($m->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and($m->fresh()->external_id)->toBe('wamid.SAIU');
});

it('audio NAO leva legenda', function () {
    // A Meta responde erro 100 "param is not valid" sem dizer qual param. Barrar aqui
    // poupa a caca.
    $m = arquivoNaFila('audio', 'audio/ogg', null, 'isto nao deveria ir');

    enviarArquivo($m);

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/messages')) {
            return false;
        }

        return $r['type'] === 'audio'
            && $r['audio']['id'] === 'MEDIA-987'
            && ! isset($r['audio']['caption']);
    });
});

it('documento leva o nome do arquivo', function () {
    // Sem filename o cliente recebe "attachment-1.pdf" em vez de "contrato.pdf", e isso
    // vira pergunta no atendimento.
    $m = arquivoNaFila('document', 'application/pdf', 'contrato.pdf');

    enviarArquivo($m);

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/messages')) {
            return false;
        }

        return $r['document']['filename'] === 'contrato.pdf'
            && $r['document']['id'] === 'MEDIA-987';
    });
});

it('figurinha vai so com o id', function () {
    $m = arquivoNaFila('sticker', 'image/webp', null, 'legenda ignorada');

    enviarArquivo($m);

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/messages')) {
            return false;
        }

        return $r['type'] === 'sticker' && $r['sticker'] === ['id' => 'MEDIA-987'];
    });
});

it('se a Meta nao devolver o id, nao manda mensagem nenhuma', function () {
    // Mandar a mensagem sem id daria erro obscuro do outro lado. Melhor falhar aqui, com
    // frase que diz o que aconteceu.
    $this->idDaMidia = '';

    $m = arquivoNaFila('image', 'image/jpeg');

    enviarArquivo($m);

    expect($m->fresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($m->fresh()->erro)->toContain('nao devolveu o id');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/messages'));
});

it('arquivo que nao esta no disco falha sem chamar a Meta', function () {
    $m = arquivoNaFila('image', 'image/jpeg');
    Storage::disk('local')->delete($m->media_path);

    enviarArquivo($m);

    expect($m->fresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($m->fresh()->erro)->toContain('nao encontrado no disco');

    Http::assertNothingSent();
});

it('janela fechada barra o arquivo antes de subir nada', function () {
    // Subir arquivo para depois a mensagem ser recusada gastaria cota e deixaria lixo no
    // servidor da Meta.
    $this->conversa->forceFill(['ultima_entrada_em' => now()->subHours(30)])->save();

    $m = arquivoNaFila('image', 'image/jpeg');

    enviarArquivo($m);

    expect($m->fresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($m->fresh()->erro)->toContain('Janela de 24 horas fechada');

    Http::assertNothingSent();
});
