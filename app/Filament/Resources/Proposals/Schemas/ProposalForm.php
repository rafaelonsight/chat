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
                    ->label('Nome do cliente')
                    ->required()
                    ->maxLength(160)
                    // E o que vai GRANDE na capa. Nao e a razao social por extenso: e como o
                    // cliente se chama quando fala de si mesmo.
                    ->helperText('Aparece grande na capa. "Ótica Central", não "Ótica Central Comércio de Óculos LTDA".'),

                TextInput::make('cliente_email')->label('E-mail')->email()->maxLength(160),

                Select::make('contact_id')
                    ->label('Contato no CRM')
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
                    ->helperText('Opcional. Ligando ao contato, a proposta entra no histórico dele.'),
            ])->columns(2),

            Section::make('A proposta')->schema([
                TextInput::make('titulo')
                    ->label('Título')
                    ->required()
                    ->maxLength(160)
                    ->columnSpanFull()
                    ->helperText('Uma frase sobre o que ele leva. Não "Proposta Comercial".'),

                DatePicker::make('validade')
                    ->label('Válida até')
                    ->default(now()->addDays(15))
                    ->helperText('A página mostra o prazo contando, e depois disso não aceita mais.'),

                /*
                 * OS SELOS RESPONDEM OBJECAO ANTES DE ELA SER FEITA.
                 *
                 * "Sem fidelidade" na capa tira do caminho a primeira pergunta de quem ja se
                 * arrependeu de assinar contrato longo. Texto livre com sugestao, e nao lista
                 * fechada: a objecao de cada venda e diferente.
                 */
                TagsInput::make('selos')
                    ->label('Selos da capa')
                    ->suggestions(Proposal::SELOS)
                    ->helperText('Frases curtas de tranquilidade. Três no máximo — mais que isso vira ruído.'),
            ])->columns(2),

            Section::make('Conteúdo')
                ->description('Cada bloco é uma seção da página. O tipo do bloco decide o desenho — e cobra a estrutura de quem escreve.')
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
                                ->label('Texto')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    TextInput::make('titulo')->label('Título da seção')->maxLength(120),
                                    Textarea::make('corpo')->label('Texto')->rows(6)
                                        ->helperText('O enter vira enter na página. Não precisa escrever HTML.'),
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
                                ->label('Diagnóstico — o que trava hoje')
                                ->icon('heroicon-o-exclamation-triangle')
                                ->schema([
                                    TextInput::make('titulo')->label('Título')->default('Dificuldades identificadas')->maxLength(120),
                                    TextInput::make('chamada')->label('Linha de apoio')->maxLength(160)
                                        ->placeholder('Pontos críticos que travam o crescimento hoje'),
                                    Repeater::make('itens')
                                        ->label('As dores, na ordem')
                                        ->schema([
                                            TextInput::make('titulo')->label('Em poucas palavras')->required()->maxLength(120),
                                            Textarea::make('corpo')->label('O que acontece na prática')->rows(3),
                                        ])
                                        ->itemLabel(fn (array $state): ?string => $state['titulo'] ?? null)
                                        ->addActionLabel('Adicionar dor')
                                        ->collapsed()
                                        ->reorderable(),
                                ]),

                            Builder\Block::make('solucao')
                                ->label('Plano de ação — como resolvemos')
                                ->icon('heroicon-o-check-badge')
                                ->schema([
                                    TextInput::make('titulo')->label('Título')->default('Soluções apresentadas')->maxLength(120),
                                    TextInput::make('chamada')->label('Linha de apoio')->maxLength(160)
                                        ->placeholder('Como vamos resolver, ponto a ponto'),
                                    Repeater::make('itens')
                                        ->label('As soluções, na MESMA ordem das dores')
                                        ->schema([
                                            TextInput::make('titulo')->label('Em poucas palavras')->required()->maxLength(120),
                                            Textarea::make('corpo')->label('O que será feito')->rows(3),
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
                                ->label('Cronograma por etapa')
                                ->icon('heroicon-o-calendar-days')
                                ->schema([
                                    TextInput::make('titulo')->label('Título')->default('Etapas do projeto')->maxLength(120),
                                    Repeater::make('etapas')
                                        ->label('Etapas')
                                        ->schema([
                                            TextInput::make('periodo')->label('Quando')->required()->maxLength(60)
                                                ->placeholder('Semana 1, Mês 2…'),
                                            TextInput::make('foco')->label('O foco da etapa')->maxLength(120),
                                            Repeater::make('itens')
                                                ->label('O que acontece')
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
                                ->label('Quem assina')
                                ->icon('heroicon-o-user-circle')
                                ->schema([
                                    TextInput::make('nome')->label('Nome')->required()->maxLength(120),
                                    TextInput::make('cargo')->label('Cargo')->maxLength(120),
                                    FileUpload::make('foto')
                                        ->label('Foto')
                                        ->image()
                                        ->maxSize(3072)
                                        ->disk('public')
                                        ->directory('propostas/assinantes')
                                        ->visibility('public')
                                        // Sem login do outro lado: o cliente abre por link. Se o
                                        // arquivo nao for publico, a foto vira quadrado quebrado.
                                        ->helperText('Fica pública: a página é aberta por link, sem login.'),
                                    Textarea::make('texto')->label('A história')->rows(6)->columnSpanFull()
                                        ->helperText('Por que você, e não outro. Concreto: onde você já esteve, o que já resolveu.'),
                                    Repeater::make('numeros')
                                        ->label('Números de credibilidade')
                                        ->schema([
                                            TextInput::make('valor')->label('Número')->required()->maxLength(20)
                                                ->placeholder('10+'),
                                            TextInput::make('rotulo')->label('Do que')->required()->maxLength(80)
                                                ->placeholder('anos atendendo provedores'),
                                        ])
                                        ->columns(2)
                                        ->columnSpanFull()
                                        ->addActionLabel('Adicionar número')
                                        ->maxItems(4)
                                        ->helperText('Até quatro. Cinco números viram tabela, e tabela ninguém lê.'),
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
                ->description('Marque "mensal" no que se repete. A página separa os dois totais, e nunca soma um com o outro.')
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
                                ->label('Do catálogo')
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
                                ->helperText('Preenche a linha. Depois dá para editar: o preço fica congelado aqui.'),

                            TextInput::make('descricao')->label('Descrição')->required()->columnSpan(3),
                            TextInput::make('quantidade')->label('Qtd')->numeric()->default(1),
                            TextInput::make('valor_unitario')->label('Valor (R$)')->numeric()->default(0),
                            Toggle::make('recorrente')->label('Mensal')->inline(false),
                        ])
                        ->columns(8)
                        ->addActionLabel('Adicionar item')
                        ->reorderable()
                        ->itemLabel(fn (array $state): ?string => $state['descricao'] ?? null),

                    TextInput::make('desconto')
                        ->label('Desconto (R$)')
                        ->numeric()
                        ->default(0)
                        ->helperText('Sai da implantação, não da mensalidade.'),

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
                        ->helperText('Opcional. Aparece riscado ao lado do total.'),

                    TextInput::make('valor_cheio_recorrente')
                        ->label('Valor cheio da mensalidade (R$)')
                        ->numeric()
                        ->helperText('Opcional. Aparece riscado ao lado da mensalidade.'),

                    TextInput::make('vencimento_dia')
                        ->label('Vence todo dia')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(28)
                        // 29, 30 e 31 nao existem em todo mes: o banco recusa acima de 28.
                        ->helperText('De 1 a 28. Dia 29 em diante não existe em todo mês.'),

                    DatePicker::make('primeiro_pagamento')
                        ->label('Primeiro pagamento')
                        ->helperText('Fecha a dúvida que sempre volta no dia seguinte ao aceite.'),
                ])->columns(2),
        ]);
    }
}
