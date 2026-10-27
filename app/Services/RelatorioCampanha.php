<?php
namespace App\Services;

use App\Models\{Campaign,CampaignRecipient,Message};
use Illuminate\Support\Facades\DB;

class RelatorioCampanha
{
 public const SITUACOES=['fila'=>'Na fila','sent'=>'Enviadas','delivered'=>'Entregues','read'=>'Lidas','failed'=>'Falhas','pulada'=>'Excluídas','cancelada'=>'Canceladas'];
 public const CLASSIFICACOES=['atingido'=>'Objetivo atingido','perdido'=>'Objetivo perdido','duvida'=>'Dúvida','outro'=>'Outro'];

 public function prepararResposta(Message $m): ?string {
  if($m->direcao!=='in' || $m->automatica || !$m->conversation || $m->conversation->atendente_id)return null;
  $cv=$m->conversation;
  $r=CampaignRecipient::query()->join('campaigns as c','c.id','=','campaign_recipients.campaign_id')->join('messages as sm','sm.id','=','campaign_recipients.message_id')->where('c.tenant_id',$m->tenant_id)->where('sm.channel_id',$m->channel_id)->where('campaign_recipients.contact_id',$cv->contact_id)->whereIn('sm.status',['sent','delivered','read'])->whereBetween('sm.enviada_em',[$m->created_at->copy()->subDays(7),$m->created_at])->orderByDesc('sm.enviada_em')->orderByDesc('sm.id')->select('c.configuracao','sm.enviada_em')->first();
  if(!$r)return null;
  $cfg=is_array($r->configuracao)?$r->configuracao:json_decode((string)$r->configuracao,true);
  $primeira=!Message::where('tenant_id',$m->tenant_id)->where('channel_id',$m->channel_id)->where('direcao','in')->where('created_at','>=',$r->enviada_em)->where('id','<',$m->id)->whereHas('conversation',fn($q)=>$q->where('contact_id',$cv->contact_id))->exists();
  if($primeira && ($team=data_get($cfg,'team_id'))){if(\App\Models\Team::where('tenant_id',$m->tenant_id)->whereKey($team)->exists())$cv->update(['team_id'=>$team]);}
  $modo=data_get($cfg,'resposta_modo','padrao');
  if($modo==='equipe')return 'equipe';
  if($primeira && $modo==='chatbot' && !$cv->chatbot_id && $cv->chatbot_estado===null){
   $bot=\App\Models\Chatbot::where('tenant_id',$m->tenant_id)->where('status','publicado')->where('ativo',true)->find(data_get($cfg,'chatbot_id'));
   if($bot)$cv->update(['chatbot_id'=>$bot->id]);
  }
  return $modo;
 }

 public function historico(Campaign $c,string $acao,?string $detalhe=null): void {
  DB::table('campaign_events')->insert(['campaign_id'=>$c->id,'user_id'=>auth()->id(),'acao'=>$acao,'detalhe'=>$detalhe,'created_at'=>now()]);
 }

 public function permitirEnvio(Message $m): bool {
  $r=CampaignRecipient::where('message_id',$m->id)->first();
  if(!$r) return true;
  $c=Campaign::withoutGlobalScopes()->where('tenant_id',$m->tenant_id)->find($r->campaign_id);
  if(!$c) return false;
  if($c->status===Campaign::CANCELADA){
   $m->update(['status'=>Message::STATUS_FAILED,'erro'=>'Campanha cancelada antes do envio.']);
   $r->update(['status'=>CampaignRecipient::PULADA,'motivo'=>'Campanha cancelada antes do envio.']);
   return false;
  }
  if(!$c->rodando()) return false;
  $quando=app(Disparador::class)->dentroDaJanela($c,now());
  if($c->agendada_para && $c->agendada_para->gt($quando))$quando=$c->agendada_para;
  if($quando->isFuture()){
   ($c->exigeTemplate()?\App\Jobs\SendTemplateMessage::dispatch($m->id,$c->meta_template_id,(array)$c->template_valores):\App\Jobs\SendTextMessage::dispatch($m->id))->delay($quando);
   return false;
  }
  $contato=$m->conversation?->contact;
  if(!$contato || $contato->opt_out_em || $contato->bloqueado_em || $contato->arquivado_em){
   $m->update(['status'=>Message::STATUS_FAILED,'erro'=>'Contato excluído antes do envio.']);
   $r->update(['status'=>CampaignRecipient::PULADA,'motivo'=>'Contato excluído antes do envio.']);
   return false;
  }
  return true;
 }

 public function consulta(Campaign $c) {
  // Status belongs to the message: receipts update it even after campaign completion.
  // Attribution: first incoming within 7 days, before the next campaign on the same channel.
  return CampaignRecipient::query()->where('campaign_id',$c->id)
   ->leftJoin('messages as envio','envio.id','=','campaign_recipients.message_id')
   ->select('campaign_recipients.*','envio.conversation_id','envio.enviada_em as confirmada_em','envio.lida_em','envio.erro')
   ->selectRaw("CASE WHEN envio.status IN ('sent','delivered','read') THEN envio.status WHEN campaign_recipients.status='pulada' THEN CASE WHEN campaign_recipients.motivo LIKE 'Campanha cancelada%' THEN 'cancelada' ELSE 'pulada' END WHEN envio.status IS NOT NULL THEN CASE WHEN envio.status='queued' THEN 'fila' ELSE envio.status END WHEN campaign_recipients.status='falhou' THEN 'failed' WHEN ?='cancelada' THEN 'cancelada' WHEN campaign_recipients.status='enviada' THEN 'sent' ELSE 'fila' END as situacao",[$c->status])
   ->selectRaw("(SELECT entrada.id FROM messages entrada JOIN conversations cv ON cv.id=entrada.conversation_id WHERE entrada.tenant_id=? AND entrada.channel_id=? AND cv.contact_id=campaign_recipients.contact_id AND entrada.direcao='in' AND entrada.created_at >= envio.enviada_em AND entrada.created_at <= envio.enviada_em + interval '7 days' AND envio.status IN ('sent','delivered','read') AND NOT EXISTS (SELECT 1 FROM campaign_recipients seguinte JOIN messages sm ON sm.id=seguinte.message_id WHERE seguinte.contact_id=campaign_recipients.contact_id AND sm.tenant_id=entrada.tenant_id AND sm.channel_id=entrada.channel_id AND (sm.enviada_em>envio.enviada_em OR (sm.enviada_em=envio.enviada_em AND sm.id>envio.id)) AND sm.enviada_em<=entrada.created_at AND sm.status IN ('sent','delivered','read')) ORDER BY entrada.created_at,entrada.id LIMIT 1) as resposta_id",[$c->tenant_id,$c->channel_id]);
 }

 public function resumo(Campaign $c): array {
  $q=DB::query()->fromSub($this->consulta($c)->toBase(),'r');
  $a=(array)$q->selectRaw("count(*) as total, count(*) filter (where situacao='fila') as fila, count(*) filter (where situacao in ('sent','delivered','read')) as sent, count(*) filter (where situacao in ('delivered','read')) as delivered, count(*) filter (where situacao='read') as read, count(*) filter (where situacao='failed') as failed, count(*) filter (where situacao='pulada') as pulada, count(*) filter (where situacao='cancelada') as cancelada, count(*) filter (where resposta_id is not null) as responderam")->first();
  return array_map('intval',$a);
 }

 public function destinatarios(Campaign $c,string $busca='',string $status='',string $classificacao='') {
  $ids=DB::query()->fromSub($this->consulta($c)->toBase(),'r')->select('id');
  if($status==='responderam') $ids->whereNotNull('resposta_id');
  elseif($status==='sent') $ids->whereIn('situacao',['sent','delivered','read']);
  elseif($status==='delivered') $ids->whereIn('situacao',['delivered','read']);
  elseif(isset(self::SITUACOES[$status])) $ids->where('situacao',$status);
  $q=$this->consulta($c)->whereIn('campaign_recipients.id',$ids)->with(['contact','message.conversation','resposta.conversation']);
  if($classificacao) $q->where('classificacao',$classificacao);
  if(trim($busca)!=='') $q->whereHas('contact',fn($q)=>$q->where(fn($q)=>$q->where('nome','ilike','%'.$busca.'%')->orWhere('telefone_e164','ilike','%'.$busca.'%')));
  return $q->orderBy('campaign_recipients.id');
 }
}
