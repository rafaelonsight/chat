<x-filament-panels::page>
    {{-- Os tres passos. Existe para a pessoa saber ONDE ela esta e o que falta — sem isso, uma
         tela com um quadrado preto no meio nao diz se ela terminou ou nao. --}}
    @php
        $conectado = $record->status === 'open';
        $passo = $conectado ? 3 : 2;
    @endphp

    <ol class="flex items-center gap-2 text-xs">
        @foreach (['Criar o canal', 'Ler o QR Code', 'Pronto'] as $i => $rotulo)
            @php $n = $i + 1; @endphp
            <li class="flex items-center gap-2">
                <span @class([
                    'grid h-6 w-6 place-items-center rounded-full text-[11px] font-semibold',
                    'bg-emerald-600 text-white' => $n < $passo || ($n === 3 && $conectado),
                    'bg-indigo-600 text-white'  => $n === $passo && ! ($n === 3 && $conectado),
                    'bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400' => $n > $passo,
                ])>{{ $n < $passo || ($n === 3 && $conectado) ? '✓' : $n }}</span>
                <span class="{{ $n === $passo ? 'font-medium text-gray-900 dark:text-gray-100' : 'text-gray-500' }}">{{ $rotulo }}</span>
            </li>
            @unless ($loop->last)
                <li class="h-px w-8 bg-gray-200 dark:bg-white/10"></li>
            @endunless
        @endforeach
    </ol>

    <x-filament::section>
        @livewire('channel-qr-code', ['channel' => $record])
    </x-filament::section>

    @unless ($conectado)
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
            <p class="font-medium text-gray-800 dark:text-gray-100">Onde encontrar isso no celular</p>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                <li>Abra o WhatsApp no aparelho que tem <strong>este número</strong></li>
                <li>Toque nos três pontinhos e depois em <strong>Aparelhos conectados</strong></li>
                <li>Toque em <strong>Conectar aparelho</strong> e aponte a câmera para o código</li>
            </ol>
            <p class="mt-3 text-xs text-gray-500">
                O celular precisa continuar ligado e com internet. Se ele ficar dias sem rede, a
                conexão cai e é preciso ler o código de novo — o Diagnóstico avisa quando isso
                acontece.
            </p>
        </div>
    @endunless
</x-filament-panels::page>
