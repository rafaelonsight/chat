<?php

namespace App\Filament\Resources\Proposals\Schemas;

use App\Models\Contact;
use App\Models\Offering;
use App\Models\Proposal;
use App\Support\PhoneNumber;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ProposalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Para quem')->schema([
                TextInput::make('cliente_nome')
                    ->label(__('ui_87187e82c424'))
                    ->required()
                    ->maxLength(160)
                    // E o que vai GRANDE na capa. Nao e a razao social por extenso: e como o
                    // cliente se chama quando fala de si mesmo.
                    ->helperText(__('ui_3c7e8c08c0d6')),

                TextInput::make('cliente_email')->label(__('ui_0d01e2e86669'))->email()->maxLength(160),

                Select::make('contact_id')
                    ->label(__('ui_a3fc2648b422'))
                    /*
                     * O NOME PODE SER NULO, e o Filament estoura com rotulo nulo — foi um 500 na
                     * tela inteira. Contato identificado so pelo numero e o caso COMUM aqui:
                     * quem escreve pelo WhatsApp entra sem nome ate alguem batizar.
                     */
                    ->options(fn () => Contact::orderBy('nome')->limit(200)->get()
                        ->mapWithKeys(fn (Contact $c) => [
                            $c->id => $c->nome
                                ?: ($c->telefone_e164 ? PhoneNumber::discavel($c->telefone_e164) : 'Contato '.$c->id),
                        ])
                        ->all())
                    ->searchable()
                    ->helperText(__('ui_016b974daf41')),
            ])->columns(2),

            Section::make('A proposta')->schema([
                TextInput::make('titulo')
                    ->label(__('ui_98a5efa60beb'))
                    ->required()
                    ->maxLength(160)
                    ->columnSpanFull()
                    ->helperText(__('ui_53f6a4fd9a8a')),

                DatePicker::make('validade')
                    ->label(__('ui_cd9bd651be81'))
                    ->default(now()->addDays(15))
                    ->helperText(__('ui_6a0a87cacf8a')),

                /*
                 * OS SELOS RESPONDEM OBJECAO ANTES DE ELA SER FEITA.
                 *
                 * "Sem fidelidade" na capa tira do caminho a primeira pergunta de quem ja se
                 * arrependeu de assinar contrato longo. Texto livre com sugestao, e nao lista
                 * fechada: a objecao de cada venda e diferente.
                 */
                TagsInput::make('selos')
                    ->label(__('ui_42119b184a8d'))
                    ->suggestions(Proposal::SELOS)
                    ->helperText(__('ui_2d2aee83bb60')),
            ])->columns(2),

            Section::make('Conteúdo')
                ->description(__('ui_7a59d11726c0'))
                ->schema([
                    Builder::make('blocos')
                        ->hiddenLabel()
                        ->addActionLabel('Adicionar seção')
                        ->collapsible()
                        ->collapsed()
                        ->blockNumbers(false)
                        ->blocks([
                            /*
                             * TEXTO e o bloco de sempre, e continua existindo por dois motivos:
                             * as propostas ja escritas viraram tudo texto na migracao, e ha coisa
                             * que nao tem forma — "como funciona o suporte" e um paragrafo, nao
                             * uma lista numerada.
                             */
                            Builder\Block::make('texto')
                                ->label(__('ui_6eeba656c0f3'))
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    TextInput::make('titulo')->label(__('ui_296f62511d6c'))->maxLength(120),
                                    Textarea::make('corpo')->label(__('ui_6eeba656c0f3'))->rows(6)
                                        ->helperText(__('ui_5a70b862a0f0')),
                                ]),

                            /*
                             * DIAGNOSTICO E PLANO DE ACAO SAO DOIS BLOCOS, e nao um.
                             *
                             * Eles se leem em par — dor 01 e solucao 01 — e e essa leitura que
                             * faz a proposta vender: mostra que voce entendeu o problema antes de
                             * falar preco. Separados, a pagina pode numerar os dois na mesma
                             * ordem e o cliente casa um com o outro sozinho.
                             */
                            Builder\Block::make('diagnostico')
                                ->label(__('ui_c7e855e6d665'))
                                ->icon('heroicon-o-exclamation-triangle')
                                ->schema([
                                    TextInput::make('titulo')->label(__('ui_98a5efa60beb'))->default('Dificuldades identificadas')->maxLength(120),
                                    TextInput::make('chamada')->label(__('ui_6911e0b6a37c'))->maxLength(160)
                                        ->placeholder(__('ui_ab724ace2e8b')),
                                    Repeater::make('itens')
                                        ->label(__('ui_8f29a10012fd'))
                                        ->schema([
                                            TextInput::make('titulo')->label(__('ui_ee48bf4f0cff'))->required()->maxLength(120),
                                            Textarea::make('corpo')->label(__('ui_de617804c9c7'))->rows(3),
                                        ])
                                        ->itemLabel(fn (array $state): ?string => $state['titulo'] ?? null)
                                        ->addActionLabel('Adicionar dor')
                                        ->collapsed()
                                        ->reorderable(),
                                ]),

                            Builder\Block::make('solucao')
                                ->label(__('ui_8a76b230409e'))
                                ->icon('heroicon-o-check-badge')
                                ->schema([
                                    TextInput::make('titulo')->label(__('ui_98a5efa60beb'))->default('Soluções apresentadas')->maxLength(120),
                                    TextInput::make('chamada')->label(__('ui_6911e0b6a37c'))->maxLength(160)
                                        ->placeholder(__('ui_3dfe0fced5b2')),
                                    Repeater::make('itens')
                                        ->label(__('ui_fbf5e1f1a72e'))
                                        ->schema([
                                            TextInput::make('titulo')->label(__('ui_ee48bf4f0cff'))->required()->maxLength(120),
                                            Textarea::make('corpo')->label(__('ui_d9257d849f11'))->rows(3),
                                        ])
                                        ->itemLabel(fn (array $state): ?string => $state['titulo'] ?? null)
                                        ->addActionLabel('Adicionar solução')
                                        ->collapsed()
                                        ->reorderable(),
                                ]),

                            /*
                             * O CRONOGRAMA E O QUE TRANSFORMA PRECO EM PROJETO. Sem ele, a
                             * proposta e um valor e uma promessa; com ele, o cliente ve onde o
                             * dinheiro dele encosta em cada semana.
                             */
                            Builder\Block::make('cronograma')
                                ->label(__('ui_1dd9a3fe83a4'))
                                ->icon('heroicon-o-calendar-days')
                                ->schema([
                                    TextInput::make('titulo')->label(__('ui_98a5efa60beb'))->default('Etapas do projeto')->maxLength(120),
                                    Repeater::make('etapas')
                                        ->label(__('ui_dd555018beec'))
                                        ->schema([
                                            TextInput::make('periodo')->label(__('ui_9cd63d01d858'))->required()->maxLength(60)
                                                ->placeholder(__('ui_21983782527c')),
                                            TextInput::make('foco')->label(__('ui_745db6d76dab'))->maxLength(120),
                                            Repeater::make('itens')
                                                ->label(__('ui_0a30ba5d69e7'))
                                                ->simple(TextInput::make('item')->required()->maxLength(200))
                                                ->addActionLabel('Adicionar passo'),
                                        ])
                                        ->itemLabel(fn (array $state): ?string => trim(($state['periodo'] ?? '').' · '.($state['foco'] ?? ''), ' ·') ?: null)
                                        ->addActionLabel('Adicionar etapa')
                                        ->collapsed()
                                        ->reorderable(),
                                ]),

                            /*
                             * QUEM ASSINA, com cara e historia.
                             *
                             * Consultoria e servico se compram DA PESSOA. Uma foto e quatro
                             * numeros de credibilidade fazem mais que tres paragrafos de "somos
                             * uma empresa focada em".
                             */
                            Builder\Block::make('assinante')
                                ->label(__('ui_20c24213f145'))
                                ->icon('heroicon-o-user-circle')
                                ->schema([
                                    TextInput::make('nome')->label(__('ui_13030dd962f4'))->required()->maxLength(120),
                                    TextInput::make('cargo')->label(__('ui_6c98e86fc49b'))->maxLength(120),
                                    FileUpload::make('foto')
                                        ->label(__('ui_0f5e4f087245'))
                                        ->image()
                                        ->maxSize(3072)
                                        ->disk('public')
                                        ->directory('propostas/assinantes')
                                        ->visibility('public')
                                        // Sem login do outro lado: o cliente abre por link. Se o
                                        // arquivo nao for publico, a foto vira quadrado quebrado.
                                        ->helperText(__('ui_b47ac1cc8641')),
                                    Textarea::make('texto')->label(__('ui_d5273425fa12'))->rows(6)->columnSpanFull()
                                        ->helperText(__('ui_7b830b49fe40')),
                                    Repeater::make('numeros')
                                        ->label(__('ui_76116040b69d'))
                                        ->schema([
                                            TextInput::make('valor')->label(__('ui_1d91f4b8d8d6'))->required()->maxLength(20)
                                                ->placeholder('10+'),
                                            TextInput::make('rotulo')->label(__('ui_9857de63674f'))->required()->maxLength(80)
                                                ->placeholder(__('ui_cc69857bec85')),
                                        ])
                                        ->columns(2)
                                        ->columnSpanFull()
                                        ->addActionLabel('Adicionar número')
                                        ->maxItems(4)
                                        ->helperText(__('ui_451302b55508')),
                                ])->columns(2),
                        ])
                        ->default([
                            ['type' => 'texto', 'data' => ['titulo' => 'O que vimos na conversa', 'corpo' => '']],
                            ['type' => 'diagnostico', 'data' => ['titulo' => 'Dificuldades identificadas', 'itens' => []]],
                            ['type' => 'solucao', 'data' => ['titulo' => 'Soluções apresentadas', 'itens' => []]],
                            ['type' => 'cronograma', 'data' => ['titulo' => 'Etapas do projeto', 'etapas' => []]],
                        ]),
                ]),

            Section::make('Investimento')
                ->description(__('ui_4dbf3a864a10'))
                ->schema([
                    Repeater::make('itens')
                        ->relationship()
                        ->hiddenLabel()
                        ->schema([
                            /*
                             * ESCOLHER DO CATALOGO COPIA OS VALORES, e nao os aponta.
                             *
                             * De proposito: a proposta e um documento. Se o preco do catalogo
                             * subir amanha, a proposta que o cliente tem na mao nao pode subir
                             * com ele — e se ela apontasse para o catalogo, subiria. O que fica
                             * guardado e a ligacao (offering_id), para relatorio por item
                             * vendido; o preco fica congelado na linha.
                             */
                            Select::make('offering_id')
                                ->label(__('ui_97edea0fd952'))
                                ->options(fn () => Offering::query()->ativos()->orderBy('nome')->pluck('nome', 'id')->all())
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set): void {
                                    $oferta = Offering::find($state);

                                    if (! $oferta) {
                                        return;
                                    }

                                    $set('descricao', $oferta->nome);
                                    $set('valor_unitario', (float) $oferta->preco);
                                    $set('recorrente', (bool) $oferta->recorrente);
                                })
                                ->columnSpan(2)
                                ->helperText(__('ui_3fbad4106b78')),

                            TextInput::make('descricao')->label(__('ui_469768598e0f'))->required()->columnSpan(3),
                            TextInput::make('quantidade')->label(__('ui_08d439aeeeec'))->numeric()->default(1),
                            TextInput::make('valor_unitario')->label('Valor (R$)')->numeric()->default(0),
                            Toggle::make('recorrente')->label(__('ui_6cb71f655e29'))->inline(false),
                        ])
                        ->columns(8)
                        ->addActionLabel('Adicionar item')
                        ->reorderable()
                        ->itemLabel(fn (array $state): ?string => $state['descricao'] ?? null),

                    TextInput::make('desconto')
                        ->label('Desconto (R$)')
                        ->numeric()
                        ->default(0)
                        ->helperText(__('ui_0be13cef2842')),

                    /*
                     * O VALOR CHEIO E O QUE FAZ O DESCONTO EXISTIR PARA QUEM LE.
                     *
                     * "R$ 3.299" e um preco. "R$ 3.500, e R$ 3.299 para pagamento em dia" e uma
                     * escolha — e a pagina so mostra o riscado quando ele e MAIOR que o proposto,
                     * porque o contrario seria anunciar aumento.
                     */
                    TextInput::make('valor_cheio_unico')
                        ->label('Valor cheio da implantação (R$)')
                        ->numeric()
                        ->helperText(__('ui_74e444907457')),

                    TextInput::make('valor_cheio_recorrente')
                        ->label('Valor cheio da mensalidade (R$)')
                        ->numeric()
                        ->helperText(__('ui_82ade541aae4')),

                    TextInput::make('vencimento_dia')
                        ->label(__('ui_e4272d1019eb'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(28)
                        // 29, 30 e 31 nao existem em todo mes: o banco recusa acima de 28.
                        ->helperText(__('ui_532378d57900')),

                    DatePicker::make('primeiro_pagamento')
                        ->label(__('ui_a8681b0fdceb'))
                        ->helperText(__('ui_f0bb1beba1b3')),
                ])->columns(2),
        ]);
    }
}
