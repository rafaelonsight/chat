<?php
use App\Models\{AttendanceAction,ChatbotIntegration,Conversation,Contact,Channel,Tenant,User};
use App\Services\AcoesAtendimento;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{Http,DB};
use Livewire\Livewire;
use App\Livewire\Inbox\ConversationActions;
beforeEach(function(){
 $this->tenant=Tenant::create(['nome'=>'Ações','slug'=>'acoes-test']);TenantContext::set($this->tenant->id);
 $this->user=User::create(['name'=>'Admin','email'=>'acoes@example.test','password'=>'teste','perfil'=>'admin','tenant_id'=>$this->tenant->id]);$this->actingAs($this->user);
 $contact=Contact::create(['nome'=>'A "B" & C','email'=>'cliente@example.test','telefone_e164'=>'+447700900001','jid'=>'447700900001@s.whatsapp.net']);$channel=Channel::create(['nome'=>'Teste']);
 $this->c=Conversation::create(['contact_id'=>$contact->id,'channel_id'=>$channel->id,'status'=>Conversation::EM_ATENDIMENTO]);
 $this->integration=ChatbotIntegration::create(['nome'=>'ERP teste','url'=>'https://8.8.8.8/test','metodo'=>'POST','timeout'=>5,'ativa'=>true,'token'=>'ficticio']);
 $this->action=AttendanceAction::create(['nome'=>'Consultar contrato','tipo'=>'integracao','chatbot_integration_id'=>$this->integration->id,'dados'=>'{"nome":"{{nome_cliente}}","telefone":"{{telefone_cliente}}"}','perfis'=>['admin','atendente'],'ativa'=>true]);
 $this->service=app(AcoesAtendimento::class);
});
afterEach(fn()=>TenantContext::forget());
it('prepara sem chamar API e pede confirmacao com dados resolvidos',function(){
 Http::fake();Livewire::test(ConversationActions::class,['conversationId'=>$this->c->id])->call('selecionar',$this->action->id)->assertSee('Dados que serão enviados')->assertSee('Confirmar execução')->assertSee('+447700900001');Http::assertNothingSent();expect(DB::table('attendance_action_runs')->count())->toBe(0);
});
it('envia somente dados configurados preservando aspas e registra autoria',function(){
 Http::fake(['*'=>Http::response(['mensagem'=>'Contrato ativo'])]);$p=$this->service->preparar($this->action,$this->c,$this->user);$key=(string)\Illuminate\Support\Str::uuid();
 $result=$this->service->executar($this->action,$this->c,$this->user,$key,$this->service->assinatura($this->action,$p));
 expect($result)->toBe('Contrato ativo');Http::assertSent(fn($r)=>$r['nome']==='A "B" & C'&&$r['telefone']==='+447700900001'&&!isset($r['email'])&&$r->hasHeader('Authorization','Bearer ficticio'));expect(DB::table('attendance_action_runs')->where('chave',$key)->value('user_id'))->toBe($this->user->id);
});
it('impede dupla execucao da mesma confirmacao',function(){
 Http::fake(['*'=>Http::response(['ok'=>true])]);$p=$this->service->preparar($this->action,$this->c,$this->user);$key=(string)\Illuminate\Support\Str::uuid();$sig=$this->service->assinatura($this->action,$p);$this->service->executar($this->action,$this->c,$this->user,$key,$sig);
 expect(fn()=>$this->service->executar($this->action,$this->c,$this->user,$key,$sig))->toThrow(\RuntimeException::class);Http::assertSentCount(1);
});
it('recusa outro tenant e perfil nao autorizado sem HTTP',function(){
 Http::fake();$this->user->update(['perfil'=>'auditor']);expect(fn()=>$this->service->preparar($this->action,$this->c,$this->user))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
 $other=Tenant::create(['nome'=>'Outra','slug'=>'acoes-outra']);$this->c->tenant_id=$other->id;expect(fn()=>$this->service->preparar($this->action,$this->c,$this->user))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);Http::assertNothingSent();
});
it('recusa integracao desativada depois da previa',function(){
 Http::fake();$p=$this->service->preparar($this->action,$this->c,$this->user);$sig=$this->service->assinatura($this->action,$p);$this->integration->update(['ativa'=>false]);$this->action->unsetRelation('integration');
 expect(fn()=>$this->service->executar($this->action,$this->c,$this->user,(string)\Illuminate\Support\Str::uuid(),$sig))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);Http::assertNothingSent();
});
it('recusa alteracao de dados depois da previa',function(){
 Http::fake();$p=$this->service->preparar($this->action,$this->c,$this->user);$sig=$this->service->assinatura($this->action,$p);$this->action->update(['dados'=>'{"novo":true}']);
 expect(fn()=>$this->service->executar($this->action,$this->c,$this->user,(string)\Illuminate\Support\Str::uuid(),$sig))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);Http::assertNothingSent();
});
it('rejeita destino privado variavel no host e variavel desconhecida',function(){
 foreach(['https://127.0.0.1','javascript:alert(1)','https://{{nome_cliente}}.example.test'] as $url)expect(fn()=>AttendanceAction::create(['nome'=>'Página','tipo'=>'pagina','url'=>$url,'perfis'=>['admin']]))->toThrow(\Illuminate\Validation\ValidationException::class);
 expect(fn()=>AttendanceAction::create(['nome'=>'Página','tipo'=>'pagina','url'=>'https://example.test/{{segredo}}','perfis'=>['admin']]))->toThrow(\Illuminate\Validation\ValidationException::class);
});
it('codifica variaveis em paginas e nao manda HTTP',function(){
 Http::fake();$a=AttendanceAction::create(['nome'=>'Abrir cadastro','tipo'=>'pagina','url'=>'https://example.test/cliente?nome={{nome_cliente}}&telefone={{telefone_cliente}}','perfis'=>['admin'],'ativa'=>true]);$p=$this->service->preparar($a,$this->c,$this->user);
 expect($p['url'])->toContain('nome=A%20%22B%22%20%26%20C')->toContain('telefone=%2B447700900001');Http::assertNothingSent();
});
it('registra falha sem revelar corpo retornado nem repetir automaticamente',function(){
 Http::fake(['*'=>Http::response('segredo no retorno',500)]);$p=$this->service->preparar($this->action,$this->c,$this->user);$key=(string)\Illuminate\Support\Str::uuid();
 expect(fn()=>$this->service->executar($this->action,$this->c,$this->user,$key,$this->service->assinatura($this->action,$p)))->toThrow(\RuntimeException::class,'A integração não confirmou');expect(DB::table('attendance_action_runs')->where('chave',$key)->value('status'))->toBe('falha');Http::assertSentCount(1);
});
it('escapa HTML retornado e nao cria mensagem para o cliente',function(){
 Http::fake(['*'=>Http::response(['mensagem'=>'<script>alert(1)</script>'])]);Livewire::test(ConversationActions::class,['conversationId'=>$this->c->id])->call('selecionar',$this->action->id)->call('confirmar')->assertSee('&lt;script&gt;',false);expect($this->c->messages()->count())->toBe(0);
});
it('admin cadastra pelo painel e atendente nao administra',function(){
 \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 Livewire::test(\App\Filament\Resources\AttendanceActions\Pages\ListAttendanceActions::class)->callAction('create',data:['nome'=>'Sistema externo','tipo'=>'pagina','url'=>'https://example.test/{{id_conversa}}','perfis'=>['admin'],'ativa'=>true,'ordem'=>0])->assertHasNoActionErrors();
 expect(AttendanceAction::where('nome','Sistema externo')->first()->tenant_id)->toBe($this->tenant->id);$this->user->update(['perfil'=>'atendente']);expect(\App\Filament\Resources\AttendanceActions\AttendanceActionResource::canViewAny())->toBeFalse();
});
it('abre iframe somente depois da confirmacao e fecha sem perder conversa',function(){
 Http::fake();$a=AttendanceAction::create(['nome'=>'Portal','tipo'=>'pagina','modo_pagina'=>'painel','url'=>'https://example.test/cliente?id={{id_contato}}','perfis'=>['admin'],'ativa'=>true]);
 $component=Livewire::test(ConversationActions::class,['conversationId'=>$this->c->id])->call('selecionar',$a->id)->assertDontSee('<iframe',false)->assertSet('painelAberto',false)->call('confirmar')->assertSet('painelAberto',true)->assertSee('<iframe',false)->assertSee('Abrir em nova aba')->assertSee('https://example.test/cliente?id='.$this->c->contact_id)->call('fechar')->assertSet('painelAberto',false)->assertSet('conversationId',$this->c->id)->assertDontSee('<iframe',false);
 Http::assertNothingSent();expect(DB::table('attendance_action_runs')->where('attendance_action_id',$a->id)->value('status'))->toBe('preparada');
});
it('mantem pagina antiga em nova aba e recusa modo desconhecido',function(){
 $a=AttendanceAction::create(['nome'=>'Portal antigo','tipo'=>'pagina','url'=>'https://example.test','perfis'=>['admin'],'ativa'=>true]);expect($a->fresh()->modo_pagina)->toBe('nova_aba');
 Livewire::test(ConversationActions::class,['conversationId'=>$this->c->id])->call('selecionar',$a->id)->call('confirmar')->assertSet('painelAberto',false)->assertDontSee('<iframe',false)->assertSee('Abrir página');
 expect(fn()=>$a->update(['modo_pagina'=>'invalido']))->toThrow(\Illuminate\Validation\ValidationException::class);
});
it('configuracao alterada nao abre iframe com antiga confirmacao',function(){
 $a=AttendanceAction::create(['nome'=>'Portal','tipo'=>'pagina','modo_pagina'=>'painel','url'=>'https://example.test','perfis'=>['admin'],'ativa'=>true]);
 $component=Livewire::test(ConversationActions::class,['conversationId'=>$this->c->id])->call('selecionar',$a->id);$a->update(['url'=>'https://example.test/novo']);
 $component->call('confirmar')->assertSet('painelAberto',false)->assertDontSee('<iframe',false)->assertSee('A configuração mudou.');
});
