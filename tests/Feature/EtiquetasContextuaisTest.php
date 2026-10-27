<?php
use App\Models\{Tenant,User,Channel,Contact,Conversation,Tag,ChatbotAction};
use App\Services\Etiquetador;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
beforeEach(function(){
 $this->tenant=Tenant::create(['nome'=>'Empresa','slug'=>'etq-local']);TenantContext::set($this->tenant->id);
 $this->user=User::factory()->create(['tenant_id'=>$this->tenant->id,'perfil'=>'admin']);$this->actingAs($this->user);
 $this->channel=Channel::create(['nome'=>'Canal']);$this->contact=Contact::create(['nome'=>'Cliente','jid'=>'5511999999999@s.whatsapp.net']);$this->conversation=Conversation::create(['channel_id'=>$this->channel->id,'contact_id'=>$this->contact->id]);
});
afterEach(fn()=>TenantContext::forget());
it('admin cria e aplica etiqueta de atendimento no local sem escolher finalidade',function(){
 Livewire::actingAs($this->user)->test(\App\Livewire\Inbox\ConversationWindow::class,['conversationId'=>$this->conversation->id])->call('abrirCriacaoEtiqueta')->set('nomeNovaEtiqueta','Resolvido')->set('corNovaEtiqueta','verde')->call('criarEtiquetaNoLocal')->assertHasNoErrors();
 $tag=Tag::where('nome','Resolvido')->firstOrFail();expect($tag->contexto)->toBe('conversa')->and($this->conversation->tags()->whereKey($tag->id)->exists())->toBeTrue()->and($this->contact->tags()->count())->toBe(0);
});
it('permite o mesmo nome em finalidades distintas',function(){
 Tag::create(['nome'=>'Suporte','contexto'=>'contato','cor'=>'azul']);Tag::create(['nome'=>'Suporte','contexto'=>'conversa','cor'=>'azul']);expect(Tag::where('nome','Suporte')->count())->toBe(2);
});
it('atendente não cria etiquetas mesmo chamando a ação diretamente',function(){
 $this->user->update(['perfil'=>'atendente']);Livewire::actingAs($this->user)->test(\App\Livewire\Inbox\ConversationWindow::class,['conversationId'=>$this->conversation->id])->call('criarEtiquetaNoLocal')->assertForbidden();expect(Tag::count())->toBe(0);
});
it('aplicar e remover pelo chatbot preserva origem e não altera etiquetas de outra finalidade',function(){
 $tag=Tag::create(['nome'=>'Suporte','contexto'=>'conversa','cor'=>'azul']);$s=app(Etiquetador::class);
 $s->aplicar($this->contact,[$tag->id],Etiquetador::CHATBOT);expect($this->contact->tags()->count())->toBe(0);
 $s->aplicar($this->conversation,[$tag->id],Etiquetador::CHATBOT);$s->remover($this->conversation,[$tag->id],Etiquetador::CHATBOT);
 expect(DB::table('etiqueta_movimentos')->where('origem','chatbot')->count())->toBe(2)->and($this->conversation->tags()->count())->toBe(0);
});
it('ignora etiquetas de outra empresa inclusive sem escopo de contexto configurado',function(){
 $t=Tenant::create(['nome'=>'Outra','slug'=>'etq-outra']);$tag=Tag::withoutGlobalScopes()->create(['tenant_id'=>$t->id,'nome'=>'Outra','contexto'=>'conversa','cor'=>'azul']);TenantContext::forget();
 $s=app(Etiquetador::class);$s->aplicar($this->conversation,[$tag->id]);expect($this->conversation->tags()->count())->toBe(0);
});
it('gestão central mostra abas e não pede finalidade no formulário',function(){
 \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 Livewire::actingAs($this->user)->test(\App\Filament\Resources\Tags\Pages\ListTags::class)->assertSee('Contatos')->assertSee('Atendimentos')->assertSee('Tarefas')->mountAction('create')->assertHasNoActionErrors()->assertDontSee('Esta etiqueta descreve');
});
it('condição verifica etiqueta de atendimento e respeita ausência',function(){
 $tag=Tag::create(['nome'=>'Resolvido','contexto'=>'conversa','cor'=>'verde']);app(Etiquetador::class)->aplicar($this->conversation,[$tag->id]);
 $acao=new ChatbotAction(['tipo'=>'condicional','config'=>['fonte'=>'etiqueta','etiqueta_id'=>$tag->id,'operador'=>'presente']]);
 $method=new ReflectionMethod(\App\Services\ChatbotMotor::class,'avaliar');$motor=app(\App\Services\ChatbotMotor::class);
 expect($method->invoke($motor,$this->conversation,$acao))->toBeTrue();$acao->config=['fonte'=>'etiqueta','etiqueta_id'=>$tag->id,'operador'=>'ausente'];expect($method->invoke($motor,$this->conversation,$acao))->toBeFalse();
});
it('mudar etiquetas do contato não remove a classificação de atendimento encerrado',function(){
 $a=Tag::create(['nome'=>'VIP','contexto'=>'contato','cor'=>'azul']);$b=Tag::create(['nome'=>'Resolvido','contexto'=>'conversa','cor'=>'verde']);$s=app(Etiquetador::class);$s->aplicar($this->contact,[$a->id]);$s->aplicar($this->conversation,[$b->id]);$this->conversation->arquivar();$s->remover($this->contact,[$a->id]);expect($this->conversation->tags()->whereKey($b->id)->exists())->toBeTrue();
});

it('aba Atendimentos cria a etiqueta com a finalidade definida pela aba',function(){
 \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 Livewire::actingAs($this->user)->test(\App\Filament\Resources\Tags\Pages\ListTags::class)->set('activeTab','conversa')->mountAction('create')->fillForm(['nome'=>'Atendimento novo','cor'=>'verde'])->callMountedAction()->assertHasNoFormErrors();
 expect(Tag::where('nome','Atendimento novo')->firstOrFail()->contexto)->toBe('conversa');
});
it('admin cria etiqueta do contato no local e registra quem aplicou',function(){
 Livewire::actingAs($this->user)->test(\App\Livewire\Inbox\ContactDetails::class)->set('conversationId',$this->conversation->id)->set('nomeNovaEtiqueta','VIP')->set('corNovaEtiqueta','azul')->call('criarEtiquetaNoLocal')->assertHasNoErrors();
 $tag=Tag::where('nome','VIP')->firstOrFail();expect($tag->contexto)->toBe('contato')->and($this->contact->tags()->whereKey($tag->id)->exists())->toBeTrue()->and(DB::table('etiqueta_movimentos')->where('tag_id',$tag->id)->value('usuario_id'))->toBe($this->user->id);
});
it('relatório recorta atendimentos pelo cadastro atual sem misturar as finalidades',function(){
 $tag=Tag::create(['nome'=>'VIP','contexto'=>'contato','cor'=>'azul']);app(Etiquetador::class)->aplicar($this->contact,[$tag->id]);
 $page=new \App\Filament\Pages\Relatorios;$page->etiqueta=(string)$tag->id;$method=new ReflectionMethod($page,'conversas');
 expect($method->invoke($page)->pluck('id')->all())->toBe([$this->conversation->id]);
 app(Etiquetador::class)->remover($this->contact,[$tag->id]);expect($method->invoke($page)->count())->toBe(0);
});

it('recorte de equipe e atendente vale para conversas e mensagens do relatório',function(){
 $equipe=\App\Models\Team::create(['nome'=>'Suporte']);$this->conversation->update(['team_id'=>$equipe->id,'atendente_id'=>$this->user->id]);
 \App\Models\Message::create(['conversation_id'=>$this->conversation->id,'channel_id'=>$this->channel->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Olá']);
 $p=new \App\Filament\Pages\Relatorios;$p->equipe=(string)$equipe->id;$p->atendente=(string)$this->user->id;$c=new ReflectionMethod($p,'conversas');$m=new ReflectionMethod($p,'mensagens');expect($c->invoke($p)->count())->toBe(1)->and($m->invoke($p)->count())->toBe(1);
 $p->atendente='999999';expect($c->invoke($p)->count())->toBe(0)->and($m->invoke($p)->count())->toBe(0);
});
