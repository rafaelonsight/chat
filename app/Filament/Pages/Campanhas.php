<?php
namespace App\Filament\Pages;
use App\Jobs\{DispararCampanha,SendTemplateMessage,SendTextMessage};
use App\Models\{Campaign,CampaignRecipient,Channel,Contact,MetaTemplate,Tag,Team,Message};
use App\Services\{Disparador,RelatorioCampanha,EstimativaConsumoMeta};
use BackedEnum;
use UnitEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
class Campanhas extends Page {
 use \App\Filament\TraduzirNavegacao, WithPagination, WithFileUploads;
 protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedMegaphone;
 protected static string|UnitEnum|null $navigationGroup='Aplicações';
 protected static ?string $navigationLabel='Campanhas', $title='Campanhas', $slug='campanhas';
 protected static ?int $navigationSort=1;
 protected string $view='filament.pages.campanhas';
 public ?int $editando=null,$selecionada=null,$channel_id=null,$tag_id=null,$excluir_tag_id=null,$meta_template_id=null,$team_id=null;
 public bool $formAberto=false;
 public string $nome='',$publico='etiqueta',$corpo='',$lista='',$inicio='agora',$agendamento='',$timezone='America/Sao_Paulo',$aba='resultados',$busca='',$filtro='',$classificacao='',$buscaCampanha='',$statusCampanha='';
 public int $etapa=1,$por_minuto=6,$hora_inicio=9,$hora_fim=20;
 public array $template_valores=[],$dias=[1,2,3,4,5];
 public $planilha=null;
 public string $respostaModo='padrao';
 public ?int $chatbot_id=null;
 public string $numeroTeste='';
 public function enviarTeste(): void {
  $this->validar(2);$this->validate(['numeroTeste'=>'required|string|max:25']);
  $numero=preg_replace('/\D/','',$this->numeroTeste);
  $contato=Contact::where('tenant_id',$this->conta())->whereRaw("regexp_replace(telefone_e164,'[^0-9]','','g') = ?",[$numero])->whereNull('opt_out_em')->whereNull('bloqueado_em')->whereNull('arquivado_em')->where('tipo','!=','grupo')->first();
  if(!$contato)throw \Illuminate\Validation\ValidationException::withMessages(['numeroTeste'=>__('Escolha um número elegível já cadastrado no CRM.')]);
  $ch=$this->canais()->findOrFail($this->channel_id);abort_unless($ch->status==='open',422);
  $cv=\App\Models\Conversation::abertaOuNova($ch->id,$contato->id,$this->conta());$t=$this->modelo();
  $m=Message::create(['tenant_id'=>$this->conta(),'conversation_id'=>$cv->id,'channel_id'=>$ch->id,'user_id'=>auth()->id(),'direcao'=>'out','tipo'=>$t?'template':'text','corpo'=>$t?$t->renderizar($this->template_valores):$this->corpo,'automatica'=>true,'status'=>'queued']);
  $t?SendTemplateMessage::dispatch($m->id,$t->id,array_values($this->template_valores)):SendTextMessage::dispatch($m->id);
  Notification::make()->title(__('Teste colocado na fila. Acompanhe o resultado na conversa.'))->success()->send();
 }
 public static function canAccess(): bool {return (bool)auth()->user()?->admin;}
 private function autorizar(): void {abort_unless(static::canAccess(),403);}
 private function conta(): int {return (int)(\App\Support\TenantContext::get()?:auth()->user()->tenant_id);}
 private function campanha(int $id): Campaign {$this->autorizar();return Campaign::where('tenant_id',$this->conta())->findOrFail($id);}
 private function canais(){return Channel::where('tenant_id',$this->conta())->whereIn('tipo',['meta_cloud','evolution']);}
 public function mount(): void {$this->autorizar();$this->channel_id=$this->canais()->value('id');}
 public function novaCampanha(): void {
  $this->autorizar();$this->reset(['editando','selecionada','nome','publico','tag_id','excluir_tag_id','corpo','meta_template_id','template_valores','lista','inicio','agendamento','team_id','planilha','etapa','dias','por_minuto','hora_inicio','hora_fim','respostaModo','chatbot_id']);
  $this->resetValidation();$this->channel_id=$this->canais()->value('id');$this->formAberto=true;
 }
 public function updatedChannelId(): void {$this->meta_template_id=null;$this->template_valores=[];}
 public function updatedMetaTemplateId(): void {$this->template_valores=[];}
 public function updatedBusca(): void {$this->resetPage();}
 public function updatedFiltro(): void {$this->resetPage();}
 public function updatedClassificacao(): void {$this->resetPage();}
 public function abrir(int $id): void {$this->campanha($id);$this->selecionada=$id;$this->formAberto=false;$this->aba='resultados';$this->filtro='';$this->busca='';$this->resetPage();}
 public function filtrar(string $s): void {$this->filtro=$s;$this->aba='destinatarios';$this->resetPage();}
 public function editar(int $id): void {
  $c=$this->campanha($id);abort_unless($c->status===Campaign::RASCUNHO,422);$this->novaCampanha();$this->editando=$id;
  foreach(['nome','channel_id','publico','tag_id','meta_template_id','por_minuto','hora_inicio','hora_fim'] as $f)$this->$f=$c->$f;
  $this->corpo=(string)$c->corpo;$this->template_valores=(array)$c->template_valores;
  $this->publico=data_get($c->configuracao,'lista')?'lista':$c->publico;$this->lista=(string)data_get($c->configuracao,'numeros','');
  $this->excluir_tag_id=data_get($c->configuracao,'excluir_tag_id');$this->team_id=data_get($c->configuracao,'team_id');
  $this->respostaModo=data_get($c->configuracao,'resposta_modo','padrao');$this->chatbot_id=data_get($c->configuracao,'chatbot_id');
  $this->timezone=data_get($c->configuracao,'timezone','America/Sao_Paulo');$this->dias=data_get($c->configuracao,'dias',[0,1,2,3,4,5,6]);
  if($c->agendada_para){$this->inicio='agendar';$this->agendamento=$c->agendada_para->timezone($this->timezone)->format('Y-m-d\TH:i');}
 }
 private function modelo(): ?MetaTemplate {
  $ch=$this->canais()->find($this->channel_id);
  return $ch?->tipo==='meta_cloud'?MetaTemplate::where('tenant_id',$this->conta())->enviaveis()->where('meta_waba_id',$ch->meta_waba_id)->find($this->meta_template_id):null;
 }
 private function validar(int $ate=4): void {
  $this->autorizar();$ch=$this->canais()->find($this->channel_id);
  $r=['nome'=>'required|string|max:80','channel_id'=>['required',Rule::exists('channels','id')->where('tenant_id',$this->conta())->whereIn('tipo',['meta_cloud','evolution'])],'publico'=>'required|in:etiqueta,todos,lista','tag_id'=>['nullable','required_if:publico,etiqueta',Rule::exists('tags','id')->where('tenant_id',$this->conta())],'excluir_tag_id'=>['nullable',Rule::exists('tags','id')->where('tenant_id',$this->conta())],'lista'=>'nullable|string|max:150000'];
  if($ate>=2)$r+=['corpo'=>$ch?->tipo==='meta_cloud'?'nullable':'required|string|max:1000','meta_template_id'=>$ch?->tipo==='meta_cloud'?'required|integer':'nullable','template_valores'=>'array','template_valores.*'=>'required|string|max:500|not_regex:/[\r\n\t]/'];
  if($ate>=3)$r+=['por_minuto'=>'required|integer|min:1|max:30','hora_inicio'=>'required|integer|min:0|max:23','hora_fim'=>'required|integer|min:1|max:23|gt:hora_inicio','inicio'=>'required|in:agora,agendar','timezone'=>['required',Rule::in(['America/Sao_Paulo','Europe/London','UTC'])],'dias'=>'required|array|min:1','dias.*'=>'integer|between:0,6','team_id'=>['nullable',Rule::exists('teams','id')->where('tenant_id',$this->conta())],'agendamento'=>'nullable|required_if:inicio,agendar|date'];
  $this->validate($r);
  if($ate>=3)$this->validate(['respostaModo'=>'required|in:padrao,equipe,chatbot','chatbot_id'=>['nullable','required_if:respostaModo,chatbot',Rule::exists('chatbots','id')->where('tenant_id',$this->conta())->where('ativo',true)->where('status','publicado')]]);
  if($ate>=2 && $ch?->tipo==='meta_cloud'){$t=$this->modelo();if(!$t || count($this->template_valores)!==$t->variaveis)throw \Illuminate\Validation\ValidationException::withMessages(['meta_template_id'=>__('Selecione um template aprovado deste canal e preencha todas as variáveis.')]);}
  if($ate>=3 && $this->inicio==='agendar' && \Carbon\Carbon::parse($this->agendamento,$this->timezone)->lte(now()))throw \Illuminate\Validation\ValidationException::withMessages(['agendamento'=>__('Escolha uma data futura.')]);
 }
 public function avancar(): void {$this->validar($this->etapa);$this->etapa=min(4,$this->etapa+1);}
 public function importar(): void {
  $this->autorizar();$this->validate(['planilha'=>'required|file|mimes:csv,txt|max:2048']);$nums=[];
  foreach(array_slice(file($this->planilha->getRealPath(),FILE_IGNORE_NEW_LINES),0,10000) as $line)foreach(preg_split('/[;,\t]/',$line) as $cell){$v=trim($cell," \"\xEF\xBB\xBF");if(preg_match('/^\+?[\d\s().-]{8,25}$/',$v))$nums[]=$v;}
  $this->lista=implode("\n",array_unique($nums));$this->publico='lista';$this->planilha=null;
 }
 private function rascunho(): Campaign {
  $config=['excluir_tag_id'=>$this->excluir_tag_id,'team_id'=>$this->team_id,'timezone'=>$this->timezone,'dias'=>array_values($this->dias),'lista'=>$this->publico==='lista','numeros'=>$this->lista];
  $config['resposta_modo']=$this->respostaModo;$config['chatbot_id']=$this->respostaModo==='chatbot'?$this->chatbot_id:null;
  if($this->publico==='lista'){$nums=array_unique(array_filter(array_map(fn($n)=>preg_replace('/\D/','',$n),preg_split('/[\r\n;,]+/',$this->lista))));$config['contact_ids']=Contact::where('tenant_id',$this->conta())->whereIn(DB::raw("regexp_replace(telefone_e164, '[^0-9]', '', 'g')"),$nums)->pluck('id')->all();$config['informados']=count($nums);}
  return new Campaign(['tenant_id'=>$this->conta(),'nome'=>trim($this->nome),'channel_id'=>$this->channel_id,'publico'=>$this->publico==='lista'?'todos':$this->publico,'tag_id'=>$this->publico==='etiqueta'?$this->tag_id:null,'corpo'=>$this->corpo,'meta_template_id'=>$this->meta_template_id,'template_valores'=>array_values($this->template_valores),'por_minuto'=>$this->por_minuto,'hora_inicio'=>$this->hora_inicio,'hora_fim'=>$this->hora_fim,'configuracao'=>$config]);
 }
 public function salvar(bool $iniciar=false): void {
  $this->validar();$d=$this->rascunho();$d->agendada_para=$this->inicio==='agendar'?\Carbon\Carbon::parse($this->agendamento,$this->timezone)->timezone(config('app.timezone')):null;
  if($this->modelo())$d->corpo=null;else{$d->meta_template_id=null;$d->template_valores=null;}
  $c=DB::transaction(function()use($d){if($this->editando){$c=Campaign::where('tenant_id',$this->conta())->lockForUpdate()->findOrFail($this->editando);abort_unless($c->status===Campaign::RASCUNHO,422);$c->fill($d->getAttributes());$c->save();}else{$c=$d;$c->criada_por=auth()->id();$c->save();}app(RelatorioCampanha::class)->historico($c,'rascunho',__('Configuração salva.'));return $c;});
  $this->formAberto=false;$this->editando=null;$this->abrir($c->id);if($iniciar)$this->iniciar($c->id);else Notification::make()->title(__('Rascunho salvo'))->success()->send();
 }
 private function validarPronta(Campaign $c): void {
  abort_unless(in_array($c->channel?->tipo,['meta_cloud','evolution']),422);
  if($c->exigeTemplate())abort_unless($c->template?->podeEnviar() && $c->template->meta_waba_id===$c->channel->meta_waba_id && $c->template->tenant_id===$c->tenant_id && count((array)$c->template_valores)===$c->template->variaveis,422);
 }
 public function iniciar(int $id): void {
  $c=$this->campanha($id);DB::transaction(function()use($c){$c=Campaign::whereKey($c->id)->lockForUpdate()->firstOrFail();if($c->status!==Campaign::RASCUNHO)return;$this->validarPronta($c);
   if(!app(Disparador::class)->montarFila($c))throw \Illuminate\Validation\ValidationException::withMessages(['campanha'=>__('Nenhum destinatário válido. Confira o público.')]);
   $agendada=$c->agendada_para?->isFuture();$c->update(['status'=>$agendada?Campaign::AGENDADA:Campaign::ENVIANDO,'iniciada_em'=>$agendada?null:now()]);app(RelatorioCampanha::class)->historico($c,$agendada?'agendada':'iniciada');DispararCampanha::dispatch($c->id)->afterCommit()->delay($agendada?$c->agendada_para:now());});
 }
 public function pausar(int $id): void {$c=$this->campanha($id);DB::transaction(function()use($c){$c=Campaign::whereKey($c->id)->lockForUpdate()->firstOrFail();if(!$c->rodando())return;$c->update(['status'=>Campaign::PAUSADA,'motivo_pausa'=>__('Pausada pelo utilizador.')]);app(RelatorioCampanha::class)->historico($c,'pausada',$c->motivo_pausa);});}
 public function retomar(int $id): void {
  $c=$this->campanha($id);DB::transaction(function()use($c){$c=Campaign::whereKey($c->id)->lockForUpdate()->firstOrFail();if($c->status!==Campaign::PAUSADA)return;$this->validarPronta($c);abort_unless($c->channel?->status==='open',422);$c->update(['status'=>Campaign::ENVIANDO,'motivo_pausa'=>null]);app(RelatorioCampanha::class)->historico($c,'retomada');foreach($c->recipients()->whereHas('message',fn($q)=>$q->where('status','queued'))->get() as $r){($c->exigeTemplate()?SendTemplateMessage::dispatch($r->message_id,$c->meta_template_id,(array)$c->template_valores):SendTextMessage::dispatch($r->message_id))->afterCommit();}DispararCampanha::dispatch($c->id)->afterCommit();});
 }
 public function cancelar(int $id): void {
  $c=$this->campanha($id);DB::transaction(function()use($c){$c=Campaign::whereKey($c->id)->lockForUpdate()->firstOrFail();if(!in_array($c->status,[Campaign::RASCUNHO,Campaign::AGENDADA,Campaign::ENVIANDO,Campaign::PAUSADA]))return;$c->update(['status'=>Campaign::CANCELADA,'concluida_em'=>now()]);
   foreach($c->recipients()->where(fn($q)=>$q->whereNull('message_id')->where('status','pendente')->orWhereHas('message',fn($q)=>$q->where('status','queued')))->get() as $r){$r->update(['status'=>CampaignRecipient::PULADA,'motivo'=>'Campanha cancelada antes do envio.']);$r->message?->update(['status'=>'failed','erro'=>'Campanha cancelada antes do envio.']);}app(RelatorioCampanha::class)->historico($c,'cancelada');});
 }
 public function duplicar(int $id): void {$c=$this->campanha($id);$n=$c->replicate(['status','agendada_para','iniciada_em','concluida_em','motivo_pausa']);$n->nome=mb_substr($c->nome,0,65).' — cópia';$n->status=Campaign::RASCUNHO;$n->criada_por=auth()->id();$n->save();app(RelatorioCampanha::class)->historico($n,'duplicada');$this->editar($n->id);}
 public function classificar(int $id,string $valor): void {$c=$this->campanha((int)$this->selecionada);abort_unless($valor===''||isset(RelatorioCampanha::CLASSIFICACOES[$valor]),422);$c->recipients()->findOrFail($id)->update(['classificacao'=>$valor?:null]);app(RelatorioCampanha::class)->historico($c,'classificada',__('Resultado de destinatário atualizado.'));}
 public function exportar() {
  $c=$this->campanha((int)$this->selecionada);$q=app(RelatorioCampanha::class)->destinatarios($c,$this->busca,$this->filtro,$this->classificacao);
  return response()->streamDownload(function()use($q){$o=fopen('php://output','w');fwrite($o,"\xEF\xBB\xBF");fputcsv($o,['Cliente','Número','Situação','Enviada em','Lida em','Respondeu','Classificação','Motivo'],';');foreach($q->lazy(100) as $r){$row=[$r->contact?->nome,$r->contact?->telefone_e164,__(RelatorioCampanha::SITUACOES[$r->situacao]??$r->situacao),$r->confirmada_em,$r->lida_em,$r->resposta_id?'Sim':'Não',$r->classificacao,$r->erro?:$r->motivo];fputcsv($o,array_map(fn($v)=>preg_match('/^[=+@\-\t\r]/',(string)$v)?"'".$v:$v,$row),';');}fclose($o);},'campanha-'.$c->id.'.csv');
 }
 public function getViewData(): array {
  $this->autorizar();$s=app(RelatorioCampanha::class);$c=$this->selecionada?$this->campanha($this->selecionada):null;$ch=$this->canais()->find($this->channel_id);$d=$this->formAberto?$this->rascunho():null;$t=$this->modelo();$estimate=null;
  if($d && $this->etapa===4 && $t?->categoria==='MARKETING'){$vol=[];$unknown=false;foreach(app(Disparador::class)->publicoFinal($d)->select('telefone_e164')->cursor() as $contact){$n=$contact->telefone_e164;$country=str_starts_with((string)$n,'+55')?'BR':(str_starts_with((string)$n,'+44')?'GB':null);if(!$country){$unknown=true;continue;}$vol[$country]=($vol[$country]??0)+1;}if(!$unknown)$estimate=app(EstimativaConsumoMeta::class)->calcular(array_map(fn($country,$n)=>['country'=>$country,'volume'=>$n,'pricing_type'=>'REGULAR','pricing_category'=>'MARKETING'],array_keys($vol),array_values($vol)),'USD')['total'];}
  return ['chatbots'=>\App\Models\Chatbot::where('tenant_id',$this->conta())->where('ativo',true)->where('status','publicado')->orderBy('nome')->get(),'campanhas'=>Campaign::where('tenant_id',$this->conta())->with('channel')->withCount('recipients')->when($this->buscaCampanha,fn($q)=>$q->where('nome','ilike','%'.$this->buscaCampanha.'%'))->when($this->statusCampanha,fn($q)=>$q->where('status',$this->statusCampanha))->latest()->limit(100)->get(),'canais'=>$this->canais()->orderBy('nome')->get(),'etiquetas'=>Tag::deContato()->where('tenant_id',$this->conta())->orderBy('nome')->get(),'equipes'=>Team::where('tenant_id',$this->conta())->orderBy('nome')->get(),'templates'=>MetaTemplate::where('tenant_id',$this->conta())->enviaveis()->where('meta_waba_id',$ch?->meta_waba_id)->orderBy('nome')->get(),'template'=>$t,'previa'=>$d?app(Disparador::class)->contagem($d):null,'rascunho'=>$d,'textoPrevia'=>$t?$t->renderizar($this->template_valores):$this->corpo,'exigeTemplate'=>$ch?->tipo==='meta_cloud','campanha'=>$c,'resumo'=>$c?$s->resumo($c):null,'destinatarios'=>$c?$s->destinatarios($c,$this->busca,$this->filtro,$this->classificacao)->paginate(25):null,'historico'=>$c?DB::table('campaign_events')->leftJoin('users','users.id','=','campaign_events.user_id')->where('campaign_id',$c->id)->select('campaign_events.*','users.name')->latest('campaign_events.id')->limit(100)->get():collect(),'estimativa'=>$estimate];
 }
}
