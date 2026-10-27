<?php
namespace App\Filament\Pages;
use App\Services\AgendaBusiness as Business;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
class AgendaFinanceiro extends Page {
 use WithPagination;
 protected static ?string $slug='financeiro';protected static ?string $title='Lançamentos';
 protected static string|\UnitEnum|null $navigationGroup='Financeiro';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-banknotes';protected static ?int $navigationSort=1;
 protected string $view='filament.pages.agenda-financeiro';
 public string $type='receivable';public string $status='open';public string $search='';public string $from='';public string $until='';public array $form=[];public array $payment=[];public string $reason='';public string $optionName='';public string $optionType='category';
 #[Locked] public ?int $selected=null;#[Locked] public ?int $version=null;#[Locked] public string $requestId='';#[Locked] public string $mode='';
 public static function canAccess():bool {return (bool)auth()->user()?->canAgendaFinance();}
 public static function getNavigationLabel():string {return __('Lançamentos');}
 public function getTitle():string {return __('Lançamentos');}
 public function mount():void {$this->guard();}
 private function guard(bool $write=false):Business {$b=app(Business::class);$b->actor(auth()->user(),$write?'agenda_finance_manage':'agenda_finance_view');return $b;}
 public function writable():bool {return auth()->user()->canAgendaFinance(true);}
 public function administrator():bool {return (bool)(auth()->user()->admin || auth()->user()->perfil==='admin');}
 public function today():string {return now(\App\Models\AgendaSettings::current()->timezone)->toDateString();}
 public function updated($name):void {if(in_array($name,['type','status','search','from','until']))$this->resetPage();}
 private function filtered(){
  validator(['type'=>$this->type,'status'=>$this->status,'from'=>$this->from?:null,'until'=>$this->until?:null],['type'=>'required|in:payable,receivable','status'=>'required|in:all,open,overdue,paid,cancelled','from'=>'nullable|date_format:Y-m-d','until'=>'nullable|date_format:Y-m-d'])->validate();
  $q=$this->guard()->query('agenda_finance_accounts')->where('type',$this->type)->when($this->search,fn($q)=>$q->where(fn($q)=>$q->where('description','ilike','%'.$this->search.'%')->orWhere('counterparty','ilike','%'.$this->search.'%')))->when($this->from,fn($q)=>$q->where('due_on','>=',$this->from))->when($this->until,fn($q)=>$q->where('due_on','<=',$this->until));
  if($this->status==='cancelled')$q->whereNotNull('cancelled_at');elseif($this->status!=='all'){$q->whereNull('cancelled_at');if($this->status==='paid')$q->whereColumn('paid_cents','amount_cents');else {$q->whereColumn('paid_cents','<','amount_cents');if($this->status==='overdue')$q->where('due_on','<',$this->today());}}return $q;
 }
 public function rows(){return $this->filtered()->orderBy('due_on')->orderBy('id')->paginate(20);}
 public function balance():int {return (int)$this->filtered()->whereNull('cancelled_at')->selectRaw('coalesce(sum(amount_cents-paid_cents),0) as balance')->value('balance');}
 public function options(string $type){return $this->guard()->query('agenda_finance_options')->where('type',$type)->orderBy('name')->get();}
 public function open(?int $id=null,string $mode='edit'):void {
  $b=$this->guard($mode!=='history');abort_unless(in_array($mode,['edit','payment','history','cancel']),422);$this->resetErrorBag();$a=$id?$b->query('agenda_finance_accounts')->where('id',$id)->first():null;if($id)abort_unless($a,404);
  $this->selected=$id;$this->version=$a?->version;$this->mode=$mode;$this->reason='';$this->requestId=(string)\Illuminate\Support\Str::uuid();
  $this->form=$a?['type'=>$a->type,'description'=>$a->description,'counterparty'=>$a->counterparty,'category_id'=>$a->category_id,'amount'=>number_format($a->amount_cents/100,2,'.',''),'issued_on'=>$a->issued_on,'due_on'=>$a->due_on]:['type'=>$this->type,'description'=>'','counterparty'=>'','category_id'=>'','amount'=>'','issued_on'=>$this->today(),'due_on'=>$this->today()];
  $this->payment=['amount'=>$a?number_format(($a->amount_cents-$a->paid_cents)/100,2,'.',''):'','paid_on'=>$this->today(),'method_id'=>'','bank_id'=>''];
 }
 public function save():void {$b=$this->guard(true);$this->resetErrorBag();if($this->mode==='edit')$b->account(auth()->user(),$this->form,$this->selected,$this->version);elseif($this->mode==='payment')$b->payment(auth()->user(),$this->selected,$this->payment,$this->requestId);elseif($this->mode==='cancel')$b->cancel(auth()->user(),$this->selected,$this->reason);else abort(422);$this->mode='';\Filament\Notifications\Notification::make()->success()->title(__('Lançamento atualizado'))->send();}
 public function reverse(int $payment):void {$this->guard(true)->reverse(auth()->user(),$this->selected,$payment,$this->reason,$this->requestId);$this->reason='';$this->requestId=(string)\Illuminate\Support\Str::uuid();}
 public function history(){if(!$this->selected)return collect();return $this->guard()->query('agenda_finance_payments')->join('users as actor','actor.id','=','agenda_finance_payments.user_id')->leftJoin('agenda_finance_options as method','method.id','=','agenda_finance_payments.method_id')->leftJoin('agenda_finance_options as bank','bank.id','=','agenda_finance_payments.bank_id')->select('agenda_finance_payments.*','bank.name as bank_name','actor.name as actor_name','method.name as method_name')->where('account_id',$this->selected)->orderByDesc('agenda_finance_payments.id')->get();}
 public function audit(){if(!$this->selected)return collect();return $this->guard()->query('agenda_business_events')->join('users as actor','actor.id','=','agenda_business_events.user_id')->select('agenda_business_events.*','actor.name as actor_name')->where('entity','account')->where('entity_id',$this->selected)->orderByDesc('agenda_business_events.id')->get();}
 public function addOption():void {$this->guard();app(Business::class)->option(auth()->user(),$this->optionType,$this->optionName);$this->optionName='';\Filament\Notifications\Notification::make()->success()->title(__('Opção cadastrada'))->send();}
}
