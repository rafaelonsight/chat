<?php
namespace App\Services;
use App\Models\MetaTarifa;
use Illuminate\Support\Facades\Http;
class TarifasMeta {
 public const MERCADOS=['GB'=>'United Kingdom','BR'=>'Brasil'];
 public const MOEDAS=['USD','GBP','BRL','EUR'];
 public const CATEGORIAS=['Marketing','Utility','Authentication'];
 public function atualizar(string $mercado,string $moeda): void {
  if(!isset(self::MERCADOS[$mercado]) || !in_array($moeda,self::MOEDAS,true)) throw new \InvalidArgumentException('Mercado ou moeda inválido');
  try {
   $pagina=Http::withUserAgent('VirtusChat/2.0')->timeout(8)->get(MetaTarifa::FONTE)->throw()->body();
   if(!preg_match('/"restNonce":"([^"]+)"/',$pagina,$nonce) || !preg_match('/"wpNonce":"([^"]+)"/',$pagina,$wp)) throw new \RuntimeException('A fonte oficial mudou o formato de consulta.');
  } catch(\Throwable $e) {
   foreach(self::CATEGORIAS as $categoria) $this->falha($mercado,$moeda,$categoria,'A fonte oficial está indisponível. Nenhuma tarifa anterior foi substituída.');
   return;
  }
  foreach(self::CATEGORIAS as $categoria) {
   try {
    $r=Http::withUserAgent('VirtusChat/2.0')->timeout(8)->withHeaders(['X-WP-Nonce'=>$wp[1]])->get('https://whatsappbusiness.com/wp-json/wab/v1/pricing?'.http_build_query(['market'=>$mercado,'currency'=>$moeda,'category'=>$categoria,'_wab_nonce'=>$nonce[1]]))->throw()->json();
    if(!is_array($r) || !isset($r['quote']) || !is_numeric($r['quote']) || (float)$r['quote']<0) throw new \RuntimeException('Tarifa não confirmada');
    $faixas=$r['tier_list']??[];
    if(!is_array($faixas)) throw new \RuntimeException('Faixas inválidas');
    foreach($faixas as $f) if(!isset($f['min_volume'],$f['quote']) || !is_numeric($f['min_volume']) || !is_numeric($f['quote']) || (float)$f['quote']<0) throw new \RuntimeException('Faixa incompleta');
    MetaTarifa::updateOrCreate(['mercado'=>$mercado,'moeda'=>$moeda,'categoria'=>$categoria],['tarifa'=>$r['quote'],'faixas'=>$faixas,'verificado_em'=>now(),'tentativa_em'=>now(),'erro'=>null]);
   } catch(\Throwable $e) {$this->falha($mercado,$moeda,$categoria,'Não foi possível confirmar a tarifa na fonte oficial.');}
  }
 }
 private function falha(string $mercado,string $moeda,string $categoria,string $erro): void {
  MetaTarifa::updateOrCreate(compact('mercado','moeda','categoria'),['tentativa_em'=>now(),'erro'=>$erro]);
 }
 public function simular(MetaTarifa $tarifa,int $quantidade): ?float {
  if($tarifa->tarifa===null || $quantidade<0 || $tarifa->erro || !$tarifa->verificado_em || $tarifa->verificado_em->lt(now()->subDays(2))) return null;
  if($quantidade===0)return 0.0;
  if(!$tarifa->faixas) return (float)$tarifa->tarifa*$quantidade;
  $total=0.0;$proximo=1;
  foreach($tarifa->faixas as $faixa){
   $inicio=max(1,(int)$faixa['min_volume']);
   $fim=isset($faixa['max_volume'])?(int)$faixa['max_volume']:$quantidade;
   if($inicio!==$proximo || $fim<$inicio || !is_numeric($faixa['quote']) || (float)$faixa['quote']<0)return null;
   $ate=min($quantidade,$fim);
   $total+=max(0,$ate-$inicio+1)*(float)$faixa['quote'];
   if($ate===$quantidade)return $total;
   $proximo=$fim+1;
  }
  return $quantidade===0?0.0:null;
 }
}
