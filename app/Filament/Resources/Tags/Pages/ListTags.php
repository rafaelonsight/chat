<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListTags extends ListRecords
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        // Como o resource nao registra rota 'create', esta acao abre em modal.
        // Etiqueta e nome mais cor: trocar de pagina para dois campos tira a
        // lista da frente justamente quando se quer conferir o que ja existe.
        // Large porque a grade de 24 cores tem 12 colunas e nao cabe em sm.
        return [
            CreateAction::make()
                ->modalHeading('Nova etiqueta')
                ->modalSubmitActionLabel('Criar')
                ->modalWidth(Width::Large),
        ];
    }
}
