<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Filament\Support\CamposDoContato;
use App\Models\Contact;
use App\Models\ContactField;
use App\Services\ConsultaCep;
use App\Services\ConsultaCnpj;
use App\Support\Documento;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->columns(2)
                ->schema([
                    /*
                     * PESSOA FISICA OU JURIDICA, ANTES DE TUDO.
                     *
                     * Vem antes do nome de proposito: e a escolha que muda o resto da ficha.
                     * Perguntar depois obrigaria a pessoa a refazer o que ja digitou quando os
                     * campos trocassem embaixo dela.
                     *
                     * Botoes e nao lista suspensa: sao duas opcoes, e duas opcoes escondidas atras
                     * de um clique e um clique cobrado sem motivo.
                     */
                    ToggleButtons::make('natureza')
                        ->label('Tipo de cadastro')
                        ->options([
                            Contact::FISICA => 'Pessoa física',
                            Contact::JURIDICA => 'Pessoa jurídica',
                        ])
                        ->icons([
                            Contact::FISICA => 'heroicon-o-user',
                            Contact::JURIDICA => 'heroicon-o-building-office-2',
                        ])
                        ->default(Contact::FISICA)
                        ->inline()
                        ->live()
                        ->columnSpanFull(),

                    /*
                     * OS PAPEIS SAO VARIOS, e nao um.
                     *
                     * A mesma empresa e cliente e fornecedora; o tecnico tambem e colaborador. Com
                     * campo unico, ou o cadastro mente ou a pessoa e cadastrada duas vezes — e ai
                     * o historico de conversa fica partido entre duas fichas, que e o pior dos dois.
                     */
                    Select::make('papeis')
                        ->label('Tipo de pessoa')
                        ->multiple()
                        ->options(Contact::PAPEIS)
                        ->columnSpanFull()
                        ->helperText('Pode marcar mais de um: quem compra de você também pode te fornecer.'),

                    /*
                     * O DOCUMENTO, E O PREENCHIMENTO PELA RECEITA.
                     *
                     * Dispara ao SAIR do campo, e nao a cada tecla: um CNPJ tem catorze digitos, e
                     * consultar a cada um seria treze idas a rede jogadas fora.
                     *
                     * Guarda so os digitos e mostra pontuado: assim "84.123.456/0001-70" e
                     * "84123456000170" sao a mesma empresa na hora de procurar.
                     */
                    TextInput::make('documento')
                        ->label(fn (Get $get) => $get('natureza') === Contact::JURIDICA ? 'CNPJ' : 'CPF')
                        ->maxLength(20)
                        ->live(onBlur: true)
                        ->afterStateUpdated(self::preencherPeloCnpj())
                        ->rule(fn () => function (string $attribute, $value, $fail) {
                            if (! Documento::valido($value)) {
                                $fail(Documento::rotulo($value).' inválido: confira os dígitos.');
                            }
                        })
                        ->dehydrateStateUsing(fn ($state) => Documento::digitos($state) ?: null)
                        ->formatStateUsing(fn ($state) => Documento::formatar($state))
                        ->helperText(fn (Get $get) => $get('natureza') === Contact::JURIDICA
                            ? 'Digite e saia do campo: buscamos razão social, endereço e contato na Receita.'
                            : null),

                    DatePicker::make('nascimento')
                        ->label('Nascimento')
                        ->maxDate(now())
                        ->visible(fn (Get $get) => $get('natureza') !== Contact::JURIDICA),

                    TextInput::make('razao_social')
                        ->label('Razão social')
                        ->maxLength(160)
                        ->columnSpanFull()
                        ->visible(fn (Get $get) => $get('natureza') === Contact::JURIDICA),

                    TextInput::make('nome_fantasia')
                        ->label('Nome fantasia')
                        ->maxLength(160)
                        ->columnSpanFull()
                        ->visible(fn (Get $get) => $get('natureza') === Contact::JURIDICA)
                        ->helperText('É por ele que o cliente se reconhece — e é ele que aparece no atendimento.'),

                    /*
                     * OBRIGATORIO SO QUANDO NAO HA OUTRO NOME.
                     *
                     * A empresa preenchida pelo CNPJ ja chega com razao social e fantasia. Exigir
                     * que alguem digite um terceiro nome a mao seria pedir para repetir o que a
                     * Receita acabou de responder — e o campo apareceria vazio e vermelho num
                     * cadastro que, na pratica, esta completo.
                     */
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required(fn (Get $get) => blank($get('nome_fantasia')) && blank($get('razao_social')))
                        ->maxLength(120)
                        ->columnSpanFull()
                        ->helperText(fn (Get $get) => $get('natureza') === Contact::JURIDICA
                            ? 'Opcional na pessoa jurídica: sem ele, o atendimento chama a empresa pelo nome fantasia.'
                            : 'O que vem do WhatsApp é o apelido que o cliente configurou. Aqui fica o nome que identifica de verdade.'),

                    // O telefone e a identidade da conversa no WhatsApp: trocar depois
                    // quebraria o vinculo com o historico. Editavel so na criacao.
                    // Grupo nao tem telefone: o campo desaparece para ele.
                    Placeholder::make('tipo_info')
                        ->label('Tipo')
                        ->content(fn ($record) => $record?->eGrupo() ? 'Grupo de WhatsApp' : 'Pessoa')
                        ->visibleOn('edit'),

                    TextInput::make('telefone_e164')
                        ->label('Telefone')
                        ->tel()
                        // Telefone OU documento: quem chega por conversa tem numero, quem chega
                        // por cadastro tem CPF ou CNPJ. Exigir os dois de todos so produziria
                        // telefone inventado na ficha do fornecedor.
                        ->required(fn (string $operation, Get $get) => $operation === 'create' && blank($get('documento')))
                        ->validationMessages([
                            'required' => 'Informe o telefone — ou o documento, se esta pessoa não fala pelo WhatsApp.',
                        ])
                        ->hidden(fn ($record) => (bool) $record?->eGrupo())
                        ->disabledOn('edit')
                        ->helperText(fn (string $operation) => $operation === 'edit'
                            ? 'Nao pode mudar: e a identidade da conversa no WhatsApp.'
                            : 'Com DDD. Ex.: (84) 99614-3373')
                        ->rule(fn () => function (string $attribute, $value, $fail) {
                            // Vazio e caso do 'required' acima decidir, nao deste: aqui so se julga
                            // numero que existe. Sem esta guarda, a ficha sem telefone reclamaria
                            // de numero invalido — erro que aponta para o lugar errado.
                            if (filled($value) && ! PhoneNumber::toE164($value)) {
                                $fail('Numero invalido. Informe DDD + numero.');
                            }
                        })
                        // Vazio guarda nulo, e nao string vazia: '' passaria a valer como telefone
                        // e duas fichas sem telefone brigariam entre si na conferencia de repetido.
                        ->dehydrateStateUsing(fn ($state) => filled($state)
                            ? (PhoneNumber::toE164($state) ?? $state)
                            : null)
                        ->unique(ignoreRecord: true),

                    TextInput::make('email')
                        ->label('E-mail')
                        ->email()
                        ->maxLength(160),

                    TextInput::make('instagram')
                        ->label('Instagram')
                        ->prefix('@')
                        ->maxLength(60)
                        // Cola a url inteira e o modelo guarda so o usuario.
                        ->dehydrateStateUsing(fn ($state) => Contact::normalizarInstagram($state))
                        ->helperText('Pode colar o @, o usuário ou a url do perfil.'),
                ]),

            Section::make('Endereço')
                ->columns(6)
                ->schema([
                    TextInput::make('cep')
                        ->label('CEP')
                        ->columnSpan(2)
                        ->maxLength(9)
                        // Busca ao DIGITAR, com meio segundo de folga. O que evita
                        // martelar a ViaCEP nao e esperar o blur, e a guarda dos 8
                        // digitos dentro do callback: antes disso nao ha o que
                        // consultar. Esperar o blur obrigava a sair do campo para o
                        // endereco aparecer, o que nao e automatico.
                        ->live(debounce: 500)
                        ->afterStateUpdated(self::preencherPeloCep())
                        ->dehydrateStateUsing(fn ($state) => ConsultaCep::digitos($state) ?: null)
                        ->helperText('Digite os 8 dígitos e o endereço se preenche sozinho.'),

                    TextInput::make('logradouro')
                        ->label('Logradouro')
                        ->columnSpan(4)
                        ->maxLength(160),

                    TextInput::make('numero')
                        ->label('Número')
                        ->columnSpan(2)
                        ->maxLength(20),

                    TextInput::make('complemento')
                        ->label('Complemento')
                        ->columnSpan(4)
                        ->maxLength(160),

                    TextInput::make('bairro')
                        ->label('Bairro')
                        ->columnSpan(3)
                        ->maxLength(120),

                    TextInput::make('cidade')
                        ->label('Cidade')
                        ->columnSpan(2)
                        ->maxLength(120),

                    TextInput::make('uf')
                        ->label('UF')
                        ->columnSpan(1)
                        ->maxLength(2)
                        ->dehydrateStateUsing(fn ($state) => $state ? mb_strtoupper($state) : null),
                ]),

            // Só aparece quando ha campo definido: secao vazia no formulario e ruido
            // que faz o usuario procurar o que preencher.
            Section::make('Campos personalizados')
                ->schema(array_merge(CamposDoContato::componentes(), [
                    // Criar o campo aqui, e nao so em Configuracoes: quem esta
                    // cadastrando o cliente e quem descobre que falta um campo, e
                    // mandar essa pessoa a outra tela faz o cadastro pela metade.
                    Actions::make([
                        Action::make('novoCampo')
                            ->label('Novo campo personalizado')
                            ->icon('heroicon-o-plus')
                            ->link()
                            ->modalHeading('Adicionar novo campo personalizado')
                            ->modalSubmitActionLabel('Salvar')
                            ->schema([
                                TextInput::make('nome')
                                    ->label('Nome do campo')
                                    ->required()
                                    ->maxLength(60),

                                Select::make('tipo')
                                    ->label('Tipo')
                                    ->options(ContactField::TIPOS)
                                    ->default(ContactField::TEXTO_CURTO)
                                    ->required()
                                    ->native(false)
                                    ->live(),

                                Repeater::make('opcoes')
                                    ->label('Opções')
                                    ->simple(TextInput::make('opcao')->required())
                                    ->visible(fn (Get $get) => in_array(
                                        $get('tipo'), ContactField::COM_OPCOES, true
                                    ))
                                    ->minItems(1)
                                    ->addActionLabel('Adicionar opção'),
                            ])
                            ->action(function (array $data, $livewire) {
                                ContactField::create([
                                    'nome' => $data['nome'],
                                    'tipo' => $data['tipo'],
                                    'opcoes' => $data['opcoes'] ?? [],
                                    'ordem' => (int) ContactField::max('ordem') + 1,
                                ]);

                                Notification::make()
                                    ->success()
                                    ->title('Campo criado')
                                    ->send();

                                // Recarrega a tela: o formulario e montado a partir
                                // das definicoes, e sem recarregar o campo novo so
                                // apareceria na proxima visita.
                                $livewire->redirect(url()->current(), navigate: false);
                            }),
                    ]),
                ]))
                ->columns(2),
        ]);
    }

    /**
     * Preenche o que o CEP determina — rua, bairro, cidade e UF. Numero e
     * complemento nao entram: numero o CEP nao sabe, e o "complemento" que a
     * ViaCEP devolve e faixa postal ("ate 600 - lado par"), nao o apartamento
     * de quem mora ali.
     */
    /**
     * Preenche a ficha com o que a Receita sabe.
     *
     * SO PARA PESSOA JURIDICA e so quando o documento fecha como CNPJ: chamar a consulta com um CPF
     * seria uma ida a rede garantidamente perdida e um aviso de erro que a pessoa nao causou.
     *
     * E NAO SOBRESCREVE O QUE JA ESTA ESCRITO. Quem digitou o telefone certo antes de lembrar do
     * CNPJ nao pode ver o proprio dado trocado pelo que a Receita tem — que costuma ser o telefone
     * da abertura da empresa, de dez anos atras.
     *
     * O AVISO DE DUPLICADO importa mais que parece: cadastrar a mesma empresa duas vezes parte o
     * historico de conversa entre duas fichas, e ai nenhuma das duas conta a verdade.
     */
    private static function preencherPeloCnpj(): callable
    {
        return function (?string $state, Set $set, Get $get, ?Contact $record): void {
            if ($get('natureza') !== Contact::JURIDICA || ! ConsultaCnpj::valido($state)) {
                return;
            }

            $digitos = Documento::digitos($state);

            $repetido = Contact::query()
                ->where('documento', $digitos)
                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->first();

            if ($repetido) {
                Notification::make()->warning()
                    ->title('Esse CNPJ já está cadastrado')
                    ->body('Em "'.$repetido->nomeExibicao().'". Vale editar aquela ficha em vez de criar outra.')
                    ->persistent()
                    ->send();
            }

            $resultado = app(ConsultaCnpj::class)->consultar($state);

            if (! $resultado['ok']) {
                Notification::make()->warning()
                    ->title('CNPJ não preencheu')
                    ->body($resultado['erro'])
                    ->send();

                return;
            }

            $dados = $resultado['dados'];

            // So o que estiver vazio.
            $sePreencher = function (string $campo, mixed $valor) use ($set, $get): void {
                if (filled($valor) && blank($get($campo))) {
                    $set($campo, $valor);
                }
            };

            foreach ([
                'razao_social', 'nome_fantasia', 'email', 'cep', 'logradouro',
                'numero', 'complemento', 'bairro', 'cidade', 'uf',
            ] as $campo) {
                $sePreencher($campo, $dados[$campo] ?? null);
            }

            $sePreencher('telefone_e164', $dados['telefone'] ?? null);

            Notification::make()->success()
                ->title('Dados da Receita preenchidos')
                ->body(trim(implode(' · ', array_filter([
                    $dados['razao_social'] ?? null,
                    $dados['situacao_cadastral'] ?? null,
                ]))))
                ->send();
        };
    }

    private static function preencherPeloCep(): callable
    {
        return function (?string $state, Set $set): void {
            if (! ConsultaCep::valido($state)) {
                return;
            }

            $resultado = app(ConsultaCep::class)->consultar($state);

            if (! $resultado['ok']) {
                Notification::make()->warning()
                    ->title('CEP não preencheu')
                    ->body($resultado['erro'])
                    ->send();

                return;
            }

            $set('cep', $resultado['dados']['cep']);

            foreach (['logradouro', 'bairro', 'cidade', 'uf'] as $campo) {
                if (filled($resultado['dados'][$campo] ?? null)) {
                    $set($campo, $resultado['dados'][$campo]);
                }
            }
        };
    }
}
