<?php
use App\Models\{Tenant,User,ChatbotIntegration,ChatbotAction,Contact,Channel,Conversation};
use App\Services\BlocosChatbot;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{Http,DB};
beforeEach(function(){ $this->tenant=Tenant::create(['nome'=>'Integrações','slug'=>'integracoes']);TenantContext::set($this->tenant->id);$this->integration=ChatbotIntegration::create(['nome'=>'ERP','url'=>'https://8.8.8.8/test','metodo'=>'POST','timeout'=>10,'token'=>'segredo-ficticio','ativa'=>true]);$contact=Contact::create(['nome'=>'Cliente','jid'=>'5511999999999@s.whatsapp.net']);$channel=Channel::create(['nome'=>'Teste']);$this->c=Conversation::create(['contact_id'=>$contact->id,'channel_id'=>$channel->id,'status'=>'novo']);$this->a=new ChatbotAction(['tenant_id'=>$this->tenant->id,'tipo'=>'webhook','config'=>['integracao_id'=>$this->integration->id,'dados'=>'{"nome":"{{nome}}"}','guardar_em'=>'resultado']]); });
afterEach(fn()=>TenantContext::forget());
it('reutiliza url e token preservando dados do bloco e criptografa credencial',function(){
 Http::fake(['*'=>Http::response(['ok'=>true])]);expect(app(BlocosChatbot::class)->validar($this->a))->toBe([]);$result=app(BlocosChatbot::class)->webhook($this->a,$this->c);expect($result)->toBe(['ok'=>true]);Http::assertSent(fn($r)=>$r->url()==='https://8.8.8.8/test'&&$r->method()==='POST'&&$r->hasHeader('Authorization','Bearer segredo-ficticio')&&$r['dados']['nome']==='Cliente');expect(DB::table('chatbot_integrations')->where('id',$this->integration->id)->value('token'))->not->toBe('segredo-ficticio')->and($this->integration->toArray())->not->toHaveKey('token');
});
it('suporta GET e alteracoes centrais sem alterar config dos blocos',function(){
 $original=$this->a->config;$this->integration->update(['metodo'=>'GET','url'=>'https://8.8.8.8/consulta']);Http::fake(['*'=>Http::response(['opcoes'=>[['id'=>'1','rotulo'=>'Suporte']]])]);$this->a->tipo='pergunta_dinamica';$r=app(BlocosChatbot::class)->webhook($this->a,$this->c);expect($r['opcoes'][0]['id'])->toBe('1')->and($this->a->config)->toBe($original);Http::assertSent(fn($r)=>$r->method()==='GET'&&str_starts_with($r->url(),'https://8.8.8.8/consulta'));
});
it('recusa outra conta integracao desativada e destino privado sem HTTP',function(){
 Http::fake();$other=Tenant::create(['nome'=>'Outra','slug'=>'outra']);$foreign=ChatbotIntegration::create(['tenant_id'=>$other->id,'nome'=>'Outra','url'=>'https://8.8.8.8/test','metodo'=>'POST','timeout'=>5,'ativa'=>true]);$this->a->config=['integracao_id'=>$foreign->id,'dados'=>'{}','guardar_em'=>'resultado'];expect(app(BlocosChatbot::class)->validar($this->a))->not->toBeEmpty();expect(fn()=>app(BlocosChatbot::class)->webhook($this->a,$this->c))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
 $this->a->config=['integracao_id'=>$this->integration->id];$this->integration->update(['ativa'=>false]);expect(fn()=>app(BlocosChatbot::class)->webhook($this->a,$this->c))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);expect(fn()=>ChatbotIntegration::create(['nome'=>'Local','url'=>'https://127.0.0.1','metodo'=>'POST','timeout'=>5]))->toThrow(\Illuminate\Validation\ValidationException::class);Http::assertNothingSent();
});
it('mantem URL direta antiga e exclui campos implicitos do contato',function(){
 Http::fake(['*'=>Http::response(['ok'=>true])]);$this->a->config=['url'=>'https://8.8.8.8/antiga','dados'=>'{}','guardar_em'=>'resultado','timeout'=>5];expect(app(BlocosChatbot::class)->validar($this->a))->toBe([]);app(BlocosChatbot::class)->webhook($this->a,$this->c);Http::assertSent(fn($r)=>$r->url()==='https://8.8.8.8/antiga'&&!$r->hasHeader('Authorization')&&!isset($r['email']));
});
it('cadastra e edita pelo painel sem revelar ou apagar token vazio',function(){
 $user=User::create(['name'=>'Admin','email'=>'admin@integracoes.test','password'=>'ficticia','perfil'=>'admin','tenant_id'=>$this->tenant->id]);$this->actingAs($user);\Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 \Livewire\Livewire::test(\App\Filament\Resources\ChatbotIntegrations\Pages\ListChatbotIntegrations::class)->callAction('create',data:['nome'=>'Consulta','url'=>'https://8.8.8.8/api','metodo'=>'GET','timeout'=>5,'ativa'=>true])->assertHasNoActionErrors();expect(ChatbotIntegration::where('nome','Consulta')->first()->tenant_id)->toBe($this->tenant->id);
 \Livewire\Livewire::test(\App\Filament\Resources\ChatbotIntegrations\Pages\EditChatbotIntegration::class,['record'=>$this->integration->id])->assertSet('data.token',null)->fillForm(['nome'=>'ERP atualizado','token'=>''])->call('save')->assertHasNoErrors();expect($this->integration->fresh()->token)->toBe('segredo-ficticio');$user->update(['perfil'=>'atendente']);$this->actingAs($user->fresh());expect(\App\Filament\Resources\ChatbotIntegrations\ChatbotIntegrationResource::canViewAny())->toBeFalse();
});
