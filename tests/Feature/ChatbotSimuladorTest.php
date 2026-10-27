<?php
use App\Models\{Chatbot,Tenant,Conversation,Message,Contact};
use App\Services\{ChatbotFluxo,ChatbotSimulador};
use App\Support\TenantContext;
beforeEach(function(){
 $t=Tenant::create(['nome'=>'Teste','slug'=>'sim']);TenantContext::set($t->id);
 $this->bot=Chatbot::create(['nome'=>'Sim','mensagem_boas_vindas'=>'Olá','mensagem_nao_entendi'=>'Inválido']);
 app(ChatbotFluxo::class)->criarExemplo($this->bot);
});
afterEach(fn()=>TenantContext::forget());
it('testa menu pergunta e transferencia em rascunho sem gravar dados de atendimento',function(){
 $motor=app(ChatbotSimulador::class);
 $s=$motor->iniciar($this->bot);
 expect($s['aguardando'])->not->toBeNull();
 $s=$motor->responder($this->bot,$s,'2');
 expect($s['aguardando'])->not->toBeNull();
 $s=$motor->responder($this->bot,$s,'Internet lenta');
 expect($s['fim'])->toBeTrue()->and($s['respostas']['problema'])->toBe('Internet lenta')
 ->and(Conversation::count())->toBe(0)->and(Message::count())->toBe(0)->and(Contact::count())->toBe(0)
 ->and($this->bot->fresh()->status)->toBe('rascunho');
});
it('mantem menu aguardando apos resposta invalida e permite reiniciar',function(){
 $m=app(ChatbotSimulador::class);$s=$m->iniciar($this->bot);$id=$s['aguardando'];
 $s=$m->responder($this->bot,$s,'999');
 expect($s['aguardando'])->toBe($id)->and($s['fim'])->toBeFalse();
 $novo=$m->iniciar($this->bot);
 expect(count($novo['historico']))->toBeLessThan(count($s['historico']));
});
