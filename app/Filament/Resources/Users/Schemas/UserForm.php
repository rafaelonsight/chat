<?php
namespace App\Filament\Resources\Users\Schemas;
use App\Models\User;
use Filament\Forms\Components\{TextInput,Select,Toggle,Hidden};
use Filament\Schemas\{Schema};
use Filament\Schemas\Components\{Section,Text};
class UserForm {
 public static function validarAcesso(array $dados,?int $tenantId): void {
  foreach(['canais'=>\App\Models\Channel::class,'teams'=>\App\Models\Team::class] as $campo=>$classe){
   $ids=array_unique(array_map('intval',$dados[$campo]??[]));
   $permitidos=$classe::withoutGlobalScopes()->where('tenant_id',$tenantId)->whereIn('id',$ids)->count();
   if($permitidos!==count($ids)) throw \Illuminate\Validation\ValidationException::withMessages(['data.'.$campo=>'Selecione somente acessos desta empresa.']);
  }
 }
 public static function dados(): array {return [TextInput::make('name')->label(__('ui_695fc3f31688'))->required()->maxLength(255),TextInput::make('email')->label(__('ui_0c74b5489cbd'))->email()->required()->unique(ignoreRecord:true)->maxLength(255)->disabled(fn(?User $record)=>(bool)$record?->agendaProfessional())->dehydrated(fn(?User $record)=>!$record?->agendaProfessional())->helperText(__('Para profissionais, altere o e-mail em Profissionais > Gerenciar acesso.')),Hidden::make('password')->default(fn()=>\Illuminate\Support\Str::random(64))->dehydrated(fn(string $operation)=>$operation==='create')];}
 public static function perfil(): array {return [Select::make('perfil')->label(__('ui_76fdce8b7931'))->options(fn () => \App\Support\Idiomas::opcoes(User::PERFIS))->default('atendente')->required()->live()->helperText(__('ui_5ab65ae92e07')),Toggle::make('ver_notas_internas')->label(__('ui_c460d0947083'))->default(false)->visible(fn($get)=>$get('perfil')==='auditor')->helperText(__('ui_7c512cbdcf07')),Toggle::make('consultar_anexos')->label(__('ui_47fe03563db1'))->default(false)->visible(fn($get)=>$get('perfil')==='auditor')->helperText(__('ui_75b2616e11f5')),\App\Forms\Components\PaletaDeCores::make('cor_chat')->label(__('ui_10eb03864ece'))];}
 public static function acesso(): array {return [Select::make('canais')->label(__('ui_ea05edbe2a5e'))->relationship('canais','nome',fn($query)=>$query->withoutGlobalScope(\App\Models\Scopes\Acesso::class))->multiple()->preload()->searchable()->helperText(__('ui_c50cce5962e6')),Select::make('teams')->label(__('ui_a9fda3212bbb'))->relationship('teams','nome')->multiple()->preload()->searchable()->helperText(__('ui_d557f8c3bc8f'))];}
 public static function business(): array {return [Toggle::make('agenda_products_manage')->label(__('Gerenciar produtos e estoque'))->default(false),Toggle::make('agenda_finance_view')->label(__('Consultar contas e pagamentos'))->default(false),Toggle::make('agenda_finance_manage')->label(__('Lançar, baixar e estornar contas'))->default(false)->helperText(__('Também permite consultar. O perfil Auditor permanece somente leitura.'))];}
 public static function configure(Schema $s): Schema {return $s->components([Section::make('Dados de acesso')->schema(self::dados()),Section::make('Perfil e responsabilidades')->schema(self::perfil()),Section::make('Onde pode trabalhar')->schema(self::acesso()),Section::make(__('Produtos e financeiro'))->description(__('Libere somente o que esta pessoa precisa operar.'))->schema(self::business())]);}
}
