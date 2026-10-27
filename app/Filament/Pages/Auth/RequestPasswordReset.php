<?php
namespace App\Filament\Pages\Auth;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 public function request(): void {
  try {$this->rateLimit(2);}catch(\DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException $e){$this->getRateLimitedNotification($e)?->send();return;}
  $data=$this->form->getState();
  $status=\Illuminate\Support\Facades\Password::broker(\Filament\Facades\Filament::getAuthPasswordBroker())->sendResetLink($this->getCredentialsFromFormData($data),function($user,$token){
   if(!$user->canDefineAgendaPassword())return;
   $notification=new \App\Notifications\AgendaResetPassword($token);
   $notification->url=\Filament\Facades\Filament::getResetPasswordUrl($token,$user);
   $user->notify($notification);
  });
  $this->getSentNotification($status)?->send();$this->form->fill();
 }
 protected function getCredentialsFromFormData(array $data): array { return ['email'=>Str::lower(trim($data['email']))]; }
 protected function getSentNotification(string $status): ?Notification { return Notification::make()->success()->title('Se o e-mail estiver cadastrado, você receberá um link para redefinir sua senha.'); }
 protected function getFailureNotification(string $status): ?Notification { return $this->getSentNotification($status); }
}
