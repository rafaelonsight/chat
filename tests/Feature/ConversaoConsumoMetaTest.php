<?php
use App\Models\MetaTarifa;
use App\Services\ConversaoConsumoMeta;
use Illuminate\Support\Facades\{Cache,Http};
beforeEach(function(){Cache::forget('virtus-cambio-ecb-BRL');Cache::forget('virtus-cambio-ecb-GBP');MetaTarifa::updateOrCreate(['mercado'=>'BR','moeda'=>'USD','categoria'=>'Marketing'],['tarifa'=>0.0625,'verificado_em'=>now(),'erro'=>null,'faixas'=>null]);});
it('converte total e linhas em reais e libras com data de referência',function(){
 Http::fake(['*/BRL'=>Http::response(['date'=>now()->toDateString(),'base'=>'USD','quote'=>'BRL','rate'=>5]),'*/GBP'=>Http::response(['date'=>now()->toDateString(),'base'=>'USD','quote'=>'GBP','rate'=>0.75])]);
 $p=[['country'=>'BR','pricing_type'=>'REGULAR','pricing_category'=>'MARKETING','volume'=>1]];
 $r=app(ConversaoConsumoMeta::class)->calcular($p,'BRL');expect($r['total'])->toBe(0.3125)->and($r['linhas'][0]['tarifa'])->toBe(0.3125)->and($r['cambio']['data'])->toBe(now()->toDateString());
 expect(app(ConversaoConsumoMeta::class)->calcular($p,'GBP')['total'])->toBe(0.046875);
});
it('não inventa valor quando a cotação está indisponível',function(){
 Http::fake(['*'=>Http::response([],503)]);
 $r=app(ConversaoConsumoMeta::class)->calcular([['country'=>'BR','pricing_type'=>'REGULAR','pricing_category'=>'MARKETING','volume'=>1]],'BRL');expect($r['total'])->toBeNull()->and($r['cambio'])->toBeNull();
});
