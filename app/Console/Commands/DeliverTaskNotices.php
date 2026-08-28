<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class DeliverTaskNotices extends Command {
 protected $signature='virtus:task-notices';
 protected $description='Retry pending task realtime notices; persistent bell entries already exist.';
 public function handle():int {
  $ids=DB::table('board_notice_outbox')->whereNull('delivered_at')->where('available_at','<=',now())->orderBy('created_at')->limit(100)->pluck('id');
  foreach($ids as $id)app(\App\Support\DeliverTaskNotice::class)->deliver($id);
  $this->info('Processed '.$ids->count().' pending task notices.');return self::SUCCESS;
 }
}
