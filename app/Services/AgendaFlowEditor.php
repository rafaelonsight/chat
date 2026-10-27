<?php
namespace App\Services;
use App\Models\{User,Contact};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class AgendaFlowEditor {
 const TYPES=['welcome'=>'Boas-vindas','identify'=>'Identificar cliente','message'=>'Enviar mensagem','question'=>'Perguntar','menu'=>'Oferecer opções','condition'=>'Condição da resposta','booking'=>'Agendamento completo','services'=>'Escolher serviço','preferred'=>'Dia e horário desejados','available'=>'Disponibilidade e alternativas','free_professionals'=>'Barbeiros disponíveis','professionals'=>'Escolher profissional','date'=>'Escolher dia','times'=>'Consultar horários','review'=>'Revisar e confirmar','manage'=>'Meus agendamentos','human'=>'Atendimento humano','end'=>'Encerrar'];
 public function guard(User $u):void {app(AgendaCalendar::class)->administrator($u);}
 public function find(User $u,int $id):object {$this->guard($u);return DB::table('agenda_editor_flows')->where('tenant_id',$u->tenant_id)->whereNull('archived_at')->where('id',$id)->firstOrFail();}
 public function delete(User $u,int $id):void {DB::transaction(function()use($u,$id){$this->guard($u);DB::table('tenants')->where('id',$u->tenant_id)->lockForUpdate()->first();$this->find($u,$id);$revisions=DB::table('agenda_editor_revisions')->where('tenant_id',$u->tenant_id)->where('flow_id',$id)->pluck('id');DB::table('agenda_flow_settings')->where('tenant_id',$u->tenant_id)->whereIn('editor_revision_id',$revisions)->update(['enabled'=>false,'updated_at'=>now()]);DB::table('agenda_editor_flows')->where('tenant_id',$u->tenant_id)->where('id',$id)->update(['archived_at'=>now(),'updated_at'=>now()]);});}
 public static function model():array {return [
  ['id'=>'inicio','type'=>'identify','title'=>'Boas-vindas','text'=>'Olá, {nome}! Bem-vindo a {estabelecimento}.','next'=>'menu'],
  ['id'=>'menu','type'=>'menu','title'=>'Como podemos ajudar?','text'=>'Escolha uma opção:','options'=>[['label'=>'Agendar horário','to'=>'agendar'],['label'=>'Meu agendamento','to'=>'consultar'],['label'=>'Falar com a equipe','to'=>'equipe']]],
  ['id'=>'agendar','type'=>'services','title'=>'Escolher serviço','text'=>'Vamos encontrar o serviço ideal para você.','next'=>'profissional'],
  ['id'=>'profissional','type'=>'professionals','title'=>'Escolher profissional','text'=>'','next'=>'dia'],
  ['id'=>'dia','type'=>'date','title'=>'Escolher dia','text'=>'','next'=>'horarios'],
  ['id'=>'horarios','type'=>'times','title'=>'Consultar horários','text'=>'Confira os horários disponíveis.','next'=>'confirmar'],
  ['id'=>'confirmar','type'=>'review','title'=>'Revisar e confirmar','text'=>'Confira os dados antes de confirmar.','next'=>'fim'],
  ['id'=>'consultar','type'=>'manage','title'=>'Consultar, remarcar ou cancelar','text'=>'Vamos consultar seus agendamentos.','next'=>'fim'],
  ['id'=>'equipe','type'=>'human','title'=>'Atendimento humano','text'=>'Vou chamar a equipe para ajudar você.'],
  ['id'=>'fim','type'=>'end','title'=>'Até breve','text'=>'Obrigado! Digite MENU quando precisar.'],
 ];}
 public static function barbershop():array {return [
  ['id'=>'boas_vindas','type'=>'welcome','title'=>'Boas-vindas','text'=>'Bem-vindo(a) à {estabelecimento}!','next'=>'menu','template'=>'barbershop_requested_v1'],
  ['id'=>'menu','type'=>'menu','title'=>'Como podemos ajudar?','text'=>'','options'=>[['label'=>'Quero agendar','to'=>'cliente'],['label'=>'Meu agendamento','to'=>'consultar'],['label'=>'Atendimento','to'=>'equipe']]],
  ['id'=>'cliente','type'=>'identify','title'=>'Consultar cliente no CRM','text'=>'Perfeito, {nome}! Vamos consultar a disponibilidade.','name_prompt'=>'Qual seu nome para eu gravar aqui no sistema?','next'=>'servico'],
  ['id'=>'servico','type'=>'services','title'=>'Escolher serviço','text'=>'Primeiro, escolha o serviço para calcular o tempo do atendimento.','next'=>'preferencia'],
  ['id'=>'preferencia','type'=>'preferred','title'=>'Dia e horário desejados','text'=>'Perfeito, {nome}! Qual dia e horário gostaria para eu consultar a disponibilidade?\nExemplo: quarta às 17h.','next'=>'disponibilidade'],
  ['id'=>'disponibilidade','type'=>'available','title'=>'Consultar a agenda','text'=>'','next'=>'barbeiro'],
  ['id'=>'barbeiro','type'=>'free_professionals','title'=>'Escolher barbeiro disponível','text'=>'Qual {profissional} você deseja?','next'=>'confirmar'],
  ['id'=>'confirmar','type'=>'review','title'=>'Revisar e confirmar','text'=>'Confira os dados antes de confirmar.','next'=>'fim'],
  ['id'=>'consultar','type'=>'manage','title'=>'Meu agendamento','text'=>'Vamos consultar seu agendamento.','next'=>'fim'],
  ['id'=>'equipe','type'=>'human','title'=>'Atendimento','text'=>'Vou chamar a equipe para atender você.'],
  ['id'=>'fim','type'=>'end','title'=>'Agendamento concluído','text'=>'Obrigado! Digite MENU quando precisar.'],
 ];}
 public function create(User $u,string $name,bool $model=true):int {$this->guard($u);return DB::table('agenda_editor_flows')->insertGetId(['tenant_id'=>$u->tenant_id,'name'=>$name,'draft'=>json_encode($model?self::model():[['id'=>'inicio','type'=>'identify','title'=>'Início','text'=>'Olá, {nome}!','next'=>'fim'],['id'=>'fim','type'=>'end','title'=>'Encerrar','text'=>'Até breve!']]),'version'=>1,'created_at'=>now(),'updated_at'=>now()]);}
 public function save(User $u,int $id,int $version,string $name,array $graph):int {$this->find($u,$id);validator(['name'=>$name,'graph'=>$graph],['name'=>'required|string|max:120','graph'=>'required|array|min:2|max:50','graph.*.id'=>'required|string|max:80','graph.*.type'=>'required|in:'.implode(',',array_keys(self::TYPES)),'graph.*.title'=>'required|string|max:120','graph.*.text'=>'nullable|string|max:1200','graph.*.options'=>'sometimes|array|max:10','graph.*.options.*.label'=>'required|string|max:120','graph.*.options.*.to'=>'present|string|max:80','graph.*.next'=>'sometimes|string|max:80','graph.*.yes'=>'sometimes|string|max:80','graph.*.no'=>'sometimes|string|max:80','graph.*.name_prompt'=>'sometimes|string|max:400','graph.*.equals'=>'sometimes|string|max:200','graph.*.x'=>'sometimes|integer|min:0|max:6000','graph.*.y'=>'sometimes|integer|min:0|max:4000'])->validate();if(strlen(json_encode($graph))>80000)throw ValidationException::withMessages(['graph'=>'Fluxo muito grande.']);$ok=DB::table('agenda_editor_flows')->where('tenant_id',$u->tenant_id)->where('id',$id)->where('version',$version)->update(['name'=>$name,'draft'=>json_encode($graph),'version'=>$version+1,'updated_at'=>now()]);if(!$ok)throw ValidationException::withMessages(['graph'=>'Este fluxo foi editado em outra janela. Reabra antes de salvar.']);return $version+1;}
 public function problems(array $g):array {
  $errors=[];$ids=array_column($g,'id');if(count($ids)!==count(array_unique($ids)))$errors[]='Existem blocos com identificadores repetidos.';
  if(!in_array($g[0]['type']??'',['identify','welcome']))$errors[]='Comece por Boas-vindas ou Identificar cliente.';
  foreach(['identify','services','professionals','date','times','review','preferred','available','free_professionals'] as $type)if(count(array_filter($g,fn($n)=>$n['type']===$type))>1)$errors[]='Use somente um bloco de cada etapa do agendamento.';
  foreach($g as $n){$id=$n['id']??'';if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$id)||!isset(self::TYPES[$n['type']??''])){$errors[]='Bloco inválido.';continue;}if(mb_strlen($n['text']??'')>1200)$errors[]="$id: mensagem muito longa.";
   preg_match_all('/\{([^}]+)\}/',$n['text']??'',$vars);foreach($vars[1] as $v)if(!in_array($v,['nome','estabelecimento','resposta','profissional']))$errors[]="$id: variável não permitida.";
   $edges=$this->edges($n);if($n['type']==='menu'&&(count($edges)<1||count($edges)>10))$errors[]="$id: informe de 1 a 10 opções.";
   foreach($edges as $to)if(!in_array($to,$ids,true))$errors[]="$id: selecione um destino válido.";
  }
  $map=array_column($g,null,'id');$seen=[];$stack=[];$visit=function($id)use(&$visit,&$seen,&$stack,&$errors,$map){if(isset($stack[$id])){$errors[]='Existe um ciclo. Use MENU para reiniciar a conversa.';return;}if(isset($seen[$id])||!isset($map[$id]))return;$seen[$id]=true;$stack[$id]=true;foreach($this->edges($map[$id]) as $to)$visit($to);unset($stack[$id]);};$visit($g[0]['id']??'');if(count($seen)!==count($g))$errors[]='Há blocos sem conexão com o início.';
  if(!$errors){$paths=0;$check=function($id,array $have)use(&$check,&$errors,&$paths,$map){if(++$paths>5000){$errors[]='Há ramificações demais para publicar.';return;}$n=$map[$id];$required=['professionals'=>'services','date'=>'professionals','times'=>'date','preferred'=>'services','available'=>'preferred','free_professionals'=>'available'];if(isset($required[$n['type']])&&!in_array($required[$n['type']],$have))$errors[]=$n['title'].': falta a etapa anterior de agendamento.';if($n['type']==='review'&&!in_array('times',$have)&&!in_array('free_professionals',$have))$errors[]=$n['title'].': falta consultar os horários antes da confirmação.';if(in_array($n['type'],['preferred','available','free_professionals'])&&!in_array('identify',$have))$errors[]=$n['title'].': identifique o cliente antes de agendar.';if($n['type']==='condition'&&!in_array('question',$have))$errors[]=$n['title'].': pergunte antes de avaliar a resposta.';$have[]=$n['type'];foreach($this->edges($n) as $to)$check($to,$have);};$check($g[0]['id'],[]);}
  return array_values(array_unique($errors));
 }
 private function edges(array $n):array {return in_array($n['type'],['end','human'])?[]:match($n['type']){'menu'=>array_column($n['options']??[],'to'),'condition'=>[$n['yes']??'',$n['no']??''],default=>[$n['next']??'']};}
 public function publish(User $u,int $id,int $version,int $channel):void {DB::transaction(function()use($u,$id,$version,$channel){DB::table('tenants')->where('id',$u->tenant_id)->lockForUpdate()->first();$f=$this->find($u,$id);if($f->version!==$version)throw ValidationException::withMessages(['graph'=>'Reabra o rascunho atualizado antes de publicar.']);$g=json_decode($f->draft,true);$errors=$this->problems($g);if($errors)throw ValidationException::withMessages(['graph'=>implode(' ',$errors)]);$flow=app(AgendaGuidedFlow::class);$cfg=$flow->settings($channel);$flow->save($u,$channel,['enabled'=>true,'business_name'=>$cfg?->business_name??\App\Models\Tenant::find($u->tenant_id)->nome,'greeting'=>$cfg?->greeting??'Como podemos ajudar?','address'=>$cfg?->address??'','handoff'=>$cfg?->handoff??'A equipe vai atender você.']);$rev=DB::table('agenda_editor_revisions')->where('tenant_id',$u->tenant_id)->where('flow_id',$id)->where('version',$version)->value('id');if(!$rev)$rev=DB::table('agenda_editor_revisions')->insertGetId(['tenant_id'=>$u->tenant_id,'flow_id'=>$id,'version'=>$version,'graph'=>$f->draft,'created_at'=>now()]);DB::table('agenda_flow_settings')->where('tenant_id',$u->tenant_id)->where('channel_id',$channel)->update(['editor_revision_id'=>$rev]);});}
 public function advance(User $u,object $cfg,int $contact,array $state,string $input,bool $preview=false,?array $draft=null):array {
  $this->guard($u);$control=AgendaFlowExperience::normalize($input);$back=$state['_back']??[];
  if($control==='voltar'){$last=array_pop($back);if(!$last)return ['editor',$state,'Você já está no início desta etapa. Digite MENU para reiniciar ou ATENDENTE para ajuda.'];$keep=array_intersect_key($state,array_flip(['name','preview_booking','_revision']));$state=$keep+$last['state'];$state['_back']=$back;$state['waiting']=true;$state['_prompt']=$last['text'];return ['editor',$state,$last['text']];}
  $before=$state;[$step,$after,$text]=$this->advanceCore($u,$cfg,$contact,$state,$input,$preview,$draft);
  if($step==='human')return app(AgendaFlowExperience::class)->human($u,$cfg,$after,$text);
  if($control==='menu')$back=[];
  if($step==='editor'&&!empty($after['waiting'])){if(!empty($before['waiting'])&&$control!=='menu'&&AgendaFlowExperience::snapshot($before)!==AgendaFlowExperience::snapshot($after)&&empty($after['_invalid_choice']))$back[]=['state'=>AgendaFlowExperience::snapshot($before),'text'=>$before['_prompt']??'Digite MENU para continuar.'];$after['_back']=array_slice($back,-10);$after['_prompt']=$text;}
  return [$step,$after,$text];
 }
 private function advanceCore(User $u,object $cfg,int $contact,array $state,string $input,bool $preview=false,?array $draft=null):array {
  $this->guard($u);$lower=mb_strtolower(trim($input));if(in_array($lower,['atendente','humano','equipe']))return ['human',$state,$cfg->handoff];
  if($lower==='menu')$state=$preview?array_intersect_key($state,array_flip(['name','preview_booking'])):[];
  if(!empty($state['_revision'])&&DB::table('agenda_editor_revisions as r')->join('agenda_editor_flows as f','f.id','=','r.flow_id')->where('r.tenant_id',$u->tenant_id)->where('r.id',$state['_revision'])->whereNotNull('f.archived_at')->exists())$state=[];
  $rev=$state['_revision']??$cfg->editor_revision_id;$graph=$draft;
  if(!$graph){$r=DB::table('agenda_editor_revisions')->where('tenant_id',$u->tenant_id)->where('id',$rev)->firstOrFail();$graph=json_decode($r->graph,true);}
  $map=array_column($graph,null,'id');$fresh=empty($state['node']);$state+=['_revision'=>$rev,'node'=>$graph[0]['id'],'waiting'=>false,'domain'=>[]];$out=[];
  $person=$contact?Contact::where('tipo','pessoa')->whereNull('anonimizado_em')->findOrFail($contact):null;
  $format=function($text)use(&$person,&$state,$cfg){return strtr($text,['{nome}'=>$person?->nome??($state['name']??'Cliente de teste'),'{estabelecimento}'=>$cfg->business_name,'{resposta}'=>$state['answer']??'','{profissional}'=>$cfg->professional_term??'profissional']);};
  try {for($i=0;$i<50;$i++){
   $n=$map[$state['node']]??null;if(!$n)return ['human',$state,'Não foi possível continuar. A equipe vai ajudar você.'];$type=$n['type'];$waiting=$state['waiting'];$state['waiting']=false;
   if($waiting&&($choices=$this->choiceOptions($n,$state))!==null){$input=AgendaFlowExperience::choice($input,$choices);$value=trim($input);$numeric=ctype_digit($value)?(int)$value:0;$more=($type==='times'||($state['phase']??'')==='times')&&mb_strtolower($value)==='mais';if(!$more&&($numeric<1||$numeric>count($choices))){$key=$n['id'].':'.($state['phase']??$type);$last=$state['_invalid_choice']??[];$count=($last['key']??null)===$key?($last['count']+1):1;$state['_invalid_choice']=['key'=>$key,'count'=>$count];if($count>=2){$state['_handoff_reason']='invalid_options';return ['human',$state,'Vou encaminhar você para a fila de atendimento para que a equipe possa ajudar.'];}$state['waiting']=true;return ['editor',$state,'Por gentileza, selecione uma opção válida.'."\n\n".implode("\n",array_map(fn($o,$i)=>($i+1).' — '.$o['label'],$choices,array_keys($choices)))."\n\nDigite o número da opção ou ATENDENTE para falar com a equipe."];}unset($state['_invalid_choice']);}
   if($type==='identify'){
    $known=$preview?!empty($state['name']):($person&&filled($person->nome)&&(in_array('cliente',$person->papeis??[],true)||blank($person->whatsapp_nome)));
    if(!$known){if(!$waiting){$state['waiting']=true;return ['editor',$state,$n['name_prompt']??'Olá! Como você gostaria de ser chamado?'];}[$s,$d,$text]=app(AgendaGuidedFlow::class)->advance($u,$cfg,$preview?0:$contact,'name',[],$input);if($s==='name'){$state['waiting']=true;return ['editor',$state,$text];}$state['name']=trim($input);$person=$person?->fresh();}
    $out[]=$format($n['text']??'');
   }elseif(in_array($type,['message','welcome'])){$out[]=$format($n['text']??'');}
   elseif($type==='question'||$type==='menu'){
    if(!$waiting){$state['waiting']=true;$out[]=$format($n['text']??'');if($type==='menu')foreach($n['options'] as $k=>$o)$out[]=($k+1).' — '.$o['label'];return ['editor',$state,implode("\n\n",$out)];}
    if($type==='question'){if(trim($input)===''||mb_strlen($input)>200){$state['waiting']=true;return ['editor',$state,'Envie uma resposta de até 200 caracteres.'];}$state['answer']=trim($input);}
    else{$choice=ctype_digit(trim($input))?($n['options'][(int)trim($input)-1]??null):null;if(!$choice){$state['waiting']=false;continue;}$state['node']=$choice['to'];$input='';continue;}
   }elseif($type==='condition'){$state['node']=mb_strtolower(trim($state['answer']??''))===mb_strtolower(trim($n['equals']??''))?$n['yes']:$n['no'];continue;}
   elseif($type==='preferred'){
    $state['preferred_booking']=true;
    if(!$waiting){$state['waiting']=true;$out[]=$format(str_replace('\\n',"\n",$n['text']?:'Qual dia e horário gostaria? Exemplo: quarta às 17h.'));return ['editor',$state,implode("\n\n",array_filter($out))];}
    try{$at=app(AgendaPreferredTime::class)->parse($input);}catch(ValidationException $e){$state['waiting']=true;return ['editor',$state,implode(' ',array_merge(...array_values($e->errors())))];}
    $state['domain']['date']=$at->format('Y-m-d');$state['domain']['time']=$at->format('H:i');unset($state['domain']['professional'],$state['review_text']);
   }elseif($type==='available'){
    $availability=app(AgendaPreferredTime::class);$d=$state['domain'];
    if($waiting){$choice=ctype_digit(trim($input))?($d['alternatives'][(int)trim($input)-1]??null):null;if(!$choice){$state['waiting']=true;return ['editor',$state,$this->alternativesText($d['alternatives'])];}$d['date']=$choice['date'];$d['time']=$choice['time'];$d['options']=$availability->professionals($u,$d['service'],$d['date'],$d['time']);if(!$d['options']){$state['domain']=$d;continue;}$state['domain']=$d;}
    else{$pros=$availability->professionals($u,$d['service'],$d['date'],$d['time']);if($pros){$state['domain']['options']=$pros;$out[]='Há disponibilidade em '.\Carbon\Carbon::parse($d['date'])->format('d/m/Y').' às '.$d['time'].'.';}
     else{$choices=$availability->next($u,$d['service'],$d['date'],$d['time']);if(!$choices){$state['node']=$this->stageNode($graph,'preferred');$state['waiting']=true;return ['editor',$state,'Não encontrei horários a partir desse momento dentro do prazo de agendamento. Informe outro dia e horário ou digite ATENDENTE.'];}$state['domain']['alternatives']=$choices;$state['waiting']=true;return ['editor',$state,$this->alternativesText($choices)];}}
   }elseif($type==='free_professionals'){
    if(!$waiting){$state['waiting']=true;$state['domain']['options']=array_values(array_filter($state['domain']['options'],fn($o)=>$o['id']!=='any'));$state['domain']['options'][]=['id'=>'any','label'=>'Sem preferência'];$out[]=$format($n['text']?:'Qual {profissional} você deseja?');foreach($state['domain']['options'] as $k=>$o)$out[]=($k+1).' — '.$o['label'];return ['editor',$state,implode("\n\n",$out)];}
    $choice=ctype_digit(trim($input))?($state['domain']['options'][(int)trim($input)-1]??null):null;if(!$choice){continue;}
    $d=$state['domain'];$pros=app(AgendaPreferredTime::class)->professionals($u,$d['service'],$d['date'],$d['time']);if($choice['id']==='any')$choice=app(AgendaFlowExperience::class)->assign($u,$cfg,$pros,$d['date']);if(!$choice||!in_array($choice['id'],array_column($pros,'id'))){$state['node']=$this->stageNode($graph,'available');$out[]='Esse profissional não está mais livre. Vou consultar novamente.';continue;}
    $d['professional']=$choice['id'];$d['options']=[['id'=>$d['time'],'label'=>$d['time']]];[$phase,$d,$review]=app(AgendaGuidedFlow::class)->advance($u,$cfg,$preview?0:$contact,'times',$d,'1');if($phase!=='review')return ['human',$state,$review];$state['domain']=$d;$state['review_text']=$review;
   }elseif(in_array($type,['services','professionals','date','times','review'])){
    $guided=app(AgendaGuidedFlow::class);
    if(!$waiting){
     if($type==='services'){[$phase,$domain,$text]=$guided->services();$state['domain']=$domain;if($phase!=='services')return ['human',$state,$text];}
     elseif($type==='professionals'){$items=\App\Models\AgendaProfessional::where('ativo',true)->whereHas('services',fn($q)=>$q->where('agenda_services.id',$state['domain']['service']))->orderBy('nome')->limit(50)->get()->map(fn($p)=>['id'=>$p->id,'label'=>$p->nome])->all();if(!$items)return ['human',$state,'A equipe vai ajudar você a escolher um profissional.'];$state['domain']['options']=$items;$text='Com qual profissional?'."\n".implode("\n",array_map(fn($o,$k)=>($k+1).' — '.$o['label'],$items,array_keys($items)));}
     elseif($type==='date'){$text='Qual dia? Envie DD/MM/AAAA.';}
     elseif($type==='times'){[$phase,$state['domain'],$text]=$guided->times($u,$state['domain']);if($phase==='date'){$state['node']=$this->stageNode($graph,'date');$state['waiting']=true;return ['editor',$state,$text];}}
     else{$text=$state['review_text']??'Digite MENU para recomeçar.';if(empty($state['domain']['reschedule']))$text.="\n3 — Alterar serviço\n4 — Alterar profissional\n5 — Atendimento";}
     $state['waiting']=true;$out[]=$format($n['text']??'');$out[]=$text;return ['editor',$state,implode("\n\n",array_filter($out))];
    }
    if($preview&&$type==='review'&&trim($input)==='1'){$state['waiting']=true;return ['editor',$state,'Prévia concluída: nenhuma reserva foi gravada.'];}
    if($type==='review'&&empty($state['domain']['reschedule'])){if(trim($input)==='5')return ['human',$state,$cfg->handoff];if(trim($input)==='3'){$state['node']=$this->stageNode($graph,'services');$state['domain']=[];unset($state['review_text']);continue;}if(trim($input)==='4'){unset($state['domain']['professional'],$state['domain']['key'],$state['review_text']);$target=$this->stageNode($graph,'free_professionals');if($target){$state['domain']['options']=app(AgendaPreferredTime::class)->professionals($u,$state['domain']['service'],$state['domain']['date'],$state['domain']['time']);$state['node']=$state['domain']['options']?$target:$this->stageNode($graph,'available');}else $state['node']=$this->stageNode($graph,'professionals');continue;}}
    if($type==='review'&&!empty($state['preferred_booking'])&&trim($input)==='2'){$state['node']=$this->stageNode($graph,'preferred');continue;}
    [$phase,$domain,$text]=$guided->advance($u,$cfg,$preview?0:$contact,$type,$state['domain'],$input);$state['domain']=$domain;
    if($phase===$type){$state['waiting']=true;return ['editor',$state,$text];}
    $expected=['services'=>'professionals','professionals'=>'date','date'=>'times','times'=>'review','review'=>'menu'];
    if($phase!==$expected[$type]){if(!empty($state['preferred_booking'])&&in_array($phase,['times','date'])){$state['node']=$this->stageNode($graph,'available');$out[]='Não foi possível confirmar. Vou consultar a disponibilidade novamente.';continue;}if(in_array($phase,['services','professionals','date','times','review'])){$state['node']=$this->stageNode($graph,$phase);$state['waiting']=true;return ['editor',$state,$text];}return ['human',$state,$text];}
    if($phase==='review')$state['review_text']=$text;if($type==='review'){$out[]=$text;$state['domain']=[];}
   }elseif($preview&&$type==='manage'){
    if(!$waiting)$state['phase']='menu';[$phase,$text]=app(AgendaAppointmentPreview::class)->advance($u,$state,$input);$out[]=$text;if($phase!=='menu'){$state['phase']=$phase;$state['waiting']=true;return ['editor',$state,implode("\n\n",$out)];}unset($state['phase']);$state['domain']=[];
   }elseif(in_array($type,['booking','manage'])){
    $phase=$state['phase']??'menu';if(!$waiting){$out[]=$format($n['text']??'');$input=$type==='booking'?'1':'2';$phase='menu';}
    if($preview&&in_array($phase,['review','manage','cancel'])){return ['editor',$state,'Prévia concluída: a confirmação seria processada aqui. Nenhuma reserva ou mensagem foi gravada.'];}
    $previous=$phase;[$phase,$domain,$text]=app(AgendaGuidedFlow::class)->advance($u,$cfg,$preview?0:$contact,$phase,$state['domain'],$input);if($previous==='name'&&$phase==='menu'&&$type==='manage')[$phase,$domain,$text]=app(AgendaGuidedFlow::class)->advance($u,$cfg,$preview?0:$contact,'menu',[],'2');if($type==='manage'&&$phase==='appointments'&&count($domain['options'])===1)[$phase,$domain,$text]=app(AgendaGuidedFlow::class)->advance($u,$cfg,$preview?0:$contact,'appointments',$domain,'1');$out[]=$text;
    if($phase!=='menu'){$state['phase']=$phase;$state['domain']=$domain;$state['waiting']=true;return [$phase==='human'?'human':'editor',$state,implode("\n\n",$out)];}unset($state['phase']);$state['domain']=[];
   }elseif($type==='human'){return ['human',$state,$format($n['text']??$cfg->handoff)];}
   elseif($type==='end'){$out[]=$format($n['text']??'');return ['editor',$preview?array_intersect_key($state,array_flip(['name','preview_booking'])):[],implode("\n\n",$out)];}
   $state['node']=$n['next'];$input='';
  }}catch(ValidationException|\Illuminate\Database\Eloquent\ModelNotFoundException $error){return ['human',$state,'Essa opção não está disponível nas regras atuais. A equipe vai ajudar você.'];}
  return ['human',$state,'A equipe vai ajudar você a continuar.'];
 }
 private function stageNode(array $graph,string $type):string {foreach($graph as $n)if($n['type']===$type)return $n['id'];return '';}
 private function choiceOptions(array $node,array $state):?array {
  $type=$node['type'];$d=$state['domain'];if($type==='menu')return $node['options'];if($type==='available')return $d['alternatives']??null;if($type==='review'){ $options=[['label'=>'Confirmar'],['label'=>'Escolher outro horário']];if(empty($d['reschedule']))$options=[...$options,['label'=>'Alterar serviço'],['label'=>'Alterar profissional'],['label'=>'Atendimento']];return $options;}
  $phase=in_array($type,['booking','manage'])?($state['phase']??'menu'):$type;
  if(in_array($phase,['services','professionals','times','free_professionals','appointments','rebooking_choices']))return $d['options']??null;
  if($phase==='manage')return [['label'=>'Confirmar presença'],['label'=>'Reagendar'],['label'=>'Cancelar']];
  if($phase==='review')return [['label'=>'Confirmar'],['label'=>'Escolher outro horário']];if($phase==='cancel')return [['label'=>'Confirmar cancelamento'],['label'=>'Manter agendamento']];return null;
 }
 private function alternativesText(array $choices):string {return 'Esse horário não está disponível. Estes são os próximos horários livres:'."\n\n".implode("\n",array_map(fn($c,$i)=>($i+1).' — '.AgendaPreferredTime::label($c['date'],$c['time']),$choices,array_keys($choices)))."\n\nDigite o número da opção, MENU para recomeçar ou ATENDENTE.";}
}
