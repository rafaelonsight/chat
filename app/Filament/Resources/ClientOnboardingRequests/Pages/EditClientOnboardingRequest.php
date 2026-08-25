<?php

namespace App\Filament\Resources\ClientOnboardingRequests\Pages;

use App\Filament\Resources\ClientOnboardingRequests\ClientOnboardingRequestResource;
use App\Models\ClientOnboardingRequest;
use App\Services\ClientOnboardingApproval;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditClientOnboardingRequest extends EditRecord
{
    protected static string $resource = ClientOnboardingRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')->label('Aprovar e criar acessos')->color('success')->icon('heroicon-o-check-circle')->requiresConfirmation()->visible(fn () => $this->record->status === ClientOnboardingRequest::SUBMITTED)->action(function (ClientOnboardingApproval $service) {
                $this->save();
                try { $client = $service->approve($this->record, auth()->user()); Notification::make()->title('Cadastro aprovado')->body('Cliente, portais e acessos criados para '.$client->name.'.')->success()->send(); $this->redirect(ClientOnboardingRequestResource::getUrl('edit', ['record' => $this->record])); }
                catch (\DomainException $e) { Notification::make()->title('Não foi possível aprovar')->body($e->getMessage())->danger()->persistent()->send(); }
            }),
            Action::make('reject')->label('Recusar')->color('danger')->icon('heroicon-o-x-circle')->visible(fn () => $this->record->status === ClientOnboardingRequest::SUBMITTED)->schema([Textarea::make('reason')->label('Motivo da recusa')->required()])->action(function (array $data) {
                $this->record->update(['status' => ClientOnboardingRequest::REJECTED, 'review_notes' => $data['reason'], 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
                Notification::make()->title('Cadastro recusado')->body('O link foi reaberto para correção.')->warning()->send();
            }),
            Action::make('open')->label('Abrir link público')->url(fn () => $this->record->url(), shouldOpenInNewTab: true),
        ];
    }

    protected function getSavedNotificationTitle(): ?string { return 'Alterações salvas'; }
}
