<?php
use App\Models\{Tenant,AiAgent,ChatbotAction,Conversation,Contact,Channel,ConversationEvent};
use App\Services\BlocosChatbot;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
beforeEach(function(){
 $this->tenant=Tenant::create(['nome'=>'Supervisão','slug'=>'supervisao']);\App\Services\GatewayVirtus::salvar(['ativa'=>true,'provedor'=>'gateway','chave'=>'ficticia','modelo'=>'teste']);TenantContext::set($this->tenant->id);
 $this->sup=AiAgent::create(['nome'=>'Triagem','tipo'=>'supervisor','instrucoes'=>'Distribua por assunto','ativo'=>true,'max_turnos'=>3]);$this->agent=AiAgent::create(['nome'=>'Financeiro','tipo'=>'agente','instrucoes'=>'Ajude com boletos','ativo'=>true,'max_turnos'=>5]);
 $this->a=new ChatbotAction(['id'=>42,'tenant_id'=>$this->tenant->id,'tipo'=>'supervisor_ia','config'=>['agente_id'=>$this->sup->id,'especialistas'=>[$this->agent->id]]]);
 $this->a->forceFill(['id'=>42]);
 $contact=Contact::create(['nome'=>'Cliente','jid'=>'5511999999999@s.whatsapp.net']);$channel=Channel::create(['nome'=>'Teste']);$this->c=Conversation::create(['contact_id'=>$contact->id,'channel_id'=>$channel->id,'status'=>'novo']);
});
afterEach(fn()=>TenantContext::forget());
it('supervisor seleciona agente autorizado e continuacao nao refaz triagem',function(){
 Http::fake(['*'=>Http::sequence()->push(['choices'=>[['message'=>['content'=>json_encode(['agente_id'=>$this->agent->id])]]]])->push(['choices'=>[['message'=>['content'=>'{"texto":"Sobre seu boleto","transferir":false}']]]])->push(['choices'=>[['message'=>['content'=>'{"texto":"Mais detalhes","transferir":false}']]]])]);
 $service=app(BlocosChatbot::class);expect($service->responderIA($this->a,$this->c,'Preciso do boleto')['texto'])->toBe('Sobre seu boleto');
 expect($this->c->fresh()->chatbot_respostas['_especialista_42'])->toBe($this->agent->id)->and($service->configIA($this->a,$this->c)['max_turnos'])->toBe(5)->and(ConversationEvent::count())->toBe(1);
 expect($service->responderIA($this->a,$this->c,'Detalhes')['texto'])->toBe('Mais detalhes');Http::assertSentCount(3);
});
it('sem agente adequado transfere sem chamar especialista',function(){
 Http::fake(['*'=>Http::response(['choices'=>[['message'=>['content'=>'{"agente_id":null}']]]])]);expect(app(BlocosChatbot::class)->responderIA($this->a,$this->c,'Quero humano')['transferir'])->toBeTrue();Http::assertSentCount(1);expect($this->c->fresh()->chatbot_respostas??[])->not->toHaveKey('_especialista_42');
});
it('sem especialistas ativos transfere sem chamar IA',function(){
 Http::fake();$this->agent->update(['ativo'=>false]);expect(app(BlocosChatbot::class)->responderIA($this->a,$this->c,'Boleto')['transferir'])->toBeTrue();Http::assertNothingSent();
});
it('rejeita escolha da IA fora do catalogo e nao grava atribuicao',function(){
 Http::fake(['*'=>Http::response(['choices'=>[['message'=>['content'=>'{"agente_id":999999}']]]])]);expect(fn()=>app(BlocosChatbot::class)->responderIA($this->a,$this->c,'Boleto'))->toThrow(RuntimeException::class);expect($this->c->fresh()->chatbot_respostas??[])->not->toHaveKey('_especialista_42');Http::assertSentCount(1);
});
it('valida isolamento da empresa e agentes desativados na continuacao',function(){
 $other=Tenant::create(['nome'=>'Outra','slug'=>'outra']);$foreign=AiAgent::create(['tenant_id'=>$other->id,'nome'=>'Outro','tipo'=>'agente','instrucoes'=>'Teste','ativo'=>true]);$this->a->config=['agente_id'=>$this->sup->id,'especialistas'=>[$foreign->id]];expect(app(BlocosChatbot::class)->validar($this->a))->not->toBeEmpty();
 $this->a->config=['agente_id'=>$this->sup->id,'especialistas'=>[$this->agent->id]];$this->c->update(['chatbot_respostas'=>['_especialista_42'=>$this->agent->id]]);$this->agent->update(['ativo'=>false]);expect(fn()=>app(BlocosChatbot::class)->configIA($this->a,$this->c))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
it('mantem supervisores antigos sem lista e nao envia notas internas para triagem',function(){
 ConversationEvent::create(['conversation_id'=>$this->c->id,'tipo'=>'nota','descricao'=>'Segredo interno']);Http::fake(['*'=>Http::response(['choices'=>[['message'=>['content'=>'{"agente_id":null}']]]])]);app(BlocosChatbot::class)->responderIA($this->a,$this->c,'Ajude');Http::assertSent(fn($r)=>!str_contains(json_encode($r->data()),'Segredo interno'));
 $this->a->config=['agente_id'=>$this->sup->id];expect(app(BlocosChatbot::class)->configIA($this->a)['nome'])->toBe('Triagem')->and(app(BlocosChatbot::class)->validar($this->a))->toBe([]);
});

it('motor entrega ao especialista e respeita seu limite para chamar humano',function(){
 \Illuminate\Support\Facades\Bus::fake();$this->agent->update(['max_turnos'=>1]);
 Http::fake(['*'=>Http::sequence()->push(['choices'=>[['message'=>['content'=>json_encode(['agente_id'=>$this->agent->id])]]]])->push(['choices'=>[['message'=>['content'=>'{"texto":"Posso ajudar com boleto","transferir":false}']]]])]);
 $bot=\App\Models\Chatbot::create(['nome'=>'Triagem','mensagem_boas_vindas'=>'Oi','mensagem_nao_entendi'=>'Repita','status'=>\App\Models\Chatbot::PUBLICADO,'ativo'=>true,'tolerancia_segundos'=>0]);$f=app(\App\Services\ChatbotFluxo::class);$group=$f->criarPasso($bot,400,100);$f->ligar($f->garantirInicio($bot),$group);$a=$f->adicionarAcao($group,'supervisor_ia',$this->a->config);
 $ch=$this->c->channel;$in=\App\Models\Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$ch->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Boleto']);app(\App\Services\ChatbotMotor::class)->talvezAtender($ch,$in);
 expect($this->c->fresh()->chatbot_aguardando)->toBe('ia')->and($this->c->fresh()->chatbot_respostas['_especialista_'.$a->id])->toBe($this->agent->id)->and($this->c->messages()->where('direcao','out')->value('corpo'))->toBe('Posso ajudar com boleto');
 $reply=\App\Models\Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$ch->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Mais ajuda']);app(\App\Services\ChatbotMotor::class)->talvezAtender($ch,$reply);expect($this->c->fresh()->chatbot_aguardando)->toBeNull();Http::assertSentCount(2);
});
