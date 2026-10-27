<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Mail,Cache};
class EnviarResumosAtendimento extends Command {
 protected $signature='virtus:enviar-resumos';
 protected $description='Entrega resumos de atendimentos, com tentativas independentes por destinatário';
 public function handle(): int {
  return Cache::lock('virtus:enviar-resumos',300)->get(function(){
   // Sem contexto global: cada destinatário está ligado ao snapshot do seu encerramento.
   DB::table('emails_encerramento')->where('estado','sem_email')->where('destinatario_tipo','cliente')->orderBy('id')->limit(100)->get()->each(function($email){
    $fim=DB::table('encerramentos_atendimento')->find($email->encerramento_id);
    $conversa=DB::table('conversations')->find($fim->conversation_id);
    $contato=DB::table('contacts')->where('id',$conversa->contact_id)->where('tenant_id',$fim->tenant_id)->first();
    if ($fim->resumo_estado==='pronto' && $contato && filter_var($contato->email,FILTER_VALIDATE_EMAIL)) DB::table('emails_encerramento')->where('id',$email->id)->update(['email'=>$contato->email,'estado'=>'pendente','updated_at'=>now()]);
   });
   $emails=DB::table('emails_encerramento')->where('estado','pendente')->where(fn($q)=>$q->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em','<=',now()))->orderBy('id')->limit(30)->get();
   foreach($emails as $email){
    $fim=DB::table('encerramentos_atendimento')->find($email->encerramento_id);$dados=json_decode($fim->dados,true);
    if($fim->resumo_estado!=='pronto') continue;
    try {
     $conta=\App\Models\Tenant::findOrFail($fim->tenant_id);
     $dados['fuso']=$conta->fuso_horario ?: 'Europe/London';
     \App\Support\Idiomas::noIdioma(\App\Support\Idiomas::comunicacoes($conta),function()use($email,$fim,$dados){
     Mail::mailer(config('acesso.mailer'))->send(['html'=>'emails.resumo-atendimento','text'=>'emails.resumo-atendimento-texto'],['fim'=>$fim,'dados'=>$dados],fn($m)=>$m->to($email->email)->subject(__('ui_98502c6b783b').' '.$fim->protocolo.' — '.$dados['empresa']));
     });
     DB::table('emails_encerramento')->where('id',$email->id)->update(['estado'=>'enviado','tentativas'=>$email->tentativas+1,'enviado_em'=>now(),'updated_at'=>now()]);
    } catch(\Throwable $e){
     DB::table('emails_encerramento')->where('id',$email->id)->update(['tentativas'=>$email->tentativas+1,'estado'=>$email->tentativas>=4?'falhou':'pendente','proxima_tentativa_em'=>now()->addMinutes(5),'updated_at'=>now()]);
     logger()->warning('Falha no envio de resumo',['email_encerramento_id'=>$email->id,'tipo'=>get_class($e)]);
    }
   }
   return self::SUCCESS;
  }) ?? self::SUCCESS;
 }
}
