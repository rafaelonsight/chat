@if(app()->getLocale()==='en_GB')
Your Virtus Chat access code is: {{ $codigo }}
Return to Virtus Chat and enter the six digits to sign in.
Valid for 10 minutes and one use only.
Never share your code. If you did not request access, please ignore this email.
@else
Seu token de acesso ao Virtus Chat é: {{ $codigo }}

Volte à tela do Virtus Chat e digite os seis números para entrar.
Válido por 10 minutos e para um único uso.

Nunca compartilhe seu token. Se você não solicitou este acesso, ignore este e-mail.

@endif
