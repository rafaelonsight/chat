<?php
use App\Models\{BoardItem,Funnel,Tenant,User};
use App\Filament\Pages\Paineis;
use App\Livewire\BoardItemDiscussion;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function(){
    config(['boards.foundation_enabled'=>true]);
    $this->tenant=Tenant::create(['nome'=>'Workspace','slug'=>'workspace']);TenantContext::set($this->tenant->id);
    $this->admin=User::create(['tenant_id'=>$this->tenant->id,'name'=>'Rafael Teste','email'=>'admin@workspace.test','password'=>'password','admin'=>true]);
    $this->celso=User::create(['tenant_id'=>$this->tenant->id,'name'=>'Celso Teste','email'=>'celso@workspace.test','password'=>'password']);
    $this->celso->forceFill(['super_admin'=>false])->saveQuietly();
    $this->actingAs($this->admin);$this->board=Funnel::criarCom('Equipe');
    $this->item=BoardItem::create(['tenant_id'=>$this->tenant->id,'funnel_id'=>$this->board->id,'funnel_stage_id'=>$this->board->stages->first()->id,'titulo'=>'Tarefa inicial']);
});
afterEach(fn()=>TenantContext::forget());
it('renders formatting while stripping active HTML and unsafe links',function(){
 $html=\App\Support\TaskText::render('**Bold** <script>alert(1)</script> [bad](javascript:alert) https://example.test');
 expect($html)->toContain('<strong>Bold</strong>')->not->toContain('<script')->not->toContain('href="javascript:');
});
it('retains and retries failed realtime delivery without duplicating bell entries',function(){
 app(\App\Services\AssignBoardItem::class)->execute($this->admin,$this->item->id,[$this->celso->id]);
 $row=DB::table('board_notice_outbox')->first();
 \Illuminate\Support\Facades\Event::listen(\App\Events\AvisoColaboracao::class,fn()=>throw new \RuntimeException('Test transport unavailable'));
 app(\App\Support\DeliverTaskNotice::class)->deliver($row->id);
 expect(DB::table('board_notice_outbox')->first()->delivered_at)->toBeNull()->and($this->celso->notifications()->count())->toBe(1);
 \Illuminate\Support\Facades\Event::fake([\App\Events\AvisoColaboracao::class]);
 DB::table('board_notice_outbox')->where('id',$row->id)->update(['available_at'=>now()->subSecond()]);
 app(\App\Support\DeliverTaskNotice::class)->deliver($row->id);
 app(\App\Support\DeliverTaskNotice::class)->deliver($row->id);
 expect(DB::table('board_notice_outbox')->first()->delivered_at)->not->toBeNull()->and($this->celso->notifications()->count())->toBe(1);
 \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\AvisoColaboracao::class,1);
});
it('rechecks panel access before delivering a pending notice',function(){
 app(\App\Services\AssignBoardItem::class)->execute($this->admin,$this->item->id,[$this->celso->id]);
 $this->board->forceFill(['restrito'=>true])->save();
 \Illuminate\Support\Facades\Event::fake([\App\Events\AvisoColaboracao::class]);
 app(\App\Support\DeliverTaskNotice::class)->deliver(DB::table('board_notice_outbox')->value('id'));
 \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\AvisoColaboracao::class);
});

it('renders real views and saves multiple assignees',function(){
    Livewire::test(Paineis::class)->assertSee('Tarefa inicial')->call('editarItem',$this->item->id)
        ->set('form.titulo','Tarefa editada')->set('form.responsaveis',[$this->admin->id,$this->celso->id])
        ->call('salvarItem')->assertHasNoErrors()->set('visualizacao','quadro')->assertSee('Tarefa editada');
    expect($this->item->refresh()->titulo)->toBe('Tarefa editada')->and($this->item->responsaveis()->count())->toBe(2);
});
it('creates an item and reopens it with persisted values',function(){
    Livewire::test(Paineis::class)->call('abrirNovoItem')->set('form.titulo','Novo trabalho')->call('salvarItem')->assertHasNoErrors();
    expect(BoardItem::where('titulo','Novo trabalho')->count())->toBe(1);
});
it('rejects stale edits without overwriting the item',function(){
    $page=Livewire::test(Paineis::class)->call('editarItem',$this->item->id);
    $this->item->update(['titulo'=>'Outro editor','version'=>2]);
    $page->set('form.titulo','Antigo rascunho')->call('salvarItem')->assertHasErrors('conflito');
    expect($this->item->refresh()->titulo)->toBe('Outro editor');
});
it('persists checklist and subtasks',function(){
    $page=Livewire::test(Paineis::class)->call('editarItem',$this->item->id)->set('novoChecklist','Verificar cadastro')->call('adicionarChecklist')->assertHasNoErrors();
    $id=$this->item->refresh()->checklist[0]['id'];
    $page->call('marcarChecklist',$id)->set('novaSubtarefa','Configurar equipe')->call('criarSubtarefa')->assertHasNoErrors();
    expect($this->item->refresh()->checklist[0]['feito'])->toBeTrue()->and(BoardItem::where('parent_id',$this->item->id)->count())->toBe(1);
});
it('denies a reader from writing or commenting',function(){
    DB::table('board_members')->insert(['tenant_id'=>$this->tenant->id,'funnel_id'=>$this->board->id,'user_id'=>$this->celso->id,'papel'=>'leitor']);
    Livewire::actingAs($this->celso)->test(Paineis::class)->call('editarItem',$this->item->id)->call('salvarItem')->assertForbidden();
    Livewire::actingAs($this->celso)->test(BoardItemDiscussion::class,['itemId'=>$this->item->id])->set('texto','not allowed')->call('enviar')->assertForbidden();
});
it('hides restricted boards and blocks direct item discussion',function(){
    $this->board->forceFill(['restrito'=>true])->save();
    Livewire::actingAs($this->celso)->test(Paineis::class)->assertDontSee('Tarefa inicial')->assertDontSee('Equipe');
    Livewire::actingAs($this->celso)->test(BoardItemDiscussion::class,['itemId'=>$this->item->id])->assertForbidden();
});
it('mentions notify once and edits do not resend an existing mention',function(){
    $c=Livewire::test(BoardItemDiscussion::class,['itemId'=>$this->item->id])->set('texto','@Celso Teste confira')->set('mencoes',[$this->celso->id,$this->celso->id])->call('enviar')->assertHasNoErrors();
    $comment=$this->item->updates()->where('tipo','mensagem')->first();
    $c->call('editar',$comment->id)->set('texto','@Celso Teste confira novamente')->call('enviar')->assertHasNoErrors();
    expect($this->celso->notifications()->count())->toBe(1)->and($comment->refresh()->edited_at)->not->toBeNull();
});
it('stores private attachments and blocks unauthorized download',function(){
    Storage::fake('local');
    Livewire::test(Paineis::class)->call('editarItem',$this->item->id)->set('arquivo',UploadedFile::fake()->create('guide.pdf',10,'application/pdf'))->call('anexar')->assertHasNoErrors();
    $file=DB::table('board_attachments')->first();Storage::disk('local')->assertExists($file->path);
    $this->board->forceFill(['restrito'=>true])->save();
    expect(fn()=>Livewire::actingAs($this->celso)->test(Paineis::class)->call('baixar',$file->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
it('saves panel configuration without replacing existing columns',function(){
    Livewire::test(Paineis::class)->call('configurar')->set('nomePainel','Equipe nova')->set('membros',[$this->celso->id=>'editor'])->set('restrito',true)->call('salvarConfiguracao')->assertHasNoErrors();
    expect($this->board->refresh()->nome)->toBe('Equipe nova')->and($this->board->stages()->count())->toBe(5);
});
it('rejects dependency cycles',function(){
    $other=BoardItem::create(['tenant_id'=>$this->tenant->id,'funnel_id'=>$this->board->id,'funnel_stage_id'=>$this->board->stages->first()->id,'titulo'=>'Outra']);
    $page=Livewire::test(Paineis::class)->call('editarItem',$this->item->id)->set('dependencia',$other->id)->call('adicionarDependencia')->assertHasNoErrors();
    $page->call('editarItem',$other->id)->set('dependencia',$this->item->id)->call('adicionarDependencia')->assertHasErrors('dependencia');
    expect(DB::table('board_dependencies')->count())->toBe(1);
});
it('deduplicates replayed comments and preserves mention history across edits',function(){
    $p=Livewire::test(BoardItemDiscussion::class,['itemId'=>$this->item->id]);
    $key=$p->get('submissionId');
    $p->set('texto','@Celso Teste confira')->set('mencoes',[$this->celso->id])->call('enviar')->assertHasNoErrors();
    $instance=$p->instance();$instance->submissionId=$key;$instance->texto='@Celso Teste confira';$instance->mencoes=[$this->celso->id];$instance->enviar();
    expect($this->item->updates()->where('tipo','mensagem')->count())->toBe(1);
    $id=$this->item->updates()->where('tipo','mensagem')->value('id');
    $p->call('editar',$id)->set('texto','Texto sem menção')->set('mencoes',[])->call('enviar')->assertHasNoErrors();
    $p->call('editar',$id)->set('texto','@Celso Teste novamente')->set('mencoes',[$this->celso->id])->call('enviar')->assertHasNoErrors();
    expect($this->celso->notifications()->count())->toBe(1);
});
it('rejects stale comment edits and retains a deletion tombstone',function(){
    $p=Livewire::test(BoardItemDiscussion::class,['itemId'=>$this->item->id])->set('texto','Original')->call('enviar');
    $id=$this->item->updates()->where('tipo','mensagem')->value('id');
    $stale=Livewire::test(BoardItemDiscussion::class,['itemId'=>$this->item->id])->call('editar',$id);
    $p->call('editar',$id)->set('texto','Atualizado')->call('enviar')->assertHasNoErrors();
    $stale->set('texto','Antigo')->call('enviar')->assertHasErrors('texto');
    $p->call('reagir',$id,'👍')->assertHasNoErrors();
    expect(DB::table('board_comment_reactions')->count())->toBe(1);
    $p->call('remover',$id)->assertHasNoErrors();
    expect($this->item->updates()->find($id)->deleted_at)->not->toBeNull()->and(DB::table('board_comment_reactions')->count())->toBe(0);
});
