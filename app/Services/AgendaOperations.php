<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\{DB,Http,Queue,Redis};
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

class AgendaOperations
{
    public const LABELS = [
        'database'=>'Banco de dados','redis'=>'Fila e cache','worker'=>'Processamento de mensagens','php'=>'Painel',
        'evolution'=>'Serviço do WhatsApp','disk'=>'Espaço em disco','queue'=>'Mensagens aguardando envio',
        'jobs'=>'Falhas recentes no processamento','backup'=>'Backup local','external'=>'Cópia externa',
        'whatsapp'=>'Conexão do WhatsApp','monitor'=>'Monitoramento','automations'=>'Campanhas e lembretes',
    ];

    public function state(): array
    {
        $path=config('agenda.operations_path','/var/lib/virtus-agenda-operations');
        $read=static function(string $file):array {
            if(!is_file($file) || filesize($file)>1048576)return [];
            return json_decode((string)file_get_contents($file),true)?:[];
        };
        return ['monitor'=>$read($path.'/monitor.json'),'backup'=>$read($path.'/backup.json')];
    }

    public function probe(): array
    {
        $result=['checks'=>[],'metrics'=>[]];
        try {
            DB::select('select 1');$result['checks']['database']=['state'=>'ok'];
            $result['metrics']['failed_job_id']=(int)DB::table('failed_jobs')->max('id');
            $oldest=DB::table('messages')->where('direcao','out')->where('status','queued')->min('created_at');
            $result['metrics']['oldest_queued_age_seconds']=$oldest?max(0,(int)\Carbon\CarbonImmutable::parse($oldest,config('app.timezone'))->diffInSeconds(now(),false)):0;
            foreach(DB::table('agenda_channel_connections')->where('type','evolution')->select('id','tenant_id','public_id','status','channel_id')->get() as $c) {
                $check=['state'=>'info','tenant_id'=>$c->tenant_id];
                if($c->channel_id && in_array($c->status,['conectado','conectando'],true)) {
                    try {
                        if(!config('agenda.evolution_enabled') || config('agenda.evolution_url')!=='http://127.0.0.1:8085')throw new \RuntimeException('Disabled');
                        $r=Http::baseUrl(config('agenda.evolution_url'))->withHeaders(['apikey'=>config('agenda.evolution_key')])
                            ->withoutRedirecting()->timeout(3)->get('/instance/connectionState/agenda-'.str_replace('-','',$c->public_id));
                        $check['state']=$r->successful() && $r->json('instance.state')==='open'?'ok':'critical';
                    }catch(\Throwable){$check['state']='critical';}
                }
                $result['checks']['whatsapp:'.$c->tenant_id.':'.$c->id]=$check;
            }
        }catch(\Throwable){$result['checks']['database']=['state'=>'critical'];}
        try {Redis::connection()->ping();$result['checks']['redis']=['state'=>'ok'];$result['metrics']['queue_size']=array_sum(array_map(fn($queue)=>Queue::connection('redis')->size($queue),['agenda-inbox','agenda-reminders','agenda-campaigns']));}
        catch(\Throwable){$result['checks']['redis']=['state'=>'critical'];}
        return $result;
    }

    public function health(): bool
    {
        try {DB::select('select 1');Redis::connection()->ping();}catch(\Throwable){return false;}
        $monitor=$this->state()['monitor'];
        try {
            if(empty($monitor['checked_at']) || \Carbon\CarbonImmutable::parse($monitor['checked_at'])->lt(now()->subMinutes(6)))return false;
        }catch(\Throwable){return false;}
        foreach($monitor['checks']??[] as $key=>$c) {
            if(in_array(explode(':',$key)[0],['database','redis','php','worker','evolution','disk','queue','backup','automations'],true) && ($c['state']??null)==='critical')return false;
        }
        return true;
    }

    public function notify(array $changes): int
    {
        $count=0;
        foreach(array_slice($changes,0,100) as $change) {
            $code=explode(':',(string)($change['key']??''))[0];
            if(!isset(self::LABELS[$code]) || !in_array($change['state']??'', ['ok','warning','critical'],true))continue;
            $tenant=$change['tenant_id']??null;
            if($code==='whatsapp' && !$tenant)continue;
            $users=User::withoutGlobalScopes()->where('agenda_access_status','ativo')->where('operador',false)
                ->where(fn($q)=>$q->where('admin',true)->orWhere('perfil','admin'))->when($tenant,fn($q)=>$q->where('tenant_id',(int)$tenant))->get();
            foreach($users as $user) {
                $ok=$change['state']==='ok';
                $n=Notification::make()->title(($ok?'Normalizado: ':'Atenção: ').self::LABELS[$code])
                    ->body($ok?'O serviço voltou ao estado normal.':'Confira Configurações → Saúde e backup. A equipe técnica pode consultar o diagnóstico sem expor dados dos clientes.');
                $ok?$n->success():$n->warning();
                // Direct database insert works even if the queue worker is down. No email or broadcast dependency.
                DB::table('notifications')->insert(['id'=>(string)Str::uuid(),'type'=>\Filament\Notifications\DatabaseNotification::class,
                    'notifiable_type'=>$user->getMorphClass(),'notifiable_id'=>$user->id,'data'=>json_encode($n->getDatabaseMessage()),'created_at'=>now(),'updated_at'=>now()]);
                $count++;
            }
        }
        return $count;
    }
}
