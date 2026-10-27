<?php
namespace App\Filament\Pages;
use App\Services\AgendaBusiness as Business;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
class AgendaProdutos extends Page {
    use \App\Filament\Concerns\PaginaDaAgenda;

    protected static string $modulo = 'financeiro';

    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 use WithPagination;
 use \Livewire\WithFileUploads;
 public $photo=null;public bool $removePhoto=false;
 #[Locked] public int $photoInputVersion=0;
 #[Locked] public bool $hasSavedPhoto=false;
 public function clearPhoto():void {$this->guard();$this->photo=null;$this->removePhoto=true;$this->photoInputVersion++;$this->resetValidation('photo');}
 protected static ?string $slug='produtos';protected static ?string $title='Produtos';
 protected static string|\UnitEnum|null $navigationGroup='Seu estabelecimento';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-cube';protected static ?int $navigationSort=2;
 protected string $view='filament.pages.agenda-produtos';
 public string $search='';public string $filter='active';public array $form=[];public array $movement=['quantity'=>1,'direction'=>'in','reason'=>''];public bool $editing=false;
 #[Locked] public ?int $selected=null;#[Locked] public ?int $version=null;#[Locked] public string $requestId='';#[Locked] public bool $stockMode=false;
 public static function acessoSemModulo(): bool {return (bool)auth()->user()?->canAgendaProducts();}
 public static function getNavigationLabel():string {return __('Produtos');}
 public function getTitle():string {return __('Produtos');}
 public function mount():void {$this->guard();}
 private function guard():Business {$b=app(Business::class);$b->actor(auth()->user(),'agenda_products_manage');return $b;}
 public function updatedSearch():void {$this->resetPage();}public function updatedFilter():void {$this->resetPage();}
 public function rows(){return $this->guard()->query('agenda_products')->when($this->search,fn($q)=>$q->where(fn($q)=>$q->where('name','ilike','%'.$this->search.'%')->orWhere('code','ilike','%'.$this->search.'%')))->when(in_array($this->filter,['active','inactive']),fn($q)=>$q->where('active',$this->filter==='active'))->orderBy('name')->paginate(20);}
 public function open(?int $id=null,bool $stock=false):void {
  $b=$this->guard();abort_if($stock && !$id,422);$this->resetErrorBag();$p=$id?$b->query('agenda_products')->where('id',$id)->first():null;if($id)abort_unless($p,404);
  $this->selected=$id;$this->version=$p?->version;$this->stockMode=$stock;$this->requestId=(string)\Illuminate\Support\Str::uuid();$this->movement=['quantity'=>1,'direction'=>'in','reason'=>''];
  $this->photo=null;$this->removePhoto=false;
  $this->hasSavedPhoto=(bool)$p?->photo_path;$this->photoInputVersion++;
  $this->form=$p?['name'=>$p->name,'code'=>$p->code,'price'=>number_format($p->price_cents/100,2,'.',''),'cost'=>number_format($p->cost_cents/100,2,'.',''),'active'=>$p->active,'stock'=>$p->stock,'stock_reason'=>'']:['name'=>'','code'=>'','price'=>'0.00','cost'=>'0.00','active'=>true,'stock'=>0,'stock_reason'=>''];$this->editing=true;
 }
 public function save():void {
  $b=$this->guard();$this->resetErrorBag();
  if($this->stockMode){validator($this->movement,['quantity'=>'required|integer|min:1|max:1000000','direction'=>'required|in:in,out','reason'=>'required|string|min:3|max:240'])->validate();$b->stock(auth()->user(),$this->selected,(int)$this->movement['quantity']*($this->movement['direction']==='out'?-1:1),$this->movement['reason'],$this->requestId);}
  else {
   $this->validate(['photo'=>'nullable|image|mimes:jpg,jpeg,png,webp|max:4096']);$data=$this->form;$path=null;
   if($this->photo){$path=$this->photo->store('agenda-products/'.auth()->user()->tenant_id,'local');$data['photo_path']=$path;}
   elseif($this->removePhoto)$data['photo_path']=null;
   try{$b->product(auth()->user(),$data,$this->selected,$this->version);}catch(\Throwable $e){if($path)\Illuminate\Support\Facades\Storage::disk('local')->delete($path);throw $e;}
  }
  $this->editing=false;\Filament\Notifications\Notification::make()->success()->title(__('Produto atualizado'))->send();
 }
 public function history(){if(!$this->selected)return collect();return $this->guard()->query('agenda_stock_movements')->join('users as actor','actor.id','=','agenda_stock_movements.user_id')->select('agenda_stock_movements.*','actor.name as actor_name')->where('product_id',$this->selected)->orderByDesc('agenda_stock_movements.id')->limit(50)->get();}
}
