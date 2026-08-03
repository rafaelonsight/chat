<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Models\Conversation;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('conversations'))
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->placeholder('sem nome'),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'grupo' ? 'Grupo' : 'Pessoa')
                    ->color(fn (string $state) => $state === 'grupo' ? 'info' : 'gray'),

                TextColumn::make('telefone_e164')
                    ->label('Telefone')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Numero copiado'),

                TextColumn::make('conversations_count')
                    ->label('Atendimentos')
                    ->badge()
                    ->sortable(),

                TextColumn::make('ultimo_atendimento')
                    ->label('Ultimo atendimento')
                    ->state(fn ($record) => Conversation::where('contact_id', $record->id)
                        ->max('ultima_msg_em'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('nunca'),

                TextColumn::make('created_at')
                    ->label('Contato desde')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('so_grupos')
                    ->label('Somente grupos')
                    ->query(fn ($query) => $query->where('tipo', 'grupo')),

                Filter::make('sem_nome')
                    ->label('Sem nome definido')
                    ->query(fn ($query) => $query->whereNull('nome')),

                Filter::make('sem_atendimento')
                    ->label('Nunca atendidos')
                    ->query(fn ($query) => $query->whereDoesntHave('conversations')),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('Nenhum contato ainda')
            ->emptyStateDescription('Os contatos aparecem sozinhos quando alguem manda mensagem.');
    }
}
