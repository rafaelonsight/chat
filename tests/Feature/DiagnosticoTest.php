<?php

use App\Models\{Channel, Tenant, WebhookEvent};
use App\Services\Diagnostico;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

beforeEach(function () {
    // E-mail configurado no cenario base. A verificacao de e-mail entrou depois destes
    // testes e nao e o assunto deles: sem isto, todo cenario "tudo de pe" passaria a ter um
    // aviso a mais e os numeros de alerta parariam de fechar.
    config(['mail.default' => 'smtp', 'mail.from.address' => 'nao-responda@onchat.test']);
    // E um envio ja aceito: sem isto, o cenario "tudo de pe" carrega o aviso novo
    // de "nunca saiu e-mail" e as contagens de alerta destes testes param de fechar.
    App\Models\SystemSetting::gravar('email.ultimo_envio', now()->toIso8601String());

    // O apontamento do webhook conferido agora mesmo. A verificacao entrou depois destes
    // testes e nao e o assunto deles: sem isto, cada cenario com canal Evolution carregaria
    // um critico a mais e as contagens de alerta parariam de fechar.
    App\Models\SystemSetting::gravar(
        App\Console\Commands\ConferirWebhooks::SELO,
        now()->toIso8601String(),
    );

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);

    // Horizon de pe por padrao: cada teste que quer a queda finge a queda.
    $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn(['mestre']));
});

afterEach(fn () => TenantContext::forget());

function chaves(array $problemas): array
{
    return array_column($problemas, 'chave');
}

function nivelDe(array $problemas, string $chave): ?string
{
    foreach ($problemas as $p) {
        if ($p['chave'] === $chave) {
            return $p['nivel'];
        }
    }

    return null;
}

it('nao reclama de nada quando tudo esta de pe', function () {
    $problemas = (new Diagnostico(fn () => true))->verificar();

    expect($problemas)->toBe([]);
});

it('separa o que derruba o atendimento do que so degrada', function () {
    $problemas = (new Diagnostico(fn () => false))->verificar();

    // Evolution e Redis parados significam mensagem nao entrando nem saindo.
    expect(nivelDe($problemas, 'evolution'))->toBe(Diagnostico::CRITICO)
        ->and(nivelDe($problemas, 'redis'))->toBe(Diagnostico::CRITICO);

    // Reverb e Whisper degradam sem parar o atendimento: a tela deixa de
    // atualizar sozinha e o audio nao e transcrito, mas a mensagem chega.
    expect(nivelDe($problemas, 'reverb'))->toBe(Diagnostico::AVISO)
        ->and(nivelDe($problemas, 'whisper'))->toBe(Diagnostico::AVISO);
});

it('acusa Horizon parado, o defeito mais silencioso que existe aqui', function () {
    $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn([]));

    $problemas = (new Diagnostico(fn () => true))->verificar();

    // O webhook responde 200, o job entra na fila e ninguem executa: de fora
    // parece que o cliente nunca escreveu.
    expect(nivelDe($problemas, 'horizon'))->toBe(Diagnostico::CRITICO);
});

it('acusa mensagem recebida e nao processada', function () {
    $canal = Channel::create(['nome' => 'Principal'])->refresh();
    $canal->forceFill(['status' => 'open'])->save();

    WebhookEvent::create([
        'channel_id'  => $canal->id,
        'evento'      => 'messages.upsert',
        'payload'     => ['a' => 1],
        'recebido_em' => now()->subMinutes(30),
    ]);

    $problemas = (new Diagnostico(fn () => true))->verificar();

    expect(nivelDe($problemas, 'webhook_parado'))->toBe(Diagnostico::CRITICO);
});

it('nao acusa webhook recem-chegado que ainda esta na fila', function () {
    $canal = Channel::create(['nome' => 'Principal'])->refresh();
    $canal->forceFill(['status' => 'open'])->save();

    WebhookEvent::create([
        'channel_id'  => $canal->id,
        'evento'      => 'messages.upsert',
        'payload'     => ['a' => 1],
        'recebido_em' => now(),
    ]);

    expect(chaves((new Diagnostico(fn () => true))->verificar()))
        ->not->toContain('webhook_parado');
});

it('acusa canal desconectado', function () {
    $canal = Channel::create(['nome' => 'Principal'])->refresh();
    $canal->forceFill(['status' => 'close'])->save();

    expect(nivelDe((new Diagnostico(fn () => true))->verificar(), 'canais'))
        ->toBe(Diagnostico::CRITICO);
});

it('acusa canal recem-criado que nunca conectou', function () {
    // O caso real: canal cadastrado e ainda sem QR lido. Ele nao envia nada, e
    // isso e uma queda de atendimento para aquele provedor.
    Channel::create(['nome' => 'Novo'])->refresh();

    expect(nivelDe((new Diagnostico(fn () => true))->verificar(), 'canais'))
        ->toBe(Diagnostico::CRITICO);
});

it('saude responde 200 quando esta tudo bem', function () {
    app()->instance(Diagnostico::class, new Diagnostico(fn () => true));

    $this->getJson('/saude')
        ->assertOk()
        ->assertJson(['ok' => true, 'falhas' => []]);
});

it('saude responde 503 e nomeia o que caiu', function () {
    app()->instance(Diagnostico::class, new Diagnostico(fn () => false));

    $resposta = $this->getJson('/saude')->assertStatus(503);

    expect($resposta->json('ok'))->toBeFalse()
        ->and($resposta->json('falhas'))->toContain('evolution')
        ->and($resposta->json('avisos'))->toContain('whisper');
});

it('nao repete o mesmo alerta dentro do silencio', function () {
    Http::fake(['*' => fn () => Http::response(['key' => ['id' => 'A-'.uniqid()]])]);
    config(['onchat.alerta_whatsapp' => '5511999998888']);

    $canal = Channel::create(['nome' => 'Principal'])->refresh();
    $canal->forceFill(['status' => 'open'])->save();

    app()->instance(Diagnostico::class, new Diagnostico(fn () => false));

    $this->artisan('onchat:diagnostico --alertar');

    // O que importa e que a SEGUNDA passada nao mande nada, e nao quantos problemas existem:
    // numero fixo aqui quebra toda vez que uma verificacao nova entra no diagnostico, e o
    // teste passa a falhar por um motivo que nao e o dele.
    $daPrimeira = count(Http::recorded());

    $this->artisan('onchat:diagnostico --alertar');

    expect($daPrimeira)->toBeGreaterThan(0);

    // Se repetisse, seriam o dobro — e alerta que repete passa a ser alerta que ninguem le.
    Http::assertSentCount($daPrimeira);
});

it('alerta nao quebra quando a Evolution esta fora', function () {
    Http::fake(['*' => Http::response(['erro' => 'fora'], 500)]);
    config(['onchat.alerta_whatsapp' => '5511999998888']);

    $canal = Channel::create(['nome' => 'Principal'])->refresh();
    $canal->forceFill(['status' => 'open'])->save();

    app()->instance(Diagnostico::class, new Diagnostico(fn () => false));

    // O comando precisa sobreviver: e justamente quando a Evolution cai que o
    // diagnostico mais importa. E por isso que /saude existe em paralelo.
    $this->artisan('onchat:diagnostico --alertar')->assertExitCode(1);
});

it('sai com erro so no critico, para aviso nao poluir o agendador', function () {
    Cache::flush();

    $canal = Channel::create(['nome' => 'Principal'])->refresh();
    $canal->forceFill(['status' => 'open'])->save();

    // Sonda que derruba apenas as portas de aviso.
    $soAvisos = new Diagnostico(fn ($host, $porta) => ! in_array($porta, [8080, 9090], true));
    app()->instance(Diagnostico::class, $soAvisos);

    $this->artisan('onchat:diagnostico')->assertExitCode(0);
});
