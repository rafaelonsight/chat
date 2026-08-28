<?php
namespace App\Support;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class InternalMediaValidation
{
    public static function check($file):void
    {
        $mime=$file->getMimeType();
        if(!str_starts_with($mime,'audio/') && !in_array($mime,['video/webm','video/mp4']))return;
        $probe=new Process(['/usr/bin/ffprobe','-v','error','-show_entries','format=duration','-of','default=noprint_wrappers=1:nokey=1',$file->getRealPath()]);
        $probe->setTimeout(10);
        try{$probe->run();$duration=trim($probe->getOutput());}
        catch(\Throwable){throw ValidationException::withMessages(['arquivo'=>'Não foi possível validar o áudio. Tente outro formato.']);}
        if(!$probe->isSuccessful() || !is_numeric($duration) || (float)$duration<=0 || (float)$duration>301){
            throw ValidationException::withMessages(['arquivo'=>'Envie um áudio válido de até 5 minutos.']);
        }
    }
}
