<?php
use App\Services\Canais\MetaEmbeddedSignup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\{Tenant,User};
use App\Support\TenantContext;

beforeEach(function () {
 config(['app.url'=>'https://app.virtus.chat','services.meta.verify_token'=>'test-verification']);
 $this->appId='app123';
 $this->debug=['is_valid'=>true,'app_id'=>'app123','granular_scopes'=>[['scope'=>'whatsapp_business_management','target_ids'=>['123']]]];
 $this->numbers=['data'=>[['id'=>'456','display_phone_number'=>'+44 1234 567890','verified_name'=>'LA Group']]];
 $this->calls=[];
 $this->subscriptionSuccess=true;
 $this->subscriptionApps=[['whatsapp_business_api_data'=>['id'=>'app123'],'override_callback_uri'=>'https://app.virtus.chat/webhooks/meta/whatsapp']];
 Http::preventStrayRequests();
 Http::fake(function ($request) {
  $this->calls[]=$request->url();
  if (str_contains($request->url(),'/oauth/access_token')) return Http::response(['access_token'=>'secret-test-token']);
  if (str_contains($request->url(),'/debug_token')) return Http::response(['data'=>$this->debug]);
  if (str_contains($request->url(),'/phone_numbers')) return Http::response($this->numbers);
  if (str_contains($request->url(),'/subscribed_apps')) return Http::response($request->method()==='POST' ? ['success'=>$this->subscriptionSuccess] : ['data'=>$this->subscriptionApps]);
  return Http::response([],404);
 });
 $this->signup=new MetaEmbeddedSignup('app123','secret','v23.0',5);
});
it('descobre conta e numero quando a Meta devolve somente code', function () {
 $r=$this->signup->autorizar('code');
 expect($r)->toMatchArray(['ok'=>true,'waba_id'=>'123','phone_number_id'=>'456','numero'=>'+441234567890','nome'=>'LA Group']);
 Http::assertSent(fn($req)=>str_contains($req->url(),'/123/subscribed_apps'));
});
it('confere identificadores recebidos no evento da Meta', function () {
 expect($this->signup->autorizar('code','123','456')['ok'])->toBeTrue();
});
it('rejeita conta que nao esta autorizada no token', function () {
 expect($this->signup->autorizar('code','999','456')['ok'])->toBeFalse();
 Http::assertNotSent(fn($req)=>str_contains($req->url(),'/phone_numbers') || str_contains($req->url(),'/subscribed_apps'));
});
it('rejeita numero de outra conta', function () {
 expect($this->signup->autorizar('code','123','999')['ok'])->toBeFalse();
 Http::assertNotSent(fn($req)=>str_contains($req->url(),'/subscribed_apps'));
});
it('nao escolhe primeira conta quando ha varias autorizadas', function () {
 $this->debug['granular_scopes'][0]['target_ids']=['123','789'];
 expect($this->signup->autorizar('code')['ok'])->toBeFalse();
});
it('nao escolhe primeiro numero quando ha varios', function () {
 $this->numbers['data'][]=['id'=>'789','display_phone_number'=>'+441111111111'];
 expect($this->signup->autorizar('code')['ok'])->toBeFalse();
 expect($this->signup->autorizar('code','123','456')['ok'])->toBeTrue();
});
it('rejeita token emitido para outro aplicativo', function () {
 $this->debug['app_id']='other';
 expect($this->signup->autorizar('code')['ok'])->toBeFalse();
 Http::assertNotSent(fn($req)=>str_contains($req->url(),'/phone_numbers'));
});
it('rejeita lista paginada como se fosse numero unico', function () {
 $this->numbers['paging']=['next'=>'https://graph.facebook.com/next'];
 expect($this->signup->autorizar('code')['ok'])->toBeFalse();
});
it('recebe code sem IDs e impede reutilizacao da mesma tentativa', function () {
 $tenant=Tenant::create(['nome'=>'LA','slug'=>'meta-retorno']);
 TenantContext::set($tenant->id);
 $user=User::create(['tenant_id'=>$tenant->id,'name'=>'Admin','email'=>'retorno@test.test','password'=>'secret','admin'=>true]);
 $this->actingAs($user);
 $this->app->instance(MetaEmbeddedSignup::class,$this->signup);
 $state=$this->postJson('/canais/meta/iniciar',[])->assertOk()->json('state');
 $response=$this->postJson('/canais/meta/receber',['state'=>$state,'code'=>'code'])->assertOk();
 $pending=Cache::get('meta_signup:'.$response->json('signup_token'));
 expect($pending)->toMatchArray(['tenant_id'=>$tenant->id,'user_id'=>$user->id,'waba_id'=>'123','phone_number_id'=>'456']);
 $this->postJson('/canais/meta/receber',['state'=>$state,'code'=>'code'])->assertStatus(422);
 expect(count(array_filter($this->calls,fn($url)=>str_contains($url,'oauth/access_token'))))->toBe(1);
});

it('nao confirma canal se Meta nao confirmar a inscricao', function () {
 $this->subscriptionSuccess=false;
 expect($this->signup->autorizar('code')['ok'])->toBeFalse();
});
it('nao confirma canal se aplicativo nao aparecer nas inscricoes', function () {
 $this->subscriptionApps=[];
 expect($this->signup->autorizar('code')['ok'])->toBeFalse();
});

it('direciona webhook da conta para esta instalacao sem alterar callback global', function () {
 expect($this->signup->autorizar('code')['ok'])->toBeTrue();
 Http::assertSent(fn($req)=>$req->method()==='POST'
  && str_contains($req->url(),'/123/subscribed_apps')
  && ($req['override_callback_uri'] ?? null)==='https://app.virtus.chat/webhooks/meta/whatsapp'
  && $req['verify_token']==='test-verification');
});
it('nao confirma autorizacao quando webhook permanece no sistema antigo', function () {
 $this->subscriptionApps[0]['override_callback_uri']='https://chat.onsight.com.br/webhooks/meta/whatsapp';
 expect($this->signup->autorizar('code')['ok'])->toBeFalse();
});
it('nao confirma autorizacao sem endereco verificado na Meta', function () {
 unset($this->subscriptionApps[0]['override_callback_uri']);
 expect($this->signup->autorizar('code')['ok'])->toBeFalse();
});
