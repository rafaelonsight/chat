<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
                    ->sortable(),

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
