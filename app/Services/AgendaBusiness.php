<?php
namespace App\Services;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class AgendaBusiness {
 public function actor(User $actor,string $permission):User {
  $u=app(AgendaCalendar::class)->actor($actor);
  abort_unless($u->admin || $u->perfil==='admin' || (!$u->ehAuditor() && $u->{$permission}) || ($permission==='agenda_finance_view' && ($u->agenda_finance_view || $u->agenda_finance_manage)),403);
  if($permission!=='agenda_finance_view')abort_if($u->ehAuditor(),403);
  return $u;
 }
 public function query(string $table){return DB::table($table)->where($table.'.tenant_id',TenantContext::get()?:0);}
 public function money(mixed $value,bool $zero=false):int {
  $s=str_replace(',','.',trim((string)$value));
  if(!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$s))$this->fail('Informe um valor com até duas casas decimais.');
  [$whole,$fraction]=array_pad(explode('.',$s),2,'');$cents=(int)$whole*100+(int)str_pad($fraction,2,'0');
  if(!$zero && $cents===0)$this->fail('O valor deve ser maior que zero.');return $cents;
 }
 private function instant():string {return now()->toIso8601String();}
 public static function format(int $cents):string{return 'R$ '.number_format($cents/100,2,',','.');}
 private function fail(string $message):never {throw ValidationException::withMessages(['business'=>__($message)]);}
 private function event(User $u,string $entity,int $id,string $action,array $details):void {$this->query('agenda_business_events')->insert(['tenant_id'=>$u->tenant_id,'user_id'=>$u->id,'entity'=>$entity,'entity_id'=>$id,'action'=>$action,'details'=>json_encode($details),'created_at'=>$this->instant()]);}
 private function row(string $table,int $id,bool $lock=false):object {$q=$this->query($table)->where('id',$id);if($lock)$q->lockForUpdate();$r=$q->first();abort_unless($r,404);return $r;}
 public function product(User $u,array $data,?int $id=null,?int $version=null):int {
  $u=$this->actor($u,'agenda_products_manage');validator($data,['name'=>'required|string|max:120','code'=>'nullable|string|max:60','active'=>'required|boolean','price'=>'required','cost'=>'required'])->validate();
  $values=['name'=>trim($data['name']),'code'=>trim($data['code']??'')?:null,'price_cents'=>$this->money($data['price'],true),'cost_cents'=>$this->money($data['cost'],true),'active'=>$data['active'],'updated_at'=>$this->instant()];
  if(array_key_exists('photo_path',$data)){$path=$data['photo_path'];abort_unless(!$path || str_starts_with($path,'agenda-products/'.$u->tenant_id.'/'),422);$values['photo_path']=$path;}
  if(isset($data['stock']))validator($data,['stock'=>'integer|min:0|max:1000000'])->validate();
  return DB::transaction(function()use($u,$values,$data,$id,$version){DB::table('tenants')->where('id',$u->tenant_id)->lockForUpdate()->first();$this->actor($u,'agenda_products_manage');$old=$id?$this->row('agenda_products',$id,true):null;
   if($old && $old->version!==$version)$this->fail('Este produto mudou. Abra novamente para editar.');
   if($values['code'] && $this->query('agenda_products')->where('code',$values['code'])->when($id,fn($q)=>$q->where('id','<>',$id))->exists())$this->fail('Este código já está em uso.');
   if($old)$this->query('agenda_products')->where('id',$id)->update($values+['version'=>$old->version+1]);else $id=DB::table('agenda_products')->insertGetId($values+['tenant_id'=>$u->tenant_id,'created_at'=>$this->instant()]);
   $this->event($u,'product',$id,$old?'updated':'created',['before'=>$old,'after'=>$values]);
   if(isset($data['stock']) && (int)$data['stock']!==($old->stock??0)){$reason=$old?($data['stock_reason']??''):'Estoque inicial';$this->stock($u,$id,(int)$data['stock']-($old->stock??0),$reason,(string)\Illuminate\Support\Str::uuid());}
   return $id;
  });
 }
 public function stock(User $u,int $id,int $quantity,string $reason,string $request):void {
  $u=$this->actor($u,'agenda_products_manage');validator(compact('quantity','reason','request'),['quantity'=>'required|integer|between:-1000000,1000000|not_in:0','reason'=>'required|string|min:3|max:240','request'=>'required|uuid'])->validate();
  DB::transaction(function()use($u,$id,$quantity,$reason,$request){$p=$this->row('agenda_products',$id,true);$this->actor($u,'agenda_products_manage');$replay=$this->query('agenda_stock_movements')->where('request_id',$request)->first();if($replay){if($replay->product_id!==$id || $replay->quantity!==$quantity || $replay->reason!==$reason)$this->fail('Esta operação já foi utilizada. Reabra o movimento.');return;}
   $balance=$p->stock+$quantity;if($balance<0 || $balance>2000000000)$this->fail('Saldo insuficiente ou quantidade fora do limite.');
   DB::table('agenda_stock_movements')->insert(['tenant_id'=>$u->tenant_id,'product_id'=>$id,'user_id'=>$u->id,'quantity'=>$quantity,'balance'=>$balance,'reason'=>$reason,'request_id'=>$request,'created_at'=>$this->instant()]);
   $this->query('agenda_products')->where('id',$id)->update(['stock'=>$balance,'version'=>$p->version+1,'updated_at'=>$this->instant()]);
  });
 }
 public function option(User $u,string $type,string $name):void {
  app(AgendaCalendar::class)->administrator($u);validator(compact('type','name'),['type'=>'required|in:category,method,bank','name'=>'required|string|min:2|max:80'])->validate();
  DB::table('agenda_finance_options')->insertOrIgnore(['tenant_id'=>$u->tenant_id,'type'=>$type,'name'=>trim($name),'created_at'=>$this->instant(),'updated_at'=>$this->instant()]);
 }
 private function optionId(mixed $id,string $type):?int {if(!$id)return null;$r=$this->row('agenda_finance_options',(int)$id);abort_unless($r->type===$type,422);return $r->id;}
 public function bank(User $u,array $data,?int $id=null,?int $version=null):int {
  app(AgendaCalendar::class)->administrator($u);
  validator($data,['name'=>'required|string|min:2|max:80','account_type'=>'required|in:checking,savings,cash','bank_name'=>'nullable|required_unless:account_type,cash|string|max:80','agency'=>['nullable','required_unless:account_type,cash','regex:/^[0-9Xx -]{1,24}$/'],'account_number'=>['nullable','required_unless:account_type,cash','regex:/^[0-9Xx -]{1,32}$/'],'opening_on'=>'required|date_format:Y-m-d|before_or_equal:'.now(\App\Models\AgendaSettings::current()->timezone)->toDateString(),'opening_amount'=>'required','active'=>'required|boolean'])->validate();
  $s=trim((string)$data['opening_amount']);$opening=$this->money(ltrim($s,'-'),true)*(str_starts_with($s,'-')?-1:1);
  $cash=$data['account_type']==='cash';$values=['name'=>trim($data['name']),'account_type'=>$data['account_type'],'bank_name'=>$cash?null:trim($data['bank_name']),'agency'=>$cash?null:trim($data['agency']),'account_number'=>$cash?null:trim($data['account_number']),'opening_cents'=>$opening,'opening_on'=>$data['opening_on'],'active'=>$data['active'],'updated_at'=>$this->instant()];
  return DB::transaction(function()use($u,$id,$version,$values){DB::table('tenants')->where('id',$u->tenant_id)->lockForUpdate()->first();app(AgendaCalendar::class)->administrator($u);$old=$id?$this->row('agenda_finance_options',$id,true):null;
   if($old && ($old->type!=='bank' || $old->version!==$version))$this->fail('Este cadastro mudou. Abra novamente.');
   if($this->query('agenda_finance_options')->where('type','bank')->where('name',$values['name'])->when($id,fn($q)=>$q->where('id','<>',$id))->exists())$this->fail('Este nome já está em uso.');
   $payments=$id?$this->query('agenda_finance_payments')->where('bank_id',$id):null;
   if($old && $payments->exists() && ($old->account_type && ($old->account_type!==$values['account_type'] || $old->opening_cents!==$values['opening_cents'] || $old->opening_on!==$values['opening_on'])))$this->fail('Conta com movimentações: preserve o tipo, a data e o saldo inicial.');
   if($old && $payments->where('paid_on','<',$values['opening_on'])->exists())$this->fail('A data inicial não pode excluir pagamentos já registrados.');
   if($old)$this->query('agenda_finance_options')->where('id',$id)->update($values+['version'=>$old->version+1]);else $id=DB::table('agenda_finance_options')->insertGetId($values+['tenant_id'=>$u->tenant_id,'type'=>'bank','created_at'=>$this->instant()]);
   $this->event($u,'bank',$id,$old?'updated':'created',['before'=>$old,'after'=>$values]);return $id;
  });
 }
 public function bankBalance(int $id):int {
  $bank=$this->row('agenda_finance_options',$id);abort_unless($bank->type==='bank',404);
  $movement=$this->query('agenda_finance_payments')->join('agenda_finance_accounts as a',function($j){$j->on('a.id','=','agenda_finance_payments.account_id')->on('a.tenant_id','=','agenda_finance_payments.tenant_id');})->where('bank_id',$id)->when($bank->opening_on,fn($q)=>$q->where('paid_on','>=',$bank->opening_on))->selectRaw("coalesce(sum(case when a.type='receivable' then agenda_finance_payments.amount_cents else -agenda_finance_payments.amount_cents end),0) as total")->value('total');
  return $bank->opening_cents+(int)$movement;
 }
 public function account(User $u,array $data,?int $id=null,?int $version=null):int {
  $u=$this->actor($u,'agenda_finance_manage');validator($data,['type'=>'required|in:payable,receivable','description'=>'required|string|max:180','counterparty'=>'nullable|string|max:180','issued_on'=>'required|date_format:Y-m-d','due_on'=>'required|date_format:Y-m-d|after_or_equal:issued_on'])->validate();
  $values=['type'=>$data['type'],'description'=>trim($data['description']),'counterparty'=>trim($data['counterparty']??'')?:null,'issued_on'=>$data['issued_on'],'due_on'=>$data['due_on'],'category_id'=>$this->optionId($data['category_id']??null,'category'),'amount_cents'=>$this->money($data['amount']),'updated_at'=>$this->instant()];
  return DB::transaction(function()use($u,$values,$id,$version){$this->actor($u,'agenda_finance_manage');$old=$id?$this->row('agenda_finance_accounts',$id,true):null;
   if($old && ($old->version!==$version || $old->cancelled_at))$this->fail('Este lançamento mudou ou foi cancelado. Abra novamente.');
   if($old && ($old->paid_cents>$values['amount_cents'] || ($old->paid_cents>0 && $old->type!==$values['type'])))$this->fail('Estorne os pagamentos antes de alterar o tipo ou reduzir o valor abaixo do quitado.');
   if($old)$this->query('agenda_finance_accounts')->where('id',$id)->update($values+['version'=>$old->version+1]);else $id=DB::table('agenda_finance_accounts')->insertGetId($values+['tenant_id'=>$u->tenant_id,'created_at'=>$this->instant()]);
   $this->event($u,'account',$id,$old?'updated':'created',['before'=>$old,'after'=>$values]);return $id;
  });
 }
 public function payment(User $u,int $id,array $data,string $request):void {
  $bank=$this->optionId($data['bank_id']??null,'bank');
  $u=$this->actor($u,'agenda_finance_manage');validator($data+['request'=>$request],['paid_on'=>'required|date_format:Y-m-d|before_or_equal:'.now(\App\Models\AgendaSettings::current()->timezone)->toDateString(),'request'=>'required|uuid'])->validate();$amount=$this->money($data['amount']);$method=$this->optionId($data['method_id']??null,'method');
  DB::transaction(function()use($u,$id,$data,$request,$amount,$method,$bank){$a=$this->row('agenda_finance_accounts',$id,true);$this->actor($u,'agenda_finance_manage');$replay=$this->query('agenda_finance_payments')->where('request_id',$request)->first();if($replay){if($replay->account_id!==$id || $replay->amount_cents!==$amount || $replay->paid_on!==$data['paid_on'] || $replay->method_id!==$method || $replay->bank_id!==$bank)$this->fail('Esta operação já foi utilizada. Abra novamente o pagamento.');return;}
   if($bank){$destination=$this->row('agenda_finance_options',$bank,true);if(!$destination->active || ($destination->opening_on && $data['paid_on']<$destination->opening_on))$this->fail('Conta inativa ou pagamento anterior ao saldo inicial.');}
   if($a->cancelled_at || $amount>$a->amount_cents-$a->paid_cents)$this->fail('O valor excede o saldo ou a conta foi cancelada.');
   DB::table('agenda_finance_payments')->insert(['tenant_id'=>$u->tenant_id,'account_id'=>$id,'user_id'=>$u->id,'method_id'=>$method,'bank_id'=>$bank,'amount_cents'=>$amount,'paid_on'=>$data['paid_on'],'request_id'=>$request,'created_at'=>$this->instant()]);
   $this->query('agenda_finance_accounts')->where('id',$id)->update(['paid_cents'=>$a->paid_cents+$amount,'version'=>$a->version+1,'updated_at'=>$this->instant()]);
  });
 }
 public function reverse(User $u,int $account,int $payment,string $reason,string $request):void {
  $u=$this->actor($u,'agenda_finance_manage');validator(compact('reason','request'),['reason'=>'required|string|min:3|max:240','request'=>'required|uuid'])->validate();
  DB::transaction(function()use($u,$account,$payment,$reason,$request){$a=$this->row('agenda_finance_accounts',$account,true);$this->actor($u,'agenda_finance_manage');$p=$this->row('agenda_finance_payments',$payment);abort_unless($p->account_id===$account && !$p->reversal_of,404);
   if($this->query('agenda_finance_payments')->where('reversal_of',$payment)->exists())return;
   if($this->query('agenda_finance_payments')->where('request_id',$request)->exists())$this->fail('Esta operação já foi utilizada.');
   DB::table('agenda_finance_payments')->insert(['tenant_id'=>$u->tenant_id,'account_id'=>$account,'user_id'=>$u->id,'method_id'=>$p->method_id,'bank_id'=>$p->bank_id,'amount_cents'=>-$p->amount_cents,'paid_on'=>now(\App\Models\AgendaSettings::current()->timezone)->toDateString(),'reversal_of'=>$payment,'reason'=>$reason,'request_id'=>$request,'created_at'=>$this->instant()]);
   $this->query('agenda_finance_accounts')->where('id',$account)->update(['paid_cents'=>$a->paid_cents-$p->amount_cents,'version'=>$a->version+1,'updated_at'=>$this->instant()]);
  });
 }
 public function cancel(User $u,int $id,string $reason):void {
  $u=$this->actor($u,'agenda_finance_manage');validator(compact('reason'),['reason'=>'required|string|min:3|max:240'])->validate();
  DB::transaction(function()use($u,$id,$reason){$a=$this->row('agenda_finance_accounts',$id,true);$this->actor($u,'agenda_finance_manage');if($a->cancelled_at)return;if($a->paid_cents)$this->fail('Estorne os pagamentos antes de cancelar.');$this->query('agenda_finance_accounts')->where('id',$id)->update(['cancelled_at'=>$this->instant(),'version'=>$a->version+1,'updated_at'=>$this->instant()]);$this->event($u,'account',$id,'cancelled',['reason'=>$reason]);});
 }
}
