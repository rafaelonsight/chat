<?php
namespace App\Filament\Resources\Agenda\Professionals;
use App\Models\AgendaProfessional;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\{TextInput,Textarea,Toggle,Select,ColorPicker};
use Filament\Tables\Table;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
class ProfessionalResource extends Resource
{
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 use \App\Filament\TraduzirResource;
 protected static ?string $model=AgendaProfessional::class;
 protected static ?string $slug='profissionais';
 protected static ?string $modelLabel='profissional';
 protected static ?string $pluralModelLabel='Profissionais';
 protected static ?string $navigationLabel='Profissionais';
 protected static string|\UnitEnum|null $navigationGroup='Seu estabelecimento';
 protected static string|\BackedEnum|null $navigationIcon=Heroicon::OutlinedUserGroup;
 protected static ?int $navigationSort=2;
 protected static ?string $recordTitleAttribute='nome';
 public static function form(Schema $schema): Schema
 {
  return $schema->components([
 Section::make(__('Quem atende'))->description(__('O nome será usado na agenda e no atendimento ao cliente.'))->schema([
  TextInput::make('nome')->label(__('Nome do profissional'))->required()->maxLength(120),
  ColorPicker::make('cor')->label(__('Cor na agenda'))->default('#6338F6')->required()->rules(['regex:/^#[0-9a-fA-F]{6}$/'])->helperText(__('Ajuda a identificar este profissional. O nome sempre será exibido.')),
  Textarea::make('descricao')->label(__('Apresentação'))->rows(3)->maxLength(2000)->columnSpanFull(),
  TextInput::make('telefone')->label(__('Telefone'))->tel()->maxLength(30),
  Toggle::make('ativo')->label(__('Profissional ativo'))->default(true)->helperText(__('Desative para retirar de novos atendimentos, preservando seu histórico.')),
 ])->columns(2),
 Section::make(__('Serviços que executa'))->description(__('Vincule somente os serviços que esta pessoa está preparada para realizar.'))->schema([
  Select::make('services')->label(__('Serviços'))->relationship('services','nome')->getOptionLabelFromRecordUsing(fn ($record) => $record->nome.($record->ativo ? '' : ' · '.__('inativo')))->multiple()->preload()->searchable()
   ->rules(fn () => [\Illuminate\Validation\Rule::exists('agenda_services','id')->where('tenant_id',\App\Support\TenantContext::get())])
   ->validationMessages(['exists'=>__('Selecione somente serviços deste estabelecimento.')])
   ->saveRelationshipsUsing(fn (\App\Models\AgendaProfessional $record, ?array $state) => $record->syncServices($state ?? []))
   ->helperText(__('Você pode cadastrar o profissional agora e vincular os serviços depois.')),
 ]),
 Section::make(__('Acesso ao painel'))->description(__('Cada profissional tem seu próprio login. A senha é definida por um link enviado por e-mail.'))->schema([
  Select::make('access_mode')->label(__('Conta de acesso'))->options(['new'=>__('Criar nova conta'),'existing'=>__('Vincular usuário existente')])->default('new')->required()->live()->dehydrated(false)->visible(fn(?AgendaProfessional $record)=>!$record?->user_id),
  Select::make('access_user_id')->label(__('Usuário deste estabelecimento'))->options(fn()=>\App\Models\User::withoutGlobalScopes()->where('tenant_id',\App\Support\TenantContext::get())->whereNotIn('id',\Illuminate\Support\Facades\DB::table('agenda_professionals')->whereNotNull('user_id')->select('user_id'))->get()->mapWithKeys(fn($u)=>[$u->id=>$u->name.' · '.$u->email])->all())->searchable()->required(fn($get)=>$get('access_mode')==='existing')->dehydrated(false)->visible(fn($get,?AgendaProfessional $record)=>!$record?->user_id && $get('access_mode')==='existing')->helperText(__('A senha e as permissões da conta existente serão preservadas.')),
  TextInput::make('email')->label(__('E-mail de acesso'))->email()->maxLength(255)->required(fn($get,?AgendaProfessional $record)=>!$record?->user_id && $get('access_mode')!=='existing')->disabled(fn(?AgendaProfessional $record)=>(bool)$record?->user_id)->dehydrated(fn(?AgendaProfessional $record)=>!$record?->user_id)->visible(fn($get,?AgendaProfessional $record)=>$record?->user_id || $get('access_mode')!=='existing')->helperText(__('É o e-mail usado para entrar. Para alterá-lo depois, use Gerenciar acesso.')),
  \Filament\Schemas\Components\Text::make(function(?AgendaProfessional $record){$u=$record?->user_id ? app(\App\Services\AgendaAccess::class)->userFor($record):null;return $u ? __(\App\Services\AgendaAccess::LABELS[$u->agenda_access_status]??'Acesso ainda não configurado'):__('Acesso ainda não configurado');})->visible(fn(?AgendaProfessional $record)=>(bool)$record),
  \Filament\Schemas\Components\Text::make(function(?AgendaProfessional $record){$d=$record ? app(\App\Services\AgendaAccess::class)->latestDelivery($record):null;return match($d?->status){'aceito'=>__('Último envio aceito pelo servidor de e-mail.'),'falhou'=>__('Falha no último envio. Use Gerenciar acesso para reenviar.'),'pendente','enviando'=>__('Envio de e-mail pendente.'),default=>__('Nenhum envio de acesso registrado.')};})->visible(fn(?AgendaProfessional $record)=>(bool)$record?->user_id),
 ])->columns(1),])->columns(1);
 }
 public static function table(Table $table): Table
 {
  return $table->modifyQueryUsing(fn ($query) => $query->with(['services','accessUser']))
   ->columns([ViewColumn::make('catalog')->label(__('Profissional'))->view('filament.agenda.professional-card')->searchable(['nome','descricao'])])
   ->contentGrid(['md'=>2,'xl'=>3])->defaultSort('nome')->paginated([12,24,48])
   ->filters([TernaryFilter::make('ativo')->label(__('Situação'))->placeholder(__('Todos'))->trueLabel(__('Ativos'))->falseLabel(__('Inativos'))])
   ->recordActions([EditAction::make()->label(__('Editar profissional'))])
   ->emptyStateHeading(__('Cada atendimento começa com uma pessoa'))
   ->emptyStateDescription(__('Cadastre os profissionais e escolha os serviços que cada um executa.'))
   ->emptyStateIcon(Heroicon::OutlinedUserGroup);
 }
 public static function getPages(): array
 {
  return ['index'=>Pages\ListProfessionals::route('/'),'create'=>Pages\CreateProfessional::route('/novo'),'edit'=>Pages\EditProfessional::route('/{record}/editar')];
 }
}
