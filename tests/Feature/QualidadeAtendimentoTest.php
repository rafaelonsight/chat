<?php
use App\Models\{Tenant,User,Channel,Contact,Conversation,Message};
use App\Services\{EncerrarComResumo,PesquisaDeSatisfacao};
use App\Support\TenantContext;
use App\Filament\Pages\QualidadeAtendimento;
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

it('separa notas baixas de ausência de resposta e acompanha evolução',function(){
 $t=Livewire::actingAs($this->u)->test(QualidadeAtendimento::class);$t->assertViewHas('linhas',fn($d)=>$d->total()===0)->set('avaliacao','sem')->call('aplicar')->assertViewHas('linhas',fn($d)=>$d->total()===1);
 DB::table('encerramentos_atendimento')->where('id',$this->id)->update(['satisfacao'=>2]);$t->set('avaliacao','baixas')->call('aplicar')->assertViewHas('linhas',fn($d)=>$d->total()===1)->assertViewHas('evolucao',fn($d)=>(int)$d[0]->respostas===1 && (float)$d[0]->media===2.0);
});
it('mantém histórico interno e considera apenas última situação',function(){
 $t=Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->call('revisar',$this->id)->set('comentario','Orientar a equipe')->set('estado','acompanhamento')->call('salvar')->assertSee('Orientar a equipe');
 $t->set('comentario','Revisão concluída')->set('estado','concluida')->call('salvar')->assertViewHas('historico',fn($h)=>$h->count()===2)->set('avaliacao','')->set('situacao','concluida')->call('aplicar')->assertViewHas('linhas',fn($d)=>$d->total()===1);
 expect(DB::table('encerramentos_atendimento')->where('id',$this->id)->value('resumo'))->toBe('Resumo');
});
it('auditor consulta sem editar e comentários exigem permissão',function(){
 Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->call('revisar',$this->id)->set('comentario','INTERNO PRIVADO')->call('salvar');
 $this->u->update(['perfil'=>'auditor','ver_notas_internas'=>false]);$this->actingAs($this->u->fresh());
 Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->call('revisar',$this->id)->assertStatus(200)->assertDontSee('INTERNO PRIVADO')->set('comentario','Não permitido')->assertStatus(200)->call('salvar')->assertForbidden();
 expect(DB::table('revisoes_qualidade')->count())->toBe(1);
});
it('recusa IDs sem autorização tanto ao abrir quanto ao salvar',function(){
 $t=Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->call('revisar',$this->id);
 $this->u->canais()->detach();$t->set('comentario','Negado')->call('salvar')->assertForbidden();
 Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->call('revisar',$this->id)->assertForbidden();expect(DB::table('revisoes_qualidade')->count())->toBe(0);
});
it('isola empresas mesmo para superadmin e recusa atendente',function(){
 $outro=Tenant::create(['nome'=>'Outra','slug'=>'qualidade-outra']);$id=DB::table('encerramentos_atendimento')->insertGetId(['tenant_id'=>$outro->id,'conversation_id'=>$this->c->id,'versao'=>9,'protocolo'=>'SEGREDO','finalizado_por'=>$this->u->id,'assunto'=>'Teste','resumo'=>'Teste','dados'=>'{}','created_at'=>now(),'updated_at'=>now()]);
 $this->u->forceFill(['super_admin'=>true])->saveQuietly();Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->set('avaliacao','')->assertViewHas('linhas',fn($d)=>$d->total()===1)->call('revisar',$id)->assertForbidden();
 $this->actingAs($this->a);expect(QualidadeAtendimento::canAccess())->toBeFalse();$this->get('/admin/qualidade')->assertForbidden();
});
it('valida comentário e período',function(){
 Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->call('revisar',$this->id)->call('salvar')->assertHasErrors('comentario')->set('inicio','2026-10-03')->set('fim','2026-10-01')->call('aplicar')->assertHasErrors('fim');
});
it('filtros de canal atendente e etiqueta usam mesmo recorte',function(){
 Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->set('avaliacao','')->set('atendente',(string)$this->b->id)->call('aplicar')->assertViewHas('linhas',fn($d)=>$d->total()===0)->assertViewHas('evolucao',fn($d)=>$d->isEmpty());
 Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->set('avaliacao','')->set('etiqueta','999999')->call('aplicar')->assertViewHas('totais',fn($d)=>(int)$d->total===0);
});

it('abre página autenticada e supervisor registra respeitando equipe',function(){
 $this->get('/admin/qualidade')->assertOk()->assertSee('Gestão da qualidade');
 $this->u->update(['perfil'=>'supervisor']);$this->actingAs($this->u->fresh());
 Livewire::actingAs($this->u->fresh())->test(QualidadeAtendimento::class)->call('revisar',$this->id)->set('comentario','Orientação do supervisor')->call('salvar')->assertSee('Orientação do supervisor');
 $outra=\App\Models\Team::create(['nome'=>'Outra equipe']);$this->u->teams()->attach($outra->id);
 Livewire::actingAs($this->u->fresh())->test(QualidadeAtendimento::class)->set('avaliacao','')->assertViewHas('linhas',fn($d)=>$d->total()===0)->call('revisar',$this->id)->assertForbidden();
});
it('etiqueta filtra satisfação e evolução sem alterar classificação',function(){
 $tag=\App\Models\Tag::create(['nome'=>'Suporte','cor'=>'azul','contexto'=>'conversa']);$this->c->tags()->attach($tag->id,['origem'=>'manual']);DB::table('encerramentos_atendimento')->where('id',$this->id)->update(['satisfacao'=>1]);
 Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->set('etiqueta',(string)$tag->id)->call('aplicar')->assertViewHas('linhas',fn($d)=>$d->total()===1)->assertViewHas('evolucao',fn($d)=>(int)$d[0]->respostas===1);
});
