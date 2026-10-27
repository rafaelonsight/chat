<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\AlertasQualidade;
class AlertarQualidade extends Command {
 protected $signature='virtus:alertar-qualidade';protected $description='Reconcilia avisos de notas baixas sem duplicar notificações';
 public function handle(AlertasQualidade $alertas): int {
  DB::table('encerramentos_atendimento as f')->whereBetween('f.satisfacao',[1,2])->whereRaw("NOT EXISTS (SELECT 1 FROM revisoes_qualidade r WHERE r.encerramento_id=f.id AND r.tenant_id=f.tenant_id AND r.estado='concluida' AND r.id=(SELECT MAX(z.id) FROM revisoes_qualidade z WHERE z.encerramento_id=f.id AND z.tenant_id=f.tenant_id))")->select('f.id','f.tenant_id')->orderBy('f.id')->chunkById(100,function($fs)use($alertas){foreach($fs as $f)if(\App\Support\Modulos::ativo('qualidade',(int)$f->tenant_id))$alertas->avisar($f->id);},'f.id','id');
  return self::SUCCESS;
 }
}
