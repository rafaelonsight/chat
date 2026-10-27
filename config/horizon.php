<?php
return [
 'name'=>'Virtus Agenda','path'=>'horizon','use'=>'default','prefix'=>env('HORIZON_PREFIX','virtus_agenda_horizon:'),
 'middleware'=>['web','auth',\App\Http\Middleware\RequireAgendaTenant::class],
 'waits'=>['redis:agenda'=>60],
 'trim'=>['recent'=>60,'pending'=>60,'completed'=>60,'recent_failed'=>10080,'failed'=>10080,'monitored'=>10080],
 'silenced'=>[],'metrics'=>['trim_snapshots'=>['job'=>24,'queue'=>24]],'fast_termination'=>false,'memory_limit'=>128,
 'defaults'=>['agenda'=>['connection'=>'redis','queue'=>['agenda'],'balance'=>'simple','maxProcesses'=>1,'maxTime'=>0,'maxJobs'=>0,'memory'=>256,'tries'=>3,'timeout'=>60,'nice'=>5]],
 'environments'=>['staging'=>['agenda'=>[]],'production'=>['agenda'=>[]],'local'=>['agenda'=>[]]],
 'watch'=>['app','config','routes','composer.lock'],
];
