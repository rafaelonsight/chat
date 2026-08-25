<?php

namespace App\Filament\Resources\ClientOnboardingRequests\Pages;

use App\Filament\Resources\ClientOnboardingRequests\ClientOnboardingRequestResource;
use App\Models\ClientOnboardingRequest;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListClientOnboardingRequests extends ListRecords
{
    protected static string $resource = ClientOnboardingRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('newLink')->label('Gerar link de cadastro')->icon('heroicon-o-link')->action(function () {
            $record = ClientOnboardingRequest::issue(auth()->user()->tenant_id, auth()->id());
            Notification::make()->title('Link criado')->body($record->url())->success()->persistent()->actions([Action::make('open')->label('Abrir link')->url($record->url(), shouldOpenInNewTab: true)])->send();
        })];
    }
}
