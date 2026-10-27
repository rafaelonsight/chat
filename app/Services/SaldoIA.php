<?php
namespace App\Services;
use App\Models\Tenant;use Illuminate\Support\Facades\DB;
class SaldoIA {
 public static function estado(Tenant $t):array {$tz=$t->fuso_horario ?: 'America/Sao_Paulo';$usados=(int)DB::table('consumos_ia')->where('tenant_id',$t->id)->whereBetween('created_at',[now()->timezone($tz)->startOfMonth()->timezone(config('app.timezone')),now()->timezone($tz)->endOfMonth()->timezone(config('app.timezone'))])->sum('total');$franquia=(int)$t->franquia_ia;return ['usados'=>$usados,'franquia'=>$franquia,'mensal'=>max(0,$franquia-$usados),'extras'=>(int)$t->extras_ia,'disponiveis'=>max(0,$franquia-$usados)+(int)$t->extras_ia];}
 public static function executar(Tenant $t,int $reserva,callable $chamada):array {
  return DB::transaction(function()use($t,$reserva,$chamada){DB::select('SELECT pg_advisory_xact_lock(7391, ?)',[$t->id]);$atual=Tenant::withoutGlobalScopes()->findOrFail($t->id);$antes=self::estado($atual);if($antes['disponiveis']<$reserva)throw new \RuntimeException(__('Saldo de IA insuficiente para esta operação. Solicite mais tokens ao administrador.'));
   $id=(int)DB::table('consumos_ia')->where('tenant_id',$t->id)->max('id');$erro=null;$retorno='';try{$retorno=$chamada();}catch(\Throwable $e){$erro=$e;}
   $uso=DB::table('consumos_ia')->where('tenant_id',$t->id)->where('id','>',$id)->get();$total=(int)$uso->sum('total');$desconhecido=$uso->contains(fn($u)=>$u->total===null);if($desconhecido){$total=max($total,$reserva);foreach($uso->whereNull('total') as $u)DB::table('consumos_ia')->where('id',$u->id)->update(['total'=>$reserva,'estimado'=>true]);}
   $extra=max(0,$total-$antes['mensal']);if($extra)$atual->forceFill(['extras_ia'=>max(0,(int)$atual->extras_ia-$extra)])->save();
   $depois=self::estado($atual->fresh());$percentual=$depois['franquia']>0?$depois['usados']/$depois['franquia']*100:100;
   foreach([80,95,100] as $limite){if($percentual<$limite)continue;$chave='ia_aviso_'.$t->id.'_'.now()->timezone($atual->fuso_horario ?: 'America/Sao_Paulo')->format('Y-m').'_'.$limite;
    if(\App\Models\SystemSetting::ler($chave))continue;
    $admins=\App\Models\User::withoutGlobalScopes()->where('tenant_id',$t->id)->where('perfil','admin')->get();
    foreach($admins as $admin){$aviso=\Filament\Notifications\Notification::make()->warning()->title('Créditos Virtus IA: '.$limite.'% da franquia')->body('Confira o saldo e o consumo de IA da sua conta.');$admin->notifyNow(new \Filament\Notifications\DatabaseNotification($aviso->getDatabaseMessage()));}
    \App\Models\SystemSetting::gravar($chave,'1');
   }
   // Preserve o registro de chamadas consumidas mesmo quando a resposta é inválida.
   return ['retorno'=>$retorno,'erro'=>$erro];
  });
 }
}
