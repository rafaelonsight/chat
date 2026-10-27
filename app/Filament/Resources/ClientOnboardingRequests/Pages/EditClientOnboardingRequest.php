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
            Action::make('approve')->label(__('ui_15d3cbef49d3'))->color('success')->icon('heroicon-o-check-circle')->requiresConfirmation()->visible(fn () => $this->record->status === ClientOnboardingRequest::SUBMITTED)->action(function (ClientOnboardingApproval $service) {
                $this->save();
                try { $client = $service->approve($this->record, auth()->user()); Notification::make()->title(__('ui_9d1cc66a6e5b'))->body(__('ui_96114cd1cac7').$client->name.'.')->success()->send(); $this->redirect(ClientOnboardingRequestResource::getUrl('edit', ['record' => $this->record])); }
                catch (\DomainException $e) { Notification::make()->title(__('ui_d1333a63262c'))->body($e->getMessage())->danger()->persistent()->send(); }
            }),
            Action::make('reject')->label(__('ui_34d9f2bda1ac'))->color('danger')->icon('heroicon-o-x-circle')->visible(fn () => $this->record->status === ClientOnboardingRequest::SUBMITTED)->schema([Textarea::make('reason')->label(__('ui_63fa823a758a'))->required()])->action(function (array $data) {
                $this->record->update(['status' => ClientOnboardingRequest::REJECTED, 'review_notes' => $data['reason'], 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
                Notification::make()->title(__('ui_2ebd4d0659dd'))->body(__('ui_1dbf5ad8cfec'))->warning()->send();
            }),
            Action::make('open')->label(__('ui_1c2e920f101f'))->url(fn () => $this->record->url(), shouldOpenInNewTab: true),
        ];
    }

    protected function getSavedNotificationTitle(): ?string { return __('ui_5a4cd7c60223'); }
}
