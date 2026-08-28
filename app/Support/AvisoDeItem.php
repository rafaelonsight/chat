<?php
namespace App\Support;

use App\Events\AvisoColaboracao;
use App\Models\{BoardItem,Funnel,User};
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{DB,Log};
use Illuminate\Support\Str;

class AvisoDeItem
{
    public static function enviar(BoardItem $item,array $ids,string $titulo,?int $commentId=null): void
    {
        $board=Funnel::withoutGlobalScope('tenant')->where('tenant_id',$item->tenant_id)->find($item->funnel_id);
        if(!$board)return;
        $url='/admin/paineis?painel='.$item->funnel_id.'&item='.$item->id.($commentId?'&comentario='.$commentId.'#comment-'.$commentId:'');
        foreach(User::withoutGlobalScope('tenant')->where('tenant_id',$item->tenant_id)->whereIn('id',array_unique(array_filter($ids)))->where('id','!=',auth()->id()??0)->get() as $user){
            if(!app(BoardAccess::class)->canView($user,$board))continue;
            $id=(string)Str::uuid();
            // Durable bell entry is committed alongside the task/comment, before broadcasting.
            Notification::make($id)->title(e($titulo))->body('Abra a tarefa para ver a atualização.')
                ->icon('heroicon-o-clipboard-document-check')->actions([Action::make('abrir')->label('Abrir tarefa')->url($url)->markAsRead()])->sendToDatabase($user);
            DB::table('board_notice_outbox')->insert(['id'=>$id,'tenant_id'=>$item->tenant_id,'board_item_id'=>$item->id,'user_id'=>$user->id,'title'=>$titulo,'url'=>$url,'available_at'=>now(),'created_at'=>now()]);
            DB::afterCommit(function()use($id){
                // Sync environments leave delivery to the scheduled outbox processor.
                if(config('queue.default')==='sync')return;
                try{\App\Jobs\DeliverTaskNoticeJob::dispatch($id);}
                catch(\Throwable $e){Log::warning('Task notice enqueue deferred',['notification_id'=>$id,'exception'=>$e::class]);}
            });
        }
    }
}
