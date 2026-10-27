<?php
namespace App\Filament\Pages\Auth;

use App\Services\AgendaAccess;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{DB,Password};

class ResetPassword extends \Filament\Auth\Pages\PasswordReset\ResetPassword
{
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 public function resetPassword(): ?PasswordResetResponse {
  try {$this->rateLimit(2);}catch(TooManyRequestsException $e){$this->getRateLimitedNotification($e)?->send();return null;}
  if($this->isResetPasswordRateLimited($this->email))return null;
  $data=$this->form->getState();$data['email']=$this->email;$data['token']=$this->token;
  $status=DB::transaction(function()use($data){
   // Same lock order as administrative revocation: identity, then recovery token.
   DB::table('users')->where('email',$data['email'])->lockForUpdate()->first();
   DB::table(config('auth.passwords.users.table'))->where('email',$data['email'])->lockForUpdate()->first();
   return Password::broker(Filament::getAuthPasswordBroker())->reset($this->getCredentialsFromFormData($data),function($user)use($data){
    app(AgendaAccess::class)->completePassword($user,$data['password']);
    event(new \Illuminate\Auth\Events\PasswordReset($user));
   });
  });
  if($status===Password::PASSWORD_RESET){Notification::make()->success()->title(__($status))->send();return app(PasswordResetResponse::class);}
  Notification::make()->danger()->title(__($status))->send();return null;
 }
}
