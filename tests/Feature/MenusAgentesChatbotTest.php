<?php
use App\Models\{Tenant,Channel,Contact,Conversation,Chatbot,ChatbotAction,AiAgent};
use App\Support\TenantContext;
use App\Services\{MenuChatbot,BlocosChatbot,ChatbotFluxo};
use Illuminate\Support\Facades\Http;
beforeEach(function(){ $this->tenant=Tenant::create(['nome'=>'Menus','slug'=>'menus']);TenantContext::set($this->tenant->id); });
afterEach(fn()=>TenantContext::forget());
it('mantem identificadores em botoes e listas e usa texto nos demais canais',function(){
 $meta=new Channel(['tipo'=>Channel::META_CLOUD]);$qr=new Channel(['tipo'=>'evolution']);$ops=[['gatilho'=>'1','rotulo'=>'Financeiro'],['gatilho'=>'2','rotulo'=>'Suporte']];
 $button=MenuChatbot::montar($meta,'botoes','Como ajudar?',$ops,'1. Financeiro');expect($button['interactive']['action']['buttons'][1]['reply'])->toBe(['id'=>'vc:2','title'=>'Suporte']);
 $list=MenuChatbot::montar($meta,'lista','Como ajudar?',$ops,'1. Financeiro');expect($list['interactive']['action']['sections'][0]['rows'][0]['id'])->toBe('vc:1');
 expect(MenuChatbot::montar($qr,'botoes','Como ajudar?',$ops,'1. Financeiro'))->toBe('1. Financeiro');
});
it('recusa menus acima dos limites e ids repetidos',function(){
 $ops=array_fill(0,4,['gatilho'=>'1','rotulo'=>'Financeiro']);expect(MenuChatbot::validar('botoes',$ops))->not->toBeEmpty();
 expect(MenuChatbot::validar('lista',[['id'=>'1','rotulo'=>str_repeat('x',25)]]))->not->toBeEmpty();
 expect(MenuChatbot::validar('desconhecido',[]))->not->toBeEmpty();
});
it('envia payload interativo pelo driver oficial sem rede real',function(){
 Http::fake(['*'=>Http::response(['messages'=>[['id'=>'wamid.test']]],200)]);
 $driver=new \App\Services\Canais\MetaCloudEnviador('token-ficticio','v23.0',10);$channel=new Channel(['tipo'=>Channel::META_CLOUD,'meta_phone_number_id'=>'123']);
 $menu=MenuChatbot::montar($channel,'botoes','Escolha',[['id'=>'a','rotulo'=>'Suporte']],'Suporte');
 expect($driver->interativo($channel,'5511999999999',$menu['interactive'])['external_id'])->toBe('wamid.test');
 Http::assertSent(fn($r)=>$r['type']==='interactive' && $r['interactive']['action']['buttons'][0]['reply']['id']==='vc:a');
});
it('nao seleciona agente de outra conta nem supervisor em bloco agente',function(){
 $other=Tenant::create(['nome'=>'Outra','slug'=>'outra']);$foreign=AiAgent::create(['tenant_id'=>$other->id,'nome'=>'Outro','tipo'=>'agente','instrucoes'=>'Teste','max_turnos'=>3,'ativo'=>true]);
 $super=AiAgent::create(['nome'=>'Supervisor','tipo'=>'supervisor','instrucoes'=>'Teste','max_turnos'=>3,'ativo'=>true]);
 expect(BlocosChatbot::opcoes('agentes',$this->tenant->id))->toBe([])->and(BlocosChatbot::opcoes('supervisores',$this->tenant->id))->toBe([$super->id=>'Supervisor']);
 $a=new ChatbotAction(['tenant_id'=>$this->tenant->id,'tipo'=>'agente_ia','config'=>['agente_id'=>$foreign->id]]);
 expect(fn()=>app(BlocosChatbot::class)->configIA($a))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
 $a->config=['agente_id'=>$super->id];expect(fn()=>app(BlocosChatbot::class)->configIA($a))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
it('usa gateway central e instrucoes do agente com historico',function(){
 \App\Services\GatewayVirtus::salvar(['ativa'=>true,'provedor'=>'gateway','chave'=>'ficticia','modelo'=>'padrao']);
 $agent=AiAgent::create(['nome'=>'Suporte','tipo'=>'agente','instrucoes'=>'Ajude com internet','modelo'=>'modelo-agente','max_turnos'=>5,'ativo'=>true]);
 $a=new ChatbotAction(['tenant_id'=>$this->tenant->id,'tipo'=>'agente_ia','config'=>['agente_id'=>$agent->id]]);
 $ch=Channel::create(['nome'=>'Teste','tipo'=>'evolution']);$contact=Contact::create(['nome'=>'Cliente','jid'=>'5511999999999@s.whatsapp.net']);$c=Conversation::create(['channel_id'=>$ch->id,'contact_id'=>$contact->id,'status'=>'novo']);
 \App\Models\Message::create(['conversation_id'=>$c->id,'channel_id'=>$ch->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Minha conexão caiu']);
 \App\Models\ConversationEvent::create(['conversation_id'=>$c->id,'tipo'=>'nota','descricao'=>'Segredo interno']);
 Http::fake(['*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Vou ajudar","transferir":false}']]]],200)]);
 expect(app(BlocosChatbot::class)->responderIA($a,$c,'O que fazer?')['texto'])->toBe('Vou ajudar');
 Http::assertSent(fn($r)=>$r['model']==='padrao' && str_contains($r['messages'][1]['content'],'Minha conexão caiu') && !str_contains(json_encode($r->data()),'Segredo interno'));
 expect(\App\Services\GatewayVirtus::configuracao()['modelo'])->toBe('padrao')->and(app(BlocosChatbot::class)->configIA($a)['max_turnos'])->toBe(5);
 $agent->update(['ativo'=>false]);expect(fn()=>app(BlocosChatbot::class)->configIA($a))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
it('decodifica clique pelo id do menu sem depender do titulo',function(){
 $method=new ReflectionMethod(\App\Jobs\ProcessMetaWebhook::class,'textoInterativo');
 expect($method->invoke(null,['interactive'=>['button_reply'=>['id'=>'vc:2','title'=>'Suporte']]]))->toBe('2');
 expect($method->invoke(null,['interactive'=>['list_reply'=>['id'=>'externo','title'=>'Outro']]]))->toBe('Outro');
});

it('cadastra agente no painel com validacao do limite e bloqueia atendente',function(){
 $user=\App\Models\User::create(['name'=>'Admin','email'=>'admin@menus.test','password'=>'ficticia','perfil'=>'admin','tenant_id'=>$this->tenant->id]);$this->actingAs($user);\Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 \Livewire\Livewire::test(\App\Filament\Resources\AiAgents\Pages\ListAiAgents::class)->callAction('create',data:['nome'=>'Suporte','tipo'=>'agente','instrucoes'=>'Ajude sem inventar','max_turnos'=>3,'ativo'=>true])->assertHasNoActionErrors();
 expect(AiAgent::where('nome','Suporte')->first()->tenant_id)->toBe($this->tenant->id);
 \Livewire\Livewire::test(\App\Filament\Resources\AiAgents\Pages\ListAiAgents::class)->callAction('create',data:['nome'=>'Outro','tipo'=>'agente','instrucoes'=>'Ajude','max_turnos'=>11,'ativo'=>true])->assertHasActionErrors(['max_turnos']);
 $user->update(['perfil'=>'atendente']);$this->actingAs($user->fresh());expect(\App\Filament\Resources\AiAgents\AiAgentResource::canViewAny())->toBeFalse();
});
it('motor enfileira menu interativo e segue a opcao pelo identificador',function(){
 \Illuminate\Support\Facades\Bus::fake();$f=app(ChatbotFluxo::class);$bot=Chatbot::create(['nome'=>'Menu','mensagem_boas_vindas'=>'Oi','mensagem_nao_entendi'=>'Repita','status'=>Chatbot::PUBLICADO,'ativo'=>true,'tolerancia_segundos'=>0]);
 $start=$f->garantirInicio($bot);$menu=$f->criarPasso($bot,300,100);$dest=$f->criarPasso($bot,700,100);$f->ligar($start,$menu);$f->adicionarAcao($menu,'menu',['texto'=>'Como ajudar?','formato'=>'botoes','opcoes'=>[['gatilho'=>'2','rotulo'=>'Suporte']]]);$f->ligar($menu,$dest,\App\Models\ChatbotEdge::opcao('2'));$f->adicionarAcao($dest,'mensagem',['texto'=>'Você escolheu suporte']);
 $ch=Channel::create(['nome'=>'Oficial','tipo'=>Channel::META_CLOUD]);$contact=Contact::create(['nome'=>'Teste','jid'=>'5511999999999@s.whatsapp.net']);$c=Conversation::create(['channel_id'=>$ch->id,'contact_id'=>$contact->id,'status'=>'novo']);$in=\App\Models\Message::create(['conversation_id'=>$c->id,'channel_id'=>$ch->id,'direcao'=>'in','tipo'=>'text','corpo'=>'oi']);
 app(\App\Services\ChatbotMotor::class)->talvezAtender($ch,$in);expect($c->fresh()->chatbot_aguardando)->toBe('menu');
 \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SendTextMessage::class,fn($j)=>$j->interactive['action']['buttons'][0]['reply']['id']==='vc:2');
 $reply=\App\Models\Message::create(['conversation_id'=>$c->id,'channel_id'=>$ch->id,'direcao'=>'in','tipo'=>'text','corpo'=>'2']);app(\App\Services\ChatbotMotor::class)->talvezAtender($ch,$reply);expect($c->messages()->where('direcao','out')->orderByDesc('id')->value('corpo'))->toBe('Você escolheu suporte');
});
