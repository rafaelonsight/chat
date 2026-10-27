<?php
use App\Models\{Tenant,User,Contact,Channel,Conversation,Chatbot,ChatbotAction,Tag};
use App\Services\{CondicoesChatbot as C,ChatbotFluxo,ChatbotMotor,ChatbotSimulador};
use App\Support\TenantContext;
use App\Filament\Resources\Chatbots\Pages\EditarFluxo;
use Livewire\Livewire;
beforeEach(function(){ $this->tenant=Tenant::create(['nome'=>'Condições','slug'=>'condicoes']);TenantContext::set($this->tenant->id);$this->user=User::create(['name'=>'Admin','email'=>'admin@condicoes.test','password'=>'ficticia','perfil'=>'admin','tenant_id'=>$this->tenant->id]);$this->actingAs($this->user);\Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));$this->bot=Chatbot::create(['nome'=>'Teste','mensagem_boas_vindas'=>'Oi','mensagem_nao_entendi'=>'Repita','tolerancia_segundos'=>0]);$this->f=app(ChatbotFluxo::class);$this->group=$this->f->criarPasso($this->bot,300,100); });
afterEach(fn()=>TenantContext::forget());
function casosCondicao(): array {return ['modo'=>'multiplo','casos'=>[['id'=>'prioritario','nome'=>'Prioritário','combinacao'=>'todas','regras'=>[['fonte'=>'resposta','campo'=>'problema','operador'=>'contem','valor'=>'conexao'],['fonte'=>'contato','campo'=>'contato.uf','operador'=>'igual','valor'=>'SP']]],['id'=>'outro','nome'=>'Outro','combinacao'=>'qualquer','regras'=>[['fonte'=>'resposta','campo'=>'problema','operador'=>'preenchido','valor'=>'']]]]];}
it('combina regras ignora acentos e respeita a primeira correspondencia',function(){
 $cfg=casosCondicao();expect(C::escolher($cfg,['problema'=>'CONEXÃO lenta'],['contato'=>['uf'=>'sp']],[]))->toBe('caso:prioritario');
 expect(C::escolher($cfg,['problema'=>'CONEXÃO lenta'],['contato'=>['uf'=>'RJ']],[]))->toBe('caso:outro');
 expect(C::escolher($cfg,[],[],[]))->toBe('padrao');
 $cfg['casos']=array_reverse($cfg['casos']);expect(C::escolher($cfg,['problema'=>'conexao'],['contato'=>['uf'=>'SP']],[]))->toBe('caso:outro');
});
it('compara numeros sem converter texto invalido em zero e aceita json aninhado',function(){
 $cfg=['casos'=>[['id'=>'valor','combinacao'=>'todas','regras'=>[['fonte'=>'resposta','campo'=>'resultado.total','operador'=>'maior','valor'=>'10,5']]]]];
 expect(C::escolher($cfg,['resultado'=>['total'=>'11,0']],[],[]))->toBe('caso:valor')->and(C::escolher($cfg,['resultado'=>['total'=>'abc']],[],[]))->toBe('padrao');
 $cfg['casos'][0]['regras'][0]['operador']='vazio';expect(C::escolher($cfg,[],[],[]))->toBe('caso:valor');
});
it('valida limites fontes campos e etiquetas de outra conta',function(){
 expect(C::validar(casosCondicao(),$this->tenant->id))->toBe([]);
 $other=Tenant::create(['nome'=>'Outra','slug'=>'outra']);$tag=Tag::create(['tenant_id'=>$other->id,'nome'=>'VIP','contexto'=>'contato']);$cfg=casosCondicao();$cfg['casos'][0]['regras']=[['fonte'=>'etiqueta','etiqueta_id'=>$tag->id,'operador'=>'ausente']];expect(C::validar($cfg,$this->tenant->id))->not->toBeEmpty();
 $cfg=casosCondicao();$cfg['casos'][1]['id']='prioritario';expect(C::validar($cfg,$this->tenant->id))->not->toBeEmpty();
 $cfg['casos'][0]['regras'][0]['fonte']='contato';$cfg['casos'][0]['regras'][0]['campo']='contato.tenant_id';expect(C::validar($cfg,$this->tenant->id))->not->toBeEmpty();
});
it('usa etiquetas do atendimento e contato e recursos removidos seguem padrao',function(){
 $tag=Tag::create(['nome'=>'VIP','contexto'=>'conversa']);$contact=Contact::create(['nome'=>'Teste','jid'=>'5511999990000@s.whatsapp.net']);$ch=Channel::create(['nome'=>'Teste']);$c=Conversation::create(['channel_id'=>$ch->id,'contact_id'=>$contact->id,'status'=>'novo']);$c->tags()->attach($tag->id,['origem'=>'manual']);
 $cfg=casosCondicao();$cfg['casos'][0]['regras']=[['fonte'=>'etiqueta','etiqueta_id'=>$tag->id,'operador'=>'presente']];$a=$this->f->adicionarAcao($this->group,'condicional',$cfg);expect(C::destino($a,$c))->toBe('caso:prioritario');$tag->delete();expect(C::destino($a,$c))->toBe('padrao');
});
it('exige conectar todos os casos inclusive caso contrario',function(){
 $a=$this->f->adicionarAcao($this->group,'condicional',casosCondicao());expect($a->handles())->toBe(['caso:prioritario','caso:outro','padrao']);
 $this->f->ligar($this->f->garantirInicio($this->bot),$this->group);expect(implode(' ', $this->f->validar($this->bot)))->toContain('padrao');
});
it('renomear e reordenar preserva ids e conexoes no editor',function(){
 $a=$this->f->adicionarAcao($this->group,'condicional',casosCondicao());$dest=$this->f->criarPasso($this->bot,700,100);$this->f->ligar($this->group,$dest,'caso:prioritario');
 Livewire::test(EditarFluxo::class,['record'=>$this->bot->id])->call('abrirAcao',$a->id)->set('form.casos.0.nome','Cliente especial')->call('moverCasoCondicao',0,1)->call('salvarAcao')->assertHasNoErrors();
 expect($a->fresh()->config['casos'][1]['id'])->toBe('prioritario')->and($this->group->destino('caso:prioritario')->id)->toBe($dest->id);
});
it('motor e simulador usam o mesmo caminho e mantem sim nao antigo',function(){
 \Illuminate\Support\Facades\Bus::fake();$cfg=casosCondicao();$cfg['casos'][0]['regras']=[['fonte'=>'resposta','campo'=>'problema','operador'=>'igual','valor'=>'conexao']];$this->f->adicionarAcao($this->group,'metadados',['chave'=>'problema','valor'=>'conexao','escopo'=>'atendimento']);$this->f->adicionarAcao($this->group,'condicional',$cfg);$this->f->ligar($this->f->garantirInicio($this->bot),$this->group);
 foreach(['caso:prioritario'=>'Prioritário','caso:outro'=>'Outro','padrao'=>'Padrão'] as $handle=>$text){$d=$this->f->criarPasso($this->bot,700,100);$this->f->adicionarAcao($d,'mensagem',['texto'=>$text]);$this->f->ligar($this->group,$d,$handle);}
 $this->bot->update(['ativo'=>true,'status'=>Chatbot::PUBLICADO]);$contact=Contact::create(['nome'=>'Cliente','jid'=>'5511999990001@s.whatsapp.net']);$ch=Channel::create(['nome'=>'Teste']);$c=Conversation::create(['channel_id'=>$ch->id,'contact_id'=>$contact->id,'status'=>'novo','chatbot_respostas'=>['problema'=>'conexao']]);$in=\App\Models\Message::create(['conversation_id'=>$c->id,'channel_id'=>$ch->id,'direcao'=>'in','tipo'=>'text','corpo'=>'oi']);app(ChatbotMotor::class)->talvezAtender($ch,$in);expect($c->messages()->where('direcao','out')->value('corpo'))->toBe('Prioritário');
 $s=app(ChatbotSimulador::class)->iniciar($this->bot);expect(json_encode($s,JSON_UNESCAPED_UNICODE))->toContain('Prioritário');
 $legacy=new ChatbotAction(['tipo'=>'condicional','config'=>['campo'=>'x','valor'=>'y']]);expect($legacy->handles())->toBe(['sim','nao']);
});
