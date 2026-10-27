<?php
use App\Models\{Tenant,User,Channel,Contact,Conversation,Message,ConversationEvent};
use App\Support\TenantContext;
use App\Services\{ResumoIA,EncerrarComResumo};
use Illuminate\Support\Facades\{Http,DB};
use Livewire\Livewire;
use App\Services\AssistenteEscrita;
use App\Livewire\Inbox\MessageComposer;
beforeEach(function(){
 $this->tenant=Tenant::create(['nome'=>'IA','slug'=>'ia']);TenantContext::set($this->tenant->id);
 $this->tenant->forceFill(['ia_config'=>['ativa'=>true,'provedor'=>'gateway','modelo'=>'claude-sonnet-5','chave'=>'segredo-api']])->save(); \App\Services\GatewayVirtus::salvar(['ativa'=>true,'provedor'=>'gateway','modelo'=>'claude-sonnet-5','chave'=>'segredo-api']); /* a IA usa o gateway global desde 09/10 */
 $this->user=User::factory()->create(['tenant_id'=>$this->tenant->id,'perfil'=>'admin']);
 $this->user->forceFill(['admin'=>true,'super_admin'=>false])->saveQuietly();
 $this->channel=Channel::create(['nome'=>'Teste']);$this->user->canais()->attach($this->channel);
 $this->contact=Contact::create(['nome'=>'Cliente','jid'=>'5511999999999@s.whatsapp.net']);
 $this->conversation=Conversation::create(['channel_id'=>$this->channel->id,'contact_id'=>$this->contact->id]);
 $this->actingAs($this->user);
 $this->resultado=['assunto'=>'Segunda via','resumo'=>'Cliente solicitou segunda via.','solucao'=>'Segunda via enviada.','pendencias'=>''];
 Http::preventStrayRequests();
});
afterEach(fn()=>TenantContext::forget());

it('gera e aplica sugestão sem enviar mensagem e sem expor histórico',function(){
 Message::create(['conversation_id'=>$this->conversation->id,'channel_id'=>$this->channel->id,'direcao'=>'in','tipo'=>'text','corpo'=>'HISTÓRICO PRIVADO','status'=>'delivered']);
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Olá, posso ajudar?"}']]]])]);
 $t=Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->set('corpo','ola posso ajudar')->call('sugerirEscrita')->assertSet('corpo','ola posso ajudar')->assertSet('sugestaoEscrita','Olá, posso ajudar?');
 Http::assertSent(fn($r)=>str_contains($r['messages'][1]['content'],'ola posso ajudar') && !str_contains(json_encode($r->data()),'HISTÓRICO PRIVADO'));
 $t->call('aplicarEscrita')->assertSet('corpo','Olá, posso ajudar?')->assertSet('sugestaoEscrita','');expect(Message::where('direcao','out')->count())->toBe(0);
});
it('preserva edição e apaga sugestão ao trocar conversa',function(){
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Olá!"}']]]])]);
 $t=Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->set('corpo','ola')->call('sugerirEscrita')->set('corpo','Olá, nova edição')->call('aplicarEscrita')->assertSet('corpo','Olá, nova edição')->assertSet('sugestaoEscrita','');
 $t->call('sugerirEscrita')->call('abrir',$this->conversation->id)->assertSet('sugestaoEscrita','')->assertSet('corpo','');
});
it('descarta e preserva original em falha ou formato inválido',function(){
 Http::fake(['claude-ss.ia.br/*'=>Http::sequence()->push(['erro'=>'CHAVE SECRETA'],401)->push(['choices'=>[['message'=>['content'=>'errado']]]])]);
 Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->set('corpo','Original')->call('sugerirEscrita')->assertSet('corpo','Original')->assertSee('HTTP 401')->assertDontSee('CHAVE SECRETA');

 Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->set('corpo','Original')->call('sugerirEscrita')->assertSet('corpo','Original')->assertSet('sugestaoEscrita','')->assertSee('formato inválido');
});
it('rejeita auditor nota interna e falta de canal sem enviar texto à IA',function(){
 Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->set('corpo','SEGREDO')->set('nota',true)->call('sugerirEscrita')->assertForbidden();
 $this->user->canais()->detach();$this->actingAs($this->user->fresh());Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->set('corpo','SEGREDO')->call('sugerirEscrita')->assertForbidden();Http::assertNothingSent();
});
it('suporta Anthropic e OpenAI com a configuração da conta',function(){
 foreach(['anthropic','openai'] as $p){$this->tenant->forceFill(['ia_config'=>['ativa'=>true,'provedor'=>$p,'modelo'=>'modelo-teste','chave'=>'segredo']])->save(); \App\Services\GatewayVirtus::salvar(['ativa'=>true,'provedor'=>$p,'modelo'=>'modelo-teste','chave'=>'segredo']); /* a IA usa o gateway global desde 09/10 */Http::fake([($p==='anthropic'?'api.anthropic.com/*':'api.openai.com/*')=>Http::response($p==='anthropic'?['content'=>[['text'=>'{"texto":"Olá!"}']]]:['choices'=>[['message'=>['content'=>'{"texto":"Olá!"}']]]])]);expect(app(AssistenteEscrita::class)->sugerir($this->conversation->fresh(),$this->user,'ola','corrigir'))->toBe('Olá!');}
});
it('recusa ação inválida ou texto excessivo',function(){
 expect(fn()=>app(AssistenteEscrita::class)->sugerir($this->conversation,$this->user,'ola','inventar'))->toThrow(RuntimeException::class);
 expect(fn()=>app(AssistenteEscrita::class)->sugerir($this->conversation,$this->user,str_repeat('a',4001),'corrigir'))->toThrow(RuntimeException::class);Http::assertNothingSent();
});
