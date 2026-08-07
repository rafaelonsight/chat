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
            <x-slot name="heading">Como você chama este canal</x-slot>
            <x-slot name="description">
                Aparece na caixa de entrada para separar um número do outro. Pode trocar quando
                quiser.
            </x-slot>

            <div class="flex flex-wrap items-end gap-2">
                <input type="text" wire:model="nome" maxlength="60"
                       class="min-w-56 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                <x-filament::button wire:click="salvarNome">Salvar</x-filament::button>
            </div>
        </x-filament::section>
    @else
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
            <p class="font-medium text-gray-800 dark:text-gray-100">Onde encontrar isso no celular</p>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                <li>Abra o WhatsApp no aparelho que tem <strong>este número</strong></li>
                <li>Toque nos três pontinhos e depois em <strong>Aparelhos conectados</strong></li>
                <li>Toque em <strong>Conectar aparelho</strong> e aponte a câmera para o código</li>
            </ol>
            <p class="mt-3 text-xs text-gray-500">
                O celular precisa continuar ligado e com internet. Se ficar dias sem rede, a
                conexão cai e é preciso ler o código de novo — o Diagnóstico avisa quando isso
                acontece.
            </p>
            <p class="mt-2 text-xs text-gray-500">
                Assim que conectar, este canal passa a se chamar pelo próprio número — e você
                pode trocar o nome aqui mesmo.
            </p>
        </div>
    @endif
</x-filament-panels::page>
