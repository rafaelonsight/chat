<?php

use App\Jobs\ProcessEvolutionWebhook;
use App\Models\{Channel, Tenant, User, WebhookEvent};
use App\Notifications\CanalDesconectado;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{DB, Http, Notification};

/*
 * QUEDA DO WHATSAPP. O que se perde se quebrar: o cliente fica horas sem receber mensagem e so
 * descobre pelo cliente dele; ou recebe um alerta a cada oscilacao e para de ler alerta.
 */

beforeEach(function () {
    Http::fake(['*' => Http::response([])]);
    $this->conta = Tenant::create(['nome' => 'Queda', 'slug' => 'queda']);
    TenantContext::set($this->conta->id);
    $this->admin = User::create(['tenant_id' => $this->conta->id, 'name' => 'Ana', 'email' => 'ana@queda.test', 'password' => 'segredo123', 'admin' => true]);
    $this->atendente = User::create(['tenant_id' => $this->conta->id, 'name' => 'Bia', 'email' => 'bia@queda.test', 'password' => 'segredo123', 'perfil' => 'atendente']);
    $this->canal = Channel::create(['tenant_id' => $this->conta->id, 'nome' => '+55 41 99999-0000', 'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'qd', 'telefone_e164' => '+5541999990000']);
    $this->canal->forceFill(['conectado_em' => now()->subDay()])->saveQuietly();
});

afterEach(fn () => TenantContext::forget());

function conexao(Channel $canal, string $estado): void
{
    $evento = WebhookEvent::create([
        'tenant_id' => $canal->tenant_id, 'channel_id' => $canal->id, 'evento' => 'connection.update',
        'payload' => ['event' => 'connection.update', 'data' => ['state' => $estado, 'statusReason' => $estado === 'close' ? 401 : 200]],
        'recebido_em' => now(),
    ]);
    (new ProcessEvolutionWebhook($evento->id))->handle();
}

it('canal que estava conectado e caiu avisa o administrador, nao o atendente', function () {
    Notification::fake();

    conexao($this->canal, 'close');

    Notification::assertSentTo($this->admin, CanalDesconectado::class, fn ($n, $via) => in_array('mail', $via) && in_array('database', $via));
    Notification::assertNotSentTo($this->atendente, CanalDesconectado::class);
    expect($this->canal->fresh()->queda_avisada_em)->not->toBeNull();
});

it('o aviso leva ao QR do proprio canal', function () {
    expect(CanalDesconectado::link($this->canal))->toEndWith("/admin/channels/{$this->canal->id}/conectar");
    $email = (new CanalDesconectado($this->canal))->toMail($this->admin);
    expect($email->actionUrl)->toBe(CanalDesconectado::link($this->canal))
        ->and($email->subject)->toContain('WhatsApp desconectado');
});

it('connecting nao e queda: a Evolution se refazendo nao gera aviso', function () {
    Notification::fake();

    conexao($this->canal, 'connecting');
    conexao($this->canal, 'open');

    Notification::assertNothingSent();
});

it('oscilacao nao vira enxurrada: um aviso a cada seis horas', function () {
    Notification::fake();

    conexao($this->canal, 'close');
    $this->canal->forceFill(['status' => 'open'])->saveQuietly();
    conexao($this->canal, 'close');

    Notification::assertSentToTimes($this->admin, CanalDesconectado::class, 1);
});

it('reconectar zera o aviso, e a proxima queda avisa de novo', function () {
    Notification::fake();

    conexao($this->canal, 'close');
    conexao($this->canal, 'open');
    expect($this->canal->fresh()->queda_avisada_em)->toBeNull();
    conexao($this->canal, 'close');

    Notification::assertSentToTimes($this->admin, CanalDesconectado::class, 2);
});

it('a inbox mostra o canal caido com o botao Reconectar para o administrador', function () {
    $this->canal->forceFill(['status' => 'close'])->saveQuietly();
    $this->admin->forceFill(['perfil_preenchido_em' => now()])->saveQuietly();

    $this->actingAs($this->admin)->get('/admin')
        ->assertSee('desconectado')
        ->assertSee(CanalDesconectado::link($this->canal), false);
});

it('canal novo, que nunca conectou, nao aparece como queda', function () {
    $this->canal->forceFill(['status' => 'close', 'conectado_em' => null])->saveQuietly();
    $this->admin->forceFill(['perfil_preenchido_em' => now()])->saveQuietly();

    $this->actingAs($this->admin)->get('/admin')->assertDontSee('data-canal-caido', false);
});

it('o cadastro de canal por QR avisa do risco antes de conectar', function () {
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::actingAs($this->admin)
        ->test(App\Filament\Resources\Channels\Pages\CreateChannel::class)
        ->fillForm(['tipo' => Channel::EVOLUTION])
        ->assertSee('não é a API oficial');
});

it('o webhook nao grava o arquivo em base64 que vem junto da mensagem', function () {
    Illuminate\Support\Facades\Queue::fake();
    $this->postJson("/webhooks/evolution/{$this->canal->id}/{$this->canal->webhook_secret}", [
        'event' => 'messages.upsert',
        'data' => ['key' => ['id' => 'X1'], 'message' => ['imageMessage' => ['mimetype' => 'image/jpeg'], 'base64' => str_repeat('A', 5000)]],
    ])->assertOk();

    $payload = WebhookEvent::latest('id')->first()->payload;
    expect($payload['data']['message'])->toHaveKey('imageMessage')->not->toHaveKey('base64');
});

it('a instancia nova e o reapontamento pedem webhook sem base64', function () {
    app(App\Services\EvolutionService::class)->apontarWebhook('qd', 'https://app.virtus.chat/webhooks/evolution/1/x');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/webhook/set/qd') && $r['webhook']['base64'] === false);
});

it('a limpeza apaga o que ja serviu e mantem o recente e o erro', function () {
    $novo = fn (int $dias, ?string $erro, array $payload = ['data' => []]) => DB::table('webhook_events')->insertGetId([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id, 'evento' => 'messages.upsert',
        'payload' => json_encode($payload), 'recebido_em' => now()->subDays($dias), 'processado_em' => now()->subDays($dias), 'erro' => $erro,
    ]);
    $velho = $novo(8, null);
    $recente = $novo(2, null, ['data' => ['message' => ['conversation' => 'oi', 'base64' => 'AAAA']]]);
    $erroRecente = $novo(20, 'falhou');
    $erroVelho = $novo(31, 'falhou');

    $this->artisan('virtus:limpar-webhooks')->assertSuccessful();

    $restam = DB::table('webhook_events')->pluck('id')->all();
    expect($restam)->not->toContain($velho)->not->toContain($erroVelho)->toContain($recente)->toContain($erroRecente);
    expect(json_decode(DB::table('webhook_events')->find($recente)->payload, true)['data']['message'])
        ->toBe(['conversation' => 'oi']);
});
