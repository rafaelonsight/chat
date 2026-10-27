<?php
use App\Models\{Tenant,User,Channel,Contact,Conversation,Message};
use App\Services\{EncerrarComResumo,PesquisaDeSatisfacao};
use App\Support\TenantContext;
use App\Filament\Pages\QualidadeAtendimento;
use App\Services\AlertasQualidade;
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


it('nota baixa gera aviso no sino sem duplicar em reconciliação',function(){
 app(PesquisaDeSatisfacao::class)->perguntar($this->c->refresh(),true);
 $m=Message::create(['conversation_id'=>$this->c->id,'channel_id'=>$this->canal->id,'direcao'=>'in','tipo'=>'text','corpo'=>'2','status'=>'delivered']);expect(app(PesquisaDeSatisfacao::class)->talvezRegistrar($m))->toBeTrue();
 expect(DB::table('avisos_qualidade')->count())->toBe(1)->and($this->u->notifications()->count())->toBe(1);
 $this->artisan('virtus:alertar-qualidade')->assertSuccessful();$this->artisan('virtus:alertar-qualidade')->assertSuccessful();expect($this->u->notifications()->count())->toBe(1);
 $data=$this->u->notifications()->first()->data;expect($data['actions'][0]['url'])->toBe('/admin/qualidade?revisao='.$this->id)->and(json_encode($data))->not->toContain('Cliente');
});
it('não avisa notas positivas ou ausência de resposta e restringe destinatários',function(){
 $s=User::factory()->create(['tenant_id'=>$this->tenant->id,'perfil'=>'supervisor']);$s->forceFill(['super_admin'=>false])->saveQuietly();
 $aud=User::factory()->create(['tenant_id'=>$this->tenant->id,'perfil'=>'auditor']);$aud->canais()->attach($this->canal);
 $outra=Tenant::create(['nome'=>'Outra','slug'=>'alerta-outra']);$ext=User::factory()->create(['tenant_id'=>$outra->id,'perfil'=>'admin']);
 app(AlertasQualidade::class)->avisar($this->id);expect(DB::table('avisos_qualidade')->count())->toBe(0);
 DB::table('encerramentos_atendimento')->where('id',$this->id)->update(['satisfacao'=>5]);app(AlertasQualidade::class)->avisar($this->id);expect(DB::table('avisos_qualidade')->count())->toBe(0);
 DB::table('encerramentos_atendimento')->where('id',$this->id)->update(['satisfacao'=>1]);app(AlertasQualidade::class)->avisar($this->id);
 expect($s->notifications()->count())->toBe(0)->and($aud->notifications()->count())->toBe(0)->and($ext->notifications()->count())->toBe(0)->and($this->u->notifications()->count())->toBe(1);
 $s->canais()->attach($this->canal);app(AlertasQualidade::class)->avisar($this->id);expect($s->notifications()->count())->toBe(1)->and($this->u->notifications()->count())->toBe(1);
});
it('ler aviso não conclui revisão e concluir remove contador',function(){
 DB::table('encerramentos_atendimento')->where('id',$this->id)->update(['satisfacao'=>1]);app(AlertasQualidade::class)->avisar($this->id);$this->u->notifications()->update(['read_at'=>now()]);
 expect(app(AlertasQualidade::class)->pendentes($this->u,$this->tenant->id)->count())->toBe(1)->and(QualidadeAtendimento::getNavigationBadge())->toBe('1');
 Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->call('revisar',$this->id)->set('estado','concluida')->set('comentario','Concluído')->call('salvar')->assertViewHas('alertas',fn($a)=>(int)$a->pendentes===0);
 expect(QualidadeAtendimento::getNavigationBadge())->toBeNull();
 $this->artisan('virtus:alertar-qualidade')->assertSuccessful();expect($this->u->notifications()->count())->toBe(1);
});
it('atribui acompanhamento apenas a gestor autorizado e conserva prazo no fuso',function(){
 $s=User::factory()->create(['tenant_id'=>$this->tenant->id,'perfil'=>'supervisor','name'=>'Supervisora']);$s->forceFill(['super_admin'=>false])->saveQuietly();$s->canais()->attach($this->canal);
 Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->call('revisar',$this->id)->set('comentario','Acompanhar')->set('responsavelRevisao',(string)$s->id)->set('prazo','2026-10-10T15:30')->call('salvar')->assertHasNoErrors()->call('revisar',$this->id)->assertSet('prazo','2026-10-10T15:30');
 $r=DB::table('revisoes_qualidade')->first();expect($r->responsavel_id)->toBe($s->id)->and($s->notifications()->count())->toBe(1);
});
it('recusa atendente ou gestor sem canal como responsável e prazo sem responsável',function(){
 $t=Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->call('revisar',$this->id)->set('comentario','Acompanhar')->set('responsavelRevisao',(string)$this->a->id)->call('salvar')->assertHasErrors('responsavelRevisao');
 $t->set('responsavelRevisao','')->set('prazo','2026-10-10T15:30')->call('salvar')->assertHasErrors('responsavelRevisao');expect(DB::table('revisoes_qualidade')->count())->toBe(0);
});
it('prazo vencido conta última revisão somente e acompanha notas altas revisadas',function(){
 DB::table('encerramentos_atendimento')->where('id',$this->id)->update(['satisfacao'=>5]);
 $t=Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->call('revisar',$this->id)->set('comentario','Pendência')->set('responsavelRevisao',(string)$this->u->id)->set('prazo',now()->subDay()->timezone('America/Sao_Paulo')->format('Y-m-d\TH:i'))->call('salvar')->assertViewHas('alertas',fn($a)=>(int)$a->pendentes===1 && (int)$a->vencidos===1)->call('verPendencias')->assertViewHas('linhas',fn($d)=>$d->total()===1);
 $t->call('revisar',$this->id)->set('comentario','Novo prazo')->set('prazo',now()->addDay()->timezone('America/Sao_Paulo')->format('Y-m-d\TH:i'))->call('salvar')->assertViewHas('alertas',fn($a)=>(int)$a->vencidos===0 && (int)$a->pendentes===1);
});
it('pendências antigas permanecem acessíveis e respeitam canal e conta',function(){
 DB::table('encerramentos_atendimento')->where('id',$this->id)->update(['satisfacao'=>1,'created_at'=>now()->subMonths(6)]);
 Livewire::actingAs($this->u)->test(QualidadeAtendimento::class)->assertViewHas('linhas',fn($d)=>$d->total()===0)->call('verPendencias')->assertViewHas('linhas',fn($d)=>$d->total()===1);
 $this->u->canais()->detach();expect(app(AlertasQualidade::class)->pendentes($this->u->fresh(),$this->tenant->id)->count())->toBe(0);
});

it('falha temporária não grava aviso incompleto e reconciliação recupera',function(){
 DB::table('encerramentos_atendimento')->where('id',$this->id)->update(['satisfacao'=>1]);$falhar=true;
 DB::connection()->beforeExecuting(function($sql)use(&$falhar){if($falhar && str_contains($sql,'insert into "notifications"')){$falhar=false;throw new \RuntimeException('Falha simulada');}});
 expect(fn()=>app(AlertasQualidade::class)->avisar($this->id))->toThrow(\RuntimeException::class);
 expect(DB::table('avisos_qualidade')->count())->toBe(0)->and($this->u->notifications()->count())->toBe(0);
 $this->artisan('virtus:alertar-qualidade')->assertSuccessful();expect(DB::table('avisos_qualidade')->count())->toBe(1)->and($this->u->notifications()->count())->toBe(1);
});
