<?php
use App\Models\{Tenant,User,KnowledgeDocument,AiAgent,Contact,Channel,Conversation,Message,ChatbotAction};
use App\Services\{KnowledgeBase,GatewayVirtus,BlocosChatbot};
use App\Support\TenantContext;
use Illuminate\Support\Facades\{DB,Http};
beforeEach(function(){
 $this->tenant=Tenant::create(['nome'=>'Knowledge','slug'=>'knowledge-test']);TenantContext::set($this->tenant->id);
 $this->user=User::create(['tenant_id'=>$this->tenant->id,'name'=>'Admin','email'=>'knowledge@example.test','password'=>'teste','perfil'=>'admin']);$this->actingAs($this->user);
 $this->doc=KnowledgeDocument::create(['titulo'=>'Serviços de limpeza','idioma'=>'en_GB','conteudo'=>'Cleaning services cost 40 pounds per hour. Weekend cleaning available.','ativo'=>true]);
 $this->agent=AiAgent::create(['nome'=>'Knowledge agent','tipo'=>'agente','instrucoes'=>'Use fatos da empresa','ativo'=>true,'max_turnos'=>3,'fontes_conhecimento'=>[$this->doc->id]]);
});
afterEach(fn()=>TenantContext::forget());
it('consulta somente fontes vinculadas e relevantes',function(){
 KnowledgeDocument::create(['titulo'=>'Não vinculada','idioma'=>'en_GB','conteudo'=>'Cleaning secret price 999']);
 $found=app(KnowledgeBase::class)->consultar($this->agent,'What is the cleaning price?');expect($found)->toHaveCount(1)->and($found[0]['documento_id'])->toBe($this->doc->id);
 expect(app(KnowledgeBase::class)->consultar($this->agent,'contract cancellation'))->toBe([]);
});
it('atualiza indice e ignora documento desativado',function(){
 $this->doc->update(['conteudo'=>'Gardening service only']);expect(app(KnowledgeBase::class)->consultar($this->agent,'cleaning'))->toBe([]);
 expect(app(KnowledgeBase::class)->consultar($this->agent,'gardening'))->toHaveCount(1);$this->doc->update(['ativo'=>false]);expect(app(KnowledgeBase::class)->consultar($this->agent,'gardening'))->toBe([]);
});
it('bloqueia vinculo e consulta entre contas mesmo com JSON adulterado',function(){
 $other=Tenant::create(['nome'=>'Outra','slug'=>'knowledge-other']);expect(fn()=>AiAgent::create(['tenant_id'=>$other->id,'nome'=>'Outro','tipo'=>'agente','fontes_conhecimento'=>[$this->doc->id]]))->toThrow(\Illuminate\Validation\ValidationException::class);
 $forged=new AiAgent(['tenant_id'=>$other->id,'fontes_conhecimento'=>[$this->doc->id]]);expect(app(KnowledgeBase::class)->consultar($forged,'cleaning'))->toBe([]);
});
it('limita contexto e suporta acentos',function(){
 $this->doc->update(['conteudo'=>str_repeat('Informações sobre instalação e manutenção. ',1500)]);expect(app(KnowledgeBase::class)->consultar($this->agent,'instalacao'))->toHaveCount(4);
 expect(mb_strlen(app(KnowledgeBase::class)->contexto($this->agent,'instalação')))->toBeLessThan(6000);
});
it('importa UTF8 e rejeita formato e conteudo invalido',function(){
 $path=tempnam(sys_get_temp_dir(),'knowledge');try{file_put_contents($path,'# Cleaning documentation');expect(KnowledgeBase::importar($path,'faq.md'))->toBe('# Cleaning documentation');expect(fn()=>KnowledgeBase::importar($path,'faq.pdf'))->toThrow(\Illuminate\Validation\ValidationException::class);file_put_contents($path,"binary\0");expect(fn()=>KnowledgeBase::importar($path,'faq.txt'))->toThrow(\Illuminate\Validation\ValidationException::class);}finally{unlink($path);}
});
it('inclui trechos na chamada central e contabiliza tokens uma unica vez',function(){
 GatewayVirtus::salvar(['ativa'=>true,'provedor'=>'gateway','chave'=>'ficticio','modelo'=>'teste']);Http::fake(['https://claude-ss.ia.br/*'=>Http::response(['choices'=>[['message'=>['content'=>'{"texto":"40 pounds per hour","transferir":false}']]],'usage'=>['prompt_tokens'=>300,'completion_tokens'=>20,'total_tokens'=>320]])]);
 $contact=Contact::create(['nome'=>'Cliente','telefone_e164'=>'+447700900001','jid'=>'447700900001@s.whatsapp.net']);$channel=Channel::create(['nome'=>'Teste']);$c=Conversation::create(['contact_id'=>$contact->id,'channel_id'=>$channel->id,'status'=>Conversation::EM_ATENDIMENTO]);Message::create(['conversation_id'=>$c->id,'channel_id'=>$channel->id,'direcao'=>'in','tipo'=>'text','corpo'=>'What does cleaning cost?']);
 $a=new ChatbotAction(['tenant_id'=>$this->tenant->id,'tipo'=>'agente_ia','config'=>['agente_id'=>$this->agent->id]]);expect(app(BlocosChatbot::class)->responderIA($a,$c,'What does cleaning cost?')['texto'])->toBe('40 pounds per hour');Http::assertSentCount(1);Http::assertSent(fn($r)=>str_contains($r['messages'][1]['content'],'40 pounds per hour'));
 expect((int)DB::table('consumos_ia')->where('tenant_id',$this->tenant->id)->sum('total'))->toBe(320);
});
it('renderiza recurso apenas para administrador',function(){
 \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));\Livewire\Livewire::test(\App\Filament\Resources\KnowledgeDocuments\Pages\ListKnowledgeDocuments::class)->assertSee('Serviços de limpeza');$this->user->update(['perfil'=>'atendente']);expect(\App\Filament\Resources\KnowledgeDocuments\KnowledgeDocumentResource::canViewAny())->toBeFalse();
});

it('cadastra documento pelo formulario e importa arquivo sem publicar upload',function(){
 \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 \Livewire\Livewire::test(\App\Filament\Resources\KnowledgeDocuments\Pages\ListKnowledgeDocuments::class)->callAction('create',['titulo'=>'Opening hours','idioma'=>'en_GB','conteudo'=>'Office opens at 9am.','ativo'=>true])->assertHasNoActionErrors();
 $d=KnowledgeDocument::where('titulo','Opening hours')->firstOrFail();expect($d->tenant_id)->toBe($this->tenant->id)->and(DB::table('knowledge_chunks')->where('knowledge_document_id',$d->id)->count())->toBe(1);
 $upload=\Illuminate\Http\UploadedFile::fake()->createWithContent('faq.txt','Cleaning is available on weekends.');
 \Livewire\Livewire::test(\App\Filament\Resources\KnowledgeDocuments\Pages\ListKnowledgeDocuments::class)->mountAction('create')->set('mountedActions.0.data.importar',[$upload])->assertActionDataSet(['conteudo'=>'Cleaning is available on weekends.']);
});
