<?php
namespace App\Filament\Resources\KnowledgeDocuments;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Forms\Components\{TextInput,Textarea,Select,Toggle,FileUpload};
use Filament\Tables\Columns\{TextColumn,IconColumn};
use Illuminate\Database\Eloquent\{Model,Builder};
class KnowledgeDocumentResource extends Resource {
 use \App\Filament\TraduzirResource;
 protected static ?string $model=KnowledgeDocument::class;
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-book-open';
 protected static string|\UnitEnum|null $navigationGroup='Aplicações';
 protected static ?string $navigationLabel='Base de conhecimento';
 protected static ?string $modelLabel='conteúdo';
 protected static ?string $pluralModelLabel='Base de conhecimento';
 protected static ?string $slug='base-conhecimento';
 protected static ?int $navigationSort=4;
 public static function canViewAny():bool{return (bool)auth()->user()?->admin&&(bool)TenantContext::get();}
 public static function canCreate():bool{return static::canViewAny();}
 public static function canEdit(Model $r):bool{return static::canViewAny()&&(int)$r->tenant_id===(int)TenantContext::get();}
 public static function canDelete(Model $r):bool{return false;}
 public static function getEloquentQuery():Builder{return parent::getEloquentQuery()->where('tenant_id',TenantContext::get()??0);}
 public static function form(Schema $s):Schema{return $s->schema([
  TextInput::make('titulo')->label(__('Título'))->required()->maxLength(160),
  Select::make('idioma')->label(__('Idioma do conteúdo'))->options(['en_GB'=>'English (UK)','pt_BR'=>'Português (Brasil)'])->default('en_GB')->required(),
  FileUpload::make('importar')->label(__('Importar TXT ou Markdown'))->storeFiles(false)->maxSize(400)->dehydrated(false)->afterStateUpdated(function($state,$set){if(is_array($state))$state=reset($state);if($state instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)$set('conteudo',\App\Services\KnowledgeBase::importar($state->getRealPath(),$state->getClientOriginalName()));})->helperText(__('Texto UTF-8. A importação preenche o conteúdo abaixo para revisão.'))->columnSpanFull(),
  Textarea::make('conteudo')->label(__('Conteúdo de referência'))->required()->rows(8)->maxLength(100000)->helperText(__('Inclua informações, serviços e perguntas frequentes. Vincule este conteúdo aos agentes de IA.'))->columnSpanFull(),
  Toggle::make('ativo')->label(__('Disponível para os agentes'))->default(true),
 ]);}
 public static function table(Table $t):Table{return $t->columns([TextColumn::make('titulo')->label(__('Título'))->searchable(),TextColumn::make('idioma')->label(__('Idioma')),IconColumn::make('ativo')->label(__('Ativo'))->boolean(),TextColumn::make('updated_at')->label(__('Atualizado em'))->dateTime('d/m/Y H:i')])->recordActions([\Filament\Actions\EditAction::make()]);}
 public static function getPages():array{return ['index'=>Pages\ListKnowledgeDocuments::route('/')];}
}
