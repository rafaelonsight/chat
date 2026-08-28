<?php
namespace App\Support;
use App\Models\{User,Funnel};
use App\Events\AvisoColaboracao;
use Illuminate\Support\Facades\{DB,Log};
class DeliverTaskNotice {
 public function deliver(string $id):void {
  DB::transaction(function()use($id){
   $row=DB::table('board_notice_outbox')->where('id',$id)->lockForUpdate()->first();
   if(!$row||$row->delivered_at||\Illuminate\Support\Carbon::parse($row->available_at)->isFuture())return;
   $user=User::withoutGlobalScopes()->where('tenant_id',$row->tenant_id)->find($row->user_id);
   $item=DB::table('board_items')->where('tenant_id',$row->tenant_id)->find($row->board_item_id);
   $board=$item?Funnel::withoutGlobalScopes()->where('tenant_id',$row->tenant_id)->find($item->funnel_id):null;
   if(!$user||!$board||!app(BoardAccess::class)->canView($user,$board)){
    DB::table('board_notice_outbox')->where('id',$id)->update(['delivered_at'=>now()]);return;
   }
   try {
    event(new AvisoColaboracao($row->user_id,$row->id,$row->title,$row->url));
    DB::table('board_notice_outbox')->where('id',$id)->update(['delivered_at'=>now(),'attempts'=>$row->attempts+1]);
   }catch(\Throwable $e){
    DB::table('board_notice_outbox')->where('id',$id)->update(['attempts'=>$row->attempts+1,'available_at'=>now()->addSeconds(min(3600,60*max(1,$row->attempts+1)))]);
    Log::warning('Task realtime delivery deferred',['notification_id'=>$id,'exception'=>$e::class]);
   }
  });
 }
}
