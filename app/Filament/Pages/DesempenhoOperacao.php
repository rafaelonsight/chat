<?php
namespace App\Filament\Pages;
use App\Models\{Conversation,Tenant};
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
class DesempenhoOperacao extends RelatorioAtendentes {
 protected static ?string $navigationLabel='Desempenho da operação';
 protected static ?string $title='Desempenho da operação';
 protected static ?string $slug='desempenho-operacao';
 protected static ?int $navigationSort=1;
 protected string $view='filament.pages.desempenho-operacao';
 public function getViewData(): array {
  abort_unless(static::canAccess(),403);
  $dados=parent::getViewData();$tz=Tenant::findOrFail(TenantContext::get())->fuso_horario ?: 'America/Sao_Paulo';
  $evolucao=$this->base()->selectRaw("DATE(f.created_at AT TIME ZONE ? AT TIME ZONE ?) as dia,COUNT(*) as finalizados,COUNT(f.satisfacao) as respostas,AVG(f.satisfacao) as nota,AVG(f.espera_segundos) as espera",[config('app.timezone'),$tz])->groupByRaw('1')->orderBy('dia')->get();
  $porCanalEquipe=$this->base()->leftJoin('channels as ch',function($j){$j->on('ch.id','=','f.canal_id')->on('ch.tenant_id','=','f.tenant_id');})->leftJoin('teams as t',function($j){$j->on('t.id','=','f.equipe_id')->on('t.tenant_id','=','f.tenant_id');})->selectRaw('f.canal_id,f.equipe_id,MAX(ch.nome) as canal,MAX(t.nome) as equipe,COUNT(*) as finalizados,COUNT(f.satisfacao) as respostas,AVG(f.satisfacao) as nota,AVG(f.espera_segundos) as espera')->groupBy('f.canal_id','f.equipe_id')->orderByDesc('finalizados')->get();
  // Fila atual independe das datas históricas e mantém o escopo de acesso do modelo.
  $fila=Conversation::query()->where('status',Conversation::NOVA)->when((int)$this->canal,fn($q,$id)=>$q->where('channel_id',$id))->when((int)$this->equipe,fn($q,$id)=>$q->where('team_id',$id))->when((int)$this->atendente,fn($q,$id)=>$q->where('atendente_id',$id));
  $aguardando=(clone $fila)->count();$pendentes=$fila->with(['contact','channel','team'])->oldest('created_at')->limit(10)->get();
  return $dados+compact('evolucao','porCanalEquipe','aguardando','pendentes');
 }
}
