<?php
namespace App\Livewire;

use App\Models\{BoardItem,Funnel,FunnelStage,User};
use App\Support\{AvisoDeItem,BoardAccess};
use Illuminate\Support\Facades\{DB,RateLimiter};
use Livewire\Attributes\Locked;
use Livewire\Component;

class BoardItemDiscussion extends Component
{
    #[Locked] public int $itemId;
    #[Locked] public ?int $editingId=null;
    #[Locked] public int $limit=30;
    #[Locked] public int $statusVersion=1;
    #[Locked] public string $submissionId='';
    #[Locked] public int $editingRevision=1;
    public string $texto='';
    public array $mencoes=[];
    public ?int $status=null;
    public ?int $replyTo=null;

    private function item(bool $edit=false):BoardItem
    {
        $item=BoardItem::where('tenant_id',auth()->user()->tenant_id)->findOrFail($this->itemId);
        $board=Funnel::where('tenant_id',$item->tenant_id)->findOrFail($item->funnel_id);
        abort_if($edit && $board->arquivado_em,403);
        $access=app(BoardAccess::class);
        abort_unless($edit?$access->canEdit(auth()->user(),$board):$access->canView(auth()->user(),$board),403);
        return $item;
    }
    public function mount(int $itemId):void
    {
        $this->submissionId=(string)\Illuminate\Support\Str::uuid();
        $this->itemId=$itemId;$item=$this->item();$this->status=$item->funnel_stage_id;$this->statusVersion=$item->version;
        if($target=(int)request()->query('comentario')){
            if($item->updates()->whereKey($target)->exists())$this->limit=max(30,$item->updates()->where('id','>=',$target)->count());
        }
    }
    public function responder(int $id):void{$this->item(true)->updates()->where('tipo','mensagem')->findOrFail($id);$this->replyTo=$id;$this->editingId=null;}
    public function editar(int $id):void
    {
        $c=$this->item(true)->updates()->whereNull('deleted_at')->where('user_id',auth()->id())->where('tipo','mensagem')->findOrFail($id);
        $this->editingRevision=$c->revision;$this->editingId=$id;$this->texto=$c->corpo;$this->mencoes=$c->mencoes??[];
    }
    public function cancelar():void{$this->reset('texto','mencoes','editingId','replyTo');$this->submissionId=(string)\Illuminate\Support\Str::uuid();}
    public function anteriores():void{$this->item();$this->limit=min(1000,$this->limit+30);}
    public function enviar():void
    {
        $this->item(true);$this->texto=trim($this->texto);
        $this->validate(['texto'=>'required|string|max:10000','mencoes'=>'array|max:50','mencoes.*'=>'integer']);
        $key='board-comments:'.auth()->id();
        if(RateLimiter::tooManyAttempts($key,30)){$this->addError('texto','Aguarde um minuto antes de enviar mais mensagens.');return;}
        DB::transaction(function(){
            $hint=$this->item(true);
            $board=Funnel::where('tenant_id',$hint->tenant_id)->lockForUpdate()->findOrFail($hint->funnel_id);
            $item=$this->item(true);
            if(!$this->editingId && $item->updates()->where('submission_id',$this->submissionId)->exists())return;
            $users=User::where('tenant_id',$item->tenant_id)->whereIn('id',$this->mencoes)->get();$ids=[];
            if($users->count()!==count(array_unique($this->mencoes)))throw \Illuminate\Validation\ValidationException::withMessages(['texto'=>'Uma menção não pertence a esta equipe.']);
            foreach($users as $user){
                if(!app(BoardAccess::class)->canView($user,$board))throw \Illuminate\Validation\ValidationException::withMessages(['texto'=>'Uma pessoa mencionada não tem mais acesso ao painel.']);
                if(str_contains($this->texto,'@'.$user->name))$ids[]=$user->id;
            }
            if($this->replyTo)$item->updates()->findOrFail($this->replyTo);
            $before=[];
            if($this->editingId){
                $c=$item->updates()->whereNull('deleted_at')->where('user_id',auth()->id())->where('tipo','mensagem')->lockForUpdate()->findOrFail($this->editingId);
                if($c->revision!==$this->editingRevision)throw \Illuminate\Validation\ValidationException::withMessages(['texto'=>'Este comentário foi editado em outra janela. Reabra a edição.']);
                $before=$c->notified_mentions??$c->mencoes??[];
                $c->update(['corpo'=>$this->texto,'mencoes'=>$ids,'edited_at'=>now(),'revision'=>$c->revision+1,'notified_mentions'=>array_values(array_unique(array_merge($before,$ids)))]);
            }else{$c=$item->updates()->create(['tenant_id'=>$item->tenant_id,'user_id'=>auth()->id(),'corpo'=>$this->texto,'mencoes'=>$ids,'notified_mentions'=>$ids,'submission_id'=>$this->submissionId,'reply_to_id'=>$this->replyTo]);}
            $participants=DB::table('board_watchers')->where('board_item_id',$item->id)->pluck('user_id')->merge($item->responsaveis()->pluck('users.id'))->push($item->responsavel_id)->all();
            AvisoDeItem::enviar($item,array_diff($ids,$before),'Você foi mencionado em uma tarefa',$c->id);
            if(!$this->editingId)AvisoDeItem::enviar($item,array_diff($participants,$ids),'Nova mensagem na tarefa',$c->id);
        });
        RateLimiter::hit($key,60);$this->cancelar();
    }
    public function alterarStatus():void
    {
        $item=$this->item(true);
        $this->statusVersion=app(\App\Support\TaskWorkflow::class)->transition(auth()->user(),$item->id,(int)$this->status,$this->statusVersion);
        $this->dispatch('status-item-alterado');
    }
    public function remover(int $id):void
    {
        DB::transaction(function()use($id){
            $hint=$this->item(true);Funnel::where('tenant_id',$hint->tenant_id)->lockForUpdate()->findOrFail($hint->funnel_id);
            $item=$this->item(true);
            $comment=$item->updates()->where('user_id',auth()->id())->where('tipo','mensagem')->lockForUpdate()->findOrFail($id);
            if($comment->deleted_at)return;
            $comment->update(['corpo'=>'Comentário removido pelo autor.','mencoes'=>[],'deleted_at'=>now(),'revision'=>$comment->revision+1]);
            DB::table('board_comment_reactions')->where('board_item_update_id',$id)->delete();
        });
        $this->cancelar();
    }
    public function reagir(int $id,string $emoji):void
    {
        abort_unless(in_array($emoji,['👍','✅','❤️','👀'],true),422);
        DB::transaction(function()use($id,$emoji){
            $hint=$this->item(true);Funnel::where('tenant_id',$hint->tenant_id)->lockForUpdate()->findOrFail($hint->funnel_id);
            $comment=$this->item(true)->updates()->whereNull('deleted_at')->where('tipo','mensagem')->lockForUpdate()->findOrFail($id);
            $q=DB::table('board_comment_reactions')->where('board_item_update_id',$comment->id)->where('user_id',auth()->id())->where('emoji',$emoji);
            if($q->exists())$q->delete();else DB::table('board_comment_reactions')->insert(['board_item_update_id'=>$comment->id,'user_id'=>auth()->id(),'emoji'=>$emoji]);
        });
    }
    public function render()
    {
        $item=$this->item();$board=Funnel::findOrFail($item->funnel_id);
        return view('livewire.board-item-discussion',[
            'reacoes'=>DB::table('board_comment_reactions')->whereIn('board_item_update_id',$item->updates()->select('id'))->get()->groupBy('board_item_update_id'),
            'atualizacoes'=>$item->updates()->with('user')->latest('id')->limit($this->limit)->get()->reverse(),
            'mais'=>$item->updates()->count()>$this->limit,'podeComentar'=>!$board->arquivado_em && app(BoardAccess::class)->canEdit(auth()->user(),$board),
            'pessoas'=>User::where('tenant_id',$item->tenant_id)->orderBy('name')->get()->filter(fn($u)=>app(BoardAccess::class)->canView($u,$board))->map(fn($u)=>['id'=>$u->id,'name'=>$u->name])->values(),
        ]);
    }
}
