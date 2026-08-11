<?php

namespace App\Filament\Resources\Proposals\Schemas;

use App\Models\Contact;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                    // E o que vai GRANDE na capa. Nao e o nome da empresa dele por extenso com
                    // razao social: e como o cliente se chama quando fala de si mesmo.
                    ->helperText('Aparece grande na capa. "Ótica Central", não "Ótica Central Comércio de Óculos LTDA".'),

                TextInput::make('cliente_email')->label('E-mail')->email()->maxLength(160),

                Select::make('contact_id')
                    ->label('Contato no CRM')
                    /*
                     * O NOME PODE SER NULO, e o Filament estoura com rotulo nulo — foi um 500
                     * na tela inteira. Contato identificado so pelo numero e o caso COMUM aqui:
                     * quem escreve pelo WhatsApp entra sem nome ate alguem batizar.
                     *
                     * O telefone como reserva resolve duas coisas: a tela nao quebra, e a lista
                     * nao mostra uma linha em branco impossivel de escolher.
                     */
                    ->options(fn () => Contact::orderBy('nome')->limit(200)->get()
                        ->mapWithKeys(fn (Contact $c) => [
                            $c->id => $c->nome
                                ?: ($c->telefone_e164 ? \App\Support\PhoneNumber::discavel($c->telefone_e164) : 'Contato '.$c->id),
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
                    ->helperText('Depois disso a página não aceita mais — e diz para falar com você.'),

                TextInput::make('desconto')
                    ->label('Desconto (R$)')
                    ->numeric()
                    ->default(0)
                    ->helperText('Sai da implantação, não da mensalidade.'),
            ])->columns(2),

            Section::make('Conteúdo')
                ->description('Cada bloco é uma seção da página. Escreva em parágrafos; o enter vira enter.')
                ->schema([
                    Repeater::make('blocos')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('titulo')->label('Título da seção')->maxLength(120),
                            Textarea::make('corpo')->label('Texto')->rows(6),
                        ])
                        ->itemLabel(fn (array $state): ?string => $state['titulo'] ?? null)
                        ->addActionLabel('Adicionar seção')
                        ->collapsible()
                        ->reorderable()
                        ->default([
                            ['titulo' => 'O que vimos na conversa', 'corpo' => ''],
                            ['titulo' => 'O que entregamos', 'corpo' => ''],
                            ['titulo' => 'Prazo', 'corpo' => ''],
                            ['titulo' => 'Condições', 'corpo' => ''],
                        ]),
                ]),

            Section::make('Investimento')
                ->description('Marque "mensal" no que se repete. A página separa os dois totais.')
                ->schema([
                    Repeater::make('itens')
                        ->relationship()
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('descricao')->label('Descrição')->required()->columnSpan(3),
                            TextInput::make('quantidade')->label('Qtd')->numeric()->default(1),
                            TextInput::make('valor_unitario')->label('Valor (R$)')->numeric()->default(0),
                            Toggle::make('recorrente')->label('Mensal')->inline(false),
                        ])
                        ->columns(6)
                        ->addActionLabel('Adicionar item')
                        ->reorderable()
                        ->itemLabel(fn (array $state): ?string => $state['descricao'] ?? null),
                ]),
        ]);
    }
}
