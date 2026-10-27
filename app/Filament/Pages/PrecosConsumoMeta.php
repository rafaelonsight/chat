<?php
namespace App\Filament\Pages;
use App\Models\{Channel,MetaTarifa,Tenant};
use App\Support\TenantContext;
use App\Services\{TarifasMeta};
use App\Services\Canais\MetaCloudEnviador;
use App\Jobs\AtualizarTarifasMeta;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use BackedEnum;
class PrecosConsumoMeta extends Page {
 use \App\Filament\TraduzirNavegacao;
 protected static string|BackedEnum|null $navigationIcon='heroicon-o-currency-pound';
 protected static ?string $title='Preços e consumo da Meta';
 protected static ?string $slug='precos-consumo-meta';
 protected static bool $shouldRegisterNavigation=false;
 protected string $view='filament.pages.precos-consumo-meta';
 public string $aba='precos'; public string $mercado='GB'; public string $moeda='USD';public string $categoria='Marketing';public int $quantidade=100;
 public string $moedaConsumo='USD';
 public ?int $channelId=null;public string $inicio='';public string $fim='';public array $consumo=[];
 public static function canAccess(): bool {return auth()->check() && TenantContext::get()!==null;}
 public function podeVerConsumo(): bool {return auth()->user()?->admin || auth()->user()?->perfil==='supervisor';}
 public function mount(): void {
  abort_unless(static::canAccess(),403);
  $t=Tenant::findOrFail(TenantContext::get());$this->mercado=$t->idioma_comunicacoes==='en_GB'?'GB':'BR';
  $this->inicio=now()->startOfMonth()->toDateString();$this->fim=now()->toDateString();
  $this->channelId=Channel::where('tipo',Channel::META_CLOUD)->whereNotNull('meta_waba_id')->value('id');
 }
 public function atualizarPrecos(): void {
  abort_unless(static::canAccess(),403);$this->validate(['mercado'=>'required|in:GB,BR','moeda'=>'required|in:USD,GBP,BRL,EUR']);
  $chave='meta-tarifas-pedido-'.$this->mercado.'-'.$this->moeda;
  if(Cache::add($chave,true,120)) AtualizarTarifasMeta::dispatch($this->mercado,$this->moeda);
  Notification::make()->success()->title(__('Atualização solicitada'))->body(__('A consulta acontece em segundo plano. A tabela será atualizada automaticamente.'))->send();
 }
 public function consultarConsumo(): void {
  abort_unless($this->podeVerConsumo() && static::canAccess(),403);
  $this->validate(['channelId'=>'required|integer','inicio'=>'required|date_format:Y-m-d','fim'=>'required|date_format:Y-m-d|after_or_equal:inicio']);
  $c=Channel::where('tenant_id',TenantContext::get())->where('tipo',Channel::META_CLOUD)->findOrFail($this->channelId);
  $tz=Tenant::findOrFail(TenantContext::get())->fuso_horario ?: config('app.timezone');
  $inicio=\Carbon\Carbon::parse($this->inicio,$tz)->startOfDay();$fim=\Carbon\Carbon::parse($this->fim,$tz)->endOfDay()->min(now());
  if($inicio->diffInDays($fim)>90 || $inicio->gt(now())) {$this->addError('inicio',__('Escolha um período de até 90 dias, sem datas futuras.'));return;}
  $chave='meta-consumo-'.TenantContext::get().'-'.$c->meta_waba_id.'-'.$inicio->timestamp.'-'.$fim->toDateString();
  $this->consumo=Cache::remember($chave,now()->addMinutes(15),function()use($c,$inicio,$fim){
   $r=app(MetaCloudEnviador::class)->consumo($c,$inicio->timestamp,$fim->timestamp);$r['consultado_em']=now()->toIso8601String();return $r;
  });
  $this->solicitarTarifasConsumo();
 }
 public function updatedMoedaConsumo(): void {
  abort_unless($this->podeVerConsumo() && static::canAccess(),403);
  $this->validate(['moedaConsumo'=>'required|in:USD,GBP,BRL']);
  $this->solicitarTarifasConsumo();
 }
 private function solicitarTarifasConsumo(): void {
  $mercados=[];foreach(($this->consumo['dados']['data']??[])as$g)foreach(($g['data_points']??[])as$p)if(isset(TarifasMeta::MERCADOS[$p['country']??'']))$mercados[$p['country']]=true;
  foreach(array_keys($mercados)as$mercado){
   $t=MetaTarifa::where('mercado',$mercado)->where('moeda','USD')->where('categoria','Marketing')->first();
   if(!$t || $t->erro || !$t->verificado_em || $t->verificado_em->lt(now()->subDays(2))){
    if(Cache::add('meta-tarifas-pedido-'.$mercado.'-'.'USD',true,120))AtualizarTarifasMeta::dispatch($mercado,'USD');
   }
  }
 }
 public function getViewData(): array {
  $tarifas=MetaTarifa::where('mercado',$this->mercado)->where('moeda',$this->moeda)->get()->keyBy('categoria');
  $tarifa=$tarifas->get($this->categoria);$estimativa=$tarifa?app(TarifasMeta::class)->simular($tarifa,max(0,min(100000000,$this->quantidade))):null;
  $pontos=[];
  if($this->podeVerConsumo()) foreach(($this->consumo['dados']['data']??[])as$grupo) foreach(($grupo['data_points']??[])as$p) $pontos[]=$p;
  return ['custoEstimado'=>app(\App\Services\ConversaoConsumoMeta::class)->calcular($pontos,in_array($this->moedaConsumo,['USD','GBP','BRL'],true)?$this->moedaConsumo:'USD'),'tarifas'=>$tarifas,'estimativa'=>$estimativa,'pontos'=>$pontos,'canais'=>$this->podeVerConsumo()?Channel::where('tipo',Channel::META_CLOUD)->whereNotNull('meta_waba_id')->pluck('nome','id'):collect()];
 }
}
