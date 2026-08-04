<?php

namespace App\Filament\Support;

use App\Models\Contact;
use App\Models\ContactField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/**
 * Transforma as definicoes de campo personalizado em componentes de formulario.
 *
 * Fica separado porque o mesmo desenho vale em tres lugares — cadastro no CRM,
 * painel do contato no atendimento e (depois) importacao — e um campo que valida
 * diferente em cada tela e pior que nao validar.
 */
class CamposDoContato
{
    /** @return array<int, \Filament\Forms\Components\Field> */
    public static function componentes(): array
    {
        return ContactField::orderBy('ordem')->orderBy('nome')->get()
            ->map(fn (ContactField $campo) => self::um($campo))
            ->all();
    }

    private static function um(ContactField $campo)
    {
        $nome = 'campos.'.$campo->id;
        $opcoes = collect($campo->opcoes ?? [])->mapWithKeys(fn ($o) => [$o => $o])->all();

        $base = match ($campo->tipo) {
            ContactField::TEXTO_LONGO => Textarea::make($nome)->rows(3)->maxLength(2000),

            ContactField::INTEIRO => TextInput::make($nome)->numeric()->integer(),

            ContactField::DECIMAL => TextInput::make($nome)->numeric(),

            ContactField::LISTA => Select::make($nome)->options($opcoes)->native(false)->searchable(),

            ContactField::MULTISELECAO => Select::make($nome)->options($opcoes)->multiple()->native(false)->searchable(),

            ContactField::DATA => DatePicker::make($nome)->native(false)->displayFormat('d/m/Y'),

            ContactField::DATA_HORA => DateTimePicker::make($nome)->native(false)->displayFormat('d/m/Y H:i'),

            ContactField::BOOLEANO => Toggle::make($nome),

            ContactField::LINK => TextInput::make($nome)->url()->maxLength(255)
                ->placeholder('https://'),

            // Digito verificador, nao so contagem de digitos: campo que aceita
            // 111.111.111-11 nao valida nada, e e justamente esse lixo que entra.
            ContactField::CPF_CNPJ => TextInput::make($nome)
                ->maxLength(18)
                ->placeholder('000.000.000-00')
                ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                    if (filled($value) && ! ContactField::cpfCnpjValido($value)) {
                        $fail('CPF ou CNPJ inválido.');
                    }
                })
                ->formatStateUsing(fn (?string $state) => ContactField::formatarCpfCnpj($state))
                ->dehydrateStateUsing(fn (?string $state) => ContactField::soDigitos($state) ?: null),

            ContactField::CEP => TextInput::make($nome)
                ->maxLength(9)
                ->placeholder('00000-000')
                ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                    if (filled($value) && strlen(ContactField::soDigitos($value)) !== 8) {
                        $fail('CEP precisa ter 8 dígitos.');
                    }
                })
                ->formatStateUsing(fn (?string $state) => ContactField::formatarCep($state))
                ->dehydrateStateUsing(fn (?string $state) => ContactField::soDigitos($state) ?: null),

            default => TextInput::make($nome)->maxLength(255),
        };

        return $base
            ->label($campo->nome)
            ->required($campo->obrigatorio)
            ->helperText($campo->ajuda);
    }

    /** Estado do formulario a partir do que esta no banco. */
    public static function paraFormulario(Contact $contato): array
    {
        $campos = ContactField::pluck('tipo', 'id')->all();
        $estado = [];

        foreach ($contato->camposPersonalizados() as $id => $valor) {
            $tipo = $campos[$id] ?? ContactField::TEXTO_CURTO;

            $estado[$id] = match ($tipo) {
                ContactField::MULTISELECAO => (array) (json_decode((string) $valor, true) ?: []),
                ContactField::BOOLEANO     => $valor === '1',
                default                    => $valor,
            };
        }

        return $estado;
    }

    /**
     * Grava os valores. Campo vazio e APAGADO em vez de guardado como string
     * vazia: senao "nunca preenchido" e "preenchido com nada" ficariam iguais no
     * banco, e o relatorio nao conseguiria distinguir.
     */
    public static function salvar(Contact $contato, array $estado): void
    {
        $campos = ContactField::pluck('tipo', 'id')->all();

        foreach ($campos as $id => $tipo) {
            $bruto = $estado[$id] ?? null;

            $valor = match ($tipo) {
                ContactField::MULTISELECAO => filled($bruto) ? json_encode(array_values((array) $bruto)) : null,
                ContactField::BOOLEANO     => $bruto ? '1' : ($bruto === false ? '0' : null),
                default                    => filled($bruto) ? (string) $bruto : null,
            };

            if ($valor === null) {
                $contato->fieldValues()->where('contact_field_id', $id)->delete();

                continue;
            }

            $contato->fieldValues()->updateOrCreate(
                ['contact_field_id' => $id],
                ['valor' => $valor],
            );
        }
    }
}
