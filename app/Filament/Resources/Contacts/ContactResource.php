<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Filament\Resources\Contacts\Tables\ContactsTable;
use App\Models\Contact;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    /*
     * SAIU DO CRM E FOI PARA ERP > Cadastro, e o nome virou "Pessoas".
     *
     * "Contato" descreve o papel de quem escreveu uma vez; "pessoa" descreve o cadastro. E a
     * mesma ficha que aparece no atendimento — o que mudou e onde ela mora no menu e como ela se
     * chama para quem esta cadastrando, nao consultando.
     */
    protected static string|UnitEnum|null $navigationGroup = 'ERP';

    protected static ?string $navigationParentItem = 'Cadastro';

    protected static ?string $navigationLabel = 'Pessoas';

    protected static ?string $modelLabel = 'pessoa';

    protected static ?string $pluralModelLabel = 'pessoas';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'nome';

    // Atendente precisa consultar cliente: isto nao e tela de gestao.
    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function form(Schema $schema): Schema
    {
        return ContactForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'edit'   => EditContact::route('/{record}/edit'),
        ];
    }
}
