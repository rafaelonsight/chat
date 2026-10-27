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


it('sugere resposta sem rascunho e não envia automaticamente',function(){
 Message::create(['conversation_id'=>$this->conversation->id,'channel_id'=>$this->channel->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Minha internet caiu','status'=>'delivered']);
 Http::fake(['*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"As luzes do equipamento estão acesas?"}']]]])]);
 Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->call('sugerirResposta')->assertSet('corpo','')->assertSet('sugestaoEscrita','As luzes do equipamento estão acesas?')->call('aplicarEscrita')->assertSet('corpo','As luzes do equipamento estão acesas?');
 expect(Message::where('direcao','out')->count())->toBe(0);
});
it('exclui notas mensagens apagadas falhas e outras conversas',function(){
 foreach([['in','delivered','CLIENTE VÁLIDO',null],['out','failed','FALHA',null],['out','queued','FILA',null],['in','delivered','APAGADA',now()]] as [$d,$st,$txt,$apagada])Message::create(['conversation_id'=>$this->conversation->id,'channel_id'=>$this->channel->id,'direcao'=>$d,'tipo'=>'text','corpo'=>$txt,'status'=>$st,'apagada_em'=>$apagada]);
 ConversationEvent::create(['conversation_id'=>$this->conversation->id,'tipo'=>'nota','descricao'=>'NOTA INTERNA']);
 Http::fake(['*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Como posso ajudar?"}']]]])]);
 app(AssistenteEscrita::class)->responderConversa($this->conversation,$this->user);
 Http::assertSent(fn($r)=>str_contains($r['messages'][1]['content'],'CLIENTE VÁLIDO') && !str_contains($r['messages'][1]['content'],'FALHA') && !str_contains($r['messages'][1]['content'],'FILA') && !str_contains($r['messages'][1]['content'],'APAGADA') && !str_contains($r['messages'][1]['content'],'NOTA INTERNA'));
});
it('preserva edição posterior e recusa conversa sem contexto',function(){
 Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->set('corpo','Original')->call('sugerirResposta')->assertSet('corpo','Original')->assertSee('Não há mensagens');Http::assertNothingSent();
 Message::create(['conversation_id'=>$this->conversation->id,'channel_id'=>$this->channel->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Oi','status'=>'delivered']);Http::fake(['*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Olá!"}']]]])]);
 Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->set('corpo','Original')->call('sugerirResposta')->set('corpo','Editado')->call('aplicarEscrita')->assertSet('corpo','Editado')->assertSet('sugestaoEscrita','');
});
it('recusa nota interna e canal sem autorização antes de transmitir contexto',function(){
 Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->set('nota',true)->call('sugerirResposta')->assertForbidden();
 $this->user->canais()->detach();$this->actingAs($this->user->fresh());Livewire::test(MessageComposer::class,['conversationId'=>$this->conversation->id])->call('sugerirResposta')->assertForbidden();Http::assertNothingSent();
});
