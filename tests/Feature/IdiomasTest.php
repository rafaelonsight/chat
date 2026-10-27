<?php
use App\Models\{Tenant,User};
use App\Support\{Idiomas,TenantContext};
use App\Services\ResumoIA;
use Illuminate\Support\Facades\Http;
beforeEach(function(){
 $this->conta=Tenant::create(['nome'=>'LA','slug'=>'idiomas-test']);
 $this->conta->forceFill(['idioma_padrao'=>'en_GB','idioma_comunicacoes'=>'en_GB'])->save();
 TenantContext::set($this->conta->id);
 $this->usuario=User::create(['tenant_id'=>$this->conta->id,'name'=>'Rafael Silva','email'=>'idioma@test.test','password'=>'secret','admin'=>true]);
 $this->usuario->forceFill(['perfil_preenchido_em'=>now()])->saveQuietly();
 $this->actingAs($this->usuario);
});
afterEach(fn()=>TenantContext::forget());
it('usa ingles britanico por padrao da conta e mostra seletor',function(){
 $this->get('/admin/channels')->assertOk()->assertSee('Channels')->assertSee('English (UK)')->assertSee('Panel language');
 expect(Idiomas::interface($this->usuario))->toBe('en_GB');
});
it('preferencia altera somente o proprio painel e persiste',function(){
 $outro=User::create(['tenant_id'=>$this->conta->id,'name'=>'Other','email'=>'other-lang@test.test','password'=>'secret']);
 $this->post('/idioma',['idioma'=>'pt_BR','user_id'=>$outro->id])->assertRedirect('/admin');
 expect($this->usuario->fresh()->idioma_interface)->toBe('pt_BR')->and($outro->fresh()->idioma_interface)->toBeNull()->and($this->conta->fresh()->idioma_comunicacoes)->toBe('en_GB');
 $this->get('/admin/channels')->assertOk()->assertSee('Canais');
});
it('recusa idioma desconhecido e exige autenticacao',function(){
 $this->post('/idioma',['idioma'=>'xx'])->assertSessionHasErrors('idioma');
 auth()->logout();$this->post('/idioma',['idioma'=>'en_GB'])->assertRedirect();
});
it('IA continua em ingles com interface em portugues',function(){
 $this->usuario->forceFill(['idioma_interface'=>'pt_BR'])->save();app()->setLocale('pt_BR');
 $this->conta->forceFill(['ia_config'=>['ativa'=>true,'provedor'=>'openai','chave'=>'test-key','modelo'=>'test-model']])->save(); \App\Services\GatewayVirtus::salvar(['ativa'=>true,'provedor'=>'openai','chave'=>'test-key','modelo'=>'test-model']); /* a IA usa o gateway global desde 09/10 */
 Http::preventStrayRequests();Http::fake(['api.openai.com/*'=>Http::response(['choices'=>[['message'=>['content'=>'British English response']]]])]);
 expect(app(ResumoIA::class)->responder($this->conta,'Review a draft','text'))->toBe('British English response');
 Http::assertSent(fn($r)=>str_contains($r['messages'][0]['content'],'exclusively in British English (UK)'));
 expect($this->usuario->preferredLocale())->toBe('en_GB');
});
it('traducao nao altera nomes nem mensagens de clientes',function(){
 $this->conta->update(['nome'=>'Configurações']);
 $html=$this->get('/admin/channels')->assertOk()->getContent();
 expect($html)->toContain('Settings')->toContain('Configurações');
});

it('email de acesso usa idioma da conta e restaura interface',function(){
 app()->setLocale('pt_BR');
 $html=Idiomas::noIdioma('en_GB',fn()=>view('emails.token-acesso',['codigo'=>'123456'])->render());
 expect($html)->toContain('123456')->toContain('Your access code')->not->toContain('Seu código');
 expect(app()->getLocale())->toBe('pt_BR');
});
