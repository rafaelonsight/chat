<?php
use App\Models\{Tenant,User,Channel};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Services\Canais\MetaEmbeddedSignup;

beforeEach(function () {
 $this->tenant=Tenant::create(['nome'=>'LA','slug'=>'confirmacao']);
 TenantContext::set($this->tenant->id);
 $this->admin=User::create(['tenant_id'=>$this->tenant->id,'name'=>'Admin','email'=>'confirmacao@test.test','password'=>'secret','admin'=>true]);
 $this->actingAs($this->admin);
 $this->signup=['ok'=>true,'token'=>'secret-token','waba_id'=>'123','phone_number_id'=>'456','nome'=>'LA','numero'=>'+441234567890'];
 $this->mock(MetaEmbeddedSignup::class)->shouldReceive('autorizar')->andReturnUsing(fn()=> $this->signup);
});
function autorizarConfirmacao(): array {
 $state=test()->postJson('/canais/meta/iniciar',[])->assertOk()->json('state');
 $token=test()->postJson('/canais/meta/receber',['state'=>$state,'code'=>'auth-code'])->assertOk()->json('signup_token');
 return [$state,$token];
}
it('recupera autorizacao sem depender da janela e sem divulgar segredo', function () {
 [$state,$token]=autorizarConfirmacao();
 $this->getJson('/canais/meta/resultado?state='.$state)->assertOk()->assertJson(['status'=>'ready','signup_token'=>$token])->assertDontSee('secret-token')->assertDontSee('auth-code');
});
it('recupera canal concluido e permite repeticao sem duplicar', function () {
 [$state,$token]=autorizarConfirmacao();
 $first=$this->postJson('/canais/meta/conectar-incorporado',['signup_token'=>$token])->assertOk();
 $this->getJson('/canais/meta/resultado?state='.$state)->assertOk()->assertJson(['status'=>'completed','redirect'=>$first->json('redirect')]);
 $this->postJson('/canais/meta/conectar-incorporado',['signup_token'=>$token])->assertOk()->assertJson(['redirect'=>$first->json('redirect')]);
 expect(Channel::withoutGlobalScopes()->where('tenant_id',$this->tenant->id)->count())->toBe(1);
});
it('duas autorizacoes do mesmo numero usam um unico canal', function () {
 [, $one]=autorizarConfirmacao();
 [, $two]=autorizarConfirmacao();
 $first=$this->postJson('/canais/meta/conectar-incorporado',['signup_token'=>$one])->assertOk();
 $this->postJson('/canais/meta/conectar-incorporado',['signup_token'=>$two])->assertOk()->assertJson(['redirect'=>$first->json('redirect')]);
 expect(Channel::withoutGlobalScopes()->where('tenant_id',$this->tenant->id)->count())->toBe(1);
});
it('bloqueia numero ja registrado em outra conta', function () {
 $other=Tenant::create(['nome'=>'Outra','slug'=>'confirmacao-outra']);
 Channel::withoutGlobalScopes()->create(['tenant_id'=>$other->id,'tipo'=>'meta_cloud','nome'=>'Outro','meta_phone_number_id'=>'456']);
 [, $token]=autorizarConfirmacao();
 $this->postJson('/canais/meta/conectar-incorporado',['signup_token'=>$token])->assertStatus(409);
 expect(Channel::withoutGlobalScopes()->where('tenant_id',$this->tenant->id)->count())->toBe(0);
});
it('resultado pertence somente ao usuario e conta da tentativa', function () {
 [$state]=autorizarConfirmacao();
 $other=User::create(['tenant_id'=>$this->tenant->id,'name'=>'Outro','email'=>'outro-confirmacao@test.test','password'=>'secret','admin'=>true]);
 $this->actingAs($other)->getJson('/canais/meta/resultado?state='.$state)->assertForbidden();
});
it('expiracao do resultado e explicita', function () {
 $this->getJson('/canais/meta/resultado?state='.Str::uuid())->assertStatus(410);
});
it('administrador recebe acesso ao canal recem criado', function () {
 [, $token]=autorizarConfirmacao();
 $this->postJson('/canais/meta/conectar-incorporado',['signup_token'=>$token])->assertOk();
 expect($this->admin->canais()->withoutGlobalScopes()->count())->toBe(1);
});
it('falha da Meta fica disponivel para a tela recuperar', function () {
 $this->signup=['ok'=>false,'erro'=>'Meta não confirmou'];
 $state=$this->postJson('/canais/meta/iniciar',[])->json('state');
 $this->postJson('/canais/meta/receber',['state'=>$state,'code'=>'auth-code'])->assertStatus(422);
 $this->getJson('/canais/meta/resultado?state='.$state)->assertOk()->assertJson(['status'=>'failed','erro'=>'Meta não confirmou']);
});
