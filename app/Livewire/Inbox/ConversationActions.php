<?php
namespace App\Livewire\Inbox;
use App\Models\AttendanceAction;
use App\Services\AcoesAtendimento;
use Livewire\Attributes\Locked;
class ConversationActions extends \Livewire\Component {
 #[Locked] public int $conversationId;
 #[Locked] public ?int $selecionada=null;
 #[Locked] public ?int $solicitacaoId=null;
 #[Locked] public array $previa=[];
 #[Locked] public string $assinatura='';
 #[Locked] public string $chave='';
 #[Locked] public bool $painelAberto=false;
 public string $resultado='';public string $erro='';
 public function mount(int $conversationId):void {$this->conversationId=$conversationId;}
 public function selecionar(int $id):void {
  $this->fechar();$service=app(AcoesAtendimento::class);$c=$service->conversa($this->conversationId,auth()->user());
  $a=AttendanceAction::where('tenant_id',$c->tenant_id)->findOrFail($id);
  $this->previa=$service->preparar($a,$c,auth()->user());$this->assinatura=$service->assinatura($a,$this->previa);$this->chave=(string)\Illuminate\Support\Str::uuid();$this->selecionada=$id;
 }
 public function selecionarSolicitacao(int $id):void {
  $service=app(AcoesAtendimento::class);$c=$service->conversa($this->conversationId,auth()->user());
  $request=\Illuminate\Support\Facades\DB::table('ai_tool_runs')->where('tenant_id',$c->tenant_id)->where('conversation_id',$c->id)->where('status','pendente')->where('created_at','>=',now()->subDay())->where('id',$id)->first();abort_unless($request,404);
  $this->selecionar($request->attendance_action_id);$a=AttendanceAction::where('tenant_id',$c->tenant_id)->findOrFail($request->attendance_action_id);
  abort_unless(hash_equals($request->config_hash,\App\Services\FerramentasAgenteIA::configHash($a)),409,__('A configuração mudou. Solicite uma nova revisão.'));$this->solicitacaoId=$id;
 }
 public function recusarSolicitacao():void {
  abort_unless($this->solicitacaoId,422);$service=app(AcoesAtendimento::class);$c=$service->conversa($this->conversationId,auth()->user());$a=AttendanceAction::where('tenant_id',$c->tenant_id)->findOrFail($this->selecionada);abort_unless($service->permitida($a,$c,auth()->user()),403);
  \Illuminate\Support\Facades\DB::table('ai_tool_runs')->where('id',$this->solicitacaoId)->where('tenant_id',$c->tenant_id)->where('conversation_id',$c->id)->where('status','pendente')->update(['status'=>'recusada','user_id'=>auth()->id(),'updated_at'=>now()]);$this->fechar();
 }
 public function confirmar():void {
  abort_unless($this->selecionada,422);$service=app(AcoesAtendimento::class);$c=$service->conversa($this->conversationId,auth()->user());$a=AttendanceAction::where('tenant_id',$c->tenant_id)->findOrFail($this->selecionada);
  if($this->solicitacaoId){
   $request=\Illuminate\Support\Facades\DB::table('ai_tool_runs')->where('id',$this->solicitacaoId)->where('tenant_id',$c->tenant_id)->where('conversation_id',$c->id)->where('attendance_action_id',$a->id)->where('status','pendente')->where('created_at','>=',now()->subDay())->first();
   abort_unless($request&&$service->permitida($a,$c,auth()->user())&&hash_equals($request->config_hash,\App\Services\FerramentasAgenteIA::configHash($a)),409);
   abort_unless(\Illuminate\Support\Facades\DB::table('ai_tool_runs')->where('id',$request->id)->where('status','pendente')->update(['status'=>'executando','user_id'=>auth()->id(),'updated_at'=>now()]),409);
  }
  try{$this->resultado=$service->executar($a,$c,auth()->user(),$this->chave,$this->assinatura);$this->erro='';$this->painelAberto=$a->tipo==='pagina'&&$a->modo_pagina==='painel';if($this->solicitacaoId)\Illuminate\Support\Facades\DB::table('ai_tool_runs')->where('id',$this->solicitacaoId)->where('tenant_id',$c->tenant_id)->update(['status'=>'sucesso','updated_at'=>now()]);}
  catch(\RuntimeException $e){$this->erro=$e->getMessage();if($this->solicitacaoId)\Illuminate\Support\Facades\DB::table('ai_tool_runs')->where('id',$this->solicitacaoId)->where('tenant_id',$c->tenant_id)->update(['status'=>'falha','updated_at'=>now()]);}
 }
 public function fechar():void {$this->reset('solicitacaoId','selecionada','previa','assinatura','chave','resultado','erro','painelAberto');}
 public function render(){
  $service=app(AcoesAtendimento::class);$c=$service->conversa($this->conversationId,auth()->user());
  $actions=AttendanceAction::where('tenant_id',$c->tenant_id)->where('ativa',true)->with('integration')->orderBy('ordem')->orderBy('nome')->get()->filter(fn($a)=>$service->permitida($a,$c,auth()->user()));
  $pendentes=\Illuminate\Support\Facades\DB::table('ai_tool_runs')->where('tenant_id',$c->tenant_id)->where('conversation_id',$c->id)->where('status','pendente')->where('created_at','>=',now()->subDay())->whereIn('attendance_action_id',$actions->pluck('id'))->orderByDesc('id')->limit(5)->get();
  return view('livewire.inbox.conversation-actions',['pendentes'=>$pendentes,'acoes'=>$actions,'acao'=>$actions->firstWhere('id',$this->selecionada)]);
 }
}
