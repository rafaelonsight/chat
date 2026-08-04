<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\CamposDoContato;
use Filament\Resources\Pages\CreateRecord;

class CreateContact extends CreateRecord
{
    protected static string $resource = ContactResource::class;

    protected function mutateFormDataBeforeCreate(array $dados): array
    {
        $this->camposPersonalizados = $dados['campos'] ?? [];
        unset($dados['campos']);

        return $dados;
    }

    protected function afterCreate(): void
    {
        CamposDoContato::salvar($this->getRecord(), $this->camposPersonalizados);
    }

    private array $camposPersonalizados = [];
}
