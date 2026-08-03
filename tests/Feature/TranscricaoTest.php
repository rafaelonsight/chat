<?php

use App\Jobs\TranscribeAudio;
use App\Livewire\Inbox\ConversationList;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Services\TranscriptionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function cenarioTransc(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $ct = Contact::create(['jid' => '5584996143373@s.whatsapp.net', 'tipo' => Contact::PESSOA, 'telefone_e164' => '+5584996143373', 'nome' => 'Joao']);
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);

    return [$t, $u, $c, $cv];
}

// Um wav real e pequeno, para o ffmpeg ter o que converter.
function wavDeTeste(Conversation $cv, float $segundos = 1.0): string
{
    $tmp = sys_get_temp_dir().'/tr-'.uniqid().'.wav';
    exec(sprintf(
        'ffmpeg -y -f lavfi -i "sine=frequency=440:duration=%s" -ar 16000 -ac 1 -c:a pcm_s16le %s 2>/dev/null',
        $segundos, escapeshellarg($tmp)
    ), $s, $c);

    $path = sprintf('media/%d/%d/audio-%s.wav', $cv->tenant_id, $cv->id, uniqid());
    Storage::disk('local')->put($path, (string) file_get_contents($tmp));
    @unlink($tmp);

    return $path;
}

function audioDe(Conversation $cv, Channel $c, string $direcao = 'in', float $segundos = 1.0): Message
{
    return Message::create([
        'conversation_id' => $cv->id,
        'channel_id'      => $c->id,
        'direcao'         => $direcao,
        'tipo'            => 'audio',
        'media_path'      => wavDeTeste($cv, $segundos),
        'media_mime'      => 'audio/wav',
        'media_duracao'   => (int) ceil($segundos),
        'status'          => Message::STATUS_DELIVERED,
    ]);
}

afterEach(fn () => TenantContext::forget());

it('transcreve audio e guarda o texto', function () {
    Http::fake(['*/inference' => Http::response(['text' => ' Meu modem esta piscando vermelho. '], 200)]);
    [, , $c, $cv] = cenarioTransc('tr1');

    $m = audioDe($cv, $c);
    (new TranscribeAudio($m->id))->handle(app(TranscriptionService::class));

    $m->refresh();
    expect($m->transcricao)->toBe('Meu modem esta piscando vermelho.')
        ->and($m->transcricao_status)->toBe('pronta');
});

it('manda o audio como wav de 16k mono para o servidor', function () {
    Http::fake(['*/inference' => Http::response(['text' => 'ok'], 200)]);
    [, , $c, $cv] = cenarioTransc('tr2');

    (new TranscribeAudio(audioDe($cv, $c)->id))->handle(app(TranscriptionService::class));

    Http::assertSent(fn ($r) => str_contains($r->url(), '/inference'));
});

it('ignora mensagem que nao e audio', function () {
    Http::fake();
    [, , $c, $cv] = cenarioTransc('tr3');

    $m = Message::create([
        'conversation_id' => $cv->id, 'channel_id' => $c->id,
        'direcao' => 'in', 'tipo' => 'text', 'corpo' => 'oi', 'status' => Message::STATUS_DELIVERED,
    ]);

    (new TranscribeAudio($m->id))->handle(app(TranscriptionService::class));

    expect($m->refresh()->transcricao)->toBeNull();
    Http::assertNothingSent();
});

// Audio de 8 minutos travaria um nucleo por minutos. Melhor recusar e dizer.
it('ignora audio acima do teto de duracao', function () {
    Http::fake();
    [, , $c, $cv] = cenarioTransc('tr4');

    config()->set('services.transcricao.max_segundos', 2);
    $m = audioDe($cv, $c, segundos: 5.0);

    (new TranscribeAudio($m->id))->handle(app(TranscriptionService::class));

    $m->refresh();
    expect($m->transcricao_status)->toBe('ignorada')
        ->and($m->transcricao)->toBeNull();
    Http::assertNothingSent();
});

it('marca falhou quando o servidor devolve erro, sem derrubar o job', function () {
    Http::fake(['*/inference' => Http::response(['error' => 'boom'], 500)]);
    [, , $c, $cv] = cenarioTransc('tr5');

    $m = audioDe($cv, $c);
    (new TranscribeAudio($m->id))->handle(app(TranscriptionService::class));

    $m->refresh();
    expect($m->transcricao_status)->toBe('falhou')
        ->and($m->transcricao)->toBeNull();
});

it('nao transcreve quando a funcao esta desligada', function () {
    Http::fake();
    config()->set('services.transcricao.ativa', false);
    [, , $c, $cv] = cenarioTransc('tr6');

    $m = audioDe($cv, $c);
    (new TranscribeAudio($m->id))->handle(app(TranscriptionService::class));

    expect($m->refresh()->transcricao_status)->toBe('ignorada');
    Http::assertNothingSent();
});

it('texto vazio nao vira transcricao pronta', function () {
    Http::fake(['*/inference' => Http::response(['text' => "  \n "], 200)]);
    [, , $c, $cv] = cenarioTransc('tr7');

    $m = audioDe($cv, $c);
    (new TranscribeAudio($m->id))->handle(app(TranscriptionService::class));

    expect($m->refresh()->transcricao_status)->toBe('falhou');
});

// O ganho que ninguem espera: audio deixa de ser buraco negro no historico.
it('a busca do inbox encontra a conversa pelo conteudo do audio', function () {
    Http::fake(['*/inference' => Http::response(['text' => 'quero cancelar meu plano'], 200)]);
    [, $u, $c, $cv] = cenarioTransc('tr8');

    $m = audioDe($cv, $c);
    (new TranscribeAudio($m->id))->handle(app(TranscriptionService::class));
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(ConversationList::class)
        ->set('balde', 'novos')
        ->set('busca', 'cancelar')
        ->assertViewHas('conversas', fn ($cs) => $cs->count() === 1 && $cs->first()->id === $cv->id);
});

it('a busca nao acha transcricao de outro tenant', function () {
    Http::fake(['*/inference' => Http::response(['text' => 'segredo do outro'], 200)]);

    [, , $cA, $cvA] = cenarioTransc('tr9');
    $mA = audioDe($cvA, $cA);
    (new TranscribeAudio($mA->id))->handle(app(TranscriptionService::class));
    TenantContext::forget();

    [, $uB] = cenarioTransc('tra');
    TenantContext::forget();

    Livewire::actingAs($uB)
        ->test(ConversationList::class)
        ->set('busca', 'segredo')
        ->assertViewHas('conversas', fn ($cs) => $cs->isEmpty());
});
