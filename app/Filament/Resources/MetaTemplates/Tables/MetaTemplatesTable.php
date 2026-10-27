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
                TextColumn::make('nome')->label(__('ui_13030dd962f4'))->searchable()->weight('medium'),

                TextColumn::make('idioma')->label(__('ui_009433886003'))->badge()->color('gray'),

                TextColumn::make('categoria')
                    ->label(__('ui_5966b0ce8b7e'))
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
                    ->label(__('ui_bbbaa52e0472'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'DRAFT' => __('Rascunho'),
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
                    ->label(__('ui_eea9c8c273d5'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'nenhuma' : $state),

                // A coluna que importa: da para usar, e se nao da, POR QUE. "Indisponivel"
                // sem motivo vira pergunta para o suporte no dia seguinte.
                TextColumn::make('suportado')
                    ->label(__('ui_a69ddabe8755').config('app.name'))
                    ->badge()
                    ->formatStateUsing(fn ($state, MetaTemplate $record) => $record->podeEnviar()
                        ? 'disponível'
                        : (string) $record->porQueNaoPodeEnviar())
                    ->color(fn ($state, MetaTemplate $record) => $record->podeEnviar() ? 'success' : 'gray')
                    ->wrap(),

                TextColumn::make('corpo')
                    ->label(__('ui_6eeba656c0f3'))
                    ->limit(60)
                    ->tooltip(fn (MetaTemplate $record) => $record->corpo)
                    ->toggleable(),

                TextColumn::make('sincronizado_em')
                    ->label(__('ui_21d92e7758e5'))
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()->visible(fn(MetaTemplate $record) => $record->status === 'DRAFT'),
                \Filament\Actions\Action::make('solicitar')->label(__('Solicitar aprovação'))->icon('heroicon-o-paper-airplane')
                    ->visible(fn(MetaTemplate $record) => $record->status === 'DRAFT')->requiresConfirmation()
                    ->modalHeading(__('Solicitar aprovação da Meta'))
                    ->modalDescription(__('O conteúdo será enviado à Meta para análise. Salvar ou solicitar não significa aprovação.'))
                    ->action(function(MetaTemplate $record) {
                        $r = app(\App\Services\Canais\CriarTemplateMeta::class)->solicitar($record);
                        \Filament\Notifications\Notification::make()->status($r['ok'] ? 'success' : 'danger')
                            ->title($r['ok'] ? __('Solicitação confirmada pela Meta') : __('Solicitação não confirmada'))
                            ->body($r['ok'] ? __('Sincronize para acompanhar o estado da análise.') : ($r['erro'] ?? __('Verifique a conexão e tente novamente.')))->persistent()->send();
                    }),
                \Filament\Actions\DeleteAction::make()->visible(fn(MetaTemplate $record) => $record->status === 'DRAFT'),
            ])
            ->defaultSort('nome')
            ->emptyStateHeading(__('ui_c8169eacf488'))
            ->emptyStateDescription(__('ui_6d0691101aef'));
    }
}
