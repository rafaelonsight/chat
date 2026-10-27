<?php

use App\Jobs\{AgendaFlowMessage, ProcessEvolutionWebhook};
use App\Models\{Channel, License, Tenant, User, WebhookEvent};
use App\Services\ResumoIA;
use App\Support\{Modulos, TenantContext};
use Illuminate\Support\Facades\{DB, Http, Queue};

/*
 * MODULOS POR PLANO. O que se perde se quebrar: conta que pagou por menos usando tudo, ou conta
 * que pagou por tudo perdendo uma tela no dia da troca de plano.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Plano', 'slug' => 'plano']);
    TenantContext::set($this->conta->id);
    $this->admin = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Gestora', 'email' => 'g@plano.test',
        'password' => 'segredo123', 'admin' => true,
    ]);
    $this->admin->forceFill(['perfil_preenchido_em' => now()])->saveQuietly();
    $this->licenca = License::where('tenant_id', $this->conta->id)->firstOrFail();
});

afterEach(fn () => TenantContext::forget());

function plano(License $licenca, ?string $plano, ?array $modulos = null): void
{
    $licenca->forceFill(['plano' => $plano, 'modulos' => $modulos])->save();
}

it('licenca sem plano vale como Completo: conta antiga nao perde nada', function () {
    expect(app(Modulos::class)->daConta($this->conta->id))
        ->toEqualCanonicalizing(config('modulos.planos.completo.modulos'));
});

it('sem conta no contexto nao ha plano a conferir', function () {
    TenantContext::forget();
    expect(Modulos::ativo('campanhas'))->toBeTrue();
});

it('plano Essencial esconde Campanhas do menu e recusa a rota', function () {
    plano($this->licenca, 'essencial');

    expect(Modulos::ativo('campanhas'))->toBeFalse()
        ->and(Modulos::ativo('automacao'))->toBeTrue()
        ->and(App\Filament\Pages\Campanhas::canAccess())->toBeFalse();

    $this->actingAs($this->admin)->get('/admin/campanhas')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/chatbot')->assertSuccessful();
});

it('recurso de modulo desligado tambem fica fechado (Propostas, Vendas)', function () {
    plano($this->licenca, 'essencial');

    $this->actingAs($this->admin)->get('/admin/proposals')->assertForbidden();
});

it('excecao em lista soma modulo ao plano', function () {
    plano($this->licenca, 'essencial', ['campanhas']);

    expect(Modulos::ativo('campanhas'))->toBeTrue()->and(Modulos::ativo('vendas'))->toBeFalse();
    $this->actingAs($this->admin)->get('/admin/campanhas')->assertSuccessful();
});

it('excecao em mapa desliga um modulo do plano', function () {
    plano($this->licenca, 'completo', ['ia' => false]);

    expect(Modulos::ativo('ia'))->toBeFalse()->and(Modulos::ativo('agenda'))->toBeTrue();
});

it('trocar o plano vale na hora, sem esperar a proxima requisicao', function () {
    expect(Modulos::ativo('campanhas'))->toBeTrue();
    plano($this->licenca, 'essencial');
    expect(Modulos::ativo('campanhas'))->toBeFalse();
});

it('IA fora do plano nao chama o provedor', function () {
    Http::fake();
    App\Services\GatewayVirtus::salvar(['ativa' => true, 'provedor' => 'gateway', 'modelo' => 'teste', 'chave' => 'fake']);
    plano($this->licenca, 'equipe');

    expect(fn () => app(ResumoIA::class)->responder($this->conta, 'sistema', 'texto'))
        ->toThrow(RuntimeException::class, 'não faz parte do plano');
    Http::assertNothingSent();
});

it('acao de IA forjada na inbox e recusada no servidor', function () {
    plano($this->licenca, 'equipe');
    $hook = new App\Support\MetodoDoModulo;
    $hook->setComponent(new App\Livewire\Inbox\ConversationWindow);

    expect(fn () => $hook->call('traduzirMensagem', [1], fn () => null))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $hook->call('selecionar', [], fn () => null))->not->toThrow(Throwable::class);
});

it('paginas da Agenda abrem no painel do chat com o modulo', function () {
    foreach (['inicio', 'configuracoes-agenda', 'fluxo-whatsapp', 'fluxos', 'follow-up', 'profissionais', 'servicos',
              'financeiro', 'financeiro/relatorios', 'financeiro/bancos', 'financeiro/categorias', 'financeiro/formas-pagamento', 'produtos'] as $slug) {
        $this->actingAs($this->admin)->get("/admin/agenda/{$slug}")->assertSuccessful();
    }
});

it('sem o modulo, a Agenda some e as rotas recusam', function () {
    plano($this->licenca, 'equipe');

    foreach (['inicio', 'profissionais', 'financeiro', 'produtos'] as $slug) {
        $this->actingAs($this->admin)->get("/admin/agenda/{$slug}")->assertForbidden();
    }
    $this->actingAs($this->admin)->get('/admin')->assertDontSee('/admin/agenda/inicio');
});

it('Financeiro e modulo separado da Agenda', function () {
    plano($this->licenca, 'essencial', ['agenda']);

    $this->actingAs($this->admin)->get('/admin/agenda/inicio')->assertSuccessful();
    $this->actingAs($this->admin)->get('/admin/agenda/financeiro')->assertForbidden();
});

it('link publico de agendamento de conta sem o modulo some', function () {
    $pagina = App\Models\BookingPage::create(['user_id' => $this->admin->id, 'slug' => 'gestora', 'titulo' => 'Conversa', 'ativa' => true]);
    plano($this->licenca, 'equipe');

    auth()->logout();
    TenantContext::forget();
    $this->get('/agendar/gestora')->assertNotFound();
})->skip(fn () => ! class_exists(App\Models\BookingPage::class), 'sem BookingPage');

function mensagemDeCliente(Channel $canal): void
{
    $evento = WebhookEvent::create([
        'tenant_id' => $canal->tenant_id, 'channel_id' => $canal->id, 'evento' => 'messages.upsert',
        'payload' => ['event' => 'messages.upsert', 'data' => [
            'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false, 'id' => 'M-'.uniqid()],
            'pushName' => 'Cliente', 'message' => ['conversation' => 'quero agendar'], 'messageTimestamp' => now()->timestamp,
        ]],
        'recebido_em' => now(),
    ]);
    (new ProcessEvolutionWebhook($evento->id))->handle();
}

it('canal com fluxo de agendamento ligado vai para a Agenda, nao para o chatbot', function () {
    Queue::fake([AgendaFlowMessage::class]);
    Http::fake(['*' => Http::response([])]);
    $canal = Channel::create(['tenant_id' => $this->conta->id, 'nome' => 'Recepcao', 'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'rec']);
    DB::table('agenda_flow_settings')->insert([
        'tenant_id' => $this->conta->id, 'channel_id' => $canal->id, 'actor_id' => $this->admin->id, 'enabled' => true,
        'business_name' => 'Barbearia', 'greeting' => 'Oi', 'handoff' => 'Ja chamo alguem', 'created_at' => now(), 'updated_at' => now(),
    ]);

    mensagemDeCliente($canal);

    Queue::assertPushed(AgendaFlowMessage::class, 1);
});

it('sem o modulo Agenda, o mesmo canal segue o caminho do chat', function () {
    Queue::fake([AgendaFlowMessage::class]);
    Http::fake(['*' => Http::response([])]);
    plano($this->licenca, 'equipe');
    $canal = Channel::create(['tenant_id' => $this->conta->id, 'nome' => 'Recepcao', 'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'rec2']);
    DB::table('agenda_flow_settings')->insert([
        'tenant_id' => $this->conta->id, 'channel_id' => $canal->id, 'actor_id' => $this->admin->id, 'enabled' => true,
        'business_name' => 'Barbearia', 'greeting' => 'Oi', 'handoff' => 'Ja chamo alguem', 'created_at' => now(), 'updated_at' => now(),
    ]);

    mensagemDeCliente($canal);

    Queue::assertNotPushed(AgendaFlowMessage::class);
});

it('a revenda escolhe o plano e os modulos a mais na licenca', function () {
    $campos = collect(App\Filament\Revenda\Resources\Licenses\Schemas\LicenseForm::configure(
        Filament\Schemas\Schema::make()
    )->getComponents())->map(fn ($c) => $c->getName())->all();

    expect($campos)->toContain('plano')->toContain('modulos');
});
