<?php
use App\Models\{Tenant,User,Contact,Channel,Conversation,Message,ChatbotIntegration,AttendanceAction,AiAgent,ChatbotAction};
use App\Services\{FerramentasAgenteIA,GatewayVirtus,BlocosChatbot};
use App\Support\TenantContext;
use Illuminate\Support\Facades\{Http,DB};
use Livewire\Livewire;
use App\Livewire\Inbox\ConversationActions;
beforeEach(function(){
 $this->tenant=Tenant::create(['nome'=>'Ferramentas','slug'=>'tools-test']);TenantContext::set($this->tenant->id);GatewayVirtus::salvar(['ativa'=>true,'provedor'=>'gateway','chave'=>'gateway-ficticio','modelo'=>'teste']);
 $this->user=User::create(['tenant_id'=>$this->tenant->id,'name'=>'Admin','email'=>'tools@example.test','password'=>'teste','perfil'=>'admin']);$this->actingAs($this->user);
 $contact=Contact::create(['nome'=>'Cliente','telefone_e164'=>'+447700900001','jid'=>'447700900001@s.whatsapp.net']);$channel=Channel::create(['nome'=>'Teste']);$this->c=Conversation::create(['contact_id'=>$contact->id,'channel_id'=>$channel->id,'status'=>Conversation::EM_ATENDIMENTO]);Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$channel->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Consulte meu contrato']);
 $this->integration=ChatbotIntegration::create(['nome'=>'ERP','url'=>'https://8.8.8.8/contrato','metodo'=>'GET','timeout'=>5,'token'=>'credencial-ficticia','ativa'=>true]);
 $this->action=AttendanceAction::create(['nome'=>'Contrato','tipo'=>'integracao','descricao'=>'Consultar contrato','chatbot_integration_id'=>$this->integration->id,'dados'=>'{"telefone":"{{telefone_cliente}}"}','perfis'=>['admin'],'ativa'=>true,'ia_consulta'=>true]);
 $this->agent=AiAgent::create(['nome'=>'Suporte','tipo'=>'agente','instrucoes'=>'Não invente','ativo'=>true,'max_turnos'=>3,'ferramentas'=>[$this->action->id]]);
 $this->choice=$this->action->id;$this->apiStatus=200;$this->apiResult=['contrato'=>'ativo'];
 Http::fake([
  'https://claude-ss.ia.br/*'=>function($r){$content=str_contains($r['messages'][0]['content'],'Escolha no máximo')?json_encode(['acao_id'=>$this->choice]):'{"texto":"Contrato ativo","transferir":false}';return Http::response(['choices'=>[['message'=>['content'=>$content]]],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>20,'total_tokens'=>120]]);},
  'https://8.8.8.8/*'=>fn()=>Http::response($this->apiResult,$this->apiStatus),
 ]);
 $this->call=fn()=>app(FerramentasAgenteIA::class)->responder($this->agent,$this->c,'Retorne JSON com texto e transferir','Consulte meu contrato');
});
afterEach(fn()=>TenantContext::forget());
it('consulta GET autorizada usa parametros fixos e contabiliza ambas chamadas IA',function(){
 $answer=($this->call)();expect($answer['texto'])->toBe('Contrato ativo')->and($answer['transferir'])->toBeFalse();
 Http::assertSent(fn($r)=>$r->method()==='GET'&&str_starts_with($r->url(),'https://8.8.8.8/contrato')&&$r['telefone']==='+447700900001'&&$r->hasHeader('Authorization','Bearer credencial-ficticia'));Http::assertSentCount(3);
 expect((int)DB::table('consumos_ia')->where('tenant_id',$this->tenant->id)->sum('total'))->toBe(240)->and(DB::table('consumos_ia')->where('recurso','chatbot_ferramenta')->count())->toBe(1)->and(DB::table('ai_tool_runs')->value('status'))->toBe('sucesso');
 expect(DB::table('ai_tool_runs')->value('resultado'))->not->toContain('ativo');
 Http::assertSent(fn($r)=>str_starts_with($r->url(),'https://claude-ss.ia.br')&&!str_contains(json_encode($r->data()),'credencial-ficticia'));
});
it('recusa ferramenta inventada sem consultar integracao',function(){
 $this->choice=999999;expect(($this->call)()['transferir'])->toBeTrue();Http::assertSentCount(1);expect(DB::table('ai_tool_runs')->count())->toBe(0);
});
it('sem selecao responde sem consultar e com cobranca de tokens',function(){
 $this->choice=null;expect(($this->call)()['texto'])->toBe('Contrato ativo');Http::assertSentCount(2);expect(DB::table('ai_tool_runs')->count())->toBe(0);
});
it('POST exige confirmacao humana e nao executa automaticamente',function(){
 $this->action->update(['ia_consulta'=>false]);$this->integration->update(['metodo'=>'POST']);
 expect(($this->call)()['transferir'])->toBeTrue();Http::assertSentCount(1);expect(DB::table('ai_tool_runs')->value('status'))->toBe('pendente');
 $id=DB::table('ai_tool_runs')->value('id');Livewire::test(ConversationActions::class,['conversationId'=>$this->c->id])->assertSee('IA aguarda sua confirmação')->call('selecionarSolicitacao',$id)->assertSee('Revise os dados')->call('confirmar')->assertSet('erro','');
 expect(DB::table('ai_tool_runs')->value('status'))->toBe('sucesso')->and(DB::table('ai_tool_runs')->value('user_id'))->toBe($this->user->id);Http::assertSentCount(2);
});
it('recusa humana nao executa a API',function(){
 $this->action->update(['ia_consulta'=>false]);($this->call)();$id=DB::table('ai_tool_runs')->value('id');Livewire::test(ConversationActions::class,['conversationId'=>$this->c->id])->call('selecionarSolicitacao',$id)->call('recusarSolicitacao');expect(DB::table('ai_tool_runs')->value('status'))->toBe('recusada');Http::assertSentCount(1);
});
it('nao repete consulta da mesma mensagem e registra falhas',function(){
 $this->apiStatus=503;expect(($this->call)()['transferir'])->toBeTrue()->and(DB::table('ai_tool_runs')->value('status'))->toBe('falha');expect(($this->call)()['transferir'])->toBeTrue();Http::assertSentCount(3);
});
it('reutiliza consulta bem sucedida sem repetir HTTP externo',function(){
 ($this->call)();($this->call)();Http::assertSentCount(5);expect(DB::table('ai_tool_runs')->count())->toBe(1);
});
it('rejeita ferramenta de outra conta e auto POST',function(){
 $other=Tenant::create(['nome'=>'Outra','slug'=>'tools-other']);expect(fn()=>AiAgent::create(['tenant_id'=>$other->id,'nome'=>'Outro','tipo'=>'agente','ativo'=>true,'ferramentas'=>[$this->action->id]]))->toThrow(\Illuminate\Validation\ValidationException::class);
 $this->integration->update(['metodo'=>'POST']);expect(fn()=>$this->action->update(['ia_consulta'=>true]))->toThrow(\Illuminate\Validation\ValidationException::class);
});
it('agente sem ferramentas preserva o caminho anterior e especialista usa ferramentas',function(){
 $a=new ChatbotAction(['tenant_id'=>$this->tenant->id,'tipo'=>'agente_ia','config'=>['agente_id'=>$this->agent->id]]);expect(app(BlocosChatbot::class)->responderIA($a,$this->c,'Consulte meu contrato')['texto'])->toBe('Contrato ativo');Http::assertSentCount(3);
});
it('pendencia expirada nao permite aprovacao',function(){
 $this->action->update(['ia_consulta'=>false]);($this->call)();$id=DB::table('ai_tool_runs')->value('id');DB::table('ai_tool_runs')->where('id',$id)->update(['created_at'=>now()->subDays(2)]);
 Livewire::test(ConversationActions::class,['conversationId'=>$this->c->id])->call('selecionarSolicitacao',$id)->assertStatus(404);Http::assertSentCount(1);
});
