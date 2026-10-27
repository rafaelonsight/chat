<?php
namespace App\Services;

use App\Models\{User,AgendaProfessional};
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB,Password,RateLimiter};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgendaAccess
{
 public const LABELS=['ativo'=>'Acesso ativo','convite_pendente'=>'Aguardando definição de senha','redefinicao_obrigatoria'=>'Redefinição obrigatória','suspenso'=>'Acesso suspenso'];

 public function assertAdministrator(User $actor, ?AgendaProfessional $professional=null): void {
  $fresh=User::withoutGlobalScopes()->findOrFail($actor->id);
  abort_unless($fresh->tenant_id && $fresh->agenda_access_status==='ativo' && ($fresh->admin || $fresh->perfil==='admin') && (int)$fresh->tenant_id===TenantContext::get(),403);
  if($professional)abort_unless($professional->tenant_id===$fresh->tenant_id,403);
 }
 private function event(User $user,?User $actor,string $action,?AgendaProfessional $professional=null,array $metadata=[]): void {
  DB::table('agenda_access_events')->insert(['tenant_id'=>$user->tenant_id,'actor_id'=>$actor?->id,'user_id'=>$user->id,'professional_id'=>$professional?->id,'action'=>$action,'metadata'=>json_encode($metadata),'created_at'=>now()]);
 }
 public function createProfessional(User $actor,array $data,string $mode='new',?int $existing=null): AgendaProfessional {
  $this->assertAdministrator($actor);
  return DB::transaction(function()use($actor,$data,$mode,$existing){
   $professional=AgendaProfessional::create($data);
   $this->link($actor,$professional,$mode,$existing);
   return $professional->refresh();
  });
 }
 public function link(User $actor,AgendaProfessional $professional,string $mode='new',?int $existing=null): User {
  $this->assertAdministrator($actor,$professional);
  return DB::transaction(function()use($actor,$professional,$mode,$existing){
   $professional=AgendaProfessional::whereKey($professional->id)->lockForUpdate()->firstOrFail();
   if($professional->user_id)throw ValidationException::withMessages(['data.email'=>__('Este profissional já possui uma conta vinculada.')]);
   if($mode==='existing') {
    $user=User::withoutGlobalScopes()->where('tenant_id',$professional->tenant_id)->whereKey($existing)->lockForUpdate()->first();
    if(!$user || DB::table('agenda_professionals')->where('user_id',$user->id)->exists())throw ValidationException::withMessages(['data.access_user_id'=>__('Selecione uma conta disponível deste estabelecimento.')]);
    // Existing roles, password and access status are preserved deliberately.
   } elseif($mode==='new') {
    $email=Str::lower(trim((string)$professional->email));
    validator(['email'=>$email],['email'=>'required|email|max:255'])->validate();
    if(User::withoutGlobalScopes()->whereRaw('lower(email) = ?',[$email])->exists())throw ValidationException::withMessages(['data.email'=>__('Este e-mail já está em uso. Para uma conta existente, escolha vincular usuário.')]);
    try {
     // Savepoint keeps the outer registration transaction usable after a unique-key race.
     $user=DB::transaction(function()use($professional,$email){
      $user=new User(['tenant_id'=>$professional->tenant_id,'name'=>$professional->nome,'email'=>$email,'password'=>Str::password(64),'perfil'=>'profissional','apelido'=>mb_substr($professional->nome,0,40),'telefone'=>$professional->telefone]);
      $user->forceFill(['agenda_access_status'=>'convite_pendente'])->save();return $user;
     });
    } catch(QueryException $e){if($e->getCode()!=='23505')throw $e;throw ValidationException::withMessages(['data.email'=>__('Este e-mail já está em uso.')]);}
   } else {throw ValidationException::withMessages(['data.access_mode'=>__('Selecione como configurar o acesso.')]);}
   $professional->forceFill(['user_id'=>$user->id,'email'=>$user->email])->save();
   $this->event($user,$actor,'profissional_vinculado',$professional);
   if($mode==='new')$this->scheduleDelivery($user,$professional,'convite');
   return $user;
  });
 }
 public function userFor(AgendaProfessional $professional): ?User {
  return $professional->user_id ? User::withoutGlobalScopes()->where('tenant_id',$professional->tenant_id)->find($professional->user_id) : null;
 }
 public function manage(User $actor,AgendaProfessional $professional,string $action,?string $email=null): void {
  $this->assertAdministrator($actor,$professional);
  try { DB::transaction(function()use($actor,$professional,$action,$email){
   // Lock the account before its members to serialise last-administrator checks.
   DB::table('tenants')->where('id',$actor->tenant_id)->lockForUpdate()->first();
   $this->assertAdministrator($actor,$professional);
   $professional=AgendaProfessional::whereKey($professional->id)->lockForUpdate()->firstOrFail();
   $user=User::withoutGlobalScopes()->where('tenant_id',$actor->tenant_id)->whereKey($professional->user_id)->lockForUpdate()->firstOrFail();
   if($user->id===$actor->id || $user->super_admin || $user->operador)throw ValidationException::withMessages(['access'=>__('Esta conta é protegida. Use o próprio perfil para alterar sua senha.')]);
   if(in_array($action,['suspend','force_reset','change_email'],true) && ($user->admin || $user->perfil==='admin') && User::withoutGlobalScopes()->where('tenant_id',$actor->tenant_id)->where('agenda_access_status','ativo')->where(fn($q)=>$q->where('admin',true)->orWhere('perfil','admin'))->count()<=1)throw ValidationException::withMessages(['access'=>__('O estabelecimento precisa manter um administrador ativo.')]);
   if(in_array($action,['invite','reset'],true)){
    if($user->agenda_access_status==='suspenso')throw ValidationException::withMessages(['access'=>__('Reative o acesso antes de enviar um link de senha.')]);
    $key='agenda-access-mail:'.$user->id;
    if(!RateLimiter::attempt($key,1,fn()=>true,60))throw ValidationException::withMessages(['access'=>__('Aguarde um minuto antes de reenviar.')]);
    $this->scheduleDelivery($user,$professional,$action==='invite'?'convite':'redefinicao');
   } elseif($action==='suspend') {
    $this->revoke($user,['agenda_access_status'=>'suspenso']);
   } elseif($action==='reactivate') {
    // Reactivation never restores a revoked password.
    $this->revoke($user,['agenda_access_status'=>'redefinicao_obrigatoria']);
    $this->scheduleDelivery($user,$professional,'redefinicao');
   } elseif($action==='force_reset') {
    if($user->agenda_access_status==='suspenso')throw ValidationException::withMessages(['access'=>__('Reative o acesso antes de redefinir a senha.')]);
    $this->revoke($user,['agenda_access_status'=>'redefinicao_obrigatoria']);
    $this->scheduleDelivery($user,$professional,'redefinicao');
   } elseif($action==='change_email') {
    $email=Str::lower(trim((string)$email));
    validator(['email'=>$email],['email'=>'required|email|max:255'])->validate();
    if(User::withoutGlobalScopes()->whereRaw('lower(email) = ?',[$email])->whereKeyNot($user->id)->exists())throw ValidationException::withMessages(['email'=>__('Este e-mail já está em uso.')]);
    Password::broker()->deleteToken($user);
    $this->revoke($user,['email'=>$email,'email_verified_at'=>null,'agenda_access_status'=>$user->agenda_access_status==='suspenso'?'suspenso':'redefinicao_obrigatoria']);
    $professional->forceFill(['email'=>$email])->save();
    if($user->agenda_access_status!=='suspenso')$this->scheduleDelivery($user,$professional,'redefinicao');
   } else {abort(422);}
   $this->event($user,$actor,$action,$professional);
  }); } catch(QueryException $e) {
   if($action!=='change_email' || $e->getCode()!=='23505')throw $e;
   throw ValidationException::withMessages(['email'=>__('Este e-mail já está em uso.')]);
  }
 }
 public function revoke(User $user,array $changes=[]): void {
  Password::broker()->deleteToken($user);
  DB::table('agenda_access_deliveries')->where('tenant_id',$user->tenant_id)->where('user_id',$user->id)->whereIn('status',['pendente','falhou'])->update(['status'=>'substituido','updated_at'=>now()]);
  $user->forceFill(array_merge(['agenda_access_version'=>$user->agenda_access_version+1,'remember_token'=>Str::random(60),'password'=>Str::password(64)],$changes))->save();
  DB::table('sessions')->where('user_id',$user->id)->delete();
 }
 private function scheduleDelivery(User $user,AgendaProfessional $professional,string $purpose): void {
  DB::table('agenda_access_deliveries')->where('user_id',$user->id)->whereIn('status',['pendente','falhou'])->update(['status'=>'substituido','updated_at'=>now()]);
  $id=DB::table('agenda_access_deliveries')->insertGetId(['tenant_id'=>$user->tenant_id,'user_id'=>$user->id,'professional_id'=>$professional->id,'purpose'=>$purpose,'status'=>'pendente','access_version'=>$user->agenda_access_version,'created_at'=>now(),'updated_at'=>now()]);
  // SMTP never holds the registration or access-management transaction open.
  DB::afterCommit(fn()=> $this->deliver($id));
 }
 public function deliver(int $id): void {
  $delivery=DB::table('agenda_access_deliveries')->find($id);
  if(!$delivery || $delivery->status!=='pendente')return;
  $user=User::withoutGlobalScopes()->where('tenant_id',$delivery->tenant_id)->find($delivery->user_id);
  if(!$user || $user->agenda_access_status==='suspenso' || (int)$user->agenda_access_version!==(int)$delivery->access_version){DB::table('agenda_access_deliveries')->where('id',$id)->update(['status'=>'substituido','updated_at'=>now()]);return;}
  if(!DB::table('agenda_access_deliveries')->where('id',$id)->where('status','pendente')->update(['status'=>'enviando','updated_at'=>now()]))return;
  try {
   if(config('mail.default')==='log')throw new \RuntimeException('MAIL_UNAVAILABLE');
   config(['mail.mailers.smtp.timeout'=>15]);
   $prepared=DB::transaction(function()use($delivery,$id){
    $fresh=User::withoutGlobalScopes()->where('tenant_id',$delivery->tenant_id)->whereKey($delivery->user_id)->lockForUpdate()->first();
    if(!$fresh || $fresh->agenda_access_status==='suspenso' || $fresh->agenda_access_version!==(int)$delivery->access_version){DB::table('agenda_access_deliveries')->where('id',$id)->update(['status'=>'substituido','updated_at'=>now()]);return null;}
    return [$fresh,Password::broker()->createToken($fresh)];
   });
   if(!$prepared)return;
   [$user,$token]=$prepared;
   $notification=new \App\Notifications\AgendaAccessInvitation($token,$delivery->purpose==='convite');
   $notification->url=Filament::getPanel('admin')->getResetPasswordUrl($token,$user);
   $user->notify($notification);
   DB::table('agenda_access_deliveries')->where('id',$id)->update(['status'=>'aceito','accepted_at'=>now(),'updated_at'=>now()]);
  }catch(\Throwable $e){
   // Do not log transport exceptions: they may contain addresses, credentials or links.
   DB::table('agenda_access_deliveries')->where('id',$id)->update(['status'=>'falhou','failure_code'=>'MAIL_NOT_ACCEPTED','updated_at'=>now()]);
  }
 }
 public function completePassword(User $user,string $password): void {
  $user=User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);
  if(!$user->tenant_id || $user->agenda_access_status==='suspenso' || ($user->isAgendaProfessional() && !$user->agendaProfessional()))throw ValidationException::withMessages(['password'=>__('Este acesso não está disponível. Fale com o administrador.')]);
  validator(['password'=>$password],['password'=>['required',\Illuminate\Validation\Rules\Password::defaults()]])->validate();
  $user->forceFill(['password'=>$password,'agenda_access_status'=>'ativo','agenda_access_version'=>$user->agenda_access_version+1,'remember_token'=>Str::random(60),'email_verified_at'=>$user->email_verified_at ?? now()])->save();
  DB::table('sessions')->where('user_id',$user->id)->delete();
  $this->event($user,$user,'senha_definida',$user->agendaProfessional());
 }
 public function latestDelivery(AgendaProfessional $professional): ?object {
  return DB::table('agenda_access_deliveries')->where('tenant_id',$professional->tenant_id)->where('professional_id',$professional->id)->orderByDesc('id')->first();
 }
 public function selfPasswordChanged(User $user): void {
  abort_unless(auth()->id()===$user->id,403);
  DB::transaction(function()use($user){
   $fresh=User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->firstOrFail();
   $fresh->forceFill(['agenda_access_version'=>$fresh->agenda_access_version+1,'remember_token'=>Str::random(60)])->save();
   Password::broker()->deleteToken($fresh);DB::table('sessions')->where('user_id',$fresh->id)->delete();
   $this->event($fresh,$fresh,'senha_alterada',$fresh->agendaProfessional());
   auth()->setUser($fresh);session()->put('agenda.access_version',$fresh->agenda_access_version);
  });
 }
}
