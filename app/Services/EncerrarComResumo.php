<?php
namespace App\Services;
use App\Models\{Conversation,User};
use Illuminate\Support\Facades\DB;
class EncerrarComResumo {
 public function registrar(Conversation $conversa,array $dados,User $usuario): int {
  abort_if($usuario->ehAuditor(),403);
  abort_unless($usuario->podeVer($conversa),403);
  abort_if($conversa->contact->tipo === \App\Models\Contact::GRUPO,422,'Grupos não são atendimentos individuais.');
  \Illuminate\Support\Facades\Validator::make($dados,['avaliacao_cliente'=>'nullable|integer|between:1,5','enviar_resumo_cliente'=>'boolean','enviar_pesquisa'=>'boolean'])->validate();
  return DB::transaction(function() use($conversa,$dados,$usuario){
   $atual=Conversation::whereKey($conversa->id)->lockForUpdate()->firstOrFail();
   $ultimo=DB::table('encerramentos_atendimento')->where('conversation_id',$atual->id);
   if ($atual->status===Conversation::ARQUIVADA && $id=(clone $ultimo)->max('id')) return $id;
   $versao=((clone $ultimo)->max('versao')??0)+1;
   $email=trim($dados['email_cliente']??$atual->contact->email??'');
   if ($email) $atual->contact->update(['email'=>$email]);
   $responsavel=$atual->atendente ?? $usuario;
   if($responsavel->tenant_id!==$atual->tenant_id) $responsavel=$usuario;
   $inicio=$atual->created_at;
   $anterior=(clone $ultimo)->max('created_at');
   if($anterior) $inicio=\Illuminate\Support\Carbon::parse($anterior);
   $msgs=$atual->messages()->where('created_at','>=',$inicio)->whereNull('apagada_em');
   $entrada=(clone $msgs)->where('direcao','in')->orderBy('id')->first();
   $resposta=(clone $msgs)->where('direcao','out')->where('automatica',false)->whereNotNull('user_id')->whereIn('status',['sent','delivered','read'])->orderBy('id')->first();
   $primeira=$resposta?->enviada_em ?? $resposta?->created_at;
   $espera=$entrada && $primeira ? max(0,(int)$entrada->created_at->diffInSeconds($primeira,false)) : null;
   $duracao=$primeira ? max(0,(int)$primeira->diffInSeconds(now(),false)) : null;
   $id=DB::table('encerramentos_atendimento')->insertGetId(['responsavel_id'=>$responsavel->id,'responsavel_nome'=>$responsavel->name,'canal_id'=>$atual->channel_id,'equipe_id'=>$atual->team_id,'espera_segundos'=>$espera,'atendimento_segundos'=>$duracao,
    'tenant_id'=>$atual->tenant_id,'conversation_id'=>$atual->id,'versao'=>$versao,'protocolo'=>$atual->protocolo,'finalizado_por'=>$usuario->id,
    'resumo_estado'=>($dados['automatico']??false)?'preparando':'pronto','ultima_mensagem_id'=>$atual->messages()->max('id'),
    'avaliacao_cliente'=>$dados['avaliacao_cliente']??null,'assunto'=>$dados['assunto'],'resumo'=>$dados['resumo'],'solucao'=>$dados['solucao']??null,'pendencias'=>$dados['pendencias']??null,
    'dados'=>json_encode(['cliente'=>$atual->contact->nomeExibicao(),'atendente'=>$atual->atendente?->nomePublico()??$usuario->nomePublico(),'empresa'=>$atual->tenant?->nome??'Virtus Chat','inicio'=>$atual->created_at->toIso8601String(),'fim'=>now()->toIso8601String(),'enviar_resumo_cliente'=>(bool)($dados['enviar_resumo_cliente']??true),'enviar_pesquisa'=>(bool)($dados['enviar_pesquisa']??false)]),'created_at'=>now(),'updated_at'=>now(),
   ]);
   $empresa=$atual->tenant?->email_resumos;
   if(!$empresa) $empresa=User::withoutGlobalScopes()->where('tenant_id',$atual->tenant_id)->where('admin',true)->orderBy('id')->value('email');
   $vistos=[];
   foreach(['cliente'=>$email,'atendente'=>$usuario->email,'administrador'=>$empresa] as $tipo=>$destino) {
    if($tipo==='cliente' && !($dados['enviar_resumo_cliente']??true)) {
     DB::table('emails_encerramento')->insert(['encerramento_id'=>$id,'destinatario_tipo'=>'cliente','email'=>$email?:null,'estado'=>'nao_solicitado','tentativas'=>0,'created_at'=>now(),'updated_at'=>now()]);
     continue;
    }
    $destino=mb_strtolower(trim((string)$destino));
    if($destino && isset($vistos[$destino])) continue;
    if($destino) $vistos[$destino]=true;
    DB::table('emails_encerramento')->insert(['encerramento_id'=>$id,'destinatario_tipo'=>$tipo,'email'=>$destino?:null,'estado'=>$destino?(($dados['automatico']??false)?'aguardando_resumo':'pendente'):'sem_email','tentativas'=>0,'created_at'=>now(),'updated_at'=>now()]);
   }
   $atual->arquivar();
   if($dados['automatico']??false) \App\Jobs\GerarResumoEncerramento::dispatch($id)->afterCommit();
   return $id;
  });
 }
}
