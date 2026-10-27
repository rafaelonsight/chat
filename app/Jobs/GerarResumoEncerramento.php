<?php
namespace App\Jobs;
use App\Models\{Conversation,User};
use App\Services\ResumoIA;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\Middleware\WithoutOverlapping;
class GerarResumoEncerramento implements ShouldQueue {
 use Queueable;
 public int $tries=3;
 public int $timeout=75;
 public array $backoff=[15,60];
 public function __construct(public int $encerramentoId) {$this->onQueue('resumos');}
 public function middleware(): array {return [(new WithoutOverlapping('resumo-fecho:'.$this->encerramentoId))->expireAfter(90)->releaseAfter(10)];}
 public function handle(ResumoIA $ia): void {
  $fim=DB::table('encerramentos_atendimento')->find($this->encerramentoId);
  if(!$fim || $fim->resumo_estado!=='preparando') return;
  TenantContext::runAs($fim->tenant_id,function()use($fim,$ia){
   $c=Conversation::findOrFail($fim->conversation_id);
   $u=User::withoutGlobalScopes()->findOrFail($fim->finalizado_por);
   $d=$ia->gerar($c,$u,(int)($fim->ultima_mensagem_id??0));
   DB::transaction(function()use($d){
    $f=DB::table('encerramentos_atendimento')->where('id',$this->encerramentoId)->lockForUpdate()->first();
    if($f->resumo_estado!=='preparando') return;
    DB::table('encerramentos_atendimento')->where('id',$f->id)->update($d+['resumo_estado'=>'pronto','updated_at'=>now()]);
    DB::table('emails_encerramento')->where('encerramento_id',$f->id)->where('estado','aguardando_resumo')->update(['estado'=>'pendente','updated_at'=>now()]);
   });
  });
 }
 public function failed(?\Throwable $e): void {
  DB::table('encerramentos_atendimento')->where('id',$this->encerramentoId)->where('resumo_estado','preparando')->update(['resumo_estado'=>'falhou','updated_at'=>now()]);
 }
}
