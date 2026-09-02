<?php

beforeEach(function () {
    $this->markTestSkipped('Módulo de demandas/ERP removido do Virtus Chat.');
});

use App\Models\ClientAccount;
use App\Models\ClientAdmin;
use App\Models\ClientPortal;
use App\Models\PortalOtp;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Demandas\PortalOtpService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'Virtus', 'slug' => 'virtus-fases']);
    TenantContext::set($this->tenant->id);
    $this->user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Rafael', 'email' => 'rafael@virtus.test', 'password' => 'secret', 'admin' => true]);
    $this->actingAs($this->user);
    $this->client = ClientAccount::create(['tenant_id' => $this->tenant->id, 'name' => 'Cliente direto']);
});

it('gera portal privado automaticamente e resolve somente o token correto', function () {
    $portal = $this->client->portal;
    expect($portal)->not->toBeNull()
        ->and(ClientPortal::findByToken($portal->token())?->is($portal))->toBeTrue()
        ->and(ClientPortal::findByToken('token-invalido'))->toBeNull();
});

it('cliente abre demanda pelo portal com status recebida e protocolo global', function () {
    $token = $this->client->portal->token();
    $this->post(route('portal.tickets.store', $token), [
        'requester_name' => 'Fernando', 'requester_email' => 'fernando@cliente.test',
        'title' => 'Preciso de suporte', 'description' => 'Detalhes da solicitação',
        'category' => 'support', 'priority' => 'normal',
    ])->assertRedirect();

    $ticket = Ticket::first();
    expect($ticket->status)->toBe(Ticket::RECEIVED)
        ->and($ticket->origin)->toBe('portal')
        ->and($ticket->protocol)->toMatch('/^VRT-\d{4}-\d{6}$/');
});

it('portal de parceiro enxerga cliente final mas não cliente de outro parceiro', function () {
    $partner = ClientAccount::create(['tenant_id' => $this->tenant->id, 'name' => 'CLISP', 'type' => ClientAccount::PARTNER]);
    $child = ClientAccount::create(['tenant_id' => $this->tenant->id, 'name' => 'Cliente da CLISP', 'type' => ClientAccount::END_CLIENT, 'parent_id' => $partner->id]);
    $other = ClientAccount::create(['tenant_id' => $this->tenant->id, 'name' => 'Outro cliente']);
    foreach ([$child, $other] as $client) {
        Ticket::create(['tenant_id' => $this->tenant->id, 'client_account_id' => $client->id, 'title' => 'Demanda '.$client->name, 'description' => 'Teste']);
    }

    $response = $this->get(route('portal.show', $partner->portal->token()));
    $response->assertOk()->assertSee('Demanda Cliente da CLISP')->assertDontSee('Demanda Outro cliente');
});

it('limita administradores externos conforme o tipo do cliente', function () {
    ClientAdmin::create(['tenant_id' => $this->tenant->id, 'client_account_id' => $this->client->id, 'name' => 'Admin 1', 'email' => 'a1@test.local', 'active' => true]);
    expect(fn () => ClientAdmin::create(['tenant_id' => $this->tenant->id, 'client_account_id' => $this->client->id, 'name' => 'Admin 2', 'email' => 'a2@test.local', 'active' => true]))
        ->toThrow(DomainException::class);
});

it('otp é de uso único e cria sessão temporária', function () {
    Mail::fake();
    $admin = ClientAdmin::create(['tenant_id' => $this->tenant->id, 'client_account_id' => $this->client->id, 'name' => 'Admin', 'email' => 'admin@test.local', 'active' => true]);
    $portal = $this->client->portal;
    PortalOtp::create(['tenant_id' => $this->tenant->id, 'client_portal_id' => $portal->id, 'client_admin_id' => $admin->id, 'code_hash' => hash('sha256', '123456'), 'expires_at' => now()->addMinutes(10)]);
    $service = app(PortalOtpService::class);
    $session = $service->verify($portal, $admin->email, '123456', '127.0.0.1', 'Pest');

    expect($session)->not->toBeNull()
        ->and($service->verify($portal, $admin->email, '123456', '127.0.0.1', 'Pest'))->toBeNull();
});
