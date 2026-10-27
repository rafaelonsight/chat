<?php
use App\Models\{Chatbot,ChatbotAction,Conversation,Contact,Tenant};
use App\Services\{BlocosChatbot,ChatbotFluxo,ChatbotSimulador};
use App\Support\TenantContext;
beforeEach(function(){ $this->tenant=Tenant::create(['nome'=>'Blocos','slug'=>'blocos']);TenantContext::set($this->tenant->id);$this->bot=Chatbot::create(['nome'=>'Biblioteca','mensagem_boas_vindas'=>'Olá','mensagem_nao_entendi'=>'Não entendi']);$this->grupo=app(ChatbotFluxo::class)->criarPasso($this->bot,400,200); });
afterEach(fn()=>TenantContext::forget());
it('oferece vinte tipos com configuracoes para todos os novos',function(){
 expect(count(ChatbotAction::TIPOS))->toBe(20);
 foreach(ChatbotAction::NOVOS as $tipo)expect(BlocosChatbot::campos($tipo))->not->toBeEmpty();
});
it('guarda metadados na conversa e contato sem alterar outros contatos',function(){
 $c=Contact::create(['nome'=>'Cliente','jid'=>'5511999990000@s.whatsapp.net']);
 $canal=\App\Models\Channel::create(['tipo'=>'evolution','nome'=>'Teste']);$con=Conversation::create(['contact_id'=>$c->id,'channel_id'=>$canal->id,'status'=>'novo']);
 $a=app(ChatbotFluxo::class)->adicionarAcao($this->grupo,'metadados',['chave'=>'plano','valor'=>'Fibra','escopo'=>'contato']);
 app(BlocosChatbot::class)->executar($a,$con);
 expect($c->fresh()->chatbot_metadados['plano'])->toBe('Fibra');
 $con->unsetRelation('contact');expect(app(BlocosChatbot::class)->texto($con,'Plano: {{plano}}'))->toBe('Plano: Fibra');
 $a->update(['config'=>['chave'=>'problema','valor'=>'Sem sinal','escopo'=>'atendimento']]);app(BlocosChatbot::class)->executar($a,$con);
 expect($con->fresh()->chatbot_respostas['problema'])->toBe('Sem sinal')->and($c->fresh()->chatbot_metadados)->not->toHaveKey('problema');
});
it('rejeita url local credenciais e portas alternativas',function(){
 foreach(['http://exemplo.com','https://127.0.0.1','https://10.0.0.1','https://localhost','https://user:senha@exemplo.com','https://exemplo.com:8080'] as $url)expect(app(BlocosChatbot::class)->urlPublica($url,false))->toBeFalse();
});
it('valida destinos de outra empresa e variaveis invalidas',function(){
 $a=app(ChatbotFluxo::class)->adicionarAcao($this->grupo,'outro_chatbot',['chatbot_id'=>999]);expect(app(BlocosChatbot::class)->validar($a))->not->toBeEmpty();
 $a->update(['tipo'=>'metadados','config'=>['chave'=>'chave com espaco','valor'=>'x','escopo'=>'contato']]);expect(app(BlocosChatbot::class)->validar($a))->not->toBeEmpty();
});
it('simula metadados e espera sem criar conversas',function(){
 $inicio=app(ChatbotFluxo::class)->garantirInicio($this->bot);app(ChatbotFluxo::class)->ligar($inicio,$this->grupo);
 app(ChatbotFluxo::class)->adicionarAcao($this->grupo,'metadados',['chave'=>'plano','valor'=>'Fibra']);app(ChatbotFluxo::class)->adicionarAcao($this->grupo,'aguardar',['guardar_em'=>'resposta']);
 $s=app(ChatbotSimulador::class)->iniciar($this->bot);expect($s['respostas']['plano'])->toBe('Fibra')->and($s['aguardando'])->not->toBeNull();
 $s=app(ChatbotSimulador::class)->responder($this->bot,$s,'Olá');expect($s['respostas']['resposta'])->toBe('Olá')->and(Conversation::count())->toBe(0);
});
it('exige configurar IA antes de publicar agente',function(){
 $a=app(ChatbotFluxo::class)->adicionarAcao($this->grupo,'agente_ia',['nome'=>'Suporte','instrucoes'=>'Oriente','max_turnos'=>3]);expect(app(BlocosChatbot::class)->validar($a))->not->toBeEmpty();
});

it('webhook guarda json e preserva aspas nas variaveis sem enviar dados implicitos',function(){
 \Illuminate\Support\Facades\Http::fake(['https://8.8.8.8/test'=>\Illuminate\Support\Facades\Http::response(['ok'=>true],200)]);
 $contact=Contact::create(['nome'=>'Rafael "Silva"','jid'=>'5511999990001@s.whatsapp.net']);$canal=\App\Models\Channel::create(['tipo'=>'evolution','nome'=>'Teste']);$c=Conversation::create(['contact_id'=>$contact->id,'channel_id'=>$canal->id,'status'=>'novo']);
 $a=app(ChatbotFluxo::class)->adicionarAcao($this->grupo,'webhook',['url'=>'https://8.8.8.8/test','dados'=>'{"nome":"{{nome}}"}','guardar_em'=>'resultado','timeout'=>2]);
 expect(app(BlocosChatbot::class)->executar($a,$c))->toBe(['handle'=>'sucesso'])->and($c->fresh()->chatbot_respostas['resultado']['ok'])->toBeTrue();
 \Illuminate\Support\Facades\Http::assertSent(fn($r)=>$r['dados']['nome']==='Rafael "Silva"' && !isset($r['email']));
});
it('executa metadados e espera uma resposta no motor real sem chamar provedores',function(){
 \Illuminate\Support\Facades\Bus::fake();$f=app(ChatbotFluxo::class);$f->ligar($f->garantirInicio($this->bot),$this->grupo);
 $f->adicionarAcao($this->grupo,'metadados',['chave'=>'plano','valor'=>'Fibra','escopo'=>'atendimento']);$f->adicionarAcao($this->grupo,'aguardar',['guardar_em'=>'resposta']);$f->adicionarAcao($this->grupo,'mensagem',['texto'=>'Recebido: {{resposta}}']);
 $this->bot->update(['ativo'=>true,'status'=>Chatbot::PUBLICADO,'tolerancia_segundos'=>0]);
 $contact=Contact::create(['nome'=>'Cliente','jid'=>'5511999990002@s.whatsapp.net']);$canal=\App\Models\Channel::create(['tipo'=>'evolution','nome'=>'Teste']);$c=Conversation::create(['contact_id'=>$contact->id,'channel_id'=>$canal->id,'status'=>'novo']);
 $in=\App\Models\Message::create(['conversation_id'=>$c->id,'channel_id'=>$canal->id,'direcao'=>'in','tipo'=>'text','corpo'=>'oi']);
 expect(app(\App\Services\ChatbotMotor::class)->talvezAtender($canal,$in))->toBeTrue()->and($c->fresh()->chatbot_aguardando)->toBe('aguardar');
 $in2=\App\Models\Message::create(['conversation_id'=>$c->id,'channel_id'=>$canal->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Meu problema']);
 app(\App\Services\ChatbotMotor::class)->talvezAtender($canal,$in2);
 expect($c->fresh()->chatbot_respostas['resposta'])->toBe('Meu problema')->and($c->messages()->where('direcao','out')->value('corpo'))->toBe('Recebido: Meu problema');
});

it('cria move e altera card do contato mantendo o id em variavel',function(){
 $f=\App\Models\Funnel::create(['nome'=>'Painel']);$e1=\App\Models\FunnelStage::create(['funnel_id'=>$f->id,'nome'=>'Novo']);$e2=\App\Models\FunnelStage::create(['funnel_id'=>$f->id,'nome'=>'Feito']);
 $contact=Contact::create(['nome'=>'Cliente','jid'=>'5511999990003@s.whatsapp.net']);$canal=\App\Models\Channel::create(['tipo'=>'evolution','nome'=>'Teste']);$c=Conversation::create(['contact_id'=>$contact->id,'channel_id'=>$canal->id,'status'=>'novo']);
 $a=app(ChatbotFluxo::class)->adicionarAcao($this->grupo,'criar_card',['stage_id'=>$e1->id,'titulo'=>'Suporte de {{nome}}','guardar_em'=>'card_id']);app(BlocosChatbot::class)->executar($a,$c);
 $id=$c->fresh()->chatbot_respostas['card_id'];$item=\App\Models\BoardItem::findOrFail($id);expect($item->titulo)->toBe('Suporte de Cliente');
 $a->update(['tipo'=>'mover_card','config'=>['card_chave'=>'card_id','stage_id'=>$e2->id]]);app(BlocosChatbot::class)->executar($a,$c);expect($item->fresh()->funnel_stage_id)->toBe($e2->id);
 $a->update(['tipo'=>'alterar_card','config'=>['card_chave'=>'card_id','descricao'=>'Atualizada']]);app(BlocosChatbot::class)->executar($a,$c);expect($item->fresh()->descricao)->toBe('Atualizada')->and($item->fresh()->titulo)->toBe('Suporte de Cliente');
});
it('IA valida resposta estruturada e nao recebe notas internas',function(){
 $this->tenant->forceFill(['ia_config'=>['ativa'=>true,'chave'=>'teste','modelo'=>'teste','provedor'=>'openai']])->save();
 \Illuminate\Support\Facades\Http::fake(['https://api.openai.com/*'=>\Illuminate\Support\Facades\Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Como posso ajudar?","transferir":false}']]]],200)]);
 $contact=Contact::create(['nome'=>'Cliente','jid'=>'5511999990004@s.whatsapp.net']);$canal=\App\Models\Channel::create(['tipo'=>'evolution','nome'=>'Teste']);$c=Conversation::create(['contact_id'=>$contact->id,'channel_id'=>$canal->id,'status'=>'novo']);
 \App\Models\ConversationEvent::create(['conversation_id'=>$c->id,'tipo'=>'nota','descricao'=>'SEGREDO_INTERNO']);
 $a=app(ChatbotFluxo::class)->adicionarAcao($this->grupo,'agente_ia',['nome'=>'Suporte','instrucoes'=>'Faça uma pergunta','max_turnos'=>3]);
 expect(app(BlocosChatbot::class)->responderIA($a,$c,'Olá'))->toBe(['texto'=>'Como posso ajudar?','transferir'=>false]);
 \Illuminate\Support\Facades\Http::assertSent(fn($r)=>!str_contains(json_encode($r->data()),'SEGREDO_INTERNO'));
});
