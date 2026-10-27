<?php
namespace App\Services;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
class AlertasQualidade {
 public function gerente(?User $u): bool {return $u && !$u->ehAuditor() && ($u->admin || in_array($u->perfil,['admin','supervisor'],true));}
 public function podeVer(User $u,object $f): bool {return $this->gerente($u) && $u->tenant_id===$f->tenant_id && ($u->veTudo() || (in_array($f->canal_id,$u->canalIds(),true) && (!$u->equipeIds() || in_array($f->equipe_id,$u->equipeIds(),true))));}
 public function pendentes(User $u,int $tenant): Builder {
  $q=DB::table('encerramentos_atendimento as f')->leftJoin('revisoes_qualidade as r',function($j){$j->on('r.encerramento_id','=','f.id')->on('r.tenant_id','=','f.tenant_id')->whereRaw('r.id=(SELECT MAX(z.id) FROM revisoes_qualidade z WHERE z.encerramento_id=f.id AND z.tenant_id=f.tenant_id)');})->where('f.tenant_id',$tenant)->whereRaw("COALESCE(r.estado,'pendente')!='concluida'")->where(function($q){$q->whereBetween('f.satisfacao',[1,2])->orWhereNotNull('r.id');});
  if(!$this->gerente($u))return $q->whereRaw('1=0');
  if(!$u->veTudo()){$q->whereIn('f.canal_id',$u->canalIds());if($ids=$u->equipeIds())$q->whereIn('f.equipe_id',$ids);}
  return $q;
 }
 public function avisar(int $id,?int $apenasUsuario=null,string $tipo='nota_baixa'): void {
  DB::transaction(function()use($id,$apenasUsuario,$tipo){
   $f=DB::table('encerramentos_atendimento')->where('id',$id)->lockForUpdate()->first();if(!$f)return;
   $r=DB::table('revisoes_qualidade')->where('tenant_id',$f->tenant_id)->where('encerramento_id',$id)->latest('id')->first();if($r?->estado==='concluida')return;
   if($tipo==='nota_baixa' && !in_array($f->satisfacao,[1,2],true))return;
   $usuarios=User::withoutGlobalScope('tenant')->where('tenant_id',$f->tenant_id)->when($apenasUsuario,fn($q,$v)=>$q->whereKey($v))->get();
   foreach($usuarios as $u){if(!$this->podeVer($u,$f))continue;
    if(DB::table('avisos_qualidade')->where('encerramento_id',$id)->where('user_id',$u->id)->where('tipo',$tipo)->exists())continue;
    $nid=(string)Str::uuid();DB::table('avisos_qualidade')->insert(['id'=>$nid,'tenant_id'=>$f->tenant_id,'encerramento_id'=>$id,'user_id'=>$u->id,'tipo'=>$tipo,'created_at'=>now(),'updated_at'=>now()]);
    // Conteúdo genérico evita expor dados do cliente após mudança de permissões ou de conta.
    $notificacao=Notification::make($nid)->title($tipo==='nota_baixa'?'Qualidade: atendimento com nota baixa':'Qualidade: acompanhamento atribuído a você')->body('Abra a revisão para consultar o protocolo e os detalhes autorizados.')->icon('heroicon-o-clipboard-document-check')->iconColor('warning')->actions([Action::make('revisar')->label('Abrir revisão')->url('/admin/qualidade?revisao='.$id)->markAsRead()]);
    $u->notifyNow($notificacao->toDatabase());
   }
  });
 }
}
