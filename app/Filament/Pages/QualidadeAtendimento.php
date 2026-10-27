<?php
namespace App\Filament\Pages;
use App\Models\{Channel,Team,Tenant,User};
use App\Support\TenantContext;
use BackedEnum;use UnitEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Livewire\WithPagination;
use Livewire\Attributes\Locked;
class QualidadeAtendimento extends Page {
    use \App\Filament\Concerns\PertenceAoModulo;

    protected static string $modulo = 'qualidade';

    use \App\Filament\TraduzirNavegacao;

 use WithPagination;
 protected static string|BackedEnum|null $navigationIcon='heroicon-o-clipboard-document-check';
 protected static string|UnitEnum|null $navigationGroup='Relatórios';
 protected static ?string $navigationLabel='Qualidade';protected static ?string $title='Gestão da qualidade';
 protected static ?string $slug='qualidade';protected string $view='filament.pages.qualidade-atendimento';
 public string $inicio='';public string $fim='';public string $canal='';public string $equipe='';public string $atendente='';
 public string $etiqueta='';public string $avaliacao='baixas';public string $situacao='';public string $comentario='';public string $estado='acompanhamento';
 public string $responsavelRevisao='';public string $prazo='';
 #[Locked] public ?int $selecionado=null;
 public static function getNavigationBadge(): ?string {$u=auth()->user()?->fresh();$id=TenantContext::get();if(!$u || !$id || !app(\App\Services\AlertasQualidade::class)->gerente($u))return null;$n=app(\App\Services\AlertasQualidade::class)->pendentes($u,$id)->count();return $n?(string)$n:null;}
 public static function acessoSemModulo(): bool {return (bool)auth()->user()?->consultaRelatorios();}
 public function podeEditar(): bool {$u=auth()->user()?->fresh();return $u && !$u->ehAuditor() && ($u->admin || in_array($u->perfil,['admin','supervisor'],true));}
 public function mount(): void {$tz=Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo';$this->inicio=now()->timezone($tz)->subDays(29)->format('Y-m-d');$this->fim=now()->timezone($tz)->format('Y-m-d');if(request()->boolean('pendencias'))$this->verPendencias();if($id=(int)request()->query('revisao'))$this->revisar($id);}
 public function aplicar(): void {$this->validate(['inicio'=>'required|date_format:Y-m-d','fim'=>'required|date_format:Y-m-d|after_or_equal:inicio','etiqueta'=>'nullable|integer','canal'=>'nullable|integer','equipe'=>'nullable|integer','atendente'=>'nullable|integer','avaliacao'=>'in:,baixas,sem,positivas','situacao'=>'in:,pendente,acompanhamento,concluida,abertas']);$this->selecionado=null;$this->resetPage();}
 private function autorizados(): \Illuminate\Database\Query\Builder {
  abort_unless(static::canAccess(),403);$id=TenantContext::get();abort_unless($id,404);$u=auth()->user()->fresh();
  $q=DB::table('encerramentos_atendimento as f')->where('f.tenant_id',$id);
  if(!$u->veTudo()){$q->whereIn('f.canal_id',$u->canalIds());if($ids=$u->equipeIds())$q->whereIn('f.equipe_id',$ids);}
  return $q;
 }
 public function verPendencias(): void {
  $u=auth()->user()->fresh();$a=app(\App\Services\AlertasQualidade::class);abort_unless($a->gerente($u),403);$min=$a->pendentes($u,TenantContext::get())->min('f.created_at');$tz=Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo';
  $this->inicio=$min?Carbon::parse($min)->timezone($tz)->format('Y-m-d'):now()->timezone($tz)->format('Y-m-d');$this->fim=now()->timezone($tz)->format('Y-m-d');$this->avaliacao='';$this->situacao='abertas';$this->canal=$this->equipe=$this->atendente=$this->etiqueta='';$this->selecionado=null;$this->resetPage();
 }
 public function revisar(int $id): void {
  abort_unless($this->autorizados()->where('f.id',$id)->exists(),403);$this->selecionado=$id;$this->comentario='';$r=DB::table('revisoes_qualidade')->where('tenant_id',TenantContext::get())->where('encerramento_id',$id)->latest('id')->first();$this->estado=$r?->estado ?: 'acompanhamento';$this->responsavelRevisao=$r?->responsavel_id?(string)$r->responsavel_id:'';$tz=Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo';$this->prazo=$r?->prazo_em?Carbon::parse($r->prazo_em)->timezone($tz)->format('Y-m-d\TH:i'):'';
 }
 public function cancelar(): void {$this->selecionado=null;$this->comentario='';}
 public function salvar(): void {
  abort_unless($this->podeEditar(),403);$this->validate(['comentario'=>'required|string|max:4000','estado'=>'required|in:pendente,acompanhamento,concluida','responsavelRevisao'=>'nullable|integer','prazo'=>'nullable|date_format:Y-m-d\TH:i']);abort_unless(trim($this->comentario)!=='',422);
  DB::transaction(function(){
   $f=$this->selecionado?$this->autorizados()->where('f.id',$this->selecionado)->lockForUpdate()->first():null;abort_unless($f,403);$u=auth()->user();$responsavel=null;
   if($this->responsavelRevisao){$responsavel=User::withoutGlobalScope('tenant')->where('tenant_id',TenantContext::get())->find((int)$this->responsavelRevisao);if(!$responsavel || !app(\App\Services\AlertasQualidade::class)->podeVer($responsavel,$f)){ $this->addError('responsavelRevisao','Selecione um supervisor ou administrador autorizado para este atendimento.');throw \Illuminate\Validation\ValidationException::withMessages(['responsavelRevisao'=>'Responsável sem acesso a este atendimento.']);}}
   if($this->prazo && !$responsavel)throw \Illuminate\Validation\ValidationException::withMessages(['responsavelRevisao'=>'Selecione quem acompanhará este prazo.']);
   $tz=Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo';$prazo=$this->prazo?Carbon::createFromFormat('Y-m-d\TH:i',$this->prazo,$tz)->timezone(config('app.timezone')):null;
   DB::table('revisoes_qualidade')->insert(['tenant_id'=>TenantContext::get(),'encerramento_id'=>$this->selecionado,'autor_id'=>$u->id,'autor_nome'=>$u->name,'estado'=>$this->estado,'comentario'=>trim($this->comentario),'responsavel_id'=>$responsavel?->id,'responsavel_nome'=>$responsavel?->name,'prazo_em'=>$prazo,'created_at'=>now(),'updated_at'=>now()]);
   if($responsavel && $responsavel->id!==$u->id && $this->estado!=='concluida')app(\App\Services\AlertasQualidade::class)->avisar($this->selecionado,$responsavel->id,'atribuicao');
  });
  $this->comentario='';\Filament\Notifications\Notification::make()->title(__('ui_15ab335d6fcb'))->success()->send();
 }
 private function base(): \Illuminate\Database\Query\Builder {
  abort_unless(static::canAccess(),403);$id=TenantContext::get();abort_unless($id,404);
  $tz=Tenant::find($id)?->fuso_horario ?: 'America/Sao_Paulo';
  $guard=\Illuminate\Support\Facades\Validator::make(['inicio'=>$this->inicio,'fim'=>$this->fim],['inicio'=>'required|date_format:Y-m-d','fim'=>'required|date_format:Y-m-d|after_or_equal:inicio']);
  if($guard->fails()) return DB::table('encerramentos_atendimento as f')->leftJoin('messages as pm','pm.id','=','f.pesquisa_message_id')->where('f.tenant_id',$id)->whereRaw('1=0');
  $de=Carbon::parse($this->inicio,$tz)->startOfDay()->timezone(config('app.timezone'));
  $ate=Carbon::parse($this->fim,$tz)->endOfDay()->timezone(config('app.timezone'));
  $q=DB::table('encerramentos_atendimento as f')->leftJoin('messages as pm',function($j){$j->on('pm.id','=','f.pesquisa_message_id')->on('pm.tenant_id','=','f.tenant_id');})->where('f.tenant_id',$id)->whereBetween('f.created_at',[$de,$ate]);
  $u=auth()->user()->fresh();
  if(!$u->veTudo()){$q->whereIn('f.canal_id',$u->canalIds());if($times=$u->equipeIds())$q->whereIn('f.equipe_id',$times);}
  if((int)$this->etiqueta)$q->whereExists(function($z)use($id){$z->selectRaw('1')->from('conversation_tag as ep')->join('tags as t','t.id','=','ep.tag_id')->whereColumn('ep.conversation_id','f.conversation_id')->where('t.tenant_id',$id)->where('t.contexto','conversa')->where('t.id',(int)$this->etiqueta);});
  return $q->when((int)$this->canal,fn($q,$v)=>$q->where('f.canal_id',$v))->when((int)$this->equipe,fn($q,$v)=>$q->where('f.equipe_id',$v))->when((int)$this->atendente,fn($q,$v)=>$q->where('f.responsavel_id',$v));
 }

 private function recorte(): \Illuminate\Database\Query\Builder {
  $q=$this->base()->leftJoin('revisoes_qualidade as r',function($j){$j->on('r.encerramento_id','=','f.id')->on('r.tenant_id','=','f.tenant_id')->whereRaw('r.id=(SELECT MAX(z.id) FROM revisoes_qualidade z WHERE z.encerramento_id=f.id AND z.tenant_id=f.tenant_id)');});
  if($this->avaliacao==='baixas')$q->whereBetween('f.satisfacao',[1,2]);elseif($this->avaliacao==='sem')$q->whereNull('f.satisfacao');elseif($this->avaliacao==='positivas')$q->whereBetween('f.satisfacao',[4,5]);
  if(in_array($this->situacao,['pendente','acompanhamento','concluida'],true))$q->whereRaw("COALESCE(r.estado,'pendente')=?",[$this->situacao]);
  if($this->situacao==='abertas')$q->whereRaw("COALESCE(r.estado,'pendente')!='concluida'")->where(function($q){$q->whereBetween('f.satisfacao',[1,2])->orWhereNotNull('r.id');});
  return $q;
 }
 public function getViewData(): array {
  $totais=$this->base()->selectRaw('COUNT(*) as total,COUNT(*) FILTER (WHERE f.satisfacao<=2) as baixas,COUNT(*) FILTER (WHERE f.satisfacao IS NULL) as sem,AVG(f.satisfacao) as media')->first();
  $linhas=$this->recorte()->select('f.id','f.conversation_id','f.protocolo','f.versao','f.responsavel_nome','f.created_at','f.satisfacao','r.responsavel_nome as revisor_nome','r.prazo_em')->selectRaw("COALESCE(r.estado,'pendente') as estado")->orderByDesc('f.created_at')->orderByDesc('f.id')->paginate(20);
  $selecionada=$this->selecionado?$this->autorizados()->where('f.id',$this->selecionado)->first():null;
  $historico=$selecionada && auth()->user()->consultaNotas()?DB::table('revisoes_qualidade')->where('tenant_id',TenantContext::get())->where('encerramento_id',$selecionada->id)->orderByDesc('id')->get():collect();
  $evolucao=$this->base()->selectRaw("TO_CHAR(f.created_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM') as mes,COUNT(*) as total,COUNT(f.satisfacao) as respostas,AVG(f.satisfacao) as media",[Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo'])->groupBy('mes')->orderBy('mes')->get();
  $u=auth()->user()->fresh();$canais=Channel::query();$equipes=Team::query();if(!$u->veTudo()){$canais->whereIn('id',$u->canalIds());if($ids=$u->equipeIds())$equipes->whereIn('id',$ids);}
  $responsaveis=$selecionada?User::where('tenant_id',TenantContext::get())->get()->filter(fn($p)=>app(\App\Services\AlertasQualidade::class)->podeVer($p,$selecionada))->values():collect();
  $alertas=app(\App\Services\AlertasQualidade::class)->pendentes($u,TenantContext::get())->selectRaw('COUNT(*) as pendentes,COUNT(*) FILTER (WHERE r.prazo_em < ?) as vencidos',[now()])->first();
  return compact('alertas','responsaveis','totais','linhas','selecionada','historico','evolucao')+['etiquetas'=>\App\Models\Tag::where('contexto','conversa')->orderBy('nome')->get(),'canais'=>$canais->orderBy('nome')->get(),'equipes'=>$equipes->orderBy('nome')->get(),'pessoas'=>User::where('tenant_id',TenantContext::get())->orderBy('name')->get(),'tz'=>Tenant::find(TenantContext::get())?->fuso_horario ?: 'America/Sao_Paulo'];
 }
}
