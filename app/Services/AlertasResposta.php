<?php
namespace App\Services;
use App\Models\{Conversation,Tenant};use App\Support\TenantContext;use Illuminate\Support\Carbon;
class AlertasResposta {
 public function pendentes(bool $somenteDestinatario = true): array {
  $u=auth()->user();$t=Tenant::find(TenantContext::get());if(!$u || !$t || $u->ehAuditor())return [];
  $cfg=$t->alertas_resposta??[];if(!($cfg['ativo']??true))return [];
  $supervisor=($cfg['supervisor']??false)&&($u->admin || $u->perfil==='supervisor');
  $q=Conversation::query()->where('status',Conversation::EM_ATENDIMENTO)->whereNotNull('atendente_id')->when($somenteDestinatario && !$supervisor,fn($q)=>$q->where('atendente_id',$u->id))->with(['channel','team','contact']);
  $q->select('conversations.*')->selectRaw("(SELECT MIN(i.created_at) FROM messages i WHERE i.conversation_id=conversations.id AND i.tenant_id=conversations.tenant_id AND i.direcao='in' AND i.apagada_em IS NULL AND i.created_at > COALESCE((SELECT MAX(COALESCE(o.enviada_em,o.created_at)) FROM messages o WHERE o.conversation_id=conversations.id AND o.tenant_id=conversations.tenant_id AND o.direcao='out' AND NOT o.automatica AND o.status IN ('sent','delivered','read')),'1970-01-01'::timestamp)) as aguardando_desde");
  $horas=new BusinessHours($t);$avisos=[];$limite=max(1,min(120,(int)($cfg['minutos']??5)));
  foreach($q->orderBy('created_at')->get() as $c){if(!$c->aguardando_desde)continue;$de=Carbon::parse($c->aguardando_desde);if(!$horas->abertoAgora($c->channel,$c->team))continue;
   $min=$horas->configurado($c->channel,$c->team)?$horas->minutosUteisEntre($de,now(),$c->channel,$c->team,false):(int)floor($de->diffInSeconds(now())/60);
   if($min<$limite)continue;$avisos[]=['id'=>$c->id,'protocolo'=>$c->protocolo,'cliente'=>$c->contact?->nomeExibicao(),'minutos'=>$min,'chave'=>$c->id.':'.$de->timestamp,'repetir'=>max(1,min(60,(int)($cfg['repetir']??5)))];
  }return $avisos;
 }
}
