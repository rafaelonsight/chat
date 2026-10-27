<?php
use App\Models\{Tenant, User, Channel};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use App\Services\Canais\MetaEmbeddedSignup;

beforeEach(function () {
 $this->home=Tenant::create(['nome'=>'Casa','slug'=>'meta-home']);
 $this->client=Tenant::create(['nome'=>'Cliente','slug'=>'meta-client']);
 TenantContext::set($this->home->id);
 $this->user=User::create(['tenant_id'=>$this->home->id,'name'=>'Admin','email'=>'meta-admin@test.test','password'=>'random','admin'=>true]);
 $this->user->forceFill(['operador'=>true,'super_admin'=>true])->save();
 $this->actingAs($this->user)->withSession([TenantContext::CHAVE_SESSAO_SUPORTE=>$this->client->id]);
});
it('vincula a tentativa a conta selecionada e ao usuario', function () {
 $state=$this->postJson('/canais/meta/iniciar',[])->assertOk()->json('state');
 expect(Cache::get("meta_attempt:{$state}"))->toMatchArray(['tenant_id'=>$this->client->id,'user_id'=>$this->user->id,'canal_id'=>null]);
});
it('rejeita reconexao de canal de outra conta', function () {
 $channel=Channel::create(['tenant_id'=>$this->home->id,'nome'=>'Casa','tipo'=>'meta_cloud']);
 $this->postJson('/canais/meta/iniciar',['canal_id'=>$channel->id])->assertNotFound();
});
it('rejeita callback expirado antes de falar com Meta', function () {
 $this->mock(MetaEmbeddedSignup::class)->shouldNotReceive('autorizar');
 $state=$this->postJson('/canais/meta/iniciar',[])->json('state');
 $this->travel(16)->minutes();
 $this->get('/canais/meta/callback?state='.$state.'&code=secret')->assertStatus(422);
});
it('outro usuario nao consome o resultado legitimo', function () {
 Cache::put('meta_signup:result',['tenant_id'=>$this->client->id,'user_id'=>999,'canal_id'=>null],300);
 $this->postJson('/canais/meta/conectar-incorporado',['signup_token'=>'result'])->assertForbidden();
 expect(Cache::has('meta_signup:result'))->toBeTrue();
});
it('grava canal na conta selecionada e impede replay', function () {
 Cache::put('meta_signup:result',['tenant_id'=>$this->client->id,'user_id'=>$this->user->id,'canal_id'=>null,'token'=>'test-token','nome'=>'LA','numero'=>'+441234567890','waba_id'=>'123','phone_number_id'=>'456','business_id'=>null],300);
 $this->postJson('/canais/meta/conectar-incorporado',['signup_token'=>'result'])->assertOk();
 expect(Channel::withoutGlobalScopes()->where('meta_phone_number_id','456')->first()->tenant_id)->toBe($this->client->id);
 $this->postJson('/canais/meta/conectar-incorporado',['signup_token'=>'result'])->assertOk();
 expect(Channel::withoutGlobalScopes()->where('meta_phone_number_id','456')->count())->toBe(1);
});
it('rejeita troca de conta depois de iniciar', function () {
 $this->mock(MetaEmbeddedSignup::class)->shouldNotReceive('autorizar');
 $state=$this->postJson('/canais/meta/iniciar',[])->json('state');
 $this->withSession([TenantContext::CHAVE_SESSAO_SUPORTE=>$this->home->id]);
 $this->get('/canais/meta/callback?state='.$state.'&code=secret')->assertStatus(422);
});

it('callback guarda destino e responsavel da tentativa validada', function () {
 $this->mock(MetaEmbeddedSignup::class)->shouldReceive('autorizar')->once()->with('auth-code','123','456')->andReturn(['ok'=>true,'token'=>'token','nome'=>'LA','numero'=>'+441234567890','waba_id'=>'123','phone_number_id'=>'456']);
 $state=$this->postJson('/canais/meta/iniciar',[])->json('state');
 $response=$this->get('/canais/meta/callback?state='.$state.'&code=auth-code&waba_id=123&phone_number_id=456')->assertOk();
 $token=$response->viewData('signupToken');
 expect(Cache::get("meta_signup:{$token}"))->toMatchArray(['tenant_id'=>$this->client->id,'user_id'=>$this->user->id,'canal_id'=>null]);
 expect(Cache::has("meta_attempt:{$state}"))->toBeFalse();
});
it('nao permite substituir o canal definido na tentativa', function () {
 Cache::put('meta_signup:result',['tenant_id'=>$this->client->id,'user_id'=>$this->user->id,'canal_id'=>null],300);
 $this->postJson('/canais/meta/conectar-incorporado',['signup_token'=>'result','canal_id'=>123])->assertForbidden();
 expect(Cache::has('meta_signup:result'))->toBeTrue();
});
