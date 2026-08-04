<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Notifications\Notification;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // with('tags') porque a coluna de etiquetas percorre a relacao: sem
            // isso e uma consulta por linha da tabela.
            ->modifyQueryUsing(fn ($query) => $query->with('tags')->withCount('conversations'))
            ->columns([
                // Avatar e nome na mesma coluna: sao a mesma informacao — quem e.
                TextColumn::make('nome')
                    ->label('Nome')
                    ->html()
                    ->formatStateUsing(fn ($state, Contact $record) => new HtmlString(
                        '<div class="flex items-center gap-2.5">'
                        .'<span class="flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold '
                        .$record->corAvatar().'">'.e($record->iniciais()).'</span>'
                        .'<span class="font-medium">'.e($record->nomeExibicao()).'</span>'
                        .'</div>'
                    ))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Contact $record) => match (true) {
                        $record->bloqueado() => 'bloqueado'.($record->bloqueio_motivo ? ': '.$record->bloqueio_motivo : ''),
                        $record->arquivado() => 'arquivado',
                        default              => null,
                    }),

                TextColumn::make('telefone_e164')
                    ->label('Telefone')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Numero copiado')
                    ->placeholder('—'),

                TextColumn::make('instagram')
                    ->label('Instagram')
                    ->searchable()
                    ->formatStateUsing(fn (?string $state) => $state ? '@'.$state : null)
                    ->url(fn (Contact $record) => $record->instagramUrl(), shouldOpenInNewTab: true)
                    ->color('primary')
                    ->placeholder('—'),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('E-mail copiado')
                    ->placeholder('—'),

                // As etiquetas como pilulas de verdade: o nome sem a cor perde
                // metade do sentido de ter escolhido a cor.
                TextColumn::make('tags.nome')
                    ->label('Etiquetas')
                    ->html()
                    ->state(function (Contact $record) {
                        if ($record->tags->isEmpty()) {
                            return null;
                        }

                        return new HtmlString('<div class="flex flex-wrap gap-1">'.$record->tags
                            ->map(fn (Tag $t) => '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 '
                                .$t->classes().'">'.e($t->nome).'</span>')
                            ->implode('').'</div>');
                    })
                    ->searchable(query: fn ($query, string $search) => $query->whereHas(
                        'tags', fn ($q) => $q->where('tags.nome', 'ilike', "%{$search}%")
                    ))
                    ->placeholder('—'),

                // O que existia antes continua alcancavel, so nao ocupa a tela:
                // as cinco colunas de cima sao as que se olha todo dia.
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'grupo' ? 'Grupo' : 'Pessoa')
                    ->color(fn (string $state) => $state === 'grupo' ? 'info' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cidade')
                    ->label('Cidade')
                    ->formatStateUsing(fn ($state, Contact $record) => trim(implode('/', array_filter([
                        $record->cidade, $record->uf,
                    ]))) ?: null)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('conversations_count')
                    ->label('Atendimentos')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ultimo_atendimento')
                    ->label('Ultimo atendimento')
                    ->state(fn ($record) => Conversation::where('contact_id', $record->id)
                        ->max('ultima_msg_em'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('nunca')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Contato desde')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // blank oculta de proposito: a lista do dia a dia e a dos ativos, e
                // quem foi arquivado ou bloqueado nao deve competir por atencao.
                TernaryFilter::make('arquivado_em')
                    ->label('Arquivados')
                    ->placeholder('Sem arquivados')
                    ->trueLabel('Só arquivados')
                    ->falseLabel('Sem arquivados')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('arquivado_em'),
                        false: fn ($query) => $query->whereNull('arquivado_em'),
                        blank: fn ($query) => $query->whereNull('arquivado_em'),
                    ),

                TernaryFilter::make('bloqueado_em')
                    ->label('Bloqueados')
                    ->placeholder('Sem bloqueados')
                    ->trueLabel('Só bloqueados')
                    ->falseLabel('Sem bloqueados')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('bloqueado_em'),
                        false: fn ($query) => $query->whereNull('bloqueado_em'),
                        blank: fn ($query) => $query->whereNull('bloqueado_em'),
                    ),

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
            // Acima da tabela, nao atras do funil: filtro escondido nao e usado, e
            // "tem arquivado que eu nao estou vendo?" e pergunta de todo dia.
            ->filtersLayout(FiltersLayout::AboveContent)
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),

                Action::make('arquivar')
                    ->label(fn (Contact $record) => $record->arquivado() ? 'Desarquivar' : 'Arquivar')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->modalDescription('Arquivar tira o contato da lista do dia a dia. Ele continua podendo conversar normalmente.')
                    ->requiresConfirmation(fn (Contact $record) => ! $record->arquivado())
                    ->action(function (Contact $record) {
                        $record->update(['arquivado_em' => $record->arquivado() ? null : now()]);

                        Notification::make()->success()
                            ->title($record->arquivado() ? 'Contato arquivado' : 'Contato desarquivado')
                            ->send();
                    }),

                // Bloquear FAZ efeito: o motor do chatbot e a resposta automatica
                // checam isto. Interruptor que nao impede o robo de responder nao
                // bloqueia nada.
                Action::make('bloquear')
                    ->label(fn (Contact $record) => $record->bloqueado() ? 'Desbloquear' : 'Bloquear')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Contact $record) => $record->bloqueado()
                        ? 'O contato volta a ser atendido pelo chatbot e pela resposta automática.'
                        : 'Bloqueado, ele NÃO recebe resposta do chatbot nem resposta automática. As mensagens dele continuam chegando no atendimento, para uma pessoa decidir.')
                    ->schema(fn (Contact $record) => $record->bloqueado() ? [] : [
                        \Filament\Forms\Components\TextInput::make('motivo')
                            ->label('Motivo')
                            ->maxLength(120)
                            ->helperText('Opcional, mas ajuda quem revisar depois.'),
                    ])
                    ->action(function (Contact $record, array $data) {
                        $record->update($record->bloqueado()
                            ? ['bloqueado_em' => null, 'bloqueio_motivo' => null]
                            : ['bloqueado_em' => now(), 'bloqueio_motivo' => $data['motivo'] ?? null]);

                        Notification::make()->success()
                            ->title($record->bloqueado() ? 'Contato bloqueado' : 'Contato desbloqueado')
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Nenhum contato ainda')
            ->emptyStateDescription('Os contatos aparecem sozinhos quando alguem manda mensagem.');
    }
}
