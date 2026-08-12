<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Services\DadosDoContato;
use App\Support\Documento;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
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
                        default => null,
                    }),

                // O papel vem logo depois do nome porque e a segunda pergunta de quem abre a lista:
                // quem e essa pessoa para o negocio. Etiqueta e outra coisa — ela e do atendimento.
                TextColumn::make('papeis')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Contact::PAPEIS[$state] ?? $state)
                    ->color('gray')
                    ->placeholder('—'),

                TextColumn::make('telefone_e164')
                    ->label('Telefone')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Numero copiado')
                    ->placeholder('—'),

                // Guardado em digitos, mostrado pontuado — e a busca aceita os dois jeitos,
                // porque ninguem lembra se digitou com ponto quando vai procurar.
                TextColumn::make('documento')
                    ->label('CPF / CNPJ')
                    ->formatStateUsing(fn (?string $state) => Documento::formatar($state))
                    ->copyable()
                    ->copyMessage('Documento copiado')
                    ->searchable(query: fn ($query, string $search) => $query->where(
                        'documento', 'like', '%'.Documento::digitos($search).'%'
                    ))
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

                SelectFilter::make('papel')
                    ->label('Tipo de pessoa')
                    ->options(Contact::PAPEIS)
                    // Coluna json: o filtro pergunta se a lista CONTEM o papel, e nao se e igual
                    // a ele — a mesma pessoa pode ser cliente e fornecedora ao mesmo tempo.
                    ->query(fn ($query, array $data) => filled($data['value'])
                        ? $query->whereJsonContains('papeis', $data['value'])
                        : $query),

                SelectFilter::make('natureza')
                    ->label('Pessoa')
                    ->options([
                        Contact::FISICA => 'Física',
                        Contact::JURIDICA => 'Jurídica',
                    ]),

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
                // ---- LGPD: acesso e eliminacao. So administrador: as duas acoes lidam
                // com pedido legal do titular, e a segunda e irreversivel.
                Action::make('exportar_lgpd')
                    ->label('Exportar dados')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => (bool) auth()->user()?->admin)
                    ->action(function (Contact $record) {
                        $servico = app(DadosDoContato::class);
                        $dados = $servico->exportar($record);
                        $nome = $servico->nomeDoArquivo($record);

                        return response()->streamDownload(function () use ($dados) {
                            echo json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }, $nome, ['Content-Type' => 'application/json; charset=utf-8']);
                    }),

                Action::make('anonimizar_lgpd')
                    ->label('Apagar dados (LGPD)')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->visible(fn (Contact $record): bool => (bool) auth()->user()?->admin && ! $record->anonimizado())
                    ->requiresConfirmation()
                    ->modalHeading('Apagar os dados desta pessoa?')
                    // O texto diz exatamente o que some e o que fica. Confirmacao que nao
                    // explica a consequencia e so um clique a mais.
                    ->modalDescription(new HtmlString(
                        '<p class="mb-2"><strong>Some para sempre:</strong> nome, telefone, e-mail, '
                        .'endereço, campos personalizados, o texto de todas as mensagens, as '
                        .'transcrições de áudio e os arquivos enviados.</p>'
                        .'<p class="mb-2"><strong>Fica:</strong> que houve atendimento, em que dia e '
                        .'quantas mensagens — sem conteúdo. Isso é registro da empresa, exigido por '
                        .'obrigação fiscal e contábil, e é o que impede os relatórios dos meses '
                        .'passados de mudarem sozinhos.</p>'
                        .'<p><strong>Não tem como desfazer.</strong></p>'
                    ))
                    ->modalSubmitActionLabel('Apagar os dados')
                    ->action(function (Contact $record) {
                        $r = app(DadosDoContato::class)->anonimizar($record);

                        Notification::make()
                            ->title('Dados removidos')
                            ->body("{$r['mensagens']} mensagem(ns) em {$r['conversas']} conversa(s), "
                                ."e {$r['arquivos']} arquivo(s) apagado(s) do disco.")
                            ->success()
                            ->send();
                    }),

                Action::make('bloquear')
                    ->label(fn (Contact $record) => $record->bloqueado() ? 'Desbloquear' : 'Bloquear')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Contact $record) => $record->bloqueado()
                        ? 'O contato volta a ser atendido pelo chatbot e pela resposta automática.'
                        : 'Bloqueado, ele NÃO recebe resposta do chatbot nem resposta automática. As mensagens dele continuam chegando no atendimento, para uma pessoa decidir.')
                    ->schema(fn (Contact $record) => $record->bloqueado() ? [] : [
                        TextInput::make('motivo')
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
