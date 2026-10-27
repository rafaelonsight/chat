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
        return [Action::make('newLink')->label(__('ui_0f4231f63b46'))->icon('heroicon-o-link')->action(function () {
            $record = ClientOnboardingRequest::issue(auth()->user()->tenant_id, auth()->id());
            Notification::make()->title(__('ui_94883f0323cd'))->body($record->url())->success()->persistent()->actions([Action::make('open')->label(__('ui_c845e3eae5bf'))->url($record->url(), shouldOpenInNewTab: true)])->send();
        })];
    }
}
