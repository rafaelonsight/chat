<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Resources\Pages\EditRecord;

class EditProposal extends EditRecord
{
    protected static string $resource = ProposalResource::class;

    /*
     * Recalcula depois de salvar: mexer num item sem refazer os totais deixaria a pagina do
     * cliente mostrando um numero que nao corresponde as linhas acima dele.
     */
    protected function afterSave(): void
    {
        $this->record->load('itens')->recalcular();
    }
}
