<x-filament-panels::page>
 <p class="text-sm text-gray-500">Controle exclusivo do superadmin. A franquia renova a cada mês; créditos extras permanecem disponíveis até serem usados.</p>
 <form wire:submit="salvar" class="max-w-xl space-y-4">
 <label class="block">Conta<select wire:model="conta" wire:change="carregar" class="mt-2 block w-full rounded-lg border-gray-300"><option value="">Selecione</option>
 @foreach($contas as $t)
 <option value="{{ $t->id }}">{{ $t->nome }}</option>
 @endforeach
 </select></label>
 @if($saldo)
 <p>Saldo atual: {{ number_format($saldo['disponiveis'],0,',','.') }} tokens · Extras: {{ number_format($saldo['extras'],0,',','.') }}</p>
 @endif
 <label class="block">Franquia mensal<input type="number" min="0" wire:model="franquia" class="mt-2 block w-full rounded-lg border-gray-300"></label>
 <label class="block">Adicionar tokens extras<input type="number" min="0" wire:model="adicionar" class="mt-2 block w-full rounded-lg border-gray-300"></label>
<label class="block">Créditos por minuto de áudio (todas as contas)<input type="number" min="1" wire:model="tarifaAudio" class="mt-2 block w-full rounded-lg border-gray-300"></label>
 <label class="block">Motivo<input wire:model="motivo" class="mt-2 block w-full rounded-lg border-gray-300"></label>
 @foreach($errors->all() as $erro)
 <p class="text-red-600">{{ $erro }}</p>
 @endforeach
 <x-filament::button type="submit" wire:loading.attr="disabled">Salvar</x-filament::button>
 </form></x-filament-panels::page>
