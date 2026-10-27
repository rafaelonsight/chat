<?php
namespace App\Filament\Pages;
use App\Models\Tenant;
use App\Services\ResumoIA;
use App\Support\TenantContext;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use BackedEnum;
use UnitEnum;
class InteligenciaArtificial extends Page {
    use \App\Filament\TraduzirNavegacao;

 protected static string|BackedEnum|null $navigationIcon='heroicon-o-sparkles';
 protected static string|UnitEnum|null $navigationGroup='Configurações';
 protected static ?string $navigationLabel='IA da plataforma';
 protected static ?string $title='IA da plataforma';
 protected static ?string $slug='inteligencia-artificial';
 protected string $view='filament.pages.inteligencia-artificial';
 public string $provedor='gateway';
 public string $modelo='claude-sonnet-5';
 public string $chave='';
 public bool $ativa=false;
 public bool $temChave=false;
 public string $resultadoTeste='';
 public static function canAccess(): bool { return (bool)auth()->user()?->super_admin; }
 private function conta(): Tenant {
  abort_unless(static::canAccess(),403);
  return Tenant::findOrFail(TenantContext::get());
 }
 public function mount(): void {
  $this->conta();$c=\App\Services\GatewayVirtus::configuracao();
  $this->provedor=$c['provedor']??'gateway';$this->modelo=$c['modelo']??'claude-sonnet-5';$this->ativa=(bool)($c['ativa']??false);$this->temChave=!empty($c['chave']);
 }
 public function updatedProvedor(): void { $this->modelo=match($this->provedor){'openai'=>'','anthropic'=>'claude-sonnet-4-6',default=>'claude-sonnet-5'};$this->chave='';$this->ativa=false;$this->temChave=false; }
 public function salvar(): void {
  $t=$this->conta();$this->validate(['provedor'=>'required|in:gateway,openai,anthropic','modelo'=>'required|string|max:120','chave'=>'nullable|string|max:500','ativa'=>'boolean']);
  $antigo=\App\Services\GatewayVirtus::configuracao();
  $chave=trim($this->chave) ?: (($antigo['provedor']??'')===$this->provedor?($antigo['chave']??''):'');
  if(!$chave){$this->addError('chave','Informe a chave da API.');return;}
  \App\Services\GatewayVirtus::salvar(['provedor'=>$this->provedor,'modelo'=>trim($this->modelo),'chave'=>$chave,'ativa'=>$this->ativa]);
  $this->chave='';$this->temChave=true;
  Notification::make()->success()->title(__('ui_fdb7e3353e5a'))->send();
 }
 public function testar(): void {
  try { $texto=app(ResumoIA::class)->testar($this->conta());$this->resultadoTeste=$texto;Notification::make()->success()->title(__('ui_862cfd6cc1bd'))->body($texto)->send(); }
  catch(\RuntimeException $e){$this->resultadoTeste=$e->getMessage();Notification::make()->danger()->title(__('ui_1ff889617043'))->body($e->getMessage())->send();}
  catch(\Throwable $e){$this->resultadoTeste='Não foi possível conectar à IA. Tente novamente.';Notification::make()->danger()->title(__('ui_18ae9060bd34'))->body(__('ui_1bb9de62b628'))->send();}
 }
}
