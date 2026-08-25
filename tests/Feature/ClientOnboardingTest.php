<?php

use App\Models\ClientAccount;
use App\Models\ClientOnboardingRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClientOnboardingApproval;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'Virtus', 'slug' => 'virtus-onboarding']);
    TenantContext::set($this->tenant->id);
    $this->admin = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Rafael', 'email' => 'rafael@virtus.test', 'password' => 'secret', 'admin' => true]);
    $this->request = ClientOnboardingRequest::issue($this->tenant->id, $this->admin->id);
    $this->token = $this->request->token();
});

it('consulta cnpj e cep somente por link válido', function () {
    Http::fake([
        '*cnpj*' => Http::response(['cnpj' => '11222333000181', 'razao_social' => 'Empresa Teste Ltda', 'nome_fantasia' => 'Empresa Teste', 'descricao_situacao_cadastral' => 'ATIVA'], 200),
        '*viacep*' => Http::response(['cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP'], 200),
    ]);

    $this->postJson(route('onboarding.cnpj', $this->token), ['cnpj' => '11.222.333/0001-81'])->assertOk()->assertJsonPath('ok', true);
    $this->postJson(route('onboarding.cep', $this->token), ['cep' => '01001-000'])->assertOk()->assertJsonPath('dados.cidade', 'São Paulo');
    $this->postJson(route('onboarding.cnpj', 'invalido'), ['cnpj' => '11.222.333/0001-81'])->assertNotFound();
});

it('mantém parceiro e clientes em espera até aprovação e cria todos os portais', function () {
    $payload = [
        'company_type' => 'partner',
        'company' => ['document' => '11.222.333/0001-81', 'legal_name' => 'CLISP Consultoria Ltda', 'trade_name' => 'CLISP', 'email' => 'caio@clisp.test', 'phone' => '41999999999', 'cep' => '01001000', 'street' => 'Praça da Sé', 'number' => '100', 'complement' => '', 'district' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP'],
        'admins' => [['name' => 'Caio', 'email' => 'caio@clisp.test'], ['name' => 'Fernando', 'email' => 'fernando@clisp.test']],
        'clients' => [['document' => '45.723.174/0001-10', 'legal_name' => 'Cliente Final Ltda', 'trade_name' => 'Cliente Final', 'email' => 'admin@final.test', 'phone' => '41988888888', 'admin' => ['name' => 'Administrador Final', 'email' => 'admin@final.test']]],
    ];

    $this->post(route('onboarding.submit', $this->token), $payload)->assertRedirect();
    expect(ClientAccount::count())->toBe(0)->and($this->request->refresh()->status)->toBe(ClientOnboardingRequest::SUBMITTED);

    $partner = app(ClientOnboardingApproval::class)->approve($this->request, $this->admin);
    expect($partner->type)->toBe(ClientAccount::PARTNER)->and($partner->admins)->toHaveCount(2)->and($partner->portal)->not->toBeNull()
        ->and($partner->children)->toHaveCount(1)->and($partner->children->first()->admins)->toHaveCount(1)->and($partner->children->first()->portal)->not->toBeNull();
});

it('rejeitado pode corrigir e reenviar pelo mesmo link', function () {
    $this->request->update(['status' => ClientOnboardingRequest::REJECTED, 'review_notes' => 'Corrigir e-mail']);
    $this->get(route('onboarding.show', $this->token))->assertOk()->assertSee('Cadastro da sua empresa');
});

it('super admin abre a lista e a edição para aprovar ou recusar', function () {
    $this->actingAs($this->admin);
    $this->get('/admin/client-onboarding-requests')->assertOk()->assertSee('Gerar link de cadastro');
    $this->get('/admin/client-onboarding-requests/'.$this->request->id.'/edit')->assertOk()->assertSee('Link enviado ao cliente');
});
