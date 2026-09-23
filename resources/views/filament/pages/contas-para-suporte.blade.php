<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Entrar para suporte</x-slot>
        <x-slot name="description">
            Escolha uma conta para atender. Você usará os recursos do painel daquela conta até sair do modo de suporte.
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
                        Abrir para suporte
                    </x-filament::button>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
