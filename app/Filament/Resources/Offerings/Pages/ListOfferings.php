<?php

namespace App\Filament\Resources\Offerings\Pages;

use App\Filament\Resources\Offerings\OfferingResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ListRecords;

class ListOfferings extends ListRecords
{
    protected static string $resource = OfferingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('ui_887bef1273bb'))->modalWidth('2xl')];
    }

    public function getTableRecordActions(): array
    {
        return [
            EditAction::make()->modalWidth('2xl'),
            DeleteAction::make(),
        ];
    }
}
