<?php
use App\Models\{Tenant,User,Channel,Contact,Conversation,Message};
use App\Services\{EncerrarComResumo,PesquisaDeSatisfacao};
use App\Support\TenantContext;
use App\Filament\Pages\RelatorioAtendentes;
use Illuminate\Support\Facades\{DB,Queue};
use Livewire\Livewire;
beforeEach(function(){
 Queue::fake();$this->tenant=Tenant::create(['nome'=>'Empresa','slug'=>'relat-atend','pesquisa_ativa'=>true]);TenantContext::set($this->tenant->id);
 $this->u=User::factory()->create(['tenant_id'=>$this->tenant->id,'name'=>'Gestor','perfil'=>'admin']);$this->u->forceFill(['super_admin'=>false])->saveQuietly();
 $this->a=User::factory()->create(['tenant_id'=>$this->tenant->id,'name'=>'Ana','perfil'=>'atendente']);
 $this->b=User::factory()->create(['tenant_id'=>$this->tenant->id,'name'=>'Bruno','perfil'=>'atendente']);
 $this->canal=Channel::create(['nome'=>'WhatsApp','tipo'=>'evolution','status'=>'open']);$this->u->canais()->attach($this->canal);
 $this->ct=Contact::create(['nome'=>'Cliente','jid'=>'5511999999999@s.whatsapp.net']);
 $this->c=Conversation::create(['contact_id'=>$this->ct->id,'channel_id'=>$this->canal->id,'atendente_id'=>$this->a->id,'ultima_entrada_em'=>now()]);
 $this->a->forceFill(['super_admin'=>false,'perfil_preenchido_em'=>now()])->saveQuietly();
 $this->u->forceFill(['perfil_preenchido_em'=>now()])->saveQuietly();
 $this->actingAs($this->u);\Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 $this->id=app(EncerrarComResumo::class)->registrar($this->c,['assunto'=>'Teste','resumo'=>'Resumo','avaliacao_cliente'=>1],$this->u);
});
afterEach(fn()=>TenantContext::forget());
it('atribui ao responsável no encerramento e não a quem apertou finalizar',function(){
 expect(DB::table('encerramentos_atendimento')->where('id',$this->id)->value('responsavel_id'))->toBe($this->a->id);
 $this->c->forceFill(['atendente_id'=>$this->b->id])->save();
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->assertViewHas('linhas',fn($l)=>$l->count()===1 && $l[0]->nome==='Ana' && $l[0]->responsavel_id===$this->a->id);
});
it('não usa nota interna como satisfação ou resposta',function(){
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->assertViewHas('recebidas',0)->assertViewHas('media',null)->assertViewHas('enviadas',0)->assertViewHas('finalizados',1);
});
it('pesquisa na fila não conta como enviada e resposta fica no encerramento',function(){
 app(PesquisaDeSatisfacao::class)->perguntar($this->c->refresh(),true);
 $pid=DB::table('encerramentos_atendimento')->where('id',$this->id)->value('pesquisa_message_id');expect($pid)->not->toBeNull();
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->assertViewHas('enviadas',0);
 Message::whereKey($pid)->update(['status'=>'sent']);
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->assertViewHas('enviadas',1)->assertViewHas('recebidas',0);
 $nova=Conversation::create(['contact_id'=>$this->ct->id,'channel_id'=>$this->canal->id]);
 $m=Message::create(['conversation_id'=>$nova->id,'channel_id'=>$this->canal->id,'direcao'=>'in','tipo'=>'text','corpo'=>'5','status'=>'delivered']);
 expect(app(PesquisaDeSatisfacao::class)->talvezRegistrar($m))->toBeTrue();
 expect(DB::table('encerramentos_atendimento')->where('id',$this->id)->value('satisfacao'))->toBe(5);
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->assertViewHas('recebidas',1)->assertViewHas('media',5.0)->assertViewHas('linhas',fn($l)=>(int)$l[0]->nota5===1)->assertSee('100,0%');
});
it('filtros por atendente e canal usam o mesmo recorte',function(){
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->call('selecionarAtendente',$this->b->id)->assertViewHas('finalizados',0)->assertViewHas('detalhes',fn($d)=>$d->total()===0);
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->set('canal','999999')->call('aplicar')->assertViewHas('finalizados',0);
});
it('não deixa dado de outra empresa entrar no relatório',function(){
 $outro=Tenant::create(['nome'=>'Outra','slug'=>'outra-relat']);
 DB::table('encerramentos_atendimento')->insert(['tenant_id'=>$outro->id,'conversation_id'=>$this->c->id,'versao'=>2,'protocolo'=>'SEGREDO-OUTRA','finalizado_por'=>$this->u->id,'responsavel_id'=>$this->u->id,'responsavel_nome'=>'SEGREDO OUTRA','assunto'=>'Teste','resumo'=>'Teste','dados'=>'{}','created_at'=>now(),'updated_at'=>now()]);
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->assertViewHas('finalizados',1)->assertDontSee('SEGREDO-OUTRA');
});
it('respeita restrição de canal de supervisor e impede atendente',function(){
 $this->u->update(['perfil'=>'supervisor']);$this->u->canais()->detach();
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->assertViewHas('finalizados',0);
 $this->actingAs($this->a);expect(RelatorioAtendentes::canAccess())->toBeFalse();
 $this->get('/admin/relatorio-atendentes')->assertForbidden();
});
it('validacao recusa intervalo invertido',function(){
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->set('inicio','2026-10-03')->set('fim','2026-10-01')->call('aplicar')->assertHasErrors(['fim']);
});

it('segunda pesquisa após reabrir preserva primeira avaliação e atribui novo responsável',function(){
 $ps=app(PesquisaDeSatisfacao::class);$ps->perguntar($this->c->refresh(),true);
 $m=Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'in','tipo'=>'text','corpo'=>'5','status'=>'delivered']);$ps->talvezRegistrar($m);
 $this->c->refresh()->reabrir();$this->c->forceFill(['atendente_id'=>$this->b->id])->save();
 $id2=app(EncerrarComResumo::class)->registrar($this->c->refresh(),['assunto'=>'Novo','resumo'=>'Novo atendimento'],$this->u);
 $ps->perguntar($this->c->refresh(),true);
 $m2=Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'in','tipo'=>'text','corpo'=>'2','status'=>'delivered']);$ps->talvezRegistrar($m2);
 expect(DB::table('encerramentos_atendimento')->where('id',$this->id)->value('satisfacao'))->toBe(5);
 expect(DB::table('encerramentos_atendimento')->where('id',$id2)->value('satisfacao'))->toBe(2);
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->assertViewHas('recebidas',2)->assertViewHas('media',3.5)->assertViewHas('linhas',fn($l)=>$l->count()===2);
});
it('tempos usam apenas resposta enviada pelo atendente e não automações',function(){
 $this->c->refresh()->reabrir();
 Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Dúvida','status'=>'delivered']);
 Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'out','tipo'=>'text','corpo'=>'Bot','automatica'=>true,'status'=>'sent']);
 $human=Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'user_id'=>$this->a->id,'direcao'=>'out','tipo'=>'text','corpo'=>'Resposta humana','status'=>'sent','enviada_em'=>now()]);
 $id2=app(EncerrarComResumo::class)->registrar($this->c->refresh(),['assunto'=>'Novo','resumo'=>'Novo'],$this->u);
 expect(DB::table('encerramentos_atendimento')->where('id',$id2)->value('espera_segundos'))->not->toBeNull();
 expect(DB::table('encerramentos_atendimento')->where('id',$id2)->value('atendimento_segundos'))->not->toBeNull();
});

it('não conta histórico sem confirmação como envio',function(){
 DB::table('encerramentos_atendimento')->where('id',$this->id)->update(['pesquisa_enviada_em'=>now(),'pesquisa_message_id'=>null]);
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->assertViewHas('enviadas',0)->assertSee('Envio histórico sem confirmação');
});

it('separa autoria humana de automação mesmo após transferência',function(){
 foreach([[$this->a->id,false,'audio'],[$this->a->id,true,'text'],[$this->b->id,false,'image']] as [$autor,$auto,$tipo])Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'user_id'=>$autor,'direcao'=>'out','tipo'=>$tipo,'corpo'=>'Teste autoria','automatica'=>$auto,'por_fora'=>false,'status'=>'sent']);
 $this->c->forceFill(['atendente_id'=>$this->b->id])->save();
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->assertViewHas('mensagensPorTipo',fn($l)=>$l->where('origem','Automação')->count()===1 && $l->where('origem','Ana')->where('tipo','audio')->count()===1)->set('atendente',(string)$this->a->id)->call('aplicar')->assertViewHas('mensagensPorTipo',fn($l)=>$l->count()===1 && $l[0]->tipo==='audio' && $l[0]->origem==='Ana');
});

it('reúne infraestrutura e Meta sem duplicar navegação',function(){
 Livewire::actingAs($this->u)->test(\App\Filament\Pages\ConsumoConversas::class)->assertSee('Infraestrutura')->set('secao','meta')->assertSee('Consultar consumo')->set('aba','precos')->assertSee('Simular custo');
});

it('compara período anterior de mesma duração e inclui quem só atendeu antes',function(){
 $linha=(array)DB::table('encerramentos_atendimento')->where('id',$this->id)->first();unset($linha['id']);$linha['versao']=9;$linha['responsavel_id']=$this->b->id;$linha['responsavel_nome']='Bruno';$linha['created_at']=now()->subDay();DB::table('encerramentos_atendimento')->insert($linha);
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->set('inicio',now()->toDateString())->set('fim',now()->toDateString())->call('aplicar')->assertViewHas('comparativos',fn($c)=>$c->count()===2 && $c->get($this->b->id)['atual']===null && (int)$c->get($this->b->id)['anterior']->finalizados===1)->call('exportarCsv')->assertFileDownloaded('virtus-atendentes-'.now()->toDateString().'-'.now()->toDateString().'.csv');
});
it('tipo filtra mensagens e não altera satisfação',function(){
 Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'user_id'=>$this->a->id,'direcao'=>'out','tipo'=>'audio','corpo'=>'Audio','status'=>'sent']);
 Livewire::actingAs($this->u)->test(RelatorioAtendentes::class)->set('tipoMensagem','image')->assertViewHas('mensagensPorTipo',fn($l)=>$l->isEmpty())->assertViewHas('finalizados',1)->set('tipoMensagem','audio')->assertViewHas('mensagensPorTipo',fn($l)=>$l->count()===1);
});

it('painel mostra encerramentos históricos e fila atual separadamente',function(){
 $nova=Conversation::create(['contact_id'=>$this->ct->id,'channel_id'=>$this->canal->id,'status'=>Conversation::NOVA]);
 Livewire::actingAs($this->u)->test(\App\Filament\Pages\DesempenhoOperacao::class)->assertViewHas('evolucao',fn($l)=>(int)$l->sum('finalizados')===1)->assertViewHas('porCanalEquipe',fn($l)=>(int)$l->sum('finalizados')===1)->assertViewHas('pendentes',fn($l)=>$l->contains('id',$nova->id));
});
it('painel impede atendente e respeita canais autorizados da fila',function(){
 $this->u->forceFill(['perfil'=>'supervisor'])->save();$this->u->canais()->detach();
 Livewire::actingAs($this->u)->test(\App\Filament\Pages\DesempenhoOperacao::class)->assertViewHas('evolucao',fn($l)=>$l->isEmpty())->assertViewHas('aguardando',0);
 $this->actingAs($this->a)->get('/admin/desempenho-operacao')->assertForbidden();
});

it('evolução diária usa a data local da conta inglesa',function(){
 $this->tenant->forceFill(['fuso_horario'=>'Europe/London'])->save();DB::table('encerramentos_atendimento')->where('id',$this->id)->update(['created_at'=>'2026-10-01 23:30:00']);
 Livewire::actingAs($this->u)->test(\App\Filament\Pages\DesempenhoOperacao::class)->set('inicio','2026-10-02')->set('fim','2026-10-02')->call('aplicar')->assertViewHas('evolucao',fn($l)=>$l->count()===1 && $l[0]->dia==='2026-10-02');
});

it('alerta preserva primeira mensagem sem resposta e ignora automações',function(){
 $this->c->forceFill(['status'=>Conversation::EM_ATENDIMENTO,'atendente_id'=>$this->u->id])->save();
 Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Primeira','created_at'=>now()->subMinutes(6)])->forceFill(['created_at'=>now()->subMinutes(6)])->saveQuietly();
 Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Segunda','created_at'=>now()->subMinute()])->forceFill(['created_at'=>now()->subMinute()])->saveQuietly();
 Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'out','tipo'=>'text','corpo'=>'Bot','automatica'=>true,'status'=>'sent']);
 $alertas=app(\App\Services\AlertasResposta::class);expect($alertas->pendentes())->toHaveCount(1);expect($alertas->pendentes()[0]['cliente'])->toBe('Cliente');Livewire::actingAs($this->u)->test(\App\Livewire\Inbox\ConversationList::class)->set('balde','meus')->set('somenteSemResposta',true)->set('ordem','atrasadas')->assertViewHas('total',1)->assertSee('Aguardando há 6 min');$this->ct->update(['nome'=>null,'telefone_e164'=>'+5511999999999']);expect($alertas->pendentes()[0]['cliente'])->toBe('+5511999999999');
 Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'out','tipo'=>'text','corpo'=>'Humano','user_id'=>$this->u->id,'status'=>'sent','enviada_em'=>now()]);expect($alertas->pendentes())->toBe([]);Livewire::actingAs($this->u)->test(\App\Livewire\Inbox\ConversationList::class)->set('balde','meus')->set('somenteSemResposta',true)->assertViewHas('total',0);
});
it('alerta não chega a outro atendente e não dispara antes de cinco minutos',function(){
 $this->c->forceFill(['status'=>Conversation::EM_ATENDIMENTO,'atendente_id'=>$this->a->id])->save();
 Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Mensagem','created_at'=>now()->subMinutes(10)]);
 expect(app(\App\Services\AlertasResposta::class)->pendentes())->toBe([]);
 $this->c->forceFill(['atendente_id'=>$this->u->id])->save();Message::where('conversation_id',$this->c->id)->update(['created_at'=>now()->subSeconds(290)]);expect(app(\App\Services\AlertasResposta::class)->pendentes())->toBe([]);
});
it('atendente não muda regras de alerta da empresa',function(){
 Livewire::actingAs($this->a)->test(\App\Filament\Pages\AlertasResposta::class)->call('salvar')->assertForbidden();
});

it('link direto abre conversa atribuída e seleciona meus',function(){
 $this->c->forceFill(['status'=>Conversation::EM_ATENDIMENTO,'atendente_id'=>$this->u->id])->save();
 Livewire::withQueryParams(['conversa'=>$this->c->id])->actingAs($this->u)->test(\App\Filament\Pages\Atendimento::class)->assertSet('conversaInicial',$this->c->id)->assertSet('baldeInicial','meus')->assertSeeLivewire(\App\Livewire\Inbox\ConversationWindow::class);
});
it('link direto não abre conversa de outra empresa',function(){
 $outra=Tenant::create(['nome'=>'Outra','slug'=>'outra-link']);$this->c->forceFill(['tenant_id'=>$outra->id])->saveQuietly();
 Livewire::withQueryParams(['conversa'=>$this->c->id])->actingAs($this->u)->test(\App\Filament\Pages\Atendimento::class)->assertSet('conversaInicial',null);
});

it('consumo IA registra usage real e relatório isola conta',function(){
 \App\Services\GatewayVirtus::salvar(['ativa'=>true,'provedor'=>'gateway','modelo'=>'teste','chave'=>'fake']);
 \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['choices'=>[['message'=>['content'=>'{"texto":"Hello"}']]],'usage'=>['prompt_tokens'=>120,'completion_tokens'=>30,'total_tokens'=>150]])]);
 app(\App\Services\ResumoIA::class)->responder($this->tenant,'Teste','Teste',2200,55,null,'sugestao',$this->u->id);
 expect(DB::table('consumos_ia')->where('tenant_id',$this->tenant->id)->first()->total)->toBe(150);
 $outro=Tenant::create(['nome'=>'Outra','slug'=>'outra-ia']);DB::table('consumos_ia')->insert(['tenant_id'=>$outro->id,'recurso'=>'segredo','provedor'=>'gateway','modelo'=>'teste','total'=>999,'created_at'=>now()]);
 Livewire::actingAs($this->u)->test(\App\Filament\Pages\ConsumoIA::class)->assertViewHas('totais',fn($t)=>(int)$t->total===150)->assertViewHas('usadosMes',150)->assertViewHas('disponiveis',999850)->assertDontSee('segredo')->call('exportar')->assertFileDownloaded('consumo-ia.csv');
});
it('consumo IA sem usage é desconhecido e não zero',function(){
 \App\Services\GatewayVirtus::salvar(['ativa'=>true,'provedor'=>'anthropic','modelo'=>'teste','chave'=>'fake']);
 \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['content'=>[['text'=>'OK']]])]);
 app(\App\Services\ResumoIA::class)->responder($this->tenant,'Teste','Teste');
 expect(DB::table('consumos_ia')->where('tenant_id',$this->tenant->id)->first()->estimado)->toBeTrue();
 Livewire::actingAs($this->u)->test(\App\Filament\Pages\ConsumoIA::class)->assertViewHas('totais',fn($t)=>(int)$t->desconhecidas===1);
});

it('cliente não acessa nem altera gateway central',function(){
 expect(\App\Filament\Pages\InteligenciaArtificial::canAccess())->toBeFalse();
 Livewire::actingAs($this->u)->test(\App\Filament\Pages\InteligenciaArtificial::class)->assertForbidden();
});
it('gateway central ignora chave do cliente e contabiliza teste',function(){
 \App\Services\GatewayVirtus::salvar(['ativa'=>true,'provedor'=>'gateway','modelo'=>'central','chave'=>'central-fake']);
 $this->tenant->forceFill(['ia_config'=>['ativa'=>false,'provedor'=>'openai','modelo'=>'cliente','chave'=>'cliente-fake']])->save();
 \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['choices'=>[['message'=>['content'=>'{"assunto":"Teste","resumo":"Resumo","solucao":"","pendencias":""}']]],'usage'=>['prompt_tokens'=>10,'completion_tokens'=>20,'total_tokens'=>30]])]);
 app(\App\Services\ResumoIA::class)->testar($this->tenant);
 \Illuminate\Support\Facades\Http::assertSent(fn($r)=>$r->hasHeader('Authorization','Bearer central-fake')&&$r['model']==='central');
 expect(DB::table('consumos_ia')->where('tenant_id',$this->tenant->id)->first()->recurso)->toBe('teste_configuracao');
 expect(\App\Models\SystemSetting::ler('ia_gateway_global'))->not->toContain('central-fake');
});

it('compra tokens tem preço fixo sem duplicar solicitação nem liberar saldo',function(){
 Livewire::actingAs($this->u)->test(\App\Filament\Pages\ConsumoIA::class)->call('solicitarCompra',500000)->call('solicitarCompra',500000)->assertViewHas('disponiveis',1000000);
 expect(DB::table('compras_tokens_ia')->where('tenant_id',$this->tenant->id)->count())->toBe(1);
 expect(DB::table('compras_tokens_ia')->where('tenant_id',$this->tenant->id)->first()->valor_centavos)->toBe(1990);
});

it('saldo IA bloqueia sem chamar gateway e usa extras após franquia',function(){
 $this->tenant->forceFill(['franquia_ia'=>0,'extras_ia'=>0])->save();$chamou=false;
 expect(fn()=>\App\Services\SaldoIA::executar($this->tenant,100,function()use(&$chamou){$chamou=true;return 'x';}))->toThrow(\RuntimeException::class);expect($chamou)->toBeFalse();
 $this->tenant->forceFill(['franquia_ia'=>100,'extras_ia'=>200])->save();
 \App\Services\SaldoIA::executar($this->tenant,250,function(){DB::table('consumos_ia')->insert(['tenant_id'=>$this->tenant->id,'recurso'=>'teste','provedor'=>'gateway','modelo'=>'teste','total'=>150,'created_at'=>now()]);return 'OK';});
 expect((int)$this->tenant->fresh()->extras_ia)->toBe(150);
 expect(\App\Services\SaldoIA::estado($this->tenant->fresh())['disponiveis'])->toBe(150);
});
it('cliente não concede créditos IA',function(){Livewire::actingAs($this->u)->test(\App\Filament\Pages\CreditosIA::class)->assertForbidden();});

it('superadmin concede saldo e registra motivo',function(){
 $this->u->forceFill(['super_admin'=>true])->save();Livewire::actingAs($this->u)->test(\App\Filament\Pages\CreditosIA::class)->set('conta',(string)$this->tenant->id)->set('franquia',2000000)->set('adicionar',500000)->set('motivo','Crédito de teste')->call('salvar')->assertHasNoErrors();expect((int)$this->tenant->fresh()->extras_ia)->toBe(500000);expect(DB::table('creditos_ia')->where('tenant_id',$this->tenant->id)->count())->toBe(1);
});

it('transcrição cobra duração uma vez e notifica limites',function(){
 $this->tenant->forceFill(['franquia_ia'=>1000])->save();
 $m=Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'in','tipo'=>'audio','media_path'=>'teste.ogg']);
 $service=\Mockery::mock(\App\Services\TranscriptionService::class);$service->shouldReceive('duracao')->once()->andReturn(60.0);$service->shouldReceive('transcrever')->once()->andReturn(['texto'=>'Olá','status'=>'pronta']);
 $job=new \App\Jobs\TranscribeAudio($m->id);$job->handle($service);$job->handle($service);
 expect(DB::table('consumos_ia')->where('message_id',$m->id)->count())->toBe(1);expect(DB::table('consumos_ia')->where('message_id',$m->id)->first()->total)->toBe(1000);
 expect(DB::table('notifications')->where('notifiable_id',$this->u->id)->count())->toBeGreaterThanOrEqual(3);
});
