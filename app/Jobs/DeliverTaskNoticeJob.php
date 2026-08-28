<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class DeliverTaskNoticeJob implements ShouldQueue {
 use Queueable;
 public int $tries=3;
 public int $timeout=30;
 public function __construct(public string $noticeId){}
 public function handle():void {app(\App\Support\DeliverTaskNotice::class)->deliver($this->noticeId);}
}
