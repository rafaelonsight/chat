<?php

namespace App\Filament\Resources\Chatbots\Tables;

use App\Filament\Resources\Chatbots\ChatbotResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChatbotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('steps'))
            ->columns([
                TextColumn::make('nome')->label('Fluxo')->searchable()->sortable(),

                TextColumn::make('channel.nome')
                    ->label('Canal')
                    ->placeholder('Todos'),

                // Passos do fluxo, e nao "opcoes" da arvore antiga. A coluna antiga
                // contava nodes, que estavam sempre em zero: a lista mostrava "Opções: 0"
                // para um fluxo com quatro passos funcionando.
                TextColumn::make('steps_count')
                    ->label('Passos')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'publicado' ? 'Publicado' : 'Rascunho')
                    ->color(fn (?string $state) => $state === 'publicado' ? 'success' : 'warning'),

                IconColumn::make('ativo')->label('Ativo')->boolean(),
            ])
            ->defaultSort('nome')
            ->recordActions([
                // O fluxo e onde o trabalho acontece; abrir direto da lista evita
                // que o usuario tenha que descobrir onde fica.
                Action::make('fluxo')
                    ->label('Abrir fluxo')
                    ->icon('heroicon-o-share')
                    ->url(fn ($record) => ChatbotResource::getUrl('fluxo', ['record' => $record])),

                EditAction::make()->label('Ajustes'),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Nenhum fluxo ainda')
            ->emptyStateDescription('Sem fluxo ativo, o atendimento funciona igual ao de hoje: a mensagem cai em Novos e uma pessoa responde.');
    }
}
