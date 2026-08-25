<?php

namespace App\Http\Controllers;

use App\Filament\Resources\ClientOnboardingRequests\ClientOnboardingRequestResource;
use App\Models\ClientAccount;
use App\Models\ClientOnboardingRequest;
use App\Models\User;
use App\Services\ConsultaCep;
use App\Services\ConsultaCnpj;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientOnboardingController extends Controller
{
    public function show(string $token): View
    {
        $onboarding = $this->resolve($token);
        return view('onboarding.show', compact('onboarding', 'token'));
    }

    public function cnpj(Request $request, string $token, ConsultaCnpj $service): JsonResponse
    {
        $this->resolveEditable($token);
        $request->validate(['cnpj' => 'required|string|max:18']);
        return response()->json($service->consultar((string) $request->string('cnpj')));
    }

    public function cep(Request $request, string $token, ConsultaCep $service): JsonResponse
    {
        $this->resolveEditable($token);
        $request->validate(['cep' => 'required|string|max:10']);
        return response()->json($service->consultar((string) $request->string('cep')));
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $onboarding = $this->resolveEditable($token);
        $data = $request->validate([
            'company_type' => 'required|in:direct,partner',
            'company.document' => ['required', 'string', fn ($a, $v, $fail) => ConsultaCnpj::valido($v) ?: $fail('Informe um CNPJ válido.')],
            'company.legal_name' => 'required|string|max:200', 'company.trade_name' => 'nullable|string|max:200',
            'company.email' => 'required|email|max:160', 'company.phone' => 'required|string|max:30',
            'company.cep' => ['required', 'string', fn ($a, $v, $fail) => ConsultaCep::valido($v) ?: $fail('Informe um CEP válido.')],
            'company.street' => 'required|string|max:255', 'company.number' => 'required|string|max:30',
            'company.complement' => 'nullable|string|max:255', 'company.district' => 'required|string|max:255',
            'company.city' => 'required|string|max:255', 'company.state' => 'required|string|size:2',
            'admins' => 'required|array|min:1|max:3', 'admins.*.name' => 'required|string|max:160', 'admins.*.email' => 'required|email|max:160',
            'clients' => 'nullable|array|max:100', 'clients.*.document' => ['required_if:company_type,partner', 'string'],
            'clients.*.legal_name' => 'required_if:company_type,partner|string|max:200', 'clients.*.trade_name' => 'nullable|string|max:200',
            'clients.*.email' => 'required_if:company_type,partner|email|max:160', 'clients.*.phone' => 'nullable|string|max:30',
            'clients.*.admin.name' => 'required_if:company_type,partner|string|max:160', 'clients.*.admin.email' => 'required_if:company_type,partner|email|max:160',
        ]);
        if ($data['company_type'] === ClientAccount::DIRECT) $data['admins'] = array_slice($data['admins'], 0, 1);
        foreach ($data['clients'] ?? [] as $client) if (! ConsultaCnpj::valido($client['document'] ?? '')) return back()->withInput()->withErrors(['clients' => 'Um dos clientes possui CNPJ inválido.']);

        $onboarding->update(['company_type' => $data['company_type'], 'payload' => ['company' => $data['company'], 'admins' => $data['admins'], 'clients' => $data['company_type'] === ClientAccount::PARTNER ? ($data['clients'] ?? []) : []], 'status' => ClientOnboardingRequest::SUBMITTED, 'submitted_at' => now()]);
        User::withoutGlobalScope('tenant')->where('tenant_id', $onboarding->tenant_id)->where(fn ($q) => $q->where('admin', true)->orWhere('operador', true))->get()->each(fn (User $user) => Notification::make()->title('Novo cadastro aguardando aprovação')->body(($data['company']['trade_name'] ?: $data['company']['legal_name']).' enviou os dados pelo wizard.')->icon('heroicon-o-user-plus')->iconColor('warning')->actions([Action::make('review')->label('Revisar cadastro')->url(ClientOnboardingRequestResource::getUrl('edit', ['record' => $onboarding]))->markAsRead()])->sendToDatabase($user));
        return redirect()->route('onboarding.show', $token)->with('submitted', true);
    }

    private function resolve(string $token): ClientOnboardingRequest
    {
        return ClientOnboardingRequest::findByToken($token) ?? abort(404);
    }

    private function resolveEditable(string $token): ClientOnboardingRequest
    {
        $record = $this->resolve($token);
        abort_unless(in_array($record->status, [ClientOnboardingRequest::PENDING, ClientOnboardingRequest::REJECTED], true), 409, 'Este cadastro já foi enviado para análise.');
        return $record;
    }
}
