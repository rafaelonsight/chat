<?php

namespace App\Filament\Resources\Offerings;

use App\Filament\Resources\Offerings\Pages\ListOfferings;
use App\Models\Offering;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class OfferingResource extends Resource
{
    use \App\Filament\Concerns\PertenceAoModulo;

    protected static string $modulo = 'vendas';

    use \App\Filament\TraduzirResource;

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return false;
    }
    protected static ?string $model = Offering::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'ERP';

    // O terceiro nivel do menu: este item se pendura no item "Cadastro".
    protected static ?string $navigationParentItem = 'Cadastro';

    protected static ?string $navigationLabel = 'Produtos e serviços';

    protected static ?string $modelLabel = 'produto ou serviço';

    protected static ?string $pluralModelLabel = 'produtos e serviços';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'nome';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nome')->label(__('ui_13030dd962f4'))->required()->maxLength(160)->columnSpan(2),

            Select::make('tipo')
                ->label(__('ui_6cc6198d9e75'))
                ->options(fn () => \App\Support\Idiomas::opcoes([Offering::SERVICO => 'Serviço', Offering::PRODUTO => 'Produto']))
                ->default(Offering::SERVICO)
                ->required()
                ->live()
                ->afterStateUpdated(self::sugerirCodigo()),

            /*
             * O CODIGO INTERNO JA VEM SUGERIDO.
             *
             * Cadastrar o primeiro servico nao pode comecar com a pergunta "que numero eu coloco
             * aqui?". A sugestao responde antes de a duvida existir — e continua editavel, porque
             * quem ja tem codigo de outro sistema tem de poder usar o dele.
             */
            TextInput::make('codigo')
                ->label(__('ui_8e1e7b4006cb'))
                ->maxLength(40)
                ->default(fn () => Offering::proximoCodigo(Offering::SERVICO))
                /*
                 * A CONFERENCIA DE REPETIDO E POR CONTA, e nao pela tabela toda: o codigo S-0001
                 * da Onsight nada tem com o S-0001 da VEX. Sem o recorte, o Filament olharia todos
                 * os inquilinos e recusaria um codigo que esta livre aqui.
                 *
                 * E ela precisa existir: duas telas de cadastro abertas ao mesmo tempo recebem a
                 * MESMA sugestao. Sem a conferencia, a segunda a salvar bateria na restricao do
                 * banco e a tela devolveria erro 500 em vez de dizer o que fazer.
                 */
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule
                    ->where('tenant_id', TenantContext::get()))
                ->helperText(__('ui_28e11dee9809')),

            Textarea::make('descricao')
                ->label(__('ui_469768598e0f'))
                ->rows(3)
                ->columnSpanFull()
                ->helperText(__('ui_0789702e7527')),

            TextInput::make('preco')->label('Preço (R$)')->numeric()->default(0)->required(),

            TextInput::make('unidade')
                ->label(__('ui_b1e91af082de'))
                ->maxLength(20)
                ->placeholder(__('ui_89ab716f1e3c'))
                ->helperText(__('ui_eea50392b555')),

            Toggle::make('recorrente')
                ->label(__('ui_9ba36f1da940'))
                // A decisao vive AQUI e nao na proposta: "plataforma" e mensal por natureza.
                // Deixar para o momento de montar a proposta convida o erro que mais dói —
                // cobrar mensalidade como parcela unica, ou o contrario.
                ->helperText(__('ui_e5d0a4a280c0')),

            Toggle::make('ativo')->label(__('ui_8263fde6ccc4'))->default(true),
        ])->columns(2);
    }

    /**
     * Trocar o tipo troca a sugestao do codigo — mas so quando a sugestao ainda e nossa.
     *
     * SO SOBRESCREVE O QUE NOS MESMOS ESCREVEMOS: campo vazio, ou valor identico a uma das duas
     * sugestoes atuais. Codigo digitado a mao sobrevive a troca de tipo — apagar o que a pessoa
     * escreveu para acertar um detalhe nosso seria a troca errada.
     */
    private static function sugerirCodigo(): callable
    {
        return function (?string $state, Get $get, Set $set): void {
            $atual = $get('codigo');

            $nossas = [
                Offering::proximoCodigo(Offering::SERVICO),
                Offering::proximoCodigo(Offering::PRODUTO),
            ];

            if (blank($atual) || in_array($atual, $nossas, true)) {
                $set('codigo', Offering::proximoCodigo($state ?: Offering::SERVICO));
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')->label(__('ui_13030dd962f4'))->searchable()->sortable()->wrap(),

                TextColumn::make('tipo')
                    ->label(__('ui_6cc6198d9e75'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === Offering::PRODUTO ? 'Produto' : 'Serviço')
                    ->color(fn (string $state) => $state === Offering::PRODUTO ? 'info' : 'gray'),

                TextColumn::make('preco')->label(__('ui_2f11b88a8d68'))->money('BRL')->alignEnd()->sortable(),

                IconColumn::make('recorrente')->label(__('ui_6cb71f655e29'))->boolean(),

                TextColumn::make('unidade')->label(__('ui_b1e91af082de'))->placeholder('—'),

                IconColumn::make('ativo')->label(__('ui_8263fde6ccc4'))->boolean(),
            ])
            ->defaultSort('nome')
            ->emptyStateHeading(__('ui_89dc525d0042'))
            ->emptyStateDescription(__('ui_ca867e2e3d0c'));
    }

    public static function getPages(): array
    {
        return ['index' => ListOfferings::route('/')];
    }
}
