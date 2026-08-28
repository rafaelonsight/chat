<?php
namespace App\Support;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage,RateLimiter};
use Illuminate\Validation\ValidationException;
class StoreTaskMedia {
 public function store(int $tenant,UploadedFile $file,string $folder,callable $persist):mixed {
  $key='task-media:'.auth()->id();
  if(RateLimiter::hit($key,60)>10)throw ValidationException::withMessages(['arquivo'=>'Limite de dez arquivos por minuto. Aguarde e tente novamente.']);
  app(ScanTaskMedia::class)->check($file);
  $path=null;
  try {
   return DB::transaction(function()use($tenant,$file,$folder,$persist,&$path){
    // Dedicated quota lock avoids locking a parent row referenced by board/outbox FKs.
    DB::select('select pg_advisory_xact_lock(?, ?)',[824061,$tenant]);
    Tenant::withoutGlobalScopes()->findOrFail($tenant);
    $used=(int)DB::table('board_attachments')->where('tenant_id',$tenant)->sum('size')+(int)DB::table('direct_messages')->where('tenant_id',$tenant)->sum('file_size');
    if($used+$file->getSize()>config('task_media.tenant_bytes'))throw ValidationException::withMessages(['arquivo'=>'Limite de armazenamento de anexos da conta atingido. Solicite uma revisão ao administrador.']);
    $path=$file->store($folder.'/'.$tenant,'local');
    if(!$path)throw ValidationException::withMessages(['arquivo'=>'Não foi possível salvar o arquivo.']);
    return $persist($path);
   });
  }catch(\Throwable $e){if($path)Storage::disk('local')->delete($path);throw $e;}
 }
}
