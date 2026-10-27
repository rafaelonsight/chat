<x-filament-panels::page>
 <div class="va-cal-panel" @if($state==='connecting') wire:poll.5s="refreshQr" @endif>
  <header class="va-cal-heading"><div><span class="va-eyebrow">{{ __('WhatsApp não oficial') }}</span><h2>{{ __('Conectar WhatsApp por QR Code') }}</h2></div><a href="/admin/canais" wire:navigate class="va-cal-secondary">{{ __('Voltar aos canais') }}</a></header>
  @if($errors->any())<div class="va-cal-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
  @if($state==='open')<div class="va-cal-empty"><h3>{{ __('WhatsApp conectado') }}</h3><p>{{ __('Seu aparelho foi vinculado com sucesso.') }}</p><button type="button" class="va-cal-danger" wire:confirm="{{ __('Desconectar este WhatsApp?') }}" wire:click="disconnect">{{ __('Desconectar') }}</button></div>
  @else
   <div class="va-qr-connect"><div class="va-qr-frame">@if($qr)<img src="{{ $qr }}" alt="{{ __('QR Code para conectar o WhatsApp') }}" width="280" height="280">@else<p>{{ $state==='error'?__('Não foi possível carregar o código.'):__('Preparando QR Code…') }}</p>@endif</div>
    <div><h3>{{ __('Leia o código com seu WhatsApp') }}</h3><ol><li>{{ __('Abra o WhatsApp no celular.') }}</li><li>{{ __('Acesse Configurações ou o menu ⋮ e toque em Aparelhos conectados.') }}</li><li>{{ __('Toque em Conectar um aparelho e leia este QR Code.') }}</li></ol><p class="va-cal-muted">{{ __('O código se renova automaticamente enquanto você conecta.') }}</p><button type="button" class="va-cal-primary" wire:click="refreshQr" wire:loading.attr="disabled">{{ __('Gerar novo QR Code') }}</button></div>
   </div>
  @endif
  <p class="va-cal-footnote">{{ __('A conexão do aparelho não ativa, por si só, o agendamento automático.') }}</p>
 </div>
</x-filament-panels::page>
