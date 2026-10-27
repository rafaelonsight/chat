<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class AgendaInicio extends Page
{
    protected static ?string $title = 'Agenda';
    protected static ?string $slug = '/';
    protected static string|\BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedCalendarDays;
    protected static ?int $navigationSort = -1;
    protected string $view = 'filament.pages.agenda-inicio';
    public string $date='';
    public string $mode='day';
    public string $professional='';
    public bool $includeCancelled=false;
    public bool $editor=false;
    public array $form=[];
    #[\Livewire\Attributes\Locked] public ?int $editing=null;
    #[\Livewire\Attributes\Locked] public ?int $version=null;
    #[\Livewire\Attributes\Locked] public string $requestKey='';
    public array $suggestions=[];
    public bool $searchedSlots=false;
    #[\Livewire\Attributes\Locked] public array $history=[];
    public function mount():void {$this->date=now(\App\Models\AgendaSettings::current()->timezone)->format('Y-m-d');}
    private function engine():\App\Services\AgendaCalendar {return app(\App\Services\AgendaCalendar::class);}
    public function isAdministrator():bool {$actor=$this->engine()->actor(auth()->user());return $actor->admin || $actor->perfil==='admin';}
    public function canOperate():bool {return $this->engine()->canManage(auth()->user());}
    public function professionals():\Illuminate\Support\Collection {
        $actor=$this->engine()->actor(auth()->user());
        return \App\Models\AgendaProfessional::query()->when($actor->isAgendaProfessional(),fn($q)=>$q->where('user_id',$actor->id))->orderBy('nome')->get();
    }
    public function calendarData():array {
        validator(['date'=>$this->date,'mode'=>$this->mode],['date'=>'required|date_format:Y-m-d','mode'=>'required|in:day,week'])->validate();
        $cfg=\App\Models\AgendaSettings::current();$day=\Carbon\CarbonImmutable::parse($this->date.' 12:00',$cfg->timezone)->startOfDay();
        $start=$this->mode==='week'?$day->startOfWeek(\Carbon\CarbonInterface::MONDAY):$day;$end=$start->addDays($this->mode==='week'?7:1);
        $query=$this->engine()->entries(auth()->user(),$this->professional?(int)$this->professional:null);
        if(!$this->includeCancelled)$query->where('active',true);
        $entries=$query->where('start_at','<',$end->utc()->toIso8601String())->where('occupied_until','>',$start->utc()->toIso8601String())->orderBy('start_at')->get();
        $days=[];for($d=$start;$d->lt($end);$d=$d->addDay())$days[]=$d;
        $people=$this->professionals()->when($this->professional,fn($p)=>$p->where('id',(int)$this->professional));
        return compact('cfg','start','end','days','entries','people');
    }
    public function move(int $direction):void {abort_unless(in_array($direction,[-1,1],true),422);validator(['date'=>$this->date],['date'=>'required|date_format:Y-m-d'])->validate();$this->date=\Carbon\CarbonImmutable::parse($this->date)->addDays($direction*($this->mode==='week'?7:1))->format('Y-m-d');}
    public function today():void {$this->date=now(\App\Models\AgendaSettings::current()->timezone)->format('Y-m-d');}
    public function openNew(string $kind='booking'):void {
        abort_unless($this->canOperate(),403);abort_unless(in_array($kind,['booking','block'],true),422);if($kind==='block')$this->engine()->administrator(auth()->user());
        $this->resetErrorBag();$this->editing=null;$this->version=null;$this->requestKey=(string)\Illuminate\Support\Str::uuid();$this->suggestions=[];$this->searchedSlots=false;
        $people=$this->professionals();$id=$this->professional ?: (string)($people->where('ativo',true)->first()?->id ?? '');
        $this->form=['kind'=>$kind,'professional_id'=>$id,'service_id'=>'','contact_id'=>'','client_name'=>'','client_phone'=>'','date'=>$this->date,'time'=>'09:00','end_date'=>$this->date,'end_time'=>'18:00','reason'=>''];$this->editor=true;
    }
    public function servicesForForm():array {
        if(empty($this->form['professional_id']))return [];
        $p=$this->engine()->professional(auth()->user(),(int)$this->form['professional_id']);
        return $p->services()->where('ativo',true)->orderBy('nome')->get()->mapWithKeys(fn($s)=>[$s->id=>$s->nome.' · '.number_format((float)$s->preco,2,',','.').' · '.$s->duracao_minutos.' '.__('min')])->all();
    }
    public function formProfessionalChanged():void {$this->form['service_id']='';$this->suggestions=[];$this->searchedSlots=false;}
    public function contactsForForm():array {
        $actor=$this->engine()->actor(auth()->user());
        $query=\App\Models\Contact::withoutGlobalScopes()->where('tenant_id',$actor->tenant_id)->where('tipo','pessoa')->whereNull('anonimizado_em');
        if($actor->isAgendaProfessional())$query->whereIn('id',$this->engine()->entries($actor)->whereNotNull('contact_id')->select('contact_id'));
        return $query->orderBy('nome')->limit(200)->get()->mapWithKeys(fn($c)=>[$c->id=>$c->nome.' · '.$c->telefone_e164])->all();
    }
    public function suggest():void {
        $this->resetErrorBag();$this->suggestions=[];$this->searchedSlots=true;
        try {$this->validate(['form.professional_id'=>'required|integer','form.service_id'=>'required|integer','form.date'=>'required|date_format:Y-m-d']);$this->suggestions=$this->engine()->slots(auth()->user(),(int)$this->form['professional_id'],(int)$this->form['service_id'],$this->form['date'],$this->editing);}
        catch(\Illuminate\Validation\ValidationException $e){$this->errorsFrom($e);}
    }
    public function selectTime(string $time):void {abort_unless(in_array($time,$this->suggestions,true),422);$this->form['time']=$time;}
    public function openReschedule(int $id):void {
        $entry=$this->engine()->entry(auth()->user(),$id,true);abort_unless($entry->kind==='booking' && $entry->status==='confirmado',422);
        $this->resetErrorBag();$tz=\App\Models\AgendaSettings::current()->timezone;$local=$entry->start_at->setTimezone($tz);
        $this->editing=$entry->id;$this->version=$entry->version;$this->suggestions=[];$this->searchedSlots=false;
        $this->form=['kind'=>'booking','professional_id'=>$entry->professional_id,'service_id'=>$entry->service_id,'contact_id'=>$entry->contact_id,'client_name'=>$entry->snapshot['client'],'client_phone'=>$entry->snapshot['phone'],'date'=>$local->format('Y-m-d'),'time'=>$local->format('H:i')];$this->editor=true;
    }
    private function errorsFrom(\Illuminate\Validation\ValidationException $e):void {foreach($e->errors() as $key=>$messages)$this->addError(str_starts_with($key,'form.')?$key:'form.'.$key,$messages[0]);}
    public function saveEntry():void {
        $this->resetErrorBag();abort_unless($this->canOperate(),403);
        try {
            $this->validate(['form.date'=>'required|date_format:Y-m-d','form.time'=>'required|date_format:H:i','form.professional_id'=>'required|integer','form.kind'=>'required|in:booking,block']);
            $from=$this->form['date'].' '.$this->form['time'];
            if($this->editing)$entry=$this->engine()->reschedule(auth()->user(),$this->editing,$this->version,$from);
            elseif($this->form['kind']==='block'){$this->validate(['form.end_date'=>'required|date_format:Y-m-d','form.end_time'=>'required|date_format:H:i','form.reason'=>'required|string|max:200']);$entry=$this->engine()->block(auth()->user(),(int)$this->form['professional_id'],$from,$this->form['end_date'].' '.$this->form['end_time'],$this->form['reason']);}
            else $entry=$this->engine()->book(auth()->user(),['professional_id'=>(int)$this->form['professional_id'],'service_id'=>(int)($this->form['service_id']??0),'contact_id'=>($this->form['contact_id']??'')?(int)$this->form['contact_id']:null,'client_name'=>$this->form['client_name']??'','client_phone'=>$this->form['client_phone']??'','start'=>$from],$this->requestKey);
            $this->date=$entry->start_at->setTimezone(\App\Models\AgendaSettings::current()->timezone)->format('Y-m-d');$this->editor=false;\Filament\Notifications\Notification::make()->success()->title(__('Agenda atualizada'))->send();
        }catch(\Illuminate\Validation\ValidationException $e){$this->errorsFrom($e);}
    }
    public function changeEntry(int $id,int $version,string $action,string $value=''):void {
        $this->resetErrorBag();
        try {match($action){'cancel'=>$this->engine()->cancel(auth()->user(),$id,$version),'state'=>$this->engine()->state(auth()->user(),$id,$version,$value),'presence'=>$this->engine()->presence(auth()->user(),$id,$version,$value),default=>abort(422)};\Filament\Notifications\Notification::make()->success()->title(__('Agenda atualizada'))->send();}
        catch(\Illuminate\Validation\ValidationException $e){$this->errorsFrom($e);}
    }
    public function showHistory(int $client):void {$this->history=$this->engine()->clientHistory(auth()->user(),$client);}
    public function closeHistory():void {$this->history=[];}

    public function getTitle(): string {return auth()->user()?->isAgendaProfessional() ? __('Minha agenda') : __('Agenda');}
    public static function getNavigationLabel(): string {return auth()->user()?->isAgendaProfessional() ? __('Minha agenda') : __('Agenda');}
    public function ownProfessional(): ?\App\Models\AgendaProfessional {return auth()->user()?->agendaProfessional()?->load('services');}

    public function establishmentName(): string
    {
        return \App\Models\Tenant::find(auth()->user()?->tenant_id)?->nome ?? config('app.name');
    }

    public function catalogCounts(): array
    {
        abort_if(auth()->user()?->isAgendaProfessional(), 403);
        return [
            'services'=>\App\Models\AgendaService::where('ativo',true)->count(),
            'professionals'=>\App\Models\AgendaProfessional::where('ativo',true)->count(),
        ];
    }
}
