<?php
namespace App\Filament\Pages;
use App\Services\AgendaBusiness;
use Filament\Pages\Page;
use Livewire\WithPagination;
class AgendaFinanceReports extends Page {
 use WithPagination;
 protected static ?string $slug='financeiro/relatorios';protected static ?string $title='Relatórios';protected static string|\UnitEnum|null $navigationGroup='Financeiro';protected static ?int $navigationSort=5;
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-chart-bar';protected string $view='filament.pages.agenda-finance-reports';
 public string $from='';public string $until='';public string $bank='';public string $category='';public string $method='';public string $type='all';
 public static function canAccess():bool{return (bool)auth()->user()?->canAgendaFinance();}
 public static function getNavigationLabel():string{return __('Relatórios');}public function getTitle():string{return __('Relatórios');}
 public function mount():void {app(AgendaBusiness::class)->actor(auth()->user(),'agenda_finance_view');$now=now(\App\Models\AgendaSettings::current()->timezone);$this->from=$now->copy()->startOfMonth()->toDateString();$this->until=$now->toDateString();}
 public function updated():void {$this->resetPage();}
 public function options(string $type){$b=app(AgendaBusiness::class);$b->actor(auth()->user(),'agenda_finance_view');return $b->query('agenda_finance_options')->where('type',$type)->orderBy('name')->get();}
 private function query(){
  $b=app(AgendaBusiness::class);$b->actor(auth()->user(),'agenda_finance_view');validator(['from'=>$this->from,'until'=>$this->until,'type'=>$this->type],['from'=>'required|date_format:Y-m-d','until'=>'required|date_format:Y-m-d|after_or_equal:from','type'=>'required|in:all,payable,receivable'])->validate();
  foreach(['bank'=>$this->bank,'category'=>$this->category,'method'=>$this->method] as $kind=>$id)if($id)abort_unless($b->query('agenda_finance_options')->where('type',$kind)->where('id',$id)->exists(),404);
  return $b->query('agenda_finance_payments')->join('agenda_finance_accounts as a',function($j){$j->on('a.id','=','agenda_finance_payments.account_id')->on('a.tenant_id','=','agenda_finance_payments.tenant_id');})->leftJoin('agenda_finance_options as bank','bank.id','=','agenda_finance_payments.bank_id')->leftJoin('agenda_finance_options as method','method.id','=','agenda_finance_payments.method_id')->leftJoin('agenda_finance_options as category','category.id','=','a.category_id')->whereBetween('agenda_finance_payments.paid_on',[$this->from,$this->until])->when($this->bank,fn($q)=>$q->where('agenda_finance_payments.bank_id',$this->bank))->when($this->method,fn($q)=>$q->where('agenda_finance_payments.method_id',$this->method))->when($this->category,fn($q)=>$q->where('a.category_id',$this->category))->when($this->type!=='all',fn($q)=>$q->where('a.type',$this->type));
 }
 private function selected(){return $this->query()->select('agenda_finance_payments.*','a.type','a.description','a.counterparty','bank.name as bank_name','method.name as method_name','category.name as category_name')->orderBy('agenda_finance_payments.paid_on')->orderBy('agenda_finance_payments.id');}
 public function rows(){return $this->selected()->paginate(30);}
 public function totals():array {$r=$this->query()->selectRaw("coalesce(sum(case when a.type='receivable' then agenda_finance_payments.amount_cents else 0 end),0) as incoming, coalesce(sum(case when a.type='payable' then agenda_finance_payments.amount_cents else 0 end),0) as outgoing")->first();return ['incoming'=>(int)$r->incoming,'outgoing'=>(int)$r->outgoing,'net'=>(int)$r->incoming-(int)$r->outgoing];}
 public function export(){
  $query=$this->selected();return response()->streamDownload(function()use($query){$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Data','Tipo','Descrição','Cliente / fornecedor','Categoria','Banco / conta','Forma de pagamento','Valor assinado (R$)','Estorno','Motivo'],';');
   foreach($query->cursor() as $r){$safe=fn($v)=>preg_match('/^[\s]*[=+@\-]/u',(string)$v)?"'".$v:$v;fputcsv($out,[$r->paid_on,$r->type==='receivable'?'Recebimento':'Pagamento',$safe($r->description),$safe($r->counterparty),$safe($r->category_name),$safe($r->bank_name),$safe($r->method_name),number_format($r->amount_cents/100,2,',',''),$r->reversal_of?'Sim':'Não',$safe($r->reason)],';');}fclose($out);
  },'virtus-agenda-financeiro-'.$this->from.'-'.$this->until.'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
 }
}
