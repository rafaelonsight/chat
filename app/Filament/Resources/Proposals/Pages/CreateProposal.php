<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProposal extends CreateRecord
{
    protected static string $resource = ProposalResource::class;

    /*
     * O autor fica gravado: e quem o cliente vai procurar, e e quem recebe o aviso do aceite.
     */
    protected function mutateFormDataBeforeCreate(array $dados): array
    {
        $dados['criada_por'] = auth()->id();

        return $dados;
    }

    protected function afterCreate(): void
    {
        $this->record->load('itens')->recalcular();
    }
}
