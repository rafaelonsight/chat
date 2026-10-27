<?php
use App\Models\{MetaTarifa,Tenant,User,Channel};
use App\Services\TarifasMeta;
use App\Services\Canais\MetaCloudEnviador;
use App\Filament\Pages\PrecosConsumoMeta;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

afterEach(fn()=>TenantContext::forget());
it('preserva a tarifa anterior quando a fonte oficial falha',function(){
 $t=MetaTarifa::create(['mercado'=>'GB','moeda'=>'USD','categoria'=>'Marketing','tarifa'=>0.05,'verificado_em'=>now()]);
 Http::fake([MetaTarifa::FONTE=>Http::response('',502)]);
 app(TarifasMeta::class)->atualizar('GB','USD');
 expect((float)$t->fresh()->tarifa)->toBe(0.05)->and($t->fresh()->erro)->not->toBeNull();
 expect(app(TarifasMeta::class)->simular($t->fresh(),100))->toBeNull();
});
it('consulta valores oficiais e calcula faixas sem sobreposicao',function(){
 Http::fake(fn($r)=>str_contains($r->url(),'wp-json')?Http::response(['quote'=>'0.05','tier_list'=>[['min_volume'=>1,'max_volume'=>100,'quote'=>0.05],['min_volume'=>101,'quote'=>0.04]]]):Http::response('"restNonce":"public" "wpNonce":"public"'));
 app(TarifasMeta::class)->atualizar('GB','USD');
 $t=MetaTarifa::where('categoria','Marketing')->firstOrFail();
 expect(app(TarifasMeta::class)->simular($t,150))->toBe(7.0);
 $t->faixas=[['min_volume'=>1,'max_volume'=>100,'quote'=>0.05],['min_volume'=>90,'quote'=>0.04]];
 expect(app(TarifasMeta::class)->simular($t,150))->toBeNull();
});
it('nao estima tarifa antiga',function(){
 $t=MetaTarifa::create(['mercado'=>'GB','moeda'=>'GBP','categoria'=>'Marketing','tarifa'=>0.03,'verificado_em'=>now()->subDays(3)]);
 expect(app(TarifasMeta::class)->simular($t,100))->toBeNull();
});
it('recupera volume quando a meta nao disponibiliza custo',function(){
 $t=Tenant::create(['nome'=>'T','slug'=>'precos']);TenantContext::set($t->id);
 $c=Channel::create(['nome'=>'Meta','tipo'=>Channel::META_CLOUD,'meta_waba_id'=>'waba','meta_access_token'=>'fake']);
 Http::fake(fn($r)=>$r['fields']==='currency'?Http::response(['currency'=>'USD']):(str_contains($r['fields'],'COST')?Http::response(['error'=>['message'=>'Cost unavailable']],400):Http::response(['pricing_analytics'=>['data'=>[['data_points'=>[['volume'=>2,'pricing_type'=>'FREE_CUSTOMER_SERVICE']]]]]])));
 $r=app(MetaCloudEnviador::class)->consumo($c,1,100);
 expect($r['ok'])->toBeTrue()->and($r['aviso_custo'])->toBe('Cost unavailable')->and($r['dados']['data'][0]['data_points'][0]['volume'])->toBe(2);
});
it('atendente pode ver precos mas nao consultar consumo',function(){
 $t=Tenant::create(['nome'=>'T','slug'=>'precos-at']);TenantContext::set($t->id);
 $u=User::create(['tenant_id'=>$t->id,'password'=>'test-password','name'=>'U','email'=>'u@precos.test','perfil'=>'atendente','admin'=>false]);
 Livewire::actingAs($u)->test(PrecosConsumoMeta::class)->assertSee('Tabela de preços')->assertDontSee('Consultar consumo')->call('consultarConsumo')->assertForbidden();
 Http::assertNothingSent();
});
it('admin nao consulta canal de outra conta',function(){
 $t=Tenant::create(['nome'=>'T','slug'=>'precos-a']);$o=Tenant::create(['nome'=>'O','slug'=>'precos-o']);
 TenantContext::set($o->id);$c=Channel::create(['nome'=>'Outra','tipo'=>Channel::META_CLOUD,'meta_waba_id'=>'outro']);
 TenantContext::set($t->id);$u=User::create(['tenant_id'=>$t->id,'password'=>'test-password','name'=>'U','email'=>'admin@precos.test','admin'=>true]);
 Livewire::actingAs($u)->test(PrecosConsumoMeta::class)->set('channelId',$c->id)->call('consultarConsumo')
        // Livewire 4.4: o findOrFail sob o escopo do tenant vira 404 no teste, em vez de
        // exceção. O que importa continua igual: o registro de outra conta não é carregado.
        ->assertStatus(404);
 Http::assertNothingSent();
});

it('estima marketing pelo pais do destinatario e separa mensagens gratuitas',function(){
 MetaTarifa::create(['mercado'=>'BR','moeda'=>'USD','categoria'=>'Marketing','tarifa'=>0.0625,'verificado_em'=>now()]);
 $r=app(\App\Services\EstimativaConsumoMeta::class)->calcular([['country'=>'BR','pricing_category'=>'MARKETING','pricing_type'=>'REGULAR','volume'=>1],['country'=>'BR','pricing_category'=>'SERVICE','pricing_type'=>'FREE_CUSTOMER_SERVICE','volume'=>3]],'USD');
 expect($r['total'])->toBe(0.0625)->and($r['linhas'][1]['estimativa'])->toBe(0.0);
});
it('nao apresenta total zero quando falta tarifa de mensagens cobradas',function(){
 $r=app(\App\Services\EstimativaConsumoMeta::class)->calcular([['country'=>'GB','pricing_category'=>'MARKETING','pricing_type'=>'REGULAR','volume'=>1]],'USD');
 expect($r['total'])->toBeNull()->and($r['linhas'][0]['estimativa'])->toBeNull();
});

it('estima em reais libras e dolares usando a tarifa de cada moeda',function(){
 foreach(['BRL'=>0.3,'GBP'=>0.05,'USD'=>0.0625]as$moeda=>$valor){
  MetaTarifa::create(['mercado'=>'BR','moeda'=>$moeda,'categoria'=>'Marketing','tarifa'=>$valor,'verificado_em'=>now()]);
  $r=app(\App\Services\EstimativaConsumoMeta::class)->calcular([['country'=>'BR','pricing_category'=>'MARKETING','pricing_type'=>'REGULAR','volume'=>1]],$moeda);
  expect($r['total'])->toBe($valor)->and($r['moeda'])->toBe($moeda);
 }
});
