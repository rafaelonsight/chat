<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    use \App\Filament\TraduzirResource;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Usuários';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'usuários';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    // A matriz de canais contem dados sensiveis. Somente o Super Admin gerencia usuarios.
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->gerenciaUsuarios();
    }

    public static function canCreate(): bool {return static::canViewAny();}
    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool {return static::canEdit($record);}
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        if(config('agenda.edition'))return static::canViewAny() && $record->tenant_id===auth()->user()->tenant_id && !$record->operador && !$record->super_admin;
        return static::canViewAny() && (auth()->user()->operador || (! $record->operador && ! $record->super_admin && $record->tenant_id === auth()->user()->tenant_id));
    }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }
    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit'   => EditUser::route('/{record}/edit'),
        ];
    }
}
