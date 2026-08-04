<?php

namespace App\Filament\Resources\Tags\Tables;

use App\Models\Tag;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
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

                // Ponto antes do nome: quem procura "aquela azul" acha pela cor.
                TextColumn::make('cor')
                    ->label('Cor')
                    ->html()
                    ->formatStateUsing(fn ($state, Tag $record) => new HtmlString(
                        '<span class="inline-flex items-center gap-1.5">'
                        .'<span class="size-3 rounded-full ring-1 ring-black/10 dark:ring-white/20 '
                        .$record->pontinho().'"></span>'
                        .e($record->corLabel()).'</span>'
                    ))
                    ->toggleable(),

                TextColumn::make('contacts_count')
                    ->label('Contatos')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'primary' : 'gray')
                    ->sortable(),
            ])
            ->defaultSort('nome')
            // Mesma largura da criacao: a grade de 24 cores tem 12 colunas.
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Editar etiqueta')
                    ->modalWidth(Width::Large),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Nenhuma etiqueta ainda')
            ->emptyStateDescription('Etiqueta serve para separar contatos: quem é cliente, quem está inadimplente, quem veio de anúncio. O chatbot também pode colocá-las.');
    }
}
