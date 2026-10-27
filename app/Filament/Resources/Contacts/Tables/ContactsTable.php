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
                    ->label(__('ui_13030dd962f4'))
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
                    ->label(__('ui_6cc6198d9e75'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Contact::PAPEIS[$state] ?? $state)
                    ->color('gray')
                    ->placeholder('—'),

                TextColumn::make('telefone_e164')
                    ->label(__('ui_04e0410282ed'))
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Numero copiado')
                    ->placeholder('—'),

                // Guardado em digitos, mostrado pontuado — e a busca aceita os dois jeitos,
                // porque ninguem lembra se digitou com ponto quando vai procurar.
                TextColumn::make('documento')
                    ->label(__('ui_5b8520c93e44'))
                    ->formatStateUsing(fn (?string $state) => Documento::formatar($state))
                    ->copyable()
                    ->copyMessage('Documento copiado')
                    ->searchable(query: fn ($query, string $search) => $query->where(
                        'documento', 'like', '%'.Documento::digitos($search).'%'
                    ))
                    ->placeholder('—'),

                TextColumn::make('instagram')
                    ->label(__('ui_5721bbef40b2'))
                    ->searchable()
                    ->formatStateUsing(fn (?string $state) => $state ? '@'.$state : null)
                    ->url(fn (Contact $record) => $record->instagramUrl(), shouldOpenInNewTab: true)
                    ->color('primary')
                    ->placeholder('—'),

                TextColumn::make('email')
                    ->label(__('ui_0d01e2e86669'))
                    ->searchable()
                    ->copyable()
                    ->copyMessage('E-mail copiado')
                    ->placeholder('—'),

                // As etiquetas como pilulas de verdade: o nome sem a cor perde
                // metade do sentido de ter escolhido a cor.
                TextColumn::make('tags.nome')
                    ->label(__('ui_d830f4246090'))
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
                    ->label(__('ui_6cc6198d9e75'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'grupo' ? 'Grupo' : 'Pessoa')
                    ->color(fn (string $state) => $state === 'grupo' ? 'info' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cidade')
                    ->label(__('ui_1a3285047365'))
                    ->formatStateUsing(fn ($state, Contact $record) => trim(implode('/', array_filter([
                        $record->cidade, $record->uf,
                    ]))) ?: null)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('conversations_count')
                    ->label(__('ui_2132daa4a06c'))
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ultimo_atendimento')
                    ->label(__('ui_d422714b5be5'))
                    ->state(fn ($record) => Conversation::where('contact_id', $record->id)
                        ->max('ultima_msg_em'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('nunca')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('ui_5fb92273bb5c'))
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // blank oculta de proposito: a lista do dia a dia e a dos ativos, e
                // quem foi arquivado ou bloqueado nao deve competir por atencao.
                TernaryFilter::make('arquivado_em')
                    ->label(__('ui_40f199dd8197'))
                    ->placeholder(__('ui_35a48ac2a877'))
                    ->trueLabel('Só arquivados')
                    ->falseLabel('Sem arquivados')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('arquivado_em'),
                        false: fn ($query) => $query->whereNull('arquivado_em'),
                        blank: fn ($query) => $query->whereNull('arquivado_em'),
                    ),

                TernaryFilter::make('bloqueado_em')
                    ->label(__('ui_2d06484b33d9'))
                    ->placeholder(__('ui_cf9eb16aa32b'))
                    ->trueLabel('Só bloqueados')
                    ->falseLabel('Sem bloqueados')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('bloqueado_em'),
                        false: fn ($query) => $query->whereNull('bloqueado_em'),
                        blank: fn ($query) => $query->whereNull('bloqueado_em'),
                    ),

                SelectFilter::make('papel')
                    ->label(__('ui_15b66b9bf5a9'))
                    ->options(fn () => \App\Support\Idiomas::opcoes(Contact::PAPEIS))
                    // Coluna json: o filtro pergunta se a lista CONTEM o papel, e nao se e igual
                    // a ele — a mesma pessoa pode ser cliente e fornecedora ao mesmo tempo.
                    ->query(fn ($query, array $data) => filled($data['value'])
                        ? $query->whereJsonContains('papeis', $data['value'])
                        : $query),

                SelectFilter::make('natureza')
                    ->label(__('ui_7c409fe6fac4'))
                    ->options(fn () => \App\Support\Idiomas::opcoes([
                        Contact::FISICA => 'Física',
                        Contact::JURIDICA => 'Jurídica',
                    ])),

                Filter::make('so_grupos')
                    ->label(__('ui_065103cdf4f1'))
                    ->query(fn ($query) => $query->where('tipo', 'grupo')),

                Filter::make('sem_nome')
                    ->label(__('ui_6fdd5039f114'))
                    ->query(fn ($query) => $query->whereNull('nome')),

                Filter::make('sem_atendimento')
                    ->label(__('ui_f9a9ca7907f2'))
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
                    ->modalDescription(__('ui_38075a7c7643'))
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
                    ->label(__('ui_775ffa0a0dd2'))
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
                    ->label(__('ui_1502d65284aa'))
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->visible(fn (Contact $record): bool => (bool) auth()->user()?->admin && ! $record->anonimizado())
                    ->requiresConfirmation()
                    ->modalHeading(__('ui_d5f3ff2f46fd'))
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
                    ->modalSubmitActionLabel(__('ui_2e1dbd653a05'))
                    ->action(function (Contact $record) {
                        $r = app(DadosDoContato::class)->anonimizar($record);

                        Notification::make()
                            ->title(__('ui_b73abb52e45c'))
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
                            ->label(__('ui_cbeec536b2f8'))
                            ->maxLength(120)
                            ->helperText(__('ui_82392f25475b')),
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
            ->emptyStateHeading(__('ui_4ea5841227f5'))
            ->emptyStateDescription(__('ui_cf1ef15ca185'));
    }
}
