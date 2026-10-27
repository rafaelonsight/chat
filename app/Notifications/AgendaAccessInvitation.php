<?php
namespace App\Notifications;

class AgendaAccessInvitation extends AgendaResetPassword
{
 public function __construct(string $token,public bool $invitation=true){parent::__construct($token);}
 public function toMail($notifiable): \Illuminate\Notifications\Messages\MailMessage {
  $mail=parent::toMail($notifiable);
  if($this->invitation){
   $en=\App\Support\Idiomas::interface($notifiable)==='en_GB';
   $mail->subject($en?'Your access · Virtus Agenda':'Seu acesso · Virtus Agenda');
   $copy=$mail->viewData['copy'];
   $copy['subject']=$mail->subject;
   $copy['title']=$en?'Welcome to your workspace':'Seu acesso está pronto';
   $copy['intro']=$en?'Your professional account is ready. Set your password to access your own workspace.':'Sua conta de profissional está pronta. Defina sua senha para acessar sua área no Virtus Agenda.';
   $copy['button']=$en?'Set password':'Definir minha senha';
   $mail->viewData['copy']=$copy;
  }
  return $mail;
 }
}
