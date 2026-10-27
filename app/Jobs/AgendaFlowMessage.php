<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;use Illuminate\Foundation\Queue\Queueable;
class AgendaFlowMessage implements ShouldQueue {
 use Queueable;public int $tries=3;public array $backoff=[5,15,30];
 public function __construct(public int $messageId){}
 public function handle():void {app(\App\Services\AgendaGuidedFlow::class)->receive($this->messageId);}
}
