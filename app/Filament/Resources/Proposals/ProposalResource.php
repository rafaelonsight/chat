<?php

namespace App\Filament\Resources\Proposals;

use App\Filament\Resources\Proposals\Pages\CreateProposal;
use App\Filament\Resources\Proposals\Pages\EditProposal;
use App\Filament\Resources\Proposals\Pages\ListProposals;
use App\Filament\Resources\Proposals\Schemas\ProposalForm;
use App\Filament\Resources\Proposals\Tables\ProposalsTable;
use App\Models\Proposal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProposalResource extends Resource
{
    protected static ?string $model = Proposal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'ERP';

    protected static ?string $navigationLabel = 'Propostas';

    protected static ?string $modelLabel = 'proposta';

    protected static ?string $pluralModelLabel = 'propostas';

    // Depois de Cadastro (1), no primeiro nivel do ERP.
    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'numero';

    public static function form(Schema $schema): Schema
    {
        return ProposalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProposalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProposals::route('/'),
            'create' => CreateProposal::route('/create'),
            'edit'   => EditProposal::route('/{record}/edit'),
        ];
    }
}
