<x-filament-panels::page>
 @php($connections=$this->connections())
 <header class="va-cal-heading"><p class="va-cal-muted">{{ __('Conecte os canais de atendimento do seu estabelecimento.') }}</p><details class="va-channel-menu"><summary class="va-cal-primary">+ {{ __('Novo canal') }}</summary><div><a href="/admin/canais/whatsapp-qr" wire:navigate>{{ __('Conectar por QR Code') }}</a><button wire:click="connect('meta_cloud')">{{ __('WhatsApp oficial') }}</button><button wire:click="connect('instagram')">{{ __('Instagram') }}</button><button wire:click="connect('messenger')">{{ __('Facebook') }}</button></div></details></header>
 @if($errors->any())<div class="va-cal-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
 <div class="va-channel-list">
  @foreach(\App\Models\AgendaChannelConnection::TYPES as $type=>$label)
   @php($c=$connections[$type]??null)
   <div class="va-channel-list-row"><div><strong>{{ $c['name']??__($label) }}</strong><span>{{ __($label) }}</span></div><span class="va-state">{{ $c?__(\App\Models\AgendaChannelConnection::STATES[$c['status']]):__('Não configurado') }}</span><div class="va-cal-buttons">
    @if($type==='evolution')<a class="va-cal-primary" href="/admin/canais/whatsapp-qr" wire:navigate>{{ $c && $c['status']==='conectado'?__('Ver conexão'):__('Conectar por QR Code') }}</a>
    @else<span class="va-cal-muted">{{ __('Conexão automática em preparação') }}</span><button class="va-cal-secondary" wire:click="connect('{{ $type }}')">{{ __(['meta_cloud'=>'Conectar com a Meta','instagram'=>'Entrar com Instagram','messenger'=>'Entrar com Facebook'][$type]) }}</button>@if($c && $c['status']!=='desconectado')<button class="va-cal-danger" wire:confirm="{{ __('Remover a credencial local e desconectar este canal?') }}" wire:click="disconnect({{ $c['id'] }})">{{ __('Desconectar') }}</button>@endif@endif
   </div></div>
  @endforeach
 </div>
</x-filament-panels::page>
