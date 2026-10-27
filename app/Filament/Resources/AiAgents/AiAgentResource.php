<?php
namespace App\Filament\Resources\AiAgents;
use App\Models\AiAgent;
use App\Support\TenantContext;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Forms\Components\{TextInput,Textarea,Select,Toggle};
use Filament\Tables\Columns\{TextColumn,IconColumn};
use Illuminate\Database\Eloquent\{Model,Builder};
class AiAgentResource extends Resource {
    use \App\Filament\Concerns\PertenceAoModulo;

    protected static string $modulo = 'ia';

    use \App\Filament\TraduzirResource;

 protected static ?string $model=AiAgent::class;
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-sparkles';
 protected static string|\UnitEnum|null $navigationGroup='Aplicações';
 protected static ?string $navigationLabel='Agentes de IA';
 protected static ?string $modelLabel='agente de IA';
 protected static ?string $pluralModelLabel='agentes de IA';
 protected static ?string $slug='agentes-ia';
 protected static ?string $recordTitleAttribute='nome';
 protected static ?int $navigationSort=3;
 public static function canViewAny(): bool {return (bool)auth()->user()?->admin && (bool)TenantContext::get();}
 public static function canCreate(): bool {return static::canViewAny();}
 public static function canEdit(Model $r): bool {return static::canViewAny() && (int)$r->tenant_id===(int)TenantContext::get();}
 public static function canDelete(Model $r): bool {return false;}
 public static function getEloquentQuery(): Builder {return parent::getEloquentQuery()->where('tenant_id',TenantContext::get()??0);}
 public static function form(Schema $s): Schema {return $s->schema([
 TextInput::make('nome')->label(__('ui_13030dd962f4'))->required()->maxLength(120),
 Select::make('tipo')->label(__('ui_c44388293804'))->options(fn () => \App\Support\Idiomas::opcoes(['agente'=>'Agente','supervisor'=>'Supervisor']))->required()->default('agente'),
 Textarea::make('instrucoes')->label(__('ui_be3b999b9a84'))->required()->rows(8)->maxLength(20000)->helperText(__('ui_7154f54d1314'))->columnSpanFull(),
 TextInput::make('modelo')->label(__('Modelo da IA Virtus'))->disabled()->dehydrated(false)->afterStateHydrated(fn($component)=>$component->state(\App\Services\GatewayVirtus::configuracao()['modelo']??''))->helperText(__('Modelo gerenciado pelo administrador da plataforma.')),
 TextInput::make('max_turnos')->label(__('ui_a98313200ade'))->numeric()->integer()->minValue(1)->maxValue(10)->required()->default(3),
 Select::make('ferramentas')->label(__('Ferramentas autorizadas'))->multiple()->options(fn()=>\App\Models\AttendanceAction::where('tenant_id',TenantContext::get())->where('ativa',true)->where('tipo','integracao')->orderBy('nome')->pluck('nome','id'))->maxItems(5)->helperText(__('Até cinco ações. A IA escolhe no máximo uma por resposta. Consultas automáticas precisam de autorização na ação; as demais exigem confirmação humana.'))->columnSpanFull(),
 Select::make('fontes_conhecimento')->label(__('Base de conhecimento'))->multiple()->searchable()->options(fn()=>\App\Models\KnowledgeDocument::where('tenant_id',TenantContext::get())->orderBy('titulo')->pluck('titulo','id'))->maxItems(20)->helperText(__('Selecione até vinte conteúdos. Somente conteúdos ativos são consultados; os trechos utilizados entram no consumo normal de tokens da resposta.'))->columnSpanFull(),
 Toggle::make('ativo')->label(__('ui_0e0c57609eb4'))->default(true),
 ]);}
 public static function table(Table $t): Table {return $t->columns([TextColumn::make('nome')->label(__('ui_13030dd962f4'))->searchable(),TextColumn::make('tipo')->label(__('ui_c44388293804'))->formatStateUsing(fn($state)=>$state==='supervisor'?'Supervisor':'Agente'),TextColumn::make('modelo')->label(__('ui_b3a01bef14b4'))->placeholder(__('ui_019911382c38')),TextColumn::make('max_turnos')->label(__('ui_90404a47ca9f')),IconColumn::make('ativo')->label(__('ui_86ba1a7ce714'))->boolean()])->recordActions([\Filament\Actions\EditAction::make()]);}
 public static function getPages(): array {return ['index'=>Pages\ListAiAgents::route('/'),'edit'=>Pages\EditAiAgent::route('/{record}/edit')];}
}
