<?php
namespace App\Support;

use App\Models\{BoardItem,Funnel,FunnelStage,User};
use App\Services\AssignBoardItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskWorkflow
{
    public function transition(User $actor,int $id,int $stage,int $version,bool $confirm=false):int
    {
        return DB::transaction(function()use($actor,$id,$stage,$version,$confirm){
            $hint=BoardItem::where('tenant_id',$actor->tenant_id)->findOrFail($id);
            $board=Funnel::where('tenant_id',$actor->tenant_id)->lockForUpdate()->findOrFail($hint->funnel_id);
            abort_if($board->arquivado_em,403);
            abort_unless(app(BoardAccess::class)->canEdit($actor,$board),403);
            $item=BoardItem::where('tenant_id',$actor->tenant_id)->where('funnel_id',$board->id)->lockForUpdate()->findOrFail($id);
            if($item->version!==$version)throw ValidationException::withMessages(['conflito'=>'A tarefa mudou. Reabra antes de alterar o status.']);
            $target=FunnelStage::where('funnel_id',$board->id)->findOrFail($stage);
            if($item->funnel_stage_id===$target->id)return $item->version;
            $wasClosed=(bool)$item->stage?->encerra;
            $this->assertCompletion($item,$target->id,$confirm);
            $item->update(['funnel_stage_id'=>$target->id,'etapa_em'=>now(),'version'=>$item->version+1]);
            if(!$wasClosed && $target->encerra)$this->recur($item,$actor);
            return $item->version;
        },3);
    }

    public function assertCompletion(BoardItem $item,int $stage,bool $confirm=false):void
    {
        if($item->funnel_stage_id===$stage || !FunnelStage::whereKey($stage)->value('encerra'))return;
        $pending=collect($item->checklist??[])->contains(fn($r)=>!$r['feito']) || BoardItem::where('parent_id',$item->id)->whereNull('arquivado_em')->whereHas('stage',fn($q)=>$q->where('encerra',false))->exists();
        if($pending&&!$confirm)throw ValidationException::withMessages(['conclusao'=>'Há checklist ou subtarefas pendentes. Confirme no detalhe para concluir somente esta tarefa.']);
    }

    // Caller holds board then item locks. Unique occurrence_of prevents replay duplicates.
    public function recur(BoardItem $original,User $actor):void
    {
        if(!$original->recorrencia||$original->modelo||BoardItem::where('occurrence_of',$original->id)->exists())return;
        $board=Funnel::where('tenant_id',$actor->tenant_id)->findOrFail($original->funnel_id);
        $stage=FunnelStage::where('funnel_id',$board->id)->where('encerra',false)->orderBy('ordem')->firstOrFail();
        $base=($original->prazo_em??now())->copy();
        $next=match($original->recorrencia){'diaria'=>$base->addDay(),'semanal'=>$base->addWeek(),'mensal'=>$base->addMonthNoOverflow()};
        $new=BoardItem::create(['tenant_id'=>$board->tenant_id,'funnel_id'=>$board->id,'funnel_stage_id'=>$stage->id,
            'titulo'=>$original->titulo,'descricao'=>$original->descricao,'prioridade'=>$original->prioridade,'contact_id'=>$original->contact_id,
            'dados'=>$original->dados,'checklist'=>collect($original->checklist??[])->map(fn($c)=>['id'=>(string)\Illuminate\Support\Str::uuid(),'texto'=>$c['texto'],'feito'=>false])->all(),
            'created_by'=>$actor->id,'version'=>1,'occurrence_of'=>$original->id,'recorrencia'=>$original->recorrencia,'prazo_em'=>$next,'parent_id'=>$original->parent_id]);
        $new->tags()->sync($original->tags()->pluck('tags.id')->mapWithKeys(fn($id)=>[$id=>['origem'=>'manual','aplicado_por'=>$actor->id,'created_at'=>now()]])->all());
        $ids=$original->responsaveis()->get()->filter(fn($u)=>app(BoardAccess::class)->canEdit($u,$board))->pluck('id')->all();
        app(AssignBoardItem::class)->execute($actor,$new->id,$ids);
    }
}
