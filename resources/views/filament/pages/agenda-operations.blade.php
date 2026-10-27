<x-filament-panels::page>
@php($summary=$this->summary())
<div wire:poll.30s>
 <p class="text-sm text-gray-600 dark:text-gray-300">Acompanhe a operação e a proteção dos dados do seu estabelecimento. Verificação automática a cada dois minutos.</p>
 <div class="mt-6 grid gap-4 md:grid-cols-2">
  <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
   <h2 class="font-semibold">Serviços e WhatsApp</h2>
   @if(empty($summary['monitor']['checked_at']))<p class="mt-3">A primeira verificação ainda está pendente.</p>
   @else
   <p class="mt-2 text-sm">Última verificação: {{ \Carbon\CarbonImmutable::parse($summary['monitor']['checked_at'])->timezone(\App\Models\AgendaSettings::current()->timezone)->format('d/m/Y H:i') }}</p>
   @if(\Carbon\CarbonImmutable::parse($summary['monitor']['checked_at'])->lt(now()->subMinutes(6)))<p role="alert" class="mt-2 text-amber-700">A verificação está atrasada. Solicite a revisão do monitoramento.</p>@endif
   <dl class="mt-4 space-y-3">@foreach($summary['monitor']['checks'] as $key=>$check)
    <div class="flex items-start justify-between gap-3"><dt>{{ \App\Services\AgendaOperations::LABELS[explode(':',$key)[0]]??'Serviço' }}</dt><dd class="font-medium">{{ ['ok'=>'Normal','info'=>'Não ativado','warning'=>'Atenção','critical'=>'Precisa de revisão'][$check['state']]??'Verificando' }}</dd></div>
   @endforeach</dl>
   @endif
  </section>
  <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
   <h2 class="font-semibold">Proteção dos dados</h2>
   <p class="mt-3">Destino configurado: <strong>Local neste servidor</strong></p>
   <p class="mt-3 text-sm">Backup criptografado a cada hora, com retenção de cópias recentes, diárias e semanais.</p>
   @if(!empty($summary['backup']['last_success']))<p class="mt-3">Última cópia local: <strong>{{ \Carbon\CarbonImmutable::parse($summary['backup']['last_success'])->timezone(\App\Models\AgendaSettings::current()->timezone)->format('d/m/Y H:i') }}</strong></p>@else<p class="mt-3">A primeira cópia ainda está pendente.</p>@endif
   <p class="mt-3">Cópia fora do servidor: <strong>{{ ($summary['backup']['external_status']??'not_enabled')==='ok'?'Concluída':'Não ativada (opcional)' }}</strong></p>
   <p class="mt-3 text-sm">Uma cópia local protege contra perda de arquivos, mas não contra a perda completa do servidor.</p>
   <p class="mt-3 text-sm">A chave de recuperação deve ficar guardada também fora deste servidor, em local seguro. Nunca a envie por conversa ou WhatsApp.</p>
   <p class="mt-3 text-sm">Alertas aparecem para administradores quando uma falha é confirmada e quando o serviço se recupera. Se o servidor inteiro ficar indisponível, é necessário monitor externo.</p>
  </section>
 </div>
</div>
</x-filament-panels::page>
