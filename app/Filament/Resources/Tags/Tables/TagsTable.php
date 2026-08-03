<?php

namespace App\Filament\Resources\Tags\Tables;

use App\Models\Tag;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('contacts'))
            ->columns([
                // Mostra a etiqueta como ela aparece de verdade. Nome numa coluna de
                // texto simples nao diz nada sobre a cor escolhida.
                TextColumn::make('nome')
                    ->label('Etiqueta')
                    ->html()
                    ->formatStateUsing(fn ($state, Tag $record) => new HtmlString(
                        '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 '
                        .$record->classes().'">'.e($state).'</span>'
                    ))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cor')
                    ->label('Cor')
                    ->formatStateUsing(fn (?string $state) => Tag::CORES[$state] ?? $state)
                    ->toggleable(),

                TextColumn::make('contacts_count')
                    ->label('Contatos')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'primary' : 'gray')
                    ->sortable(),
            ])
            ->defaultSort('nome')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Nenhuma etiqueta ainda')
            ->emptyStateDescription('Etiqueta serve para separar contatos: quem é cliente, quem está inadimplente, quem veio de anúncio. O chatbot também pode colocá-las.');
    }
}
