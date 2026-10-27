<?php
namespace App\Services;
use App\Models\MetaTarifa;
class EstimativaConsumoMeta {
 public function calcular(array $pontos,?string $moeda): array {
  $total=0.0;$linhas=[];$tarifas=[];$moedaValida=in_array($moeda,TarifasMeta::MOEDAS,true);$completa=$moedaValida;
  foreach($pontos as $p){
   $valor=null;$tarifa=null;
   if(str_starts_with((string)($p['pricing_type']??''),'FREE_')){$valor=0.0;$tarifa=0.0;}
   elseif(($p['pricing_type']??'')==='REGULAR' && ($p['pricing_category']??'')==='MARKETING' && $moedaValida){
    $chave=($p['country']??'').':'.$moeda;
    if(!array_key_exists($chave,$tarifas))$tarifas[$chave]=MetaTarifa::where('mercado',$p['country']??'')->where('moeda',$moeda)->where('categoria','Marketing')->first();
    $t=$tarifas[$chave];
    if($t && !$t->faixas){$valor=app(TarifasMeta::class)->simular($t,(int)($p['volume']??0));if($valor!==null)$tarifa=(float)$t->tarifa;}
   }
   if($valor===null)$completa=false;else $total+=$valor;
   $linhas[]=['tarifa'=>$tarifa,'estimativa'=>$valor];
  }
  return ['total'=>$completa && $pontos?$total:null,'linhas'=>$linhas,'moeda'=>$moeda];
 }
}
