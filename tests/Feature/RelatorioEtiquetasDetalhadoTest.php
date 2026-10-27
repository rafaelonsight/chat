<?php
use App\Models\{Tenant,User,Channel,Contact,Conversation,Tag};
use App\Services\EncerrarComResumo;
use App\Support\TenantContext;
use App\Filament\Pages\RelatorioEtiquetas;
use Illuminate\Support\Facades\{DB,Queue};
use Livewire\Livewire;
beforeEach(function(){
 Queue::fake();$this->tenant=Tenant::create(['nome'=>'Empresa','slug'=>'etiqueta-detalhe']);TenantContext::set($this->tenant->id);
 $this->u=User::factory()->create(['tenant_id'=>$this->tenant->id,'perfil'=>'admin']);$this->u->forceFill(['super_admin'=>false])->saveQuietly();
 $this->canal=Channel::create(['nome'=>'WhatsApp']);$this->u->canais()->attach($this->canal);
 $this->ct=Contact::create(['nome'=>'Cliente','jid'=>'5511999999999@s.whatsapp.net']);
 $this->c=Conversation::create(['contact_id'=>$this->ct->id,'channel_id'=>$this->canal->id]);
 $this->t=Tag::create(['nome'=>'Suporte','cor'=>'azul','contexto'=>'conversa']);$this->t2=Tag::create(['nome'=>'Financeiro','cor'=>'verde','contexto'=>'conversa']);$this->c->tags()->attach([$this->t->id,$this->t2->id],['origem'=>'manual']);
 $this->actingAs($this->u);\Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
});
afterEach(fn()=>TenantContext::forget());
it('mantém total único apesar de etiquetas sobrepostas',function(){
 Livewire::actingAs($this->u)->test(RelatorioEtiquetas::class)->assertViewHas('volume',1)->assertViewHas('linhas',fn($l)=>$l->count()===2 && $l->sum('volume')===2)->assertViewHas('recebidas',0)->assertViewHas('media',null);
});
it('separa etiquetas de contato e limpa seleção ao trocar contexto',function(){
 $tag=Tag::create(['nome'=>'VIP','cor'=>'roxo','contexto'=>'contato']);$this->ct->tags()->attach($tag->id);
 Livewire::actingAs($this->u)->test(RelatorioEtiquetas::class)->call('selecionarEtiqueta',$this->t->id)->set('contexto','contato')->assertSet('etiqueta','')->assertViewHas('linhas',fn($l)=>$l->count()===1 && $l[0]->nome==='VIP');
});
it('usa último encerramento sem duplicar conversa reaberta',function(){
 $id=app(EncerrarComResumo::class)->registrar($this->c,['assunto'=>'Teste','resumo'=>'Resumo'],$this->u);DB::table('encerramentos_atendimento')->where('id',$id)->update(['satisfacao'=>5,'atendimento_segundos'=>100]);
 $this->c->refresh()->reabrir();$id2=app(EncerrarComResumo::class)->registrar($this->c->refresh(),['assunto'=>'Novo','resumo'=>'Novo'],$this->u);DB::table('encerramentos_atendimento')->where('id',$id2)->update(['satisfacao'=>2,'atendimento_segundos'=>200]);
 Livewire::actingAs($this->u)->test(RelatorioEtiquetas::class)->assertViewHas('volume',1)->assertViewHas('finalizados',1)->assertViewHas('recebidas',1)->assertViewHas('media',2.0)->assertViewHas('linhas',fn($l)=>(float)$l[0]->duracao===200.0);
});
it('inclui sem etiqueta nos totais e filtros recortam protocolos',function(){
 $this->c->tags()->detach($this->t2);$ct2=Contact::create(['nome'=>'Sem etiqueta','jid'=>'5511888888888@s.whatsapp.net']);$c=Conversation::create(['contact_id'=>$ct2->id,'channel_id'=>$this->canal->id]);
 Livewire::actingAs($this->u)->test(RelatorioEtiquetas::class)->assertViewHas('volume',2)->call('selecionarEtiqueta',$this->t->id)->assertViewHas('volume',1)->assertViewHas('detalhes',fn($d)=>$d->total()===1)->set('canal','999999')->call('aplicar')->assertViewHas('volume',0);
});
it('respeita restrição de canal e perfil',function(){
 $this->u->update(['perfil'=>'supervisor']);$this->u->canais()->detach();Livewire::actingAs($this->u)->test(RelatorioEtiquetas::class)->assertViewHas('volume',0);
 $this->u->update(['perfil'=>'atendente']);expect(RelatorioEtiquetas::canAccess())->toBeFalse();
});
it('recusa intervalo invertido',function(){
 Livewire::actingAs($this->u)->test(RelatorioEtiquetas::class)->set('inicio','2026-10-03')->set('fim','2026-10-01')->call('aplicar')->assertHasErrors(['fim']);
});

it('não inclui encerramento de outra empresa nem etiqueta de outro contexto',function(){
 $outro=Tenant::create(['nome'=>'Outra','slug'=>'outra-etiqueta']);DB::table('encerramentos_atendimento')->insert(['tenant_id'=>$outro->id,'conversation_id'=>$this->c->id,'versao'=>1,'protocolo'=>'SEGREDO','finalizado_por'=>$this->u->id,'assunto'=>'Teste','resumo'=>'Teste','dados'=>'{}','satisfacao'=>5,'created_at'=>now(),'updated_at'=>now()]);
 $tag=Tag::create(['nome'=>'Contato','cor'=>'verde','contexto'=>'contato']);$this->ct->tags()->attach($tag);
 Livewire::actingAs($this->u)->test(RelatorioEtiquetas::class)->assertViewHas('recebidas',0)->call('selecionarEtiqueta',$tag->id)->assertViewHas('volume',0);
});
