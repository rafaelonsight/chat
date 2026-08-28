<?php
namespace App\Support;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
class ScanTaskMedia {
 public function check(UploadedFile $file):void {
  try {
   $scan=new Process([config('task_media.scanner'),'--fdpass','--no-summary',$file->getRealPath()]);
   $scan->setTimeout(30);$scan->run();
   if($scan->getExitCode()===0)return;
   $reason=$scan->getExitCode()===1?'Arquivo bloqueado pela verificação de segurança.':'Verificação de arquivos indisponível. Tente novamente mais tarde.';
  }catch(\Throwable $e){$reason='Verificação de arquivos indisponível. Tente novamente mais tarde.';}
  throw ValidationException::withMessages(['arquivo'=>$reason]);
 }
}
