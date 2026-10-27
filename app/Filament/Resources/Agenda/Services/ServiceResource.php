<?php
namespace App\Filament\Resources\Agenda\Services;
use App\Models\AgendaService;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\{TextInput,Textarea,Toggle,Select,ColorPicker};
use Filament\Tables\Table;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
class ServiceResource extends Resource
{
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 use \App\Filament\TraduzirResource;
 protected static ?string $model=AgendaService::class;
 protected static ?string $slug='servicos';
 protected static ?string $modelLabel='serviço';
 protected static ?string $pluralModelLabel='Serviços';
 protected static ?string $navigationLabel='Serviços';
 protected static string|\UnitEnum|null $navigationGroup='Seu estabelecimento';
 protected static string|\BackedEnum|null $navigationIcon=Heroicon::OutlinedScissors;
 protected static ?int $navigationSort=1;
 protected static ?string $recordTitleAttribute='nome';
 public static function form(Schema $schema): Schema
 {
  return $schema->components([
 Section::make(__('O que você oferece'))->description(__('Use o nome que seus clientes reconhecem.'))->schema([
  TextInput::make('nome')->label(__('Nome do serviço'))->placeholder(__('Ex.: corte, manicure ou limpeza de pele'))->required()->maxLength(120),
  Textarea::make('descricao')->label(__('Descrição'))->rows(3)->maxLength(2000)->columnSpanFull(),
  \Filament\Forms\Components\FileUpload::make('photo_path')->label(__('Foto do serviço'))->helperText(__('Exemplo do corte ou resultado do atendimento. JPG, PNG ou WebP até 4 MB.'))->image()->acceptedFileTypes(['image/jpeg','image/png','image/webp'])->maxSize(4096)->disk('local')->directory(fn()=>'agenda-services/'.\App\Support\TenantContext::get())->visibility('private')->deletable()->getUploadedFileUsing(function(string $file,?AgendaService $record):?array {
   $disk=\Illuminate\Support\Facades\Storage::disk('local');
   if(!$record || $record->photo_path!==$file || !$disk->exists($file))return null;
   return ['name'=>basename($file),'size'=>$disk->size($file),'type'=>$disk->mimeType($file),'url'=>route('agenda.services.photo',['service'=>$record->id])];
  })->columnSpanFull(),
  TextInput::make('preco')->label(__('Preço'))->prefix(config('agenda.currency') === 'BRL' ? 'R$' : config('agenda.currency'))->numeric()->minValue(0)->maxValue(99999999.99)->step('0.01')->rules(['decimal:0,2'])->required(),
  Toggle::make('ativo')->label(__('Serviço ativo'))->default(true)->helperText(__('Desative para deixar de oferecer este serviço, preservando seu histórico.')),
 ])->columns(2),
 Section::make(__('Tempo na agenda'))->description(__('O atendimento e o intervalo ocupam tempo do profissional.'))->schema([
  TextInput::make('duracao_minutos')->label(__('Duração do atendimento'))->suffix(__('min'))->numeric()->integer()->minValue(1)->maxValue(1440)->required(),
  TextInput::make('intervalo_minutos')->label(__('Intervalo após o atendimento'))->suffix(__('min'))->numeric()->integer()->minValue(0)->maxValue(1440)->default(0)->required()->helperText(__('Tempo para preparação ou organização antes do próximo cliente.')),
 ])->columns(2),])->columns(1);
 }
 public static function table(Table $table): Table
 {
  return $table
   ->columns([ViewColumn::make('catalog')->label(__('Serviço'))->view('filament.agenda.service-card')->searchable(['nome','descricao'])])
   ->contentGrid(['md'=>2,'xl'=>3])->defaultSort('nome')->paginated([12,24,48])
   ->filters([TernaryFilter::make('ativo')->label(__('Situação'))->placeholder(__('Todos'))->trueLabel(__('Ativos'))->falseLabel(__('Inativos'))])
   ->recordActions([EditAction::make()->label(__('Editar serviço'))])
   ->emptyStateHeading(__('Seu catálogo começa aqui'))
   ->emptyStateDescription(__('Cadastre seu primeiro serviço com preço, duração e intervalo.'))
   ->emptyStateIcon(Heroicon::OutlinedScissors);
 }
 public static function getPages(): array
 {
  return ['index'=>Pages\ListServices::route('/'),'create'=>Pages\CreateService::route('/novo'),'edit'=>Pages\EditService::route('/{record}/editar')];
 }
}
