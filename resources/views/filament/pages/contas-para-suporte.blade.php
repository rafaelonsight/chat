<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('ui_bfe13b9b7dd3') }}</x-slot>
        <x-slot name="description">
            {{ __('ui_145791740d26') }}
        </x-slot>

        <div class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($this->contas() as $conta)
                <div class="flex items-center justify-between gap-4 py-3">
                    <div class="min-w-0">
                        <div class="truncate font-medium text-gray-950 dark:text-white">{{ $conta->nome }}</div>
                        @if ($conta->email)
                            <div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $conta->email }}</div>
                        @endif
                    </div>

                    <x-filament::button type="button" size="sm" color="gray"
                        wire:click="acessar({{ $conta->id }})" wire:loading.attr="disabled">
                        {{ __('ui_b7418ddb62f4') }}
                    </x-filament::button>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
