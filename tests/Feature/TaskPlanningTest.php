<?php
use App\Models\{BoardItem,Funnel,Tenant,User,DirectMessage};
use App\Filament\Pages\Paineis;
use App\Livewire\ChatInterno;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{Storage,DB};
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function(){
 config(['boards.foundation_enabled'=>true]);$t=Tenant::create(['nome'=>'Planning','slug'=>'planning']);TenantContext::set($t->id);
 $this->actor=User::create(['tenant_id'=>$t->id,'name'=>'Author','email'=>'author@planning.test','password'=>'password','admin'=>true]);
 $this->peer=User::create(['tenant_id'=>$t->id,'name'=>'Peer','email'=>'peer@planning.test','password'=>'password']);
 $this->actingAs($this->actor);$this->board=Funnel::criarCom('Planning');
 $this->item=BoardItem::create(['tenant_id'=>$t->id,'funnel_id'=>$this->board->id,'funnel_stage_id'=>$this->board->stages->first()->id,'titulo'=>'Planning task']);
});
afterEach(fn()=>TenantContext::forget());

it('rejects stale discussion status and pending completion through the same workflow',function(){
 $d=Livewire::test(\App\Livewire\BoardItemDiscussion::class,['itemId'=>$this->item->id]);
 $this->item->update(['version'=>2]);
 $d->set('status',$this->board->stages->get(1)->id)->call('alterarStatus')->assertHasErrors('conflito');
 $this->item->update(['checklist'=>[['id'=>'pending','texto'=>'Check','feito'=>false]]]);
 Livewire::test(\App\Livewire\BoardItemDiscussion::class,['itemId'=>$this->item->id])->set('status',$this->board->stages->last()->id)->call('alterarStatus')->assertHasErrors('conclusao');
 expect($this->item->refresh()->funnel_stage_id)->toBe($this->board->stages->first()->id);
});
it('preserves a recurring subtask parent and creates only one next occurrence',function(){
 $child=BoardItem::create(['tenant_id'=>$this->actor->tenant_id,'funnel_id'=>$this->board->id,'funnel_stage_id'=>$this->board->stages->first()->id,'titulo'=>'Child','parent_id'=>$this->item->id,'recorrencia'=>'diaria']);
 app(\App\Support\TaskWorkflow::class)->transition($this->actor,$child->id,$this->board->stages->last()->id,$child->refresh()->version);
 expect(BoardItem::where('occurrence_of',$child->id)->sole()->parent_id)->toBe($this->item->id);
});
it('rejects bulk transfer to a completion column without deleting the source',function(){
 Livewire::test(Paineis::class)->set('etapaRemover',$this->item->funnel_stage_id)->set('destinoEtapa',$this->board->stages->last()->id)->call('removerColuna')->assertHasErrors('destinoEtapa');
 expect($this->board->stages()->count())->toBe(5);
});
it('keeps an open column for recurring and new tasks',function(){
 $columns=$this->board->stages->map(fn($s)=>['id'=>$s->id,'nome'=>$s->nome,'cor'=>$s->cor,'encerra'=>true])->all();
 Livewire::test(Paineis::class)->call('configurar')->set('colunas',$columns)->call('salvarConfiguracao')->assertHasErrors('colunas');
 expect($this->board->stages()->where('encerra',false)->exists())->toBeTrue();
});

it('creates one recurring occurrence on completion and never duplicates it',function(){
 $page=Livewire::test(Paineis::class)->call('editarItem',$this->item->id)->set('form.recorrencia','mensal')->set('form.prazo_em','2026-01-31T10:00')->set('form.funnel_stage_id',$this->board->stages->last()->id)->call('salvarItem')->assertHasNoErrors();
 $page->call('salvarItem')->assertHasNoErrors();
 expect(BoardItem::where('occurrence_of',$this->item->id)->count())->toBe(1)->and(BoardItem::where('occurrence_of',$this->item->id)->first()->prazo_em->format('Y-m-d'))->toBe('2026-02-28');
});
it('requires confirmation for pending checklist completion',function(){
 $this->item->update(['checklist'=>[['id'=>'one','texto'=>'Pending','feito'=>false]]]);
 $p=Livewire::test(Paineis::class)->call('editarItem',$this->item->id)->set('form.funnel_stage_id',$this->board->stages->last()->id)->call('salvarItem')->assertHasErrors('conclusao');
 $p->set('confirmarConclusao',true)->call('salvarItem')->assertHasNoErrors();
 expect($this->item->refresh()->checklist[0]['feito'])->toBeFalse();
});
it('creates a reusable model without copying activity',function(){
 Livewire::test(Paineis::class)->call('editarItem',$this->item->id)->call('duplicar',true)->assertHasNoErrors();
 $model=BoardItem::where('modelo',true)->first();
 expect($model)->not->toBeNull()->and($model->updates()->count())->toBe(1);
});
it('renders timeline and scoped indicators',function(){
 $this->item->update(['inicio_em'=>'2026-08-20','prazo_em'=>'2026-08-25']);
 Livewire::test(Paineis::class)->set('visualizacao','gantt')->assertSee('Planning task')->assertSee('20/08/2026');
});
it('transfers tasks before deleting a column',function(){
 $to=$this->board->stages->get(1);
 Livewire::test(Paineis::class)->set('etapaRemover',$this->item->funnel_stage_id)->set('destinoEtapa',$to->id)->call('removerColuna')->assertHasNoErrors();
 expect($this->item->refresh()->funnel_stage_id)->toBe($to->id)->and($this->board->stages()->count())->toBe(4);
});
it('sends a private file and authorizes only the two participants',function(){
 Storage::fake('local');
 Livewire::test(ChatInterno::class)->call('abrir',$this->peer->id)->set('arquivo',UploadedFile::fake()->create('guide.pdf',10,'application/pdf'))->call('enviar')->assertHasNoErrors();
 $m=DirectMessage::first();Storage::disk('local')->assertExists($m->file_path);
 $this->get(route('internal-media',$m->id))->assertOk();
 $this->actingAs($this->peer)->get(route('internal-media',$m->id))->assertOk();
 $other=User::create(['tenant_id'=>$this->actor->tenant_id,'name'=>'Other','email'=>'other@planning.test','password'=>'password']);
 $this->actingAs($other)->get(route('internal-media',$m->id))->assertNotFound();
});
it('fails closed when antivirus is unavailable and does not publish a file',function(){
 Storage::fake('local');config(['task_media.scanner'=>'/nonexistent/task-scanner']);
 Livewire::test(ChatInterno::class)->call('abrir',$this->peer->id)->set('arquivo',UploadedFile::fake()->create('file.pdf',10,'application/pdf'))->call('enviar')->assertHasErrors('arquivo');
 expect(DirectMessage::count())->toBe(0)->and(Storage::disk('local')->allFiles('internal-media'))->toBe([]);
});
it('enforces aggregate tenant quota before publishing media',function(){
 Storage::fake('local');config(['task_media.tenant_bytes'=>1]);
 Livewire::test(ChatInterno::class)->call('abrir',$this->peer->id)->set('arquivo',UploadedFile::fake()->create('file.pdf',10,'application/pdf'))->call('enviar')->assertHasErrors('arquivo');
 expect(DirectMessage::count())->toBe(0)->and(Storage::disk('local')->allFiles('internal-media'))->toBe([]);
});
it('blocks attachment downloads while the session is locked',function(){
 Storage::fake('local');
 Livewire::test(ChatInterno::class)->call('abrir',$this->peer->id)->set('arquivo',UploadedFile::fake()->create('file.pdf',10,'application/pdf'))->call('enviar')->assertHasNoErrors();
 $this->withSession([\App\Http\Middleware\SessaoBloqueada::CHAVE=>true])->get(route('internal-media',DirectMessage::first()->id))->assertRedirect(route('sessao.travada'));
});
it('accepts a real short WAV and streams it only to its participants',function(){
 Storage::fake('local');
 $pcm=str_repeat("\0",16000);
 $wav='RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($pcm)).$pcm;
 Livewire::test(ChatInterno::class)->call('abrir',$this->peer->id)->set('arquivo',UploadedFile::fake()->createWithContent('recording.wav',$wav))->call('enviar')->assertHasNoErrors();
 $message=DirectMessage::firstOrFail();
 expect($message->file_mime)->toStartWith('audio/');
 $this->get(route('internal-media',$message->id))->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
});
it('rejects an unreadable recording instead of publishing a broken player',function(){
 Storage::fake('local');
 Livewire::test(ChatInterno::class)->call('abrir',$this->peer->id)->set('arquivo',UploadedFile::fake()->create('broken.wav',10,'audio/wav'))->call('enviar')->assertHasErrors('arquivo');
 expect(DirectMessage::count())->toBe(0);
});
