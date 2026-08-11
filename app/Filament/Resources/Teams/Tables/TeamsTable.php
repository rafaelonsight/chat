<?php

namespace App\Filament\Resources\Teams\Tables;

use App\Models\Conversation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('users'))
            ->columns([
                TextColumn::make('nome')->label('Equipe')->searchable()->sortable(),
                TextColumn::make('descricao')->label('Descrição')->placeholder('—')->wrap(),

                TextColumn::make('users_count')
                    ->label('Pessoas')
                    ->badge()
                    ->sortable(),

                TextColumn::make('membros')
                    ->label('Quem')
                    ->state(fn ($record) => $record->users->pluck('name')->join(', ') ?: '—')
                    ->wrap(),

                TextColumn::make('na_fila')
                    ->label('Na fila')
                    ->state(fn ($record) => Conversation::where('team_id', $record->id)
                        ->where('status', Conversation::NOVA)
                        ->count())
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),

                /*
                 * DIZ QUE ELA E A PADRAO, em vez de so esconder o botao de apagar.
                 *
                 * Botao que desaparece sem explicacao vira suspeita de defeito: a pessoa acha
                 * que a tela quebrou. Com a etiqueta ali, a ausencia do botao passa a ter
                 * motivo visivel.
                 */
                TextColumn::make('padrao')
                    ->label('')
                    ->state(fn ($record) => $record->padrao ? 'padrão' : '')
                    ->badge()
                    ->color('warning')
                    ->tooltip(fn ($record) => $record->padrao
                        ? 'Recebe as conversas novas. Não pode ser excluída.'
                        : null),

                IconColumn::make('ativa')->label('Ativa')->boolean(),
            ])
            ->defaultSort('nome')
            ->recordActions([
                EditAction::make(),
                // Escondido, e nao desabilitado: a guarda de verdade esta no modelo (que
                // recusa a exclusao por qualquer caminho). Aqui e so nao oferecer o que vai
                // ser negado — oferecer e negar depois e pior que nao oferecer.
                DeleteAction::make()->hidden(fn ($record) => (bool) $record->padrao),
            ])
            ->emptyStateHeading('Nenhuma equipe ainda')
            ->emptyStateDescription('Sem equipe, o atendimento funciona igual ao de hoje: todos veem tudo.');
    }
}
