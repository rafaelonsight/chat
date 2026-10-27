<x-filament-panels::page>
 <p class="text-sm text-gray-500">{{ __('Avise quando um cliente aguarda resposta humana. Novas mensagens não reiniciam a espera e respostas automáticas não encerram o alerta. O horário de atendimento é respeitado.') }}</p>
 @if($this->gerencia())<x-filament::section><x-slot name="heading">{{ __('Regra da empresa') }}</x-slot><form wire:submit="salvar" class="space-y-4">
 <label class="block"><input type="checkbox" wire:model="ativo"> {{ __('Ativar alertas visuais') }}</label>
 <div class="grid gap-4 sm:grid-cols-2" style="max-width:560px"><label class="block text-sm font-medium">{{ __('Alertar após (minutos)') }}<input type="number" min="1" max="120" wire:model="minutos" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2"></label>
 <label class="block text-sm font-medium">{{ __('Repetir após (minutos)') }}<input type="number" min="1" max="60" wire:model="repetir" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2"></label></div>
 <label class="block"><input type="checkbox" wire:model="supervisor"> {{ __('Alertar também administradores e supervisores autorizados') }}</label>
 @foreach(['minutos','repetir'] as $f)@error($f)<p class="text-red-600">{{ $message }}</p>@enderror @endforeach
 </form></x-filament::section>@endif
 <x-filament::section><x-slot name="heading">{{ __('Som neste navegador') }}</x-slot><p class="mb-4 text-sm text-gray-500">{{ __('Ative e teste o som uma vez para permitir a reprodução. Os avisos funcionam enquanto o painel está aberto; não são notificações com o aplicativo fechado.') }}</p>
 <div x-data="{som:localStorage.getItem('virtus-alerta-som')==='1',volume:Number(localStorage.getItem('virtus-alerta-volume')??0.4),tom:localStorage.getItem('virtus-alerta-tom')??'suave'}" class="space-y-4">
 <label class="block"><input type="checkbox" x-model="som" @change="localStorage.setItem('virtus-alerta-som',som?'1':'0')"> {{ __('Ativar som') }}</label>
 <div class="grid items-end gap-4 sm:grid-cols-2" style="max-width:560px"><label class="block text-sm font-medium">{{ __('Volume') }}<input class="mt-3 block w-full accent-violet-600" type="range" min="0" max="1" step="0.1" x-model="volume" @input="localStorage.setItem('virtus-alerta-volume',volume)"></label>
 <label class="block text-sm font-medium">{{ __('Som') }}<select x-model="tom" @change="localStorage.setItem('virtus-alerta-tom',tom)" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2"><option value="suave">{{ __('Suave') }}</option><option value="duplo">{{ __('Duplo') }}</option><option value="sino">{{ __('Sino') }}</option><option value="digital">{{ __('Digital') }}</option><option value="ascendente">{{ __('Ascendente') }}</option><option value="descendente">{{ __('Descendente') }}</option><option value="triplo">{{ __('Triplo') }}</option><option value="grave">{{ __('Grave') }}</option></select></label></div>
 <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row">
 <x-filament::button type="button" color="gray" icon="heroicon-o-speaker-wave" class="w-full sm:w-auto" @click="window.dispatchEvent(new CustomEvent('virtus-testar-som'))">{{ __('Testar som') }}</x-filament::button>
 <x-filament::button type="button" wire:click="testarAviso" color="gray" icon="heroicon-o-bell-alert" class="w-full sm:w-auto">{{ __('Testar aviso visual') }}</x-filament::button>
 </div></div></x-filament::section>
<x-filament::section>
 <x-slot name="heading">{{ __('Notificações do computador') }}</x-slot>
 <div x-data="{ativo:window.onchatAvisoLigado?.()??false,ocupado:false}" class="space-y-4">
 <p class="text-sm text-gray-500">{{ __('Receba novas mensagens e alertas de espera nas notificações do Windows, mesmo usando outra janela. Permita as notificações quando o navegador solicitar. Alertas de espera precisam do painel aberto; novas mensagens também usam notificações em segundo plano.') }}</p>
 <p class="text-sm font-medium" x-text="ativo ? @js(__('Notificações ativadas')) : @js(__('Notificações desativadas'))"></p>
 <div class="flex flex-col gap-3 sm:flex-row">
 <x-filament::button type="button" icon="heroicon-o-bell" x-bind:disabled="ocupado" @click="ocupado=true;try{ativo=await window.onchatAlternarAviso()}finally{ocupado=false}"><span x-text="ativo ? @js(__('Desativar notificações')) : @js(__('Ativar notificações'))"></span></x-filament::button>
 <x-filament::button type="button" color="gray" x-bind:disabled="!ativo" @click="window.virtusTestarNotificacao()">{{ __('Testar notificação do computador') }}</x-filament::button>
 </div>
 <p class="text-xs text-gray-500">{{ __('O som de novas mensagens é controlado pelo ícone de som na lista de conversas. O som dos alertas de espera é configurado acima. O modo Não incomodar do Windows pode silenciar os avisos.') }}</p>
 </div></x-filament::section>
@if($this->gerencia())
 <div class="flex justify-end"><x-filament::button type="button" wire:click="salvar" wire:loading.attr="disabled" wire:target="salvar" class="w-full sm:w-auto">{{ __('Salvar') }}</x-filament::button></div>
 @endif
</x-filament-panels::page>