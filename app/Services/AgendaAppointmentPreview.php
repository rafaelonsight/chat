<?php
namespace App\Services;
use App\Models\{User,AgendaService,AgendaProfessional,AgendaSettings};
class AgendaAppointmentPreview {
 public function advance(User $u,array &$state,string $input):array {
  app(AgendaCalendar::class)->administrator($u);$phase=$state['phase']??'menu';$n=ctype_digit(trim($input))?(int)trim($input):0;
  if(!isset($state['preview_booking'])){$s=AgendaService::where('ativo',true)->orderBy('id')->first();$p=$s?AgendaProfessional::where('ativo',true)->whereHas('services',fn($q)=>$q->where('agenda_services.id',$s->id))->orderBy('id')->first():null;if(!$s||!$p)return ['menu','Cadastre um serviço e um profissional habilitado para simular Meu agendamento.'];$state['preview_booking']=['service_id'=>$s->id,'professional_id'=>$p->id,'service'=>$s->nome,'professional'=>$p->nome,'price'=>(string)$s->preco,'date'=>now(AgendaSettings::current()->timezone)->addDays(2)->format('Y-m-d'),'time'=>'10:00','active'=>true,'presence'=>'pendente'];}
  $b=$state['preview_booking'];
  if($phase==='menu'){
   if(!$b['active'])return ['menu','Simulação: você não tem agendamentos futuros. Nenhuma reserva real foi alterada.'];
   if(empty($state['name']))return ['name','Qual seu nome para eu consultar seu agendamento na simulação?'];
   return ['manage',$this->summary($state)];
  }
  if($phase==='name'){[$step,$d,$text]=app(AgendaGuidedFlow::class)->advance($u,(object)['business_name'=>'Demonstração','greeting'=>'','handoff'=>'Atendimento humano.'],0,'name',[],$input);if($step==='name')return ['name',$text];$state['name']=trim($input);return ['manage',$this->summary($state)];}
  if($phase==='manage'){
   if($n===1){$state['preview_booking']['presence']='confirmada';return ['menu','Presença confirmada na simulação. Nenhuma reserva real foi alterada.'];}
   if($n===2)return ['date','Qual o novo dia e horário? Exemplo: sexta às 15h, ou somente a data DD/MM/AAAA. A troca não será gravada.'];
   if($n===3)return ['cancel',"Deseja cancelar este agendamento fictício?\n1 — Confirmar cancelamento\n2 — Manter agendamento"];
  }
  if($phase==='date'){
   if(!preg_match('/^\d{2}\/\d{2}\/\d{4}$/',trim($input))){try{$at=app(AgendaPreferredTime::class)->parse($input);}catch(\Illuminate\Validation\ValidationException){return ['date','Informe dia e horário, por exemplo: sexta às 15h, ou a data DD/MM/AAAA.'];}$slots=app(AgendaCalendar::class)->slots($u,$b['professional_id'],$b['service_id'],$at->format('Y-m-d'));$state['domain']['preview_date']=$at->format('Y-m-d');if(in_array($at->format('H:i'),$slots,true)){$state['domain']['options']=[['id'=>$at->format('H:i'),'date'=>$at->format('Y-m-d'),'label'=>AgendaPreferredTime::label($at->format('Y-m-d'),$at->format('H:i'))]];return $this->times($state,1);} $options=app(AgendaPreferredTime::class)->nextForProfessional($u,$b['professional_id'],$b['service_id'],$at->format('Y-m-d'),$at->format('H:i'));if(!$options)return ['date','Não encontrei alternativas. Envie outro dia e horário.'];$state['domain']['options']=$options;return ['times',"Escolha uma alternativa:\n".implode("\n",array_map(fn($o,$k)=>($k+1).' — '.$o['label'],$options,array_keys($options)))];}
   try{$date=\Carbon\CarbonImmutable::createFromFormat('!d/m/Y',trim($input),AgendaSettings::current()->timezone);if(!$date||$date->format('d/m/Y')!==trim($input))throw new \InvalidArgumentException();}catch(\Throwable){return ['date','Data inválida. Envie DD/MM/AAAA.'];}
   $slots=app(AgendaCalendar::class)->slots($u,$b['professional_id'],$b['service_id'],$date->format('Y-m-d'));if(!$slots)return ['date','Não há horários disponíveis nessa data. Envie outro dia (DD/MM/AAAA).'];$state['domain']['preview_date']=$date->format('Y-m-d');$state['domain']['options']=array_map(fn($t)=>['id'=>$t,'label'=>AgendaPreferredTime::label($date->format('Y-m-d'),$t)],array_slice($slots,0,10));return ['times',"Escolha um novo horário:\n".implode("\n",array_map(fn($o,$k)=>($k+1).' — '.$o['label'],$state['domain']['options'],array_keys($state['domain']['options'])))];
  }
  if($phase==='times')return $this->times($state,$n);
  if($phase==='review'){if($n===2)return ['date','Qual o novo dia? Envie DD/MM/AAAA.'];if($n===1){$state['preview_booking']['date']=$state['domain']['preview_date'];$state['preview_booking']['time']=$state['domain']['preview_time'];return ['menu','Agendamento reagendado na simulação. Nenhuma reserva real foi alterada.'];}}
  if($phase==='cancel'){if($n===2)return ['menu','Agendamento fictício mantido.'];if($n===1){$state['preview_booking']['active']=false;return ['menu','Agendamento cancelado na simulação. Nenhuma reserva real foi alterada.'];}}
  return [$phase,'Por gentileza, selecione uma opção válida.'];
 }
 private function summary(array $state):string {$b=$state['preview_booking'];return $state['name'].", este é o seu agendamento fictício:\n\n".$b['service'].' com '.$b['professional']."\n".AgendaPreferredTime::label($b['date'],$b['time'])."\nPresença: ".$b['presence']."\n\n1 — Confirmar presença\n2 — Reagendar\n3 — Cancelar";}
 private function times(array &$state,int $n):array {$b=$state['preview_booking'];$choice=$state['domain']['options'][$n-1]??null;if(!$choice)return ['times','Escolha o número do horário.'];$state['domain']['preview_date']=$choice['date']??$state['domain']['preview_date'];$state['domain']['preview_time']=$choice['id'];return ['review','Simulação: '.$b['service'].' com '.$b['professional']."\nHorário atual: ".AgendaPreferredTime::label($b['date'],$b['time'])."\nNovo horário: ".AgendaPreferredTime::label($state['domain']['preview_date'],$choice['id'])."\nPreço: R$ ".number_format((float)$b['price'],2,',','.')."\n1 — Confirmar troca\n2 — Escolher outro dia\nNenhuma reserva real será alterada."];}
}
