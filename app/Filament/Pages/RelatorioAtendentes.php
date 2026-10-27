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
class RelatorioAtendentes extends Page {
    use \App\Filament\Concerns\PertenceAoModulo;

    protected static string $modulo = 'qualidade';

    use \App\Filament\TraduzirNavegacao;

 use WithPagination;
 protected static string|BackedEnum|null $navigationIcon='heroicon-o-user-group';
 protected static string|UnitEnum|null $navigationGroup='Relatórios';
 protected static ?string $navigationLabel='Por atendente';
 protected static ?string $title='Relatório por atendente';
 protected static ?string $slug='relatorio-atendentes';
 protected string $view='filament.pages.relatorio-atendentes';
 public string $inicio='';public string $fim='';public string $canal='';public string $equipe='';public string $atendente=''; public string $tipoMensagem='';
 public static function acessoSemModulo(): bool {return (bool)auth()->user()?->consultaRelatorios();}
 public function mount(): void {$tz=Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo';$this->inicio=now()->timezone($tz)->subDays(29)->format('Y-m-d');$this->fim=now()->timezone($tz)->format('Y-m-d');}
 public function aplicar(): void {$this->validar();$this->resetPage();}
 public function selecionarAtendente(int $id): void {abort_unless(static::canAccess(),403);$this->atendente=(string)$id;$this->resetPage();}
 private function validar(): void {$this->validate(['inicio'=>'required|date_format:Y-m-d','fim'=>'required|date_format:Y-m-d|after_or_equal:inicio','canal'=>'nullable|integer','equipe'=>'nullable|integer','atendente'=>'nullable|integer','tipoMensagem'=>'nullable|in:text,audio,image,video,document,template,sticker']);}
 protected function base(bool $anterior = false): \Illuminate\Database\Query\Builder {
  abort_unless(static::canAccess(),403);$id=TenantContext::get();abort_unless($id,404);
  $tz=Tenant::find($id)?->fuso_horario ?: 'America/Sao_Paulo';
  $guard=\Illuminate\Support\Facades\Validator::make(['inicio'=>$this->inicio,'fim'=>$this->fim],['inicio'=>'required|date_format:Y-m-d','fim'=>'required|date_format:Y-m-d|after_or_equal:inicio']);
  if($guard->fails()) return DB::table('encerramentos_atendimento as f')->leftJoin('messages as pm','pm.id','=','f.pesquisa_message_id')->where('f.tenant_id',$id)->whereRaw('1=0');
  $de=Carbon::parse($this->inicio,$tz)->startOfDay()->timezone(config('app.timezone'));
  $ate=Carbon::parse($this->fim,$tz)->endOfDay()->timezone(config('app.timezone'));
  if($anterior){$dias=(int)Carbon::parse($this->inicio,$tz)->diffInDays(Carbon::parse($this->fim,$tz))+1;$ate=$de->copy()->subSecond();$de=$de->copy()->subDays($dias);}
  $q=DB::table('encerramentos_atendimento as f')->leftJoin('messages as pm',function($j){$j->on('pm.id','=','f.pesquisa_message_id')->on('pm.tenant_id','=','f.tenant_id');})->where('f.tenant_id',$id)->whereBetween('f.created_at',[$de,$ate]);
  $u=auth()->user()->fresh();
  if(!$u->veTudo()){$q->whereIn('f.canal_id',$u->canalIds());if($times=$u->equipeIds())$q->whereIn('f.equipe_id',$times);}
  return $q->when((int)$this->canal,fn($q,$v)=>$q->where('f.canal_id',$v))->when((int)$this->equipe,fn($q,$v)=>$q->where('f.equipe_id',$v))->when((int)$this->atendente,fn($q,$v)=>$q->where('f.responsavel_id',$v));
 }
 public function mensagensPorTipo(): \Illuminate\Support\Collection {
  abort_unless(static::canAccess(),403);
  $id=TenantContext::get();abort_unless($id,404);
  $v=\Illuminate\Support\Facades\Validator::make(['inicio'=>$this->inicio,'fim'=>$this->fim],['inicio'=>'required|date_format:Y-m-d','fim'=>'required|date_format:Y-m-d|after_or_equal:inicio']);
  if($v->fails())return collect();
  $tz=Tenant::find($id)?->fuso_horario ?: 'America/Sao_Paulo';
  $de=Carbon::parse($this->inicio,$tz)->startOfDay();$ate=Carbon::parse($this->fim,$tz)->endOfDay();
  $q=DB::table('messages as m')->join('conversations as c',function($j){$j->on('c.id','=','m.conversation_id')->on('c.tenant_id','=','m.tenant_id');})->leftJoin('users as u',function($j){$j->on('u.id','=','m.user_id')->on('u.tenant_id','=','m.tenant_id');})->where('m.tenant_id',$id)->whereBetween('m.created_at',[$de,$ate]);
  $u=auth()->user()->fresh();
  if(!$u->veTudo()){$q->whereIn('m.channel_id',$u->canalIds());if($times=$u->equipeIds())$q->whereIn('c.team_id',$times);}
  $q->when((int)$this->canal,fn($q,$v)=>$q->where('m.channel_id',$v))->when((int)$this->equipe,fn($q,$v)=>$q->where('c.team_id',$v));
  if(in_array($this->tipoMensagem,['text','audio','image','video','document','template','sticker'],true))$q->where('m.tipo',$this->tipoMensagem);
  if((int)$this->atendente)$q->where('m.user_id',(int)$this->atendente)->where('m.direcao','out')->where('m.automatica',false)->where('m.por_fora',false);
  // Autor da mensagem, nunca o responsável atual da conversa: transferências não reescrevem autoria.
  return $q->selectRaw("m.tipo, CASE WHEN m.direcao='in' THEN 'Cliente' WHEN m.automatica THEN 'Automação' WHEN m.por_fora THEN 'Fora do painel' WHEN m.user_id IS NOT NULL THEN COALESCE(u.name,'Atendente indisponível') ELSE 'Sem autor identificado' END as origem, COUNT(*) as total, COUNT(*) FILTER (WHERE m.status='failed') as falhas")->groupBy('m.tipo')->groupByRaw("CASE WHEN m.direcao='in' THEN 'Cliente' WHEN m.automatica THEN 'Automação' WHEN m.por_fora THEN 'Fora do painel' WHEN m.user_id IS NOT NULL THEN COALESCE(u.name,'Atendente indisponível') ELSE 'Sem autor identificado' END")->orderBy('origem')->orderBy('m.tipo')->get();
 }
 private function resumoPorAtendente(bool $anterior=false): \Illuminate\Support\Collection {
  $enviada="(pm.status IN ('sent','delivered','read') OR f.satisfacao IS NOT NULL)";
  return $this->base($anterior)->selectRaw("f.responsavel_id,MAX(f.responsavel_nome) as nome,COUNT(*) as finalizados,COUNT(*) FILTER (WHERE $enviada) as enviadas,COUNT(f.satisfacao) as recebidas,AVG(f.satisfacao) as media,AVG(f.espera_segundos) as espera,AVG(f.atendimento_segundos) as duracao,COUNT(*) FILTER (WHERE f.satisfacao=1) as nota1,COUNT(*) FILTER (WHERE f.satisfacao=2) as nota2,COUNT(*) FILTER (WHERE f.satisfacao=3) as nota3,COUNT(*) FILTER (WHERE f.satisfacao=4) as nota4,COUNT(*) FILTER (WHERE f.satisfacao=5) as nota5")->groupBy('f.responsavel_id')->orderByRaw('MAX(f.responsavel_nome)')->get();
 }
 public function exportarCsv() {
  abort_unless(static::canAccess(),403);$this->validar();
  $dados=$this->getViewData();
  return response()->streamDownload(function()use($dados){
   $arquivo=fopen('php://output','w');fwrite($arquivo,"\xEF\xBB\xBF");
   $escrever=function(array $campos)use($arquivo){$campos=array_map(fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r\n]/',$v)?"'".$v:$v,$campos);fputcsv($arquivo,$campos,';', '"', '');};
   $escrever([__('Relatório por atendente'),$this->inicio,$this->fim]);
   $escrever([__('Atendente'),__('Finalizados'),__('Período anterior'),__('Avaliações recebidas'),__('Período anterior'),__('Média'),__('Período anterior'),__('Espera média (segundos)'),__('Período anterior'),__('Atendimento médio (segundos)'),__('Período anterior')]);
   foreach($dados['comparativos'] as $c){$a=$c['atual'];$b=$c['anterior'];$escrever([$c['nome'],$a?->finalizados??0,$b?->finalizados??0,$a?->recebidas??0,$b?->recebidas??0,$a?->media,$b?->media,$a?->espera,$b?->espera,$a?->duracao,$b?->duracao]);}
   $escrever([]);$escrever([__('Mensagens por atendente e tipo'),__('Tipo de mensagem'),__('Mensagens'),__('Falhas')]);
   foreach($dados['mensagensPorTipo'] as $m)$escrever([__($m->origem),$m->tipo,$m->total,$m->falhas]);
   fclose($arquivo);
  },'virtus-atendentes-'.$this->inicio.'-'.$this->fim.'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
 }
 public function getViewData(): array {
  $enviada="(pm.status IN ('sent','delivered','read') OR f.satisfacao IS NOT NULL)";
  $linhas=$this->resumoPorAtendente();
  $anteriores=$this->resumoPorAtendente(true)->keyBy('responsavel_id');
  $comparativos=$linhas->keyBy('responsavel_id')->union($anteriores)->map(fn($l,$id)=>['nome'=>$l->nome,'atual'=>$linhas->firstWhere('responsavel_id',$id),'anterior'=>$anteriores->get($id)]);
  $finalizados=(int)$linhas->sum('finalizados');$enviadas=(int)$linhas->sum('enviadas');$recebidas=(int)$linhas->sum('recebidas');
  $media=$recebidas?$linhas->sum(fn($l)=>(float)$l->media*(int)$l->recebidas)/$recebidas:null;
  $detalhes=$this->base()->select('f.id','f.conversation_id','f.protocolo','f.versao','f.responsavel_nome','f.created_at','f.pesquisa_enviada_em','f.satisfacao','f.satisfacao_em')->selectRaw("CASE WHEN $enviada THEN 'Enviada' WHEN pm.status='failed' THEN 'Falha no envio' WHEN f.pesquisa_message_id IS NOT NULL THEN 'Aguardando envio' WHEN f.pesquisa_enviada_em IS NOT NULL THEN 'Envio histórico sem confirmação' ELSE 'Não enviada' END as pesquisa_status")->orderByDesc('f.created_at')->paginate(20);
  $u=auth()->user()->fresh();$canais=Channel::query();$equipes=Team::query();
  if(!$u->veTudo()){$canais->whereIn('id',$u->canalIds());if($times=$u->equipeIds())$equipes->whereIn('id',$times);}
  $mensagensPorTipo=$this->mensagensPorTipo();
  return compact('comparativos','mensagensPorTipo','linhas','finalizados','enviadas','recebidas','media','detalhes')+['canais'=>$canais->orderBy('nome')->get(),'equipes'=>$equipes->orderBy('nome')->get(),'pessoas'=>User::where('tenant_id',TenantContext::get())->orderBy('name')->get(),'tz'=>Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo'];
 }
 public static function tempo(?float $s): string {if($s===null)return '—';$s=(int)round($s);return $s>=3600?floor($s/3600).'h '.floor(($s%3600)/60).'min':($s>=60?floor($s/60).'min '.$s%60 .'s':$s.'s');}
}
