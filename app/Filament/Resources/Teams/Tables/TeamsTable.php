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
                TextColumn::make('nome')->label(__('ui_e03e3996dc23'))->searchable()->sortable(),
                TextColumn::make('descricao')->label(__('ui_469768598e0f'))->placeholder('—')->wrap(),

                TextColumn::make('users_count')
                    ->label(__('ui_c9929703a8da'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('membros')
                    ->label(__('ui_2af502b70a02'))
                    ->state(fn ($record) => $record->users->pluck('name')->join(', ') ?: '—')
                    ->wrap(),

                TextColumn::make('na_fila')
                    ->label(__('ui_a3ecf1f4868b'))
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

                IconColumn::make('ativa')->label(__('ui_f21c4ff2bbb3'))->boolean(),
            ])
            ->defaultSort('nome')
            ->recordActions([
                EditAction::make(),
                // Escondido, e nao desabilitado: a guarda de verdade esta no modelo (que
                // recusa a exclusao por qualquer caminho). Aqui e so nao oferecer o que vai
                // ser negado — oferecer e negar depois e pior que nao oferecer.
                DeleteAction::make()->hidden(fn ($record) => (bool) $record->padrao),
            ])
            ->emptyStateHeading(__('ui_0c2383cb24ca'))
            ->emptyStateDescription(__('ui_a13a6f47ded7'));
    }
}
