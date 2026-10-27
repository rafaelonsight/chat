<?php
use App\Models\{Tenant,User,Channel,Contact,Conversation,Message};
use App\Support\TenantContext;
use App\Services\{TradutorMensagem,AssistenteEscrita};
use Illuminate\Support\Facades\{Http,Queue};
beforeEach(function(){
 $this->t=Tenant::create(['nome'=>'LA','slug'=>'traducao','idioma_comunicacoes'=>'en_GB','ia_config'=>['provedor'=>'gateway','modelo'=>'claude-sonnet-5','ativa'=>true,'chave'=>'fake']]);
 $this->t->forceFill(['idioma_comunicacoes'=>'en_GB','ia_config'=>['provedor'=>'gateway','modelo'=>'claude-sonnet-5','ativa'=>true,'chave'=>'fake']])->save(); \App\Services\GatewayVirtus::salvar(['provedor'=>'gateway','modelo'=>'claude-sonnet-5','ativa'=>true,'chave'=>'fake']); /* a IA usa o gateway global desde 09/10 */
 TenantContext::set($this->t->id);
 $this->u=User::create(['tenant_id'=>$this->t->id,'name'=>'R','email'=>'r@traducao.test','password'=>'test-password','admin'=>true]);
 $this->c=Channel::create(['nome'=>'C','tipo'=>Channel::EVOLUTION]);
 $contact=Contact::create(['nome'=>'Cliente','telefone_e164'=>'+447000000001']);
 $this->conv=Conversation::abertaOuNova($this->c->id,$contact->id);
 $this->m=Message::create(['conversation_id'=>$this->conv->id,'channel_id'=>$this->c->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Hello, how are you?','status'=>'sent']);
 Queue::fake();
});
afterEach(fn()=>TenantContext::forget());
it('traduz recebida em portugues mesmo com comunicacoes da conta em ingles e preserva original',function(){
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Olá, como você está?"}']]]])]);
 $r=app(TradutorMensagem::class)->traduzir($this->m,$this->u);
 expect($r)->toBe('Olá, como você está?')->and($this->m->fresh()->corpo)->toBe('Hello, how are you?')->and(Message::count())->toBe(1);
 Http::assertSent(fn($req)=>str_contains($req['messages'][0]['content'],'exclusivamente em português brasileiro'));
 Queue::assertNothingPushed();
});
it('traduz rascunho para ingles e nao envia mensagem',function(){
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Hello, how are you?"}']]]])]);
 expect(app(AssistenteEscrita::class)->sugerir($this->conv,$this->u,'Olá, tudo bem?','traduzir_uk'))->toBe('Hello, how are you?');
 Http::assertSent(fn($req)=>str_contains($req['messages'][0]['content'],'Write exclusively in British English'));
 expect(Message::count())->toBe(1);Queue::assertNothingPushed();
});
it('recusa notas internas sem consultar gateway',function(){
 Http::fake();$this->m->update(['direcao'=>'int']);
 expect(fn()=>app(TradutorMensagem::class)->traduzir($this->m,$this->u))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
 Http::assertNothingSent();
});
it('recusa traducao entre contas sem consultar gateway',function(){
 Http::fake();TenantContext::set(Tenant::create(['nome'=>'Outra','slug'=>'traducao-outra'])->id);
 expect(fn()=>app(TradutorMensagem::class)->traduzir($this->m,$this->u))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
 Http::assertNothingSent();
});
it('recusa retorno invalido sem alterar mensagem',function(){
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'invalid']]]])]);
 expect(fn()=>app(TradutorMensagem::class)->traduzir($this->m,$this->u))->toThrow(\RuntimeException::class);
 expect($this->m->fresh()->corpo)->toBe('Hello, how are you?');Queue::assertNothingPushed();
});

it('traduz transcricao do audio e preserva audio e texto original',function(){
 $this->m->update(['tipo'=>'audio','corpo'=>null,'transcricao'=>'I need an appointment tomorrow.','transcricao_status'=>'pronta']);
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Preciso de um agendamento amanhã."}']]]])]);
 expect(app(TradutorMensagem::class)->traduzir($this->m,$this->u))->toBe('Preciso de um agendamento amanhã.');
 Http::assertSent(fn($r)=>str_contains($r['messages'][1]['content'],'I need an appointment tomorrow.'));
 expect($this->m->fresh()->tipo)->toBe('audio')->and($this->m->fresh()->transcricao)->toBe('I need an appointment tomorrow.')->and(Message::count())->toBe(1);
 Queue::assertNothingPushed();
});
it('nao consulta ia quando audio ainda nao tem transcricao',function(){
 $this->m->update(['tipo'=>'audio','transcricao'=>null]);Http::fake();
 expect(fn()=>app(TradutorMensagem::class)->traduzir($this->m,$this->u))->toThrow(\RuntimeException::class,'Aguarde a transcrição');
 Http::assertNothingSent();
});

it('sugere resposta em ingles britanico para conta la sem traduzir a interface',function(){
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Hello, how can I help?"}']]]])]);
 expect(app(AssistenteEscrita::class)->responderConversa($this->conv,$this->u))->toBe('Hello, how can I help?');
 Http::assertSent(fn($r)=>str_contains($r['messages'][0]['content'],'inglês britânico') && str_contains($r['messages'][0]['content'],'British English'));
 expect(Message::count())->toBe(1);Queue::assertNothingPushed();
});

it('atendente escolhe pt-br para sugestao em conta cujo padrao e ingles',function(){
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Olá, como posso ajudar?"}']]]])]);
 expect(app(AssistenteEscrita::class)->responderConversa($this->conv,$this->u,'pt_BR'))->toBe('Olá, como posso ajudar?');
 Http::assertSent(fn($r)=>str_contains($r['messages'][0]['content'],'exclusivamente em português brasileiro'));
 expect($this->t->fresh()->idioma_comunicacoes)->toBe('en_GB');Queue::assertNothingPushed();
});
it('traduz recebida para ingles quando escolhido pelo atendente',function(){
 $this->m->update(['corpo'=>'Olá, tudo bem?']);
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Hello, how are you?"}']]]])]);
 expect(app(TradutorMensagem::class)->traduzir($this->m,$this->u,'en_GB'))->toBe('Hello, how are you?');
 Http::assertSent(fn($r)=>str_contains($r['messages'][0]['content'],'Write exclusively in British English'));
 expect($this->m->fresh()->corpo)->toBe('Olá, tudo bem?');Queue::assertNothingPushed();
});
it('traduz rascunho para portugues quando escolhido pelo atendente',function(){
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Olá, como você está?"}']]]])]);
 expect(app(AssistenteEscrita::class)->sugerir($this->conv,$this->u,'Hello, how are you?','traduzir_uk','pt_BR'))->toBe('Olá, como você está?');
 Http::assertSent(fn($r)=>str_contains($r['messages'][0]['content'],'Traduza para português brasileiro') && str_contains($r['messages'][0]['content'],'exclusivamente em português brasileiro'));
 Queue::assertNothingPushed();
});
