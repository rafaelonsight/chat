<?php
namespace App\Filament\Resources\ChatbotIntegrations;
use App\Models\ChatbotIntegration;use App\Support\TenantContext;
use Filament\Resources\Resource;use Filament\Schemas\Schema;use Filament\Tables\Table;
use Filament\Forms\Components\{TextInput,Select,Toggle};use Filament\Tables\Columns\{TextColumn,IconColumn};use Illuminate\Database\Eloquent\{Model,Builder};
class ChatbotIntegrationResource extends Resource {
    use \App\Filament\TraduzirResource;

 protected static ?string $model=ChatbotIntegration::class;
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-link';
 protected static string|\UnitEnum|null $navigationGroup='Aplicações';
 protected static ?string $navigationLabel='Integrações do chatbot';protected static ?string $modelLabel='integração';protected static ?string $pluralModelLabel='integrações';protected static ?string $slug='integracoes-chatbot';protected static ?string $recordTitleAttribute='nome';protected static ?int $navigationSort=4;
 public static function canViewAny():bool{return (bool)auth()->user()?->admin && (bool)TenantContext::get();}
 public static function canCreate():bool{return static::canViewAny();}public static function canEdit(Model $record):bool{return static::canViewAny()&&(int)$record->tenant_id===(int)TenantContext::get();}public static function canDelete(Model $record):bool{return false;}
 public static function getEloquentQuery():Builder{return parent::getEloquentQuery()->where('tenant_id',TenantContext::get()??0);}
 public static function form(Schema $schema):Schema{return $schema->schema([
 TextInput::make('nome')->label(__('ui_13030dd962f4'))->required()->maxLength(120),
 TextInput::make('url')->label(__('ui_fd7fb3f80d40'))->url()->required()->maxLength(2048)->helperText(__('ui_ae9fb8669227')),
 Select::make('metodo')->label(__('ui_49ddd875379e'))->options(fn () => \App\Support\Idiomas::opcoes(['POST'=>'POST — enviar dados','GET'=>'GET — consultar dados']))->required()->default('POST'),
 TextInput::make('timeout')->label(__('ui_6acc55c07949'))->numeric()->integer()->minValue(1)->maxValue(20)->required()->default(15),
 TextInput::make('token')->label(__('ui_51b5a21f1bbf'))->password()->maxLength(2000)->afterStateHydrated(fn(TextInput $component)=>$component->state(null))->dehydrated(fn($state)=>filled($state))->helperText(__('ui_44a986a73d23')),
 Toggle::make('ativa')->label(__('ui_0e0c57609eb4'))->default(true),
 ]);}
 public static function table(Table $table):Table{return $table->columns([TextColumn::make('nome')->label(__('ui_13030dd962f4'))->searchable(),TextColumn::make('metodo')->label(__('ui_49ddd875379e')),TextColumn::make('url')->label(__('ui_ef764c1db7f8'))->limit(70),TextColumn::make('timeout')->label(__('ui_6564b9c8a6b0')),IconColumn::make('ativa')->label(__('ui_86ba1a7ce714'))->boolean()])->recordActions([\Filament\Actions\EditAction::make()]);}
 public static function getPages():array{return ['index'=>Pages\ListChatbotIntegrations::route('/'),'edit'=>Pages\EditChatbotIntegration::route('/{record}/edit')];}
}
