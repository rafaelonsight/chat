<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ClientAdmin;
use App\Models\ClientOnboardingRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClientOnboardingApproval
{
    public function approve(ClientOnboardingRequest $request, User $reviewer): ClientAccount
    {
        if ($request->status === ClientOnboardingRequest::APPROVED && $request->createdClient) return $request->createdClient;
        if ($request->status !== ClientOnboardingRequest::SUBMITTED) throw new \DomainException('Somente solicitações enviadas podem ser aprovadas.');

        return DB::transaction(function () use ($request, $reviewer) {
            $request = ClientOnboardingRequest::withoutGlobalScope('tenant')->lockForUpdate()->findOrFail($request->id);
            if ($request->created_client_account_id) return ClientAccount::withoutGlobalScope('tenant')->findOrFail($request->created_client_account_id);
            $payload = $request->payload;
            $company = $payload['company'] ?? [];
            $admins = $payload['admins'] ?? [];
            $this->assertUniqueDocument($request->tenant_id, $company['document'] ?? null);

            $parent = $this->createClient($request->tenant_id, null, $request->company_type, $company);
            $this->createAdmins($parent, $admins);

            if ($request->company_type === ClientAccount::PARTNER) {
                foreach ($payload['clients'] ?? [] as $child) {
                    $this->assertUniqueDocument($request->tenant_id, $child['document'] ?? null);
                    $client = $this->createClient($request->tenant_id, $parent->id, ClientAccount::END_CLIENT, $child);
                    $this->createAdmins($client, [$child['admin'] ?? []]);
                }
            }

            $request->update(['status' => ClientOnboardingRequest::APPROVED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'created_client_account_id' => $parent->id]);
            return $parent;
        });
    }

    private function createClient(int $tenantId, ?int $parentId, string $type, array $data): ClientAccount
    {
        return ClientAccount::create([
            'tenant_id' => $tenantId, 'parent_id' => $parentId, 'type' => $type,
            'name' => $data['trade_name'] ?: ($data['legal_name'] ?? ''), 'document' => ConsultaCnpj::digitos($data['document'] ?? ''),
            'legal_name' => $data['legal_name'] ?? null, 'trade_name' => $data['trade_name'] ?? null,
            'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
            'cep' => ConsultaCep::digitos($data['cep'] ?? ''), 'street' => $data['street'] ?? null,
            'number' => $data['number'] ?? null, 'complement' => $data['complement'] ?? null,
            'district' => $data['district'] ?? null, 'city' => $data['city'] ?? null, 'state' => $data['state'] ?? null,
        ]);
    }

    private function createAdmins(ClientAccount $client, array $admins): void
    {
        foreach (array_values(array_filter($admins, fn ($a) => filled($a['email'] ?? null))) as $index => $admin) {
            ClientAdmin::create(['tenant_id' => $client->tenant_id, 'client_account_id' => $client->id, 'name' => $admin['name'], 'email' => mb_strtolower($admin['email']), 'is_primary' => $index === 0, 'active' => true]);
        }
    }

    private function assertUniqueDocument(int $tenantId, ?string $document): void
    {
        $document = ConsultaCnpj::digitos($document);
        if ($document && ClientAccount::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('document', $document)->exists()) throw new \DomainException('Já existe um cliente com o CNPJ '.ConsultaCnpj::formatar($document).'.');
    }
}
