<x-filament-panels::page>
    @php
        $conectado = $record->status === 'open';
        $passo = $conectado ? 3 : 2;
    @endphp

    {{-- Os tres passos. Sem eles, uma tela com um quadrado preto no meio nao diz se a pessoa
         terminou ou nao. --}}
    <ol class="flex items-center gap-2 text-xs">
        @foreach (['Canal criado', 'Ler o QR Code', 'Pronto'] as $i => $rotulo)
            @php $n = $i + 1; $feito = $n < $passo || ($n === 3 && $conectado); @endphp
            <li class="flex items-center gap-2">
                <span @class([
                    'grid h-6 w-6 place-items-center rounded-full text-[11px] font-semibold',
                    'bg-emerald-600 text-white' => $feito,
                    'bg-indigo-600 text-white'  => $n === $passo && ! $feito,
                    'bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400' => $n > $passo,
                ])>{{ $feito ? '✓' : $n }}</span>
                <span class="{{ $n === $passo && ! $feito ? 'font-medium text-gray-900 dark:text-gray-100' : 'text-gray-500' }}">{{ $rotulo }}</span>
            </li>
            @unless ($loop->last)
                <li class="h-px w-8 bg-gray-200 dark:bg-white/10"></li>
            @endunless
        @endforeach
    </ol>

    <x-filament::section>
        @livewire('channel-qr-code', ['channel' => $record])
    </x-filament::section>

    @if ($conectado)
        {{-- O nome so e pedido DEPOIS de conectar, e ja vem sugerido com o numero. Antes do QR
             a pessoa nao tinha como saber que nome dar: ela veio conectar um telefone. --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('ui_283a27fcb111') }}</x-slot>
            <x-slot name="description">
                {{ __('ui_4547990742bf') }}
            </x-slot>

            <div class="flex flex-wrap items-end gap-2">
                <input type="text" wire:model="nome" maxlength="60"
                       class="min-w-56 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                <x-filament::button wire:click="salvarNome">{{ __('ui_93bf2d32ec33') }}</x-filament::button>
            </div>
        </x-filament::section>
    @else
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
            <p class="font-medium text-gray-800 dark:text-gray-100">{{ __('ui_7862ef38db46') }}</p>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                <li>{{ __('ui_5a77150b0faf') }} <strong>{{ __('ui_11c24af68671') }}</strong></li>
                <li>{{ __('ui_72fe64f2aebb') }} <strong>{{ __('ui_d2e8f355deaa') }}</strong></li>
                <li>{{ __('ui_c693db20c86e') }} <strong>{{ __('ui_cdcfcd9315d7') }}</strong> {{ __('ui_a605cb324aee') }}</li>
            </ol>
            <p class="mt-3 text-xs text-gray-500">
                {{ __('ui_854a9dbd1be6') }}
            </p>
            <p class="mt-2 text-xs text-gray-500">
                {{ __('ui_ed794ff75158') }}
            </p>
        </div>
    @endif
</x-filament-panels::page>
