<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use Filament\Actions\DeleteAction;
use App\Filament\Support\CamposDoContato;
use Filament\Resources\Pages\EditRecord;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    // Os valores nao moram no contato, entao entram e saem do estado do formulario
    // a mao. 'campos' e descartado antes de salvar para nao virar coluna inexistente.
    protected function mutateFormDataBeforeFill(array $dados): array
    {
        $dados['campos'] = CamposDoContato::paraFormulario($this->getRecord());

        return $dados;
    }

    protected function mutateFormDataBeforeSave(array $dados): array
    {
        $this->camposPersonalizados = $dados['campos'] ?? [];
        unset($dados['campos']);

        return $dados;
    }

    protected function afterSave(): void
    {
        CamposDoContato::salvar($this->getRecord(), $this->camposPersonalizados);
    }

    private array $camposPersonalizados = [];
}
