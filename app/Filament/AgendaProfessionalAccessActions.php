<?php
namespace App\Filament;
use App\Models\AgendaProfessional;
use App\Services\AgendaAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class AgendaProfessionalAccessActions
{
 public static function make(): array {
  $actions=[];
  foreach([
   'invite'=>['Reenviar convite','Envia um novo link para definir a senha. O link anterior deixa de funcionar.'],
   'reset'=>['Enviar redefinição de senha','Envia um link de recuperação sem interromper o acesso atual.'],
   'force_reset'=>['Forçar redefinição','Encerra as sessões e exige uma nova senha antes de entrar novamente.'],
   'suspend'=>['Suspender acesso','Encerra as sessões e impede o login. O cadastro e os compromissos são preservados.'],
   'reactivate'=>['Reativar acesso','Envia um link para definir uma nova senha. A senha anterior não será restaurada.'],
  ] as $name=>[$label,$description]) {
   $actions[]=Action::make($name)->label(__($label))->requiresConfirmation()->modalDescription(__($description))
    ->color(in_array($name,['suspend','force_reset'])?'danger':'primary')
    ->visible(function(AgendaProfessional $record)use($name){
     $u=app(AgendaAccess::class)->userFor($record);
     if(!$u || $u->id===auth()->id() || $u->super_admin || $u->operador)return false;
     if($name==='reactivate')return $u->agenda_access_status==='suspenso';
     if($u->agenda_access_status==='suspenso')return false;
     return $name!=='invite' || $u->agenda_access_status==='convite_pendente';
    })
    ->action(fn(AgendaProfessional $record)=>static::execute($record,$name));
  }
  $actions[]=Action::make('change_email')->label(__('Alterar e-mail de acesso'))->schema([
   TextInput::make('email')->label(__('Novo e-mail de acesso'))->email()->required()->maxLength(255),
  ])->modalDescription(__('O acesso atual será revogado. Um novo link será enviado para o novo e-mail, exceto se o acesso estiver suspenso.'))
   ->visible(function(AgendaProfessional $record){$u=app(AgendaAccess::class)->userFor($record);return $u && $u->id!==auth()->id() && !$u->super_admin && !$u->operador;})
   ->action(fn(AgendaProfessional $record,array $data)=>static::execute($record,'change_email',$data['email']));
  return $actions;
 }
 private static function execute(AgendaProfessional $record,string $action,?string $email=null): void {
  try {
   $previousDelivery=app(AgendaAccess::class)->latestDelivery($record)?->id;
   app(AgendaAccess::class)->manage(auth()->user(),$record,$action,$email);
   $delivery=app(AgendaAccess::class)->latestDelivery($record);
   $mailAction=$delivery && $delivery->id!==$previousDelivery;
   $notification=Notification::make()->title(__('Acesso atualizado'));
   if($mailAction && $delivery?->status==='falhou')$notification->warning()->body(__('Alteração salva. O e-mail não foi aceito pelo servidor; tente reenviar.'));
   elseif($mailAction && $delivery?->status==='aceito')$notification->success()->body(__('Envio aceito pelo servidor de e-mail. A entrega na caixa de entrada depende do provedor.'));
   else $notification->success();
   $notification->send();
  }catch(ValidationException $e){Notification::make()->danger()->title(__('Não foi possível atualizar o acesso'))->body(collect($e->errors())->flatten()->first())->send();}
 }
}
