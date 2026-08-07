<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Sem isto o sistema não funciona</x-slot>

        <div class="space-y-3">
            @foreach ($this->essenciais() as $p)
                <div @class([
                    'flex items-start gap-3 rounded-lg border p-4',
                    'border-red-300 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10' => ! $p['feito'],
                    'border-gray-200 dark:border-white/10' => $p['feito'],
                ])>
                    <div class="mt-0.5">
                        @if ($p['feito'])
                            <x-filament::icon icon="heroicon-o-check-circle"
                                              class="h-5 w-5 text-green-600 dark:text-green-400" />
                        @else
                            <x-filament::icon icon="heroicon-o-exclamation-circle"
                                              class="h-5 w-5 text-red-600 dark:text-red-400" />
                        @endif
                    </div>

                    <div class="flex-1">
                        <h3 @class([
                            'text-sm font-semibold',
                            'text-gray-900 dark:text-gray-100' => ! $p['feito'],
                            'text-gray-500 line-through dark:text-gray-400' => $p['feito'],
                        ])>{{ $p['titulo'] }}</h3>

                        @unless ($p['feito'])
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $p['porque'] }}</p>
                        @endunless
                    </div>

                    @unless ($p['feito'])
                        <x-filament::button tag="a" href="{{ $p['url'] }}" size="sm">
                            {{ $p['acao'] }}
                        </x-filament::button>
                    @endunless
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Dá para viver sem, mas ajuda</x-slot>
        <x-slot name="description">
            {{-- Dito em voz alta de proposito: lista que cobra para sempre vira lista que
                 ninguem olha, e ai o alerta que importa some junto. --}}
            Nada aqui trava o sistema. Se algum não fizer sentido para o seu negócio, deixe
            de lado — não vai ficar cobrando.
        </x-slot>

        <div class="space-y-3">
            @foreach ($this->recomendados() as $p)
                <div class="flex items-start gap-3 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                    <div class="mt-0.5">
                        @if ($p['feito'])
                            <x-filament::icon icon="heroicon-o-check-circle"
                                              class="h-5 w-5 text-green-600 dark:text-green-400" />
                        @else
                            <x-filament::icon icon="heroicon-o-minus-circle"
                                              class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                        @endif
                    </div>

                    <div class="flex-1">
                        <h3 @class([
                            'text-sm font-semibold',
                            'text-gray-900 dark:text-gray-100' => ! $p['feito'],
                            'text-gray-500 line-through dark:text-gray-400' => $p['feito'],
                        ])>{{ $p['titulo'] }}</h3>

                        @unless ($p['feito'])
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $p['porque'] }}</p>
                        @endunless
                    </div>

                    @unless ($p['feito'])
                        <x-filament::button tag="a" href="{{ $p['url'] }}" size="sm" color="gray">
                            {{ $p['acao'] }}
                        </x-filament::button>
                    @endunless
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
