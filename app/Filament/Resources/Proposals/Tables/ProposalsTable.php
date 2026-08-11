<?php

namespace App\Filament\Resources\Proposals\Tables;

use App\Models\Proposal;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProposalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * $query e nao $q: o Filament injeta parametro de closure PELO NOME. Com outro
             * nome ele passa null, e a tela inteira responde 500 com "withCount() on null" —
             * erro que nao parece ser de nome de variavel nenhuma.
             */
            ->modifyQueryUsing(fn ($query) => $query->withCount('visualizacoes'))
            ->columns([
                TextColumn::make('numero')->label('Número')->searchable()->sortable(),

                TextColumn::make('cliente_nome')->label('Cliente')->searchable()->wrap(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    // Vencida nao e status guardado: e a data. Mostrar aqui evita a proposta
                    // aparecer como "enviada" tres meses depois de ter perdido a validade.
                    ->state(fn (Proposal $r) => $r->vencida() ? 'vencida' : $r->status)
                    ->color(fn (string $state) => match ($state) {
                        'aceita'   => 'success',
                        'recusada' => 'danger',
                        'vista'    => 'warning',
                        'vencida'  => 'gray',
                        'enviada'  => 'info',
                        default    => 'gray',
                    }),

                TextColumn::make('visualizacoes_count')
                    ->label('Aberturas')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    // O numero de aberturas e o sinal de venda: tres aberturas sem resposta e
                    // hora de ligar, e uma proposta com zero nao foi lida ainda.
                    ->tooltip(fn (Proposal $r) => $r->vista_em
                        ? 'Primeira em '.$r->vista_em->format('d/m H:i')
                        : 'Ainda não abriu'),

                TextColumn::make('total_unico')
                    ->label('Uma vez')
                    ->money('BRL')
                    ->alignEnd(),

                TextColumn::make('total_recorrente')
                    ->label('Mensal')
                    ->money('BRL')
                    ->alignEnd(),

                TextColumn::make('validade')->label('Válida até')->date('d/m/Y')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                /*
                 * COPIAR O LINK e a acao mais usada da tela, e por isso e a primeira.
                 *
                 * Ela tambem MARCA COMO ENVIADA: quem copia o link vai mandar. Exigir um segundo
                 * botao "marcar como enviada" seria burocracia que ninguem lembra de cumprir, e
                 * ai o rastreamento comeca errado — a primeira abertura chegaria numa proposta
                 * ainda em rascunho.
                 */
                Action::make('link')
                    ->label('Copiar link')
                    ->icon(Heroicon::OutlinedLink)
                    ->color('gray')
                    ->action(function (Proposal $record) {
                        $record->marcarEnviada();

                        Notification::make()
                            ->success()
                            ->title('Link copiado')
                            ->body(route('proposta', $record->token))
                            ->send();
                    })
                    ->extraAttributes(fn (Proposal $record) => [
                        'x-on:click' => 'navigator.clipboard.writeText("'.route('proposta', $record->token).'")',
                    ]),

                Action::make('abrir')
                    ->label('Ver')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Proposal $record) => route('proposta', $record->token))
                    ->openUrlInNewTab(),

                EditAction::make(),

                /*
                 * O botao aparece so no rascunho.
                 *
                 * Proposta ENVIADA e documento na mao do cliente — o modelo recusa apagar, e
                 * oferecer para negar depois e pior que nao oferecer. Quem precisa tirar da
                 * lista marca como recusada, que preserva o registro.
                 */
                DeleteAction::make()->hidden(fn (Proposal $record) => $record->enviada_em !== null),
            ])
            ->emptyStateHeading('Nenhuma proposta ainda')
            ->emptyStateDescription('A proposta é uma página com link: você vê quando o cliente abriu e ele aceita ali mesmo.');
    }
}
