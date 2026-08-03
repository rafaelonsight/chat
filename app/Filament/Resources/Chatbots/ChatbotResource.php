<?php

namespace App\Filament\Resources\Chatbots;

use App\Filament\Resources\Chatbots\Pages\CreateChatbot;
use App\Filament\Resources\Chatbots\Pages\EditChatbot;
use App\Filament\Resources\Chatbots\Pages\ListChatbots;
use App\Filament\Resources\Chatbots\RelationManagers\NodesRelationManager;
use App\Filament\Resources\Chatbots\Schemas\ChatbotForm;
use App\Filament\Resources\Chatbots\Tables\ChatbotsTable;
use App\Models\Chatbot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ChatbotResource extends Resource
{
    protected static ?string $model = Chatbot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Aplicações';

    protected static ?string $navigationLabel = 'Chatbot';

    protected static ?string $modelLabel = 'fluxo';

    protected static ?string $pluralModelLabel = 'fluxos';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'chatbot';

    protected static ?string $recordTitleAttribute = 'nome';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public static function form(Schema $schema): Schema
    {
        return ChatbotForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChatbotsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [NodesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListChatbots::route('/'),
            'create' => CreateChatbot::route('/create'),
            'edit'   => EditChatbot::route('/{record}/edit'),
        ];
    }
}
