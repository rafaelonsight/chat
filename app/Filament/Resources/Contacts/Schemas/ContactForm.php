<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Models\Contact;
use App\Services\ConsultaCep;
use App\Support\PhoneNumber;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use App\Filament\Support\CamposDoContato;
use App\Models\ContactField;
use Filament\Schemas\Components\Section;
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
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(120)
                        ->columnSpanFull()
                        ->helperText('O que vem do WhatsApp e o apelido que o cliente configurou. Aqui fica o nome que identifica de verdade.'),

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
                        ->required(fn (string $operation) => $operation === 'create')
                        ->hidden(fn ($record) => (bool) $record?->eGrupo())
                        ->disabledOn('edit')
                        ->helperText(fn (string $operation) => $operation === 'edit'
                            ? 'Nao pode mudar: e a identidade da conversa no WhatsApp.'
                            : 'Com DDD. Ex.: (84) 99614-3373')
                        ->rule(fn () => function (string $attribute, $value, $fail) {
                            if (! PhoneNumber::toE164($value)) {
                                $fail('Numero invalido. Informe DDD + numero.');
                            }
                        })
                        ->dehydrateStateUsing(fn ($state) => PhoneNumber::toE164($state) ?? $state)
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
                    \Filament\Schemas\Components\Actions::make([
                        \Filament\Actions\Action::make('novoCampo')
                            ->label('Novo campo personalizado')
                            ->icon('heroicon-o-plus')
                            ->link()
                            ->modalHeading('Adicionar novo campo personalizado')
                            ->modalSubmitActionLabel('Salvar')
                            ->schema([
                                \Filament\Forms\Components\TextInput::make('nome')
                                    ->label('Nome do campo')
                                    ->required()
                                    ->maxLength(60),

                                \Filament\Forms\Components\Select::make('tipo')
                                    ->label('Tipo')
                                    ->options(\App\Models\ContactField::TIPOS)
                                    ->default(\App\Models\ContactField::TEXTO_CURTO)
                                    ->required()
                                    ->native(false)
                                    ->live(),

                                \Filament\Forms\Components\Repeater::make('opcoes')
                                    ->label('Opções')
                                    ->simple(\Filament\Forms\Components\TextInput::make('opcao')->required())
                                    ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => in_array(
                                        $get('tipo'), \App\Models\ContactField::COM_OPCOES, true
                                    ))
                                    ->minItems(1)
                                    ->addActionLabel('Adicionar opção'),
                            ])
                            ->action(function (array $data, $livewire) {
                                \App\Models\ContactField::create([
                                    'nome'   => $data['nome'],
                                    'tipo'   => $data['tipo'],
                                    'opcoes' => $data['opcoes'] ?? [],
                                    'ordem'  => (int) \App\Models\ContactField::max('ordem') + 1,
                                ]);

                                \Filament\Notifications\Notification::make()
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
