<?php
use App\Models\{Tenant,User,Channel,Contact,Conversation,ConversationEvent};
use App\Support\TenantContext;
use App\Services\EncerrarComResumo;
use Illuminate\Support\Facades\{DB,Mail};
use Livewire\Livewire;
beforeEach(function(){
 config(['acesso.mailer'=>'array']);
 $this->tenant=Tenant::create(['nome'=>'Empresa','slug'=>'protocolo']);TenantContext::set($this->tenant->id);
 $this->user=User::factory()->create(['tenant_id'=>$this->tenant->id,'perfil'=>'admin']);
 $this->user->forceFill(['super_admin'=>false])->saveQuietly();
 $this->channel=Channel::create(['nome'=>'Principal']);$this->user->canais()->attach($this->channel);
 $this->contact=Contact::create(['nome'=>'Cliente','jid'=>'5511999999999@s.whatsapp.net','email'=>'cliente@example.test']);
 $this->conversation=Conversation::create(['channel_id'=>$this->channel->id,'contact_id'=>$this->contact->id,'atendente_id'=>$this->user->id]);
 $this->actingAs($this->user);
 $this->dados=['assunto'=>'Dúvida sobre pedido','resumo'=>'Cliente pediu o prazo de entrega.','solucao'=>'Prazo informado: amanhã.','pendencias'=>'','email_cliente'=>'cliente@example.test'];
});
afterEach(fn()=>TenantContext::forget());
it('gera protocolo único e mantém na transferência e reabertura',function(){
 $p=$this->conversation->protocolo;expect($p)->toMatch('/^VT-\d{2}\d{2,}$/');
 $outra=Contact::create(['nome'=>'Outro','jid'=>'5511888888888@s.whatsapp.net']);
 $c=Conversation::create(['channel_id'=>$this->channel->id,'contact_id'=>$outra->id]);expect($c->protocolo)->not->toBe($p);
 $this->conversation->assumir($this->user);$this->conversation->arquivar();$this->conversation->reabrir();expect($this->conversation->refresh()->protocolo)->toBe($p);
});
it('registra dois destinatários separados sem duplicar encerramento',function(){
 $s=app(EncerrarComResumo::class);$id=$s->registrar($this->conversation,$this->dados,$this->user);$id2=$s->registrar($this->conversation->refresh(),$this->dados,$this->user);
 expect($id2)->toBe($id)->and(DB::table('emails_encerramento')->count())->toBe(2)->and($this->conversation->refresh()->status)->toBe(Conversation::ARQUIVADA);
});
it('entrega resumo sem notas internas e não reenvia o que já foi enviado',function(){
 ConversationEvent::create(['conversation_id'=>$this->conversation->id,'tipo'=>'nota','descricao'=>'SEGREDO INTERNO','user_id'=>$this->user->id]);
 app(EncerrarComResumo::class)->registrar($this->conversation,$this->dados,$this->user);
 $this->artisan('virtus:enviar-resumos')->assertSuccessful();
 $mensagens=Mail::mailer('array')->getSymfonyTransport()->messages();expect($mensagens)->toHaveCount(2);
 foreach($mensagens as $mail){expect($mail->getOriginalMessage()->getHtmlBody())->toContain($this->conversation->protocolo)->not->toContain('SEGREDO INTERNO');expect($mail->getOriginalMessage()->getTo())->toHaveCount(1);}
 $this->artisan('virtus:enviar-resumos')->assertSuccessful();expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(2);
});
it('mantém envio pendente sem email e entrega após atualizar cadastro',function(){
 $this->contact->update(['email'=>null]);$this->dados['email_cliente']='';
 app(EncerrarComResumo::class)->registrar($this->conversation,$this->dados,$this->user);
 expect(DB::table('emails_encerramento')->where('destinatario_tipo','cliente')->value('estado'))->toBe('sem_email');
 $this->artisan('virtus:enviar-resumos');$this->contact->update(['email'=>'novo@example.test']);$this->artisan('virtus:enviar-resumos');
 expect(DB::table('emails_encerramento')->where('destinatario_tipo','cliente')->value('estado'))->toBe('enviado');
});
it('não permite auditor encerrar ou enviar mensagem',function(){
 $this->user->update(['perfil'=>'auditor']);
 Livewire::actingAs($this->user)->test(\App\Livewire\Inbox\ConversationWindow::class,['conversationId'=>$this->conversation->id])->call('encerrar')->assertForbidden();
 Livewire::actingAs($this->user)->test(\App\Livewire\Inbox\MessageComposer::class,['conversationId'=>$this->conversation->id])->call('enviar')->assertForbidden();
 expect($this->conversation->refresh()->status)->not->toBe(Conversation::ARQUIVADA);
});
it('perfis administrativos não concedem canais de outra empresa',function(){
 $outro=Tenant::create(['nome'=>'Outra','slug'=>'outra']);$contato=Contact::withoutGlobalScopes()->create(['tenant_id'=>$outro->id,'nome'=>'Outro','jid'=>'5511777777777@s.whatsapp.net']);
 $canal=Channel::withoutGlobalScopes()->create(['tenant_id'=>$outro->id,'nome'=>'Outro']);
 $conversa=Conversation::withoutGlobalScopes()->create(['tenant_id'=>$outro->id,'channel_id'=>$canal->id,'contact_id'=>$contato->id]);
 expect($this->user->podeVer($conversa))->toBeFalse()->and(Conversation::find($conversa->id))->toBeNull();
});
it('wizard exibe quatro etapas e acesso por código',function(){
 \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 Livewire::actingAs($this->user)->test(\App\Filament\Resources\Users\Pages\CreateUser::class)->assertSee('Conferência')->assertSee('Quem vai acessar')->assertSee('Não há senha.');
});
it('novo encerramento após reabrir gera versão nova do mesmo protocolo',function(){
 $s=app(EncerrarComResumo::class);$s->registrar($this->conversation,$this->dados,$this->user);$this->conversation->refresh()->reabrir();$s->registrar($this->conversation->refresh(),$this->dados,$this->user);
 expect(DB::table('encerramentos_atendimento')->count())->toBe(2)->and(DB::table('encerramentos_atendimento')->distinct()->count('protocolo'))->toBe(1);
});

it('admin cadastra atendente pelo wizard sem conceder privilégio global',function(){
 \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 Livewire::actingAs($this->user)->test(\App\Filament\Resources\Users\Pages\CreateUser::class)->fillForm(['name'=>'Nova pessoa','email'=>'nova@example.test','perfil'=>'atendente','canais'=>[$this->channel->id],'teams'=>[]])->call('create')->assertHasNoFormErrors();
 $nova=User::where('email','nova@example.test')->firstOrFail();
 // O bootstrap da suíte promove novos atores; aqui verificamos os campos do formulário e o tenant.
 expect($nova->tenant_id)->toBe($this->tenant->id)->and($nova->perfil)->toBe('atendente')->and($nova->admin)->toBeFalse()->and($nova->operador)->toBeFalse()->and($nova->canais()->pluck('channels.id')->all())->toBe([$this->channel->id]);
});
it('auditor não vê notas soltas nem notas vinculadas a mensagens',function(){
 $m=\App\Models\Message::create(['conversation_id'=>$this->conversation->id,'channel_id'=>$this->channel->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Olá']);
 ConversationEvent::create(['conversation_id'=>$this->conversation->id,'tipo'=>'nota','descricao'=>'SEGREDO SOLTO']);
 ConversationEvent::create(['conversation_id'=>$this->conversation->id,'tipo'=>'nota','descricao'=>'SEGREDO VINCULADO','dados'=>['message_id'=>$m->id]]);
 $this->user->update(['perfil'=>'auditor','ver_notas_internas'=>false]);
 Livewire::actingAs($this->user)->test(\App\Livewire\Inbox\ConversationWindow::class,['conversationId'=>$this->conversation->id])->assertDontSee('SEGREDO SOLTO')->assertDontSee('SEGREDO VINCULADO');
});
it('falha de transporte conserva o resumo e agenda nova tentativa',function(){
 app(EncerrarComResumo::class)->registrar($this->conversation,$this->dados,$this->user);
 Mail::shouldReceive('mailer')->twice()->andThrow(new RuntimeException('Transporte indisponível'));
 $this->artisan('virtus:enviar-resumos')->assertSuccessful();
 expect(DB::table('emails_encerramento')->where('estado','pendente')->count())->toBe(2)->and(DB::table('emails_encerramento')->min('tentativas'))->toBe(1)->and(DB::table('emails_encerramento')->whereNotNull('proxima_tentativa_em')->count())->toBe(2);
});
it('wizard recusa canais de outra empresa mesmo com estado adulterado',function(){
 $outro=Tenant::create(['nome'=>'Outra','slug'=>'outra-wizard']);$canal=Channel::withoutGlobalScopes()->create(['tenant_id'=>$outro->id,'nome'=>'Outro']);
 expect(fn()=>\App\Filament\Resources\Users\Schemas\UserForm::validarAcesso(['canais'=>[$canal->id]],$this->tenant->id))->toThrow(\Illuminate\Validation\ValidationException::class);
});
it('supervisor consulta relatórios mas não gerencia usuários',function(){
 $this->user->update(['perfil'=>'supervisor']);expect($this->user->consultaRelatorios())->toBeTrue()->and($this->user->gerenciaUsuarios())->toBeFalse();
 $this->user->update(['perfil'=>'auditor']);expect(\App\Filament\Resources\Users\UserResource::canCreate())->toBeFalse();
});

it('auditor só consulta anexos quando autorizado explicitamente',function(){
 $this->user->update(['perfil'=>'auditor','consultar_anexos'=>false]);expect($this->user->consultaAnexos())->toBeFalse();
 $this->get('/media/999999')->assertForbidden();$this->user->update(['consultar_anexos'=>true]);expect($this->user->consultaAnexos())->toBeTrue();
});

it('desmarcar resumo ao cliente não envia depois de cadastrar email',function(){
 $this->tenant->update(['email_resumos'=>'gestao@example.test']);
 $this->dados['enviar_resumo_cliente']=false;
 app(EncerrarComResumo::class)->registrar($this->conversation,$this->dados,$this->user);
 expect(DB::table('emails_encerramento')->where('destinatario_tipo','cliente')->value('estado'))->toBe('nao_solicitado');
 $this->artisan('virtus:enviar-resumos')->assertSuccessful();
 expect(DB::table('emails_encerramento')->where('destinatario_tipo','cliente')->value('estado'))->toBe('nao_solicitado');
 expect(DB::table('emails_encerramento')->where('destinatario_tipo','administrador')->value('estado'))->toBe('enviado');
});
it('nota do cliente fica interna e não entra no snapshot ou email',function(){
 $this->dados['avaliacao_cliente']=4;
 $id=app(EncerrarComResumo::class)->registrar($this->conversation,$this->dados,$this->user);
 $fim=DB::table('encerramentos_atendimento')->find($id);
 expect($fim->avaliacao_cliente)->toBe(4)->and($fim->dados)->not->toContain('avaliacao_cliente');
 $html=view('emails.resumo-atendimento',['fim'=>$fim,'dados'=>json_decode($fim->dados,true)])->render();
 expect($html)->not->toContain('Avaliação interna')->not->toContain('avaliacao_cliente');
});
it('recusa nota fora da escala sem encerrar conversa',function(){
 $this->dados['avaliacao_cliente']=6;
 expect(fn()=>app(EncerrarComResumo::class)->registrar($this->conversation,$this->dados,$this->user))->toThrow(\Illuminate\Validation\ValidationException::class);
 expect(DB::table('encerramentos_atendimento')->count())->toBe(0);
});
it('pesquisa opcional não é enviada quando desmarcada',function(){
 \Illuminate\Support\Facades\Queue::fake();
 $this->tenant->update(['pesquisa_ativa'=>true,'pesquisa_obrigatoria'=>false]);
 Livewire::actingAs($this->user)->test(\App\Livewire\Inbox\ConversationWindow::class,['conversationId'=>$this->conversation->id])
 ->set('assuntoEncerramento','Teste')->set('resumoEncerramento','Resumo de teste')->set('enviarPesquisa',false)->call('encerrar')->assertHasNoErrors();
 expect($this->conversation->refresh()->pesquisa_enviada_em)->toBeNull();
});
it('pesquisa obrigatória ignora tentativa de desmarcar e não oferece checkbox',function(){
 \Illuminate\Support\Facades\Queue::fake();
 $this->tenant->update(['pesquisa_ativa'=>true,'pesquisa_obrigatoria'=>true]);
 Livewire::actingAs($this->user)->test(\App\Livewire\Inbox\ConversationWindow::class,['conversationId'=>$this->conversation->id])
 ->call('prepararResumo')->assertSee('Pesquisa de satisfação obrigatória')->assertDontSeeHtml('wire:model="enviarPesquisa"')
 ->set('assuntoEncerramento','Teste')->set('resumoEncerramento','Resumo de teste')->set('enviarPesquisa',false)->call('encerrar')->assertHasNoErrors();
 expect($this->conversation->refresh()->pesquisa_enviada_em)->not->toBeNull();
});
it('pesquisa opcional pode ser escolhida ao encerrar',function(){
 \Illuminate\Support\Facades\Queue::fake();
 $this->tenant->update(['pesquisa_ativa'=>false,'pesquisa_obrigatoria'=>false]);
 Livewire::actingAs($this->user)->test(\App\Livewire\Inbox\ConversationWindow::class,['conversationId'=>$this->conversation->id])
 ->set('assuntoEncerramento','Teste')->set('resumoEncerramento','Resumo de teste')->set('enviarPesquisa',true)->call('encerrar')->assertHasNoErrors();
 expect($this->conversation->refresh()->pesquisa_enviada_em)->not->toBeNull();
});
