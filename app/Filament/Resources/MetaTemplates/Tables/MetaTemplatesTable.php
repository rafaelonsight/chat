<?php

namespace App\Filament\Resources\MetaTemplates\Tables;

use App\Models\MetaTemplate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MetaTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')->label('Nome')->searchable()->weight('medium'),

                TextColumn::make('idioma')->label('Idioma')->badge()->color('gray'),

                TextColumn::make('categoria')
                    ->label('Categoria')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'UTILITY'        => 'utilidade',
                        'MARKETING'      => 'marketing',
                        'AUTHENTICATION' => 'autenticação',
                        default          => mb_strtolower((string) $state),
                    })
                    // A categoria decide o PRECO do envio, entao ela fica visivel: nao e
                    // detalhe tecnico, e a diferenca entre uma conversa barata e uma caha.
                    ->color(fn (?string $state) => $state === 'MARKETING' ? 'warning' : 'gray'),

                TextColumn::make('status')
                    ->label('Na Meta')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'APPROVED' => 'aprovado',
                        'PENDING'  => 'em análise',
                        'REJECTED' => 'reprovado',
                        'PAUSED'   => 'pausado',
                        'DISABLED' => 'desativado',
                        default    => mb_strtolower((string) $state),
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'APPROVED'            => 'success',
                        'PENDING'             => 'warning',
                        'REJECTED', 'DISABLED' => 'danger',
                        default               => 'gray',
                    }),

                TextColumn::make('variaveis')
                    ->label('Variáveis')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'nenhuma' : $state),

                // A coluna que importa: da para usar, e se nao da, POR QUE. "Indisponivel"
                // sem motivo vira pergunta para o suporte no dia seguinte.
                TextColumn::make('suportado')
                    ->label('Envio pelo '.config('app.name'))
                    ->badge()
                    ->formatStateUsing(fn ($state, MetaTemplate $record) => $record->podeEnviar()
                        ? 'disponível'
                        : (string) $record->porQueNaoPodeEnviar())
                    ->color(fn ($state, MetaTemplate $record) => $record->podeEnviar() ? 'success' : 'gray')
                    ->wrap(),

                TextColumn::make('corpo')
                    ->label('Texto')
                    ->limit(60)
                    ->tooltip(fn (MetaTemplate $record) => $record->corpo)
                    ->toggleable(),

                TextColumn::make('sincronizado_em')
                    ->label('Sincronizado')
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('nome')
            ->emptyStateHeading('Nenhum template sincronizado')
            ->emptyStateDescription('Os templates sao criados no painel da Meta. Clique em Sincronizar para traze-los.');
    }
}
