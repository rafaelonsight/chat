<?php
namespace App\Services;
use App\Models\{User,AgendaProfessional,AgendaSettings};
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class AgendaPreferredTime {
 public static function label(string $date,string $time):string {$day=Time::parse($date,AgendaSettings::current()->timezone);$week=['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];return $week[$day->dayOfWeek].', '.$day->format('d/m/Y').' às '.$time;}
 public function parse(string $input):Time {
  $cfg=AgendaSettings::current();$now=Time::now($cfg->timezone);$v=Str::ascii(mb_strtolower(trim($input)));$v=preg_replace('/\s+/',' ',$v);
  if(!preg_match('/^(hoje|amanha|domingo|segunda(?:-feira)?|terca(?:-feira)?|quarta(?:-feira)?|quinta(?:-feira)?|sexta(?:-feira)?|sabado|\d{2}\/\d{2}(?:\/\d{4})?)\s+(?:as\s+)?(\d{1,2})(?:(?::|h)(\d{2}))?\s*(?:h|hs|hrs|horas)?$/',$v,$m))$this->invalid();
  $hour=(int)$m[2];$minute=(int)($m[3]??0);if($hour>23||$minute>59)$this->invalid();$day=$m[1];
  if(in_array($day,['hoje','amanha']))$date=$now->startOfDay()->addDays($day==='amanha'?1:0);
  elseif(str_contains($day,'/')){$format=substr_count($day,'/')===2?'d/m/Y':'d/m';$value=$format==='d/m'?$day.'/'.$now->year:$day;try{$date=Time::createFromFormat('!d/m/Y',$value,$cfg->timezone);}catch(\Throwable){$this->invalid();}if(!$date||$date->format('d/m/Y')!==$value)$this->invalid();}
  else{$days=['domingo'=>0,'segunda'=>1,'terca'=>2,'quarta'=>3,'quinta'=>4,'sexta'=>5,'sabado'=>6];$weekday=$days[str_replace('-feira','',$day)];$date=$now->startOfDay()->addDays(($weekday-$now->dayOfWeek+7)%7);if($date->setTime($hour,$minute)->lte($now))$date=$date->addWeek();}
  $date=$date->setTime($hour,$minute);if($date->lt($now)||$date->gt($now->addDays($cfg->horizon_days)->endOfDay()))$this->invalid();return $date;
 }
 private function invalid():never {throw ValidationException::withMessages(['preferred'=>'Informe dia e horário, por exemplo: quarta às 17h, amanhã às 10:30 ou 21/10/2026 às 15h.']);}
 public function professionals(User $u,int $service,string $date,string $time):array {app(AgendaCalendar::class)->administrator($u);$items=[];foreach($this->eligible($service) as $p)if(in_array($time,app(AgendaCalendar::class)->slots($u,$p->id,$service,$date),true))$items[]=['id'=>$p->id,'label'=>$p->nome];return $items;}
 private function eligible(int $service){return AgendaProfessional::where('ativo',true)->whereHas('services',fn($q)=>$q->where('agenda_services.id',$service))->orderBy('nome')->limit(50)->get();}
 public function nextForProfessional(User $u,int $professional,int $service,string $date,string $time,?int $entry=null):array {app(AgendaCalendar::class)->administrator($u);$cfg=AgendaSettings::current();$from=Time::parse($date.' '.$time,$cfg->timezone);$last=Time::now($cfg->timezone)->addDays($cfg->horizon_days)->startOfDay();$days=array_column(app(AgendaCalendar::class)->workHours($professional),'weekday');$found=[];for($day=$from->startOfDay();$day->lte($last);$day=$day->addDay()){if(!in_array($day->dayOfWeek,$days))continue;foreach(app(AgendaCalendar::class)->slots($u,$professional,$service,$day->format('Y-m-d'),$entry) as $t){if($day->setTimeFromTimeString($t)->lte($from))continue;$found[]=['id'=>$t,'date'=>$day->format('Y-m-d'),'label'=>self::label($day->format('Y-m-d'),$t)];if(count($found)===3)return $found;}}return $found;}
 public function next(User $u,int $service,string $date,string $time):array {
  app(AgendaCalendar::class)->administrator($u);$cfg=AgendaSettings::current();$from=Time::parse($date.' '.$time,$cfg->timezone);$last=Time::now($cfg->timezone)->addDays($cfg->horizon_days)->startOfDay();$pros=$this->eligible($service);$found=[];
  $hours=\Illuminate\Support\Facades\DB::table('agenda_work_hours')->where('tenant_id',$u->tenant_id)->whereIn('professional_id',$pros->pluck('id'))->get()->groupBy('professional_id');
  for($day=$from->startOfDay();$day->lte($last);$day=$day->addDay()){$times=[];foreach($pros as $p){if(!($hours[$p->id]??collect())->contains(fn($h)=>(int)$h->weekday===$day->dayOfWeek))continue;foreach(app(AgendaCalendar::class)->slots($u,$p->id,$service,$day->format('Y-m-d')) as $t){$at=$day->setTimeFromTimeString($t);if($at->lte($from))continue;$times[$t][]=['id'=>$p->id,'label'=>$p->nome];}}ksort($times);foreach($times as $t=>$available){$found[]=['id'=>$day->format('Y-m-d').' '.$t,'label'=>self::label($day->format('Y-m-d'),$t),'date'=>$day->format('Y-m-d'),'time'=>$t,'professionals'=>$available];if(count($found)===3)return $found;}}
  return $found;
 }
}
