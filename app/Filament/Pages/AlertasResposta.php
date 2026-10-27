<?php
namespace App\Filament\Pages;
use App\Models\Tenant;use App\Support\TenantContext;use BackedEnum;use UnitEnum;use Filament\Pages\Page;use Filament\Notifications\Notification;
class AlertasResposta extends Page {
 use \App\Filament\TraduzirNavegacao;
 protected static string|BackedEnum|null $navigationIcon='heroicon-o-bell-alert';protected static string|UnitEnum|null $navigationGroup='Configurações';protected static ?string $title='Alertas de resposta';protected static ?string $navigationLabel='Alertas de resposta';protected string $view='filament.pages.alertas-resposta';
 public bool $ativo=true;public bool $supervisor=false;public int $minutos=5;public int $repetir=5;
 public static function canAccess():bool{return auth()->check()&&!auth()->user()->ehAuditor()&&TenantContext::get()!==null;}
 public function gerencia():bool{return auth()->user()?->admin || in_array(auth()->user()?->perfil,['admin','supervisor'],true);}
 public function mount():void{$c=Tenant::findOrFail(TenantContext::get())->alertas_resposta??[];$this->ativo=$c['ativo']??true;$this->supervisor=$c['supervisor']??false;$this->minutos=$c['minutos']??5;$this->repetir=$c['repetir']??5;}
 public function testarAviso():void{abort_unless(static::canAccess(),403);$this->dispatch('virtus-atrasos',avisos:[['id'=>0,'protocolo'=>'VT-DEMO','minutos'=>$this->minutos,'chave'=>'teste:'.now()->timestamp,'repetir'=>1,'demo'=>true]]);}
 public function salvar():void{abort_unless(static::canAccess()&&$this->gerencia(),403);$c=$this->validate(['ativo'=>'boolean','supervisor'=>'boolean','minutos'=>'integer|min:1|max:120','repetir'=>'integer|min:1|max:60']);Tenant::findOrFail(TenantContext::get())->forceFill(['alertas_resposta'=>$c])->save();Notification::make()->success()->title(__('Configuração salva'))->send();}
}
