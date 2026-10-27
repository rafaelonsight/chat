<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Message;
use App\Services\TranscriptionService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class TranscribeAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [30, 120];

    public const FILA = 'transcricao';

    public function __construct(public int $messageId)
    {
        // Transcrever um audio de 30s ocupa o worker por dezenas de segundos.
        // Na mesma fila da entrega, cinco audios seguidos atrasam mensagem
        // nova de cliente — que e a coisa que nunca pode esperar.
        $this->onQueue(self::FILA);
    }

    // Uma transcricao por vez em toda a instalacao. O servidor whisper ja limita
    // as threads, mas sem isto varios workers ficariam bloqueados esperando HTTP
    // enquanto o resto da fila para.
    public function middleware(): array
    {
        return [(new WithoutOverlapping('transcricao'))->releaseAfter(20)->expireAfter(600)];
    }

    public function handle(TranscriptionService $transcricao): void
    {
        $mensagem = Message::withoutGlobalScope('tenant')->find($this->messageId);

        if (! $mensagem || $mensagem->tipo !== 'audio' || ! $mensagem->media_path) {
            return;
        }

        if ($mensagem->transcricao_status === 'pronta') {
            return;
        }

        TenantContext::runAs($mensagem->tenant_id, function () use ($mensagem, $transcricao) {
            $segundos=$transcricao->duracao(\Illuminate\Support\Facades\Storage::disk('local')->path($mensagem->media_path));
            if(!$segundos || $segundos<=0){$mensagem->update(['transcricao_status'=>'falhou']);return;}
            $creditos=(int)ceil($segundos/60*max(1,(int)\App\Models\SystemSetting::ler('ia_creditos_minuto_audio','1000')));
            try {
                $resultado=\App\Services\SaldoIA::executar($mensagem->tenant,$creditos,function()use($mensagem,$transcricao,$creditos,$segundos){
                    $db=\Illuminate\Support\Facades\DB::table('consumos_ia');
                    if($db->where('message_id',$mensagem->id)->exists())return 'registrada';
                    $r=$transcricao->transcrever($mensagem->media_path);
                    $mensagem->update(['transcricao'=>$r['texto'],'transcricao_status'=>$r['status']]);
                    if($r['status']==='pronta')\Illuminate\Support\Facades\DB::table('consumos_ia')->insert(['tenant_id'=>$mensagem->tenant_id,'user_id'=>null,'recurso'=>'transcricao','provedor'=>'whisper_local','modelo'=>'whisper','entrada'=>null,'saida'=>null,'total'=>$creditos,'message_id'=>$mensagem->id,'segundos_audio'=>$segundos,'created_at'=>now()]);
                    return $r['status'];
                });
                if($resultado['erro'])throw $resultado['erro'];
            }catch(\RuntimeException $e){$mensagem->update(['transcricao_status'=>'ignorada']);return;}
            $r=['texto'=>$mensagem->fresh()->transcricao,'status'=>$mensagem->fresh()->transcricao_status];

            $mensagem->update([
                'transcricao'        => $r['texto'],
                'transcricao_status' => $r['status'],
            ]);

            // A tela mostra a transcricao embaixo do player: precisa saber que
            // chegou, senao o atendente so ve depois de recarregar.
            if ($r['status'] === 'pronta') {
                broadcast(new MessageStored($mensagem->refresh()));
            }
        });
    }
}
