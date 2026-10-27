<?php
namespace App\Services;
use App\Models\{AgendaService,AgendaProfessional,AgendaSettings,User};
use Illuminate\Support\Str;
class AgendaFlowExperience {
 public function assign(User $u,object $cfg,array $professionals,string $date):?array {if(!$professionals)return null;if(($cfg->assignment_strategy??'alphabetical')==='least_booked'){$tz=AgendaSettings::current()->timezone;$start=\Carbon\CarbonImmutable::parse($date,$tz)->startOfDay()->utc();$end=$start->setTimezone($tz)->addDay()->startOfDay()->utc();$counts=\Illuminate\Support\Facades\DB::table('agenda_calendar_entries')->where('tenant_id',$u->tenant_id)->where('kind','booking')->where('active',true)->whereIn('professional_id',array_column($professionals,'id'))->where('start_at','>=',$start)->where('start_at','<',$end)->selectRaw('professional_id, count(*) as total')->groupBy('professional_id')->pluck('total','professional_id');usort($professionals,fn($a,$b)=>(($counts[$a['id']]??0)<=>($counts[$b['id']]??0))?:strcmp(self::normalize($a['label']),self::normalize($b['label'])));}return $professionals[0];}
 public static function normalize(string $s):string {return trim(preg_replace('/\s+/',' ',Str::ascii(mb_strtolower(preg_replace('/\[TESTE\]\s*/iu','',$s)))));}
 public static function choice(string $input,array $choices):string {
  if(ctype_digit(trim($input)))return trim($input);$value=self::normalize($input);if($value==='')return $input;$exact=[];$prefix=[];
  foreach($choices as $i=>$o){$label=self::normalize(explode(' · ',$o['label'])[0]);$aliases=match($label){'quero agendar','agendar horario'=>['agendar','quero agendar'],'meu agendamento'=>['meu agendamento','consultar agendamento'],'confirmar','confirmar presenca','confirmar troca','confirmar cancelamento'=>['confirmar','sim'],'reagendar'=>['reagendar','remarcar'],'escolher outro horario','alterar horario'=>['outro horario','alterar horario'],'sem preferencia'=>['sem preferencia','qualquer profissional','qualquer barbeiro'],default=>[]};if($value===$label||in_array($value,$aliases,true))$exact[]=$i+1;elseif(mb_strlen($value)>=3&&str_starts_with($label,$value.' '))$prefix[]=$i+1;}
  $matches=$exact?:$prefix;return count($matches)===1?(string)$matches[0]:$input;
 }
 public static function snapshot(array $s):array {return array_diff_key($s,array_flip(['_back','_invalid_choice','_prompt','name','preview_booking']));}
 public function human(User $u,object $cfg,array $state,string $text):array {
  $d=$state['domain']??[];$service=!empty($d['service'])?AgendaService::find($d['service']):null;$pro=!empty($d['professional'])?AgendaProfessional::find($d['professional']):null;
  $state['_handoff_context']=['service'=>$service?->nome,'professional'=>$pro?->nome,'date'=>$d['date']??null,'time'=>$d['time']??null,'stage'=>$state['node']??null,'reason'=>$state['_handoff_reason']??'requested'];
  $hours=$cfg->support_hours??[];if(is_string($hours))$hours=json_decode($hours,true)?:[];$now=now(AgendaSettings::current()->timezone);$minute=$now->hour*60+$now->minute;$open=false;$labels=[];$days=['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
  foreach($hours as $h){$start=explode(':',$h['start']);$end=explode(':',$h['end']);if((int)$h['weekday']===$now->dayOfWeek&&$minute>=((int)$start[0]*60+(int)$start[1])&&$minute<((int)$end[0]*60+(int)$end[1]))$open=true;$labels[]=$days[(int)$h['weekday']].': '.$h['start'].'–'.$h['end'];}
  if($hours&&!$open)$text.="\n\n".((($cfg->handoff_after_hours??null)?:'Nosso atendimento humano está fora do expediente. Sua mensagem ficou na fila.'))."\nHorários de atendimento:\n".implode("\n",$labels);return ['human',$state,$text];
 }
}
