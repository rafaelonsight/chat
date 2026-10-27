<x-filament-panels::page>
<form wire:submit="salvar" class="max-w-2xl space-y-5 rounded-2xl bg-white p-6 shadow-sm dark:bg-gray-900">
<h2 class="text-lg font-semibold">{{ __('ui_5d87ce5f8217') }}</h2>
<p class="text-sm text-gray-500">{{ __('Configuração central de IA, aplicada a todos os clientes. Somente o superadmin pode alterá-la. Cada chamada é contabilizada na conta que a utiliza.') }}</p>
<label class="block">{{ __('ui_2afe9c49ab44') }}<select wire:model.live="provedor" class="mt-2 w-full rounded-lg border p-3">@foreach(\App\Services\ResumoIA::PROVEDORES as $id=>$nome)<option value="{{ $id }}">{{ $nome }}</option>@endforeach</select></label>
@if($provedor==='gateway')<p class="text-sm text-gray-500">Gateway: https://claude-ss.ia.br · API compatível com OpenAI</p>@endif
<label class="block">{{ __('ui_b3a01bef14b4') }}<input wire:model="modelo" class="mt-2 w-full rounded-lg border p-3" placeholder="{{ __('ui_647b01898e66') }}"></label>@error('modelo')<p class="text-red-600">{{ $message }}</p>@enderror
@if($provedor==='gateway')<p class="text-sm text-gray-500">{{ __('ui_5f7dee843fbf') }}</p>@endif
<label class="block">{{ __('ui_922539e83902') }}<input type="password" wire:model="chave" autocomplete="new-password" class="mt-2 w-full rounded-lg border p-3" placeholder="{{ $temChave ? 'Chave salva. Deixe vazio para manter.' : 'Cole sua chave' }}"></label>@error('chave')<p class="text-red-600">{{ $message }}</p>@enderror
<p class="text-xs text-gray-500">{{ __('ui_a61cc90c588a') }}</p>
<label class="flex items-center gap-3"><input type="checkbox" wire:model="ativa">{{ __('ui_fab81eeb6428') }}</label>
<div class="flex gap-3">
<button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-violet-600 px-4 py-3 font-medium text-white">{{ __('ui_7b62aa3a386d') }}</button>
<button type="button" wire:click="testar" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-4 py-3 font-medium">{{ __('ui_240d4a718bc4') }}</button>
</div>
<p wire:loading wire:target="testar" role="status">{{ __('ui_35068675d9e2') }}</p>
@if($resultadoTeste)<p role="status" class="rounded-lg bg-gray-50 p-3 text-sm">{{ $resultadoTeste }}</p>@endif
</form>
</x-filament-panels::page>
