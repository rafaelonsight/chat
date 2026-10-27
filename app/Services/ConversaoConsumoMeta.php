<?php
namespace App\Services;
use Illuminate\Support\Facades\{Cache,Http};
use Carbon\Carbon;
class ConversaoConsumoMeta {
 public function calcular(array $pontos,string $moeda): array {
  $r=app(EstimativaConsumoMeta::class)->calcular($pontos,'USD');$r['cambio']=null;
  if($moeda==='USD')return $r;
  if(!in_array($moeda,['GBP','BRL'],true))abort(422);
  $cotacao=Cache::remember('virtus-cambio-ecb-'.$moeda,now()->addHours(1),function()use($moeda){
   try{$resp=Http::timeout(4)->acceptJson()->get('https://api.frankfurter.dev/v2/providers/ecb/rate/USD/'.$moeda);$d=$resp->json();
    if(!$resp->successful() || ($d['base']??'')!=='USD' || ($d['quote']??'')!==$moeda || !is_numeric($d['rate']??null) || $d['rate']<=0 || !isset($d['date']))return [];
    $data=Carbon::createFromFormat('!Y-m-d',$d['date']);if(!$data || $data->lt(now()->startOfDay()->subDays(7)) || $data->gt(now()->endOfDay()))return [];
    return ['taxa'=>(float)$d['rate'],'data'=>$d['date']];
   }catch(\Throwable $e){return [];}
  });
  $r['moeda']=$moeda;$r['cambio']=$cotacao ?: null;
  $taxa=$cotacao['taxa']??null;
  $r['total']=$taxa!==null && $r['total']!==null?$r['total']*$taxa:null;
  foreach($r['linhas'] as &$linha)foreach(['tarifa','estimativa'] as $campo)$linha[$campo]=$taxa!==null && $linha[$campo]!==null?$linha[$campo]*$taxa:null;
  return $r;
 }
}
