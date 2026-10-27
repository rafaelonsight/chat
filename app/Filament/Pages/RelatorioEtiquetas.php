<?php
namespace App\Filament\Pages;
use App\Models\{Channel,Team,Tenant,User};
use App\Support\TenantContext;
use BackedEnum;
use UnitEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Livewire\WithPagination;
class RelatorioEtiquetas extends Page {
    use \App\Filament\TraduzirNavegacao;

 use WithPagination;
 protected static string|BackedEnum|null $navigationIcon='heroicon-o-tag';
 protected static string|UnitEnum|null $navigationGroup='Relatórios';
 protected static ?string $navigationLabel='Por etiqueta';
 protected static ?string $title='Relatório por etiquetas';
 protected static ?string $slug='relatorio-etiquetas';
 protected string $view='filament.pages.relatorio-etiquetas';
 public string $inicio='';public string $fim='';public string $canal='';public string $equipe='';public string $atendente='';
 public string $etiqueta='';public string $contexto='conversa';
 public static function canAccess(): bool {return (bool)auth()->user()?->consultaRelatorios();}
 public function mount(): void {$tz=Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo';$this->inicio=now()->timezone($tz)->subDays(29)->format('Y-m-d');$this->fim=now()->timezone($tz)->format('Y-m-d');}
 public function updatedContexto(): void {$this->etiqueta="";$this->resetPage();}
 public function aplicar(): void {$this->validar();$this->resetPage();}
 public function selecionarEtiqueta(int $id): void {abort_unless(static::canAccess(),403);$this->etiqueta=(string)$id;$this->resetPage();}
 private function validar(): void {$this->validate(['contexto'=>'in:conversa,contato','etiqueta'=>'nullable|integer','inicio'=>'required|date_format:Y-m-d','fim'=>'required|date_format:Y-m-d|after_or_equal:inicio','canal'=>'nullable|integer','equipe'=>'nullable|integer','atendente'=>'nullable|integer']);}
 private function base(): \Illuminate\Database\Query\Builder {
  abort_unless(static::canAccess(),403);$id=TenantContext::get();abort_unless($id,404);
  $q=DB::table('conversations as c')->leftJoin('encerramentos_atendimento as f',function($j){$j->on('f.conversation_id','=','c.id')->on('f.tenant_id','=','c.tenant_id')->whereRaw('f.id=(SELECT MAX(x.id) FROM encerramentos_atendimento x WHERE x.conversation_id=c.id AND x.tenant_id=c.tenant_id)');})->where('c.tenant_id',$id)->whereExists(function($q)use($id){$q->selectRaw('1')->from('contacts as ct')->whereColumn('ct.id','c.contact_id')->where('ct.tenant_id',$id)->where('ct.tipo','!=',\App\Models\Contact::GRUPO);});
  $guard=\Illuminate\Support\Facades\Validator::make(['inicio'=>$this->inicio,'fim'=>$this->fim,'contexto'=>$this->contexto],['inicio'=>'required|date_format:Y-m-d','fim'=>'required|date_format:Y-m-d|after_or_equal:inicio','contexto'=>'in:conversa,contato']);
  if($guard->fails())return $q->whereRaw('1=0');
  $tz=Tenant::find($id)?->fuso_horario ?: 'America/Sao_Paulo';
  $q->whereBetween('c.created_at',[Carbon::parse($this->inicio,$tz)->startOfDay()->timezone(config('app.timezone')),Carbon::parse($this->fim,$tz)->endOfDay()->timezone(config('app.timezone'))]);
  $u=auth()->user()->fresh();if(!$u->veTudo()){$q->whereIn('c.channel_id',$u->canalIds());if($ids=$u->equipeIds())$q->whereIn('c.team_id',$ids);}
  $q->when((int)$this->canal,fn($q,$v)=>$q->where('c.channel_id',$v))->when((int)$this->equipe,fn($q,$v)=>$q->where('c.team_id',$v))->when((int)$this->atendente,fn($q,$v)=>$q->whereRaw('COALESCE(f.responsavel_id,c.atendente_id)=?',[$v]));
  $pivot=$this->contexto==='contato'?'contact_tag':'conversation_tag';$col=$this->contexto==='contato'?'contact_id':'conversation_id';$campo=$this->contexto==='contato'?'c.contact_id':'c.id';
  if((int)$this->etiqueta)$q->whereExists(function($q)use($pivot,$col,$campo,$id){$q->selectRaw('1')->from($pivot.' as ep')->join('tags as et','et.id','=','ep.tag_id')->whereColumn('ep.'.$col,$campo)->where('et.tenant_id',$id)->where('et.contexto',$this->contexto)->where('et.id',(int)$this->etiqueta);});
  return $q;
 }
 public function getViewData(): array {
  $pivot=$this->contexto==='contato'?'contact_tag':'conversation_tag';$col=$this->contexto==='contato'?'contact_id':'conversation_id';$campo=$this->contexto==='contato'?'c.contact_id':'c.id';
  $volume=$this->base()->count();$finalizados=$this->base()->where('c.status',\App\Models\Conversation::ARQUIVADA)->count();
  $recebidas=$this->base()->whereNotNull('f.satisfacao')->count();$media=$this->base()->avg('f.satisfacao');
  $linhas=$this->base()->join($pivot.' as ep','ep.'.$col,'=',$campo)->join('tags as t','t.id','=','ep.tag_id')->where('t.tenant_id',TenantContext::get())->where('t.contexto',$this->contexto)->when((int)$this->etiqueta,fn($q,$v)=>$q->where('t.id',$v))->selectRaw('t.id,t.nome,t.cor,COUNT(DISTINCT c.id) as volume,COUNT(DISTINCT c.id) FILTER (WHERE c.status=?) as finalizados,AVG(f.atendimento_segundos) as duracao,COUNT(f.satisfacao) as recebidas,AVG(f.satisfacao) as media',[\App\Models\Conversation::ARQUIVADA])->groupBy('t.id','t.nome','t.cor')->orderByDesc('volume')->get();
  $detalhes=$this->base()->select('c.id','c.protocolo','c.status','c.created_at','f.responsavel_nome','f.satisfacao','f.atendimento_segundos')->orderByDesc('c.created_at')->paginate(20);
  $u=auth()->user()->fresh();$canais=Channel::query();$equipes=Team::query();if(!$u->veTudo()){$canais->whereIn('id',$u->canalIds());if($ids=$u->equipeIds())$equipes->whereIn('id',$ids);}
  return compact('linhas','volume','finalizados','recebidas','media','detalhes')+['canais'=>$canais->orderBy('nome')->get(),'equipes'=>$equipes->orderBy('nome')->get(),'pessoas'=>User::where('tenant_id',TenantContext::get())->orderBy('name')->get(),'etiquetas'=>\App\Models\Tag::where('contexto',$this->contexto)->orderBy('nome')->get(),'tz'=>Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo'];
 }
 public static function tempo(?float $s): string {if($s===null)return '—';$s=(int)round($s);return $s>=3600?floor($s/3600).'h '.floor(($s%3600)/60).'min':($s>=60?floor($s/60).'min '.$s%60 .'s':$s.'s');}
}
