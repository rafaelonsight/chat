<?php
use App\Models\{Tenant,User,Channel,Contact,Conversation,Message,ConversationEvent};
use App\Support\TenantContext;
use App\Services\{ResumoIA,EncerrarComResumo};
use Illuminate\Support\Facades\{Http,DB};
use Livewire\Livewire;
beforeEach(function(){
 $this->tenant=Tenant::create(['nome'=>'IA','slug'=>'ia']);TenantContext::set($this->tenant->id);
 $this->tenant->forceFill(['ia_config'=>['ativa'=>true,'provedor'=>'gateway','modelo'=>'claude-sonnet-5','chave'=>'segredo-api']])->save();
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
it('criptografa chave e não serializa configurações',function(){
 expect(DB::table('tenants')->where('id',$this->tenant->id)->value('ia_config'))->not->toContain('segredo-api');
 expect($this->tenant->fresh()->ia_config['chave'])->toBe('segredo-api');
 expect($this->tenant->toArray())->not->toHaveKey('ia_config');
});
it('envia apenas mensagens válidas sem notas internas e não encerra automaticamente',function(){
 Message::create(['conversation_id'=>$this->conversation->id,'channel_id'=>$this->channel->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Quero segunda via','status'=>'delivered']);
 Message::create(['conversation_id'=>$this->conversation->id,'channel_id'=>$this->channel->id,'direcao'=>'out','tipo'=>'text','corpo'=>'NÃO FOI ENVIADA','status'=>'failed']);
 ConversationEvent::create(['conversation_id'=>$this->conversation->id,'tipo'=>'nota','descricao'=>'SEGREDO INTERNO']);
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>json_encode($this->resultado)]]]])]);
 expect(app(ResumoIA::class)->gerar($this->conversation,$this->user))->toBe($this->resultado);
 Http::assertSent(fn($r)=>str_contains($r['messages'][1]['content'],'Quero segunda via') && !str_contains($r['messages'][1]['content'],'SEGREDO INTERNO') && !str_contains($r['messages'][1]['content'],'NÃO FOI ENVIADA'));
 expect($this->conversation->refresh()->status)->not->toBe(Conversation::ARQUIVADA);
});
it('auditor não pode gerar resumo e não faz requisição',function(){
 $this->user->update(['perfil'=>'auditor']);
 expect(fn()=>app(ResumoIA::class)->gerar($this->conversation,$this->user))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
 Http::assertNothingSent();
});
it('recusa resposta fora do formato sem fabricar conteúdo',function(){
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'texto solto']]]])]);
 expect(fn()=>app(ResumoIA::class)->testar($this->tenant))->toThrow(RuntimeException::class);
});
it('não expõe corpo de erro do provedor',function(){
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['erro'=>'segredo-api'],401)]);
 try {app(ResumoIA::class)->testar($this->tenant);throw new Exception('Esperava falha');}catch(RuntimeException $e){expect($e->getMessage())->toContain('HTTP 401')->not->toContain('segredo-api');}
});
it('empresa recebe no endereço dedicado com deduplicação',function(){
 $this->tenant->update(['email_resumos'=>'atendimento@example.test']);
 app(EncerrarComResumo::class)->registrar($this->conversation,$this->resultado,$this->user);
 expect(DB::table('emails_encerramento')->where('destinatario_tipo','administrador')->value('email'))->toBe('atendimento@example.test');
 expect(DB::table('emails_encerramento')->where('destinatario_tipo','cliente')->value('estado'))->toBe('sem_email');
});
it('configuração não devolve chave salva ao navegador',function(){
 \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 Livewire::actingAs($this->user)->test(\App\Filament\Pages\InteligenciaArtificial::class)->assertSet('chave','')->assertSet('temChave',true)->assertDontSee('segredo-api');
});

it('abrir popup não chama IA e finalizar sem texto deixa email aguardando',function(){
 \Illuminate\Support\Facades\Queue::fake();
 $l=Livewire::actingAs($this->user)->test(\App\Livewire\Inbox\ConversationWindow::class,['conversationId'=>$this->conversation->id]);
 $l->call('prepararResumo')->assertSet('classificando',true)->assertSeeHtml('role="dialog"')->assertDontSeeHtml('wire:model="resumoEncerramento"');
 Http::assertNothingSent();
 $l->call('encerrar')->assertHasNoErrors()->assertSet('classificando',false);
 Http::assertNothingSent();
 expect($this->conversation->refresh()->status)->toBe(Conversation::ARQUIVADA);
 expect(DB::table('encerramentos_atendimento')->value('resumo_estado'))->toBe('preparando');
 expect(DB::table('emails_encerramento')->whereNotNull('email')->where('estado','aguardando_resumo')->count())->toBeGreaterThan(0);
});
it('job prepara resumo sem incluir mensagens posteriores e libera emails uma vez',function(){
 \Illuminate\Support\Facades\Queue::fake();
 $m=Message::create(['conversation_id'=>$this->conversation->id,'channel_id'=>$this->channel->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Pedido original','status'=>'delivered']);
 $id=app(EncerrarComResumo::class)->registrar($this->conversation,['automatico'=>true,'assunto'=>'Em preparação','resumo'=>'Em preparação','enviar_resumo_cliente'=>false],$this->user);
 Message::create(['conversation_id'=>$this->conversation->id,'channel_id'=>$this->channel->id,'direcao'=>'in','tipo'=>'text','corpo'=>'NOVA MENSAGEM APÓS ENCERRAR','status'=>'delivered']);
 Http::fake(['claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>json_encode($this->resultado)]]]])]);
 $job=new \App\Jobs\GerarResumoEncerramento($id);$job->handle(app(ResumoIA::class));$job->handle(app(ResumoIA::class));
 Http::assertSentCount(1);
 Http::assertSent(fn($r)=>str_contains($r['messages'][1]['content'],'Pedido original') && !str_contains($r['messages'][1]['content'],'NOVA MENSAGEM APÓS ENCERRAR'));
 expect(DB::table('encerramentos_atendimento')->value('resumo_estado'))->toBe('pronto');
 expect(DB::table('emails_encerramento')->where('estado','aguardando_resumo')->count())->toBe(0);
 expect(DB::table('emails_encerramento')->where('destinatario_tipo','cliente')->value('estado'))->toBe('nao_solicitado');
});
it('falha final do job não libera envio do texto provisório',function(){
 \Illuminate\Support\Facades\Queue::fake();
 $id=app(EncerrarComResumo::class)->registrar($this->conversation,['automatico'=>true,'assunto'=>'Em preparação','resumo'=>'Em preparação'],$this->user);
 (new \App\Jobs\GerarResumoEncerramento($id))->failed(new RuntimeException('erro'));
 expect(DB::table('encerramentos_atendimento')->value('resumo_estado'))->toBe('falhou');
 expect(DB::table('emails_encerramento')->where('estado','pendente')->count())->toBe(0);
});
