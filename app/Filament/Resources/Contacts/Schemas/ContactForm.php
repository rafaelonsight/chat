<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Support\PhoneNumber;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nome')
                ->label('Nome')
                ->required()
                ->maxLength(120)
                ->helperText('O que vem do WhatsApp e o apelido que o cliente configurou. Aqui fica o nome que identifica de verdade.'),

            // O telefone e a identidade da conversa no WhatsApp: trocar depois
            // quebraria o vinculo com o historico. Editavel so na criacao.
            // Grupo nao tem telefone: o campo desaparece para ele.
            \Filament\Forms\Components\Placeholder::make('tipo_info')
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
        ]);
    }
}
