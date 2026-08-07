@php($alturaDia = 24 * \App\Filament\Pages\Agenda::ALTURA_HORA)

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
    {{-- cabecalho: fica fora da area que rola, senao o dia some quando a pessoa desce --}}
    <div class="flex border-b border-gray-200 dark:border-white/10">
        <div class="w-14 shrink-0"></div>

        @foreach ($colunas as $c)
            <div class="flex-1 border-l border-gray-100 py-2 text-center dark:border-white/5">
                <div class="text-[11px] uppercase tracking-wide text-gray-400">{{ $c['dia'] }}</div>
                <div @class([
                        'mx-auto mt-0.5 grid h-7 w-7 place-items-center rounded-full text-sm',
                        'bg-primary-600 font-semibold text-white' => $c['hoje'],
                        'text-gray-700 dark:text-gray-200' => ! $c['hoje'],
                    ])>{{ $c['numero'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- Comeca as 7h: madrugada vazia no topo da tela e a primeira coisa que a pessoa ve, e
         nao e sobre ela que a pessoa veio perguntar. --}}
    <div class="max-h-[68vh] overflow-y-auto" x-init="$el.scrollTop = 7 * {{ \App\Filament\Pages\Agenda::ALTURA_HORA }}">
        <div class="flex">
            <div class="w-14 shrink-0">
                @for ($h = 0; $h < 24; $h++)
                    <div class="relative" style="height: {{ \App\Filament\Pages\Agenda::ALTURA_HORA }}px">
                        <span class="absolute -top-1.5 right-1 text-[10px] text-gray-400">
                            {{ $h ? sprintf('%02d:00', $h) : '' }}
                        </span>
                    </div>
                @endfor
            </div>

            @foreach ($colunas as $c)
                <div class="relative flex-1 border-l border-gray-100 dark:border-white/5"
                     style="height: {{ $alturaDia }}px"
                     data-dia="{{ $c['data'] }}" data-horas="1"
                     @click="if (! mexeu) { const r = $el.getBoundingClientRect(); $wire.novoEm('{{ $c['data'] }}', Math.max(0, Math.min(1425, Math.round((($event.clientY - r.top) / r.height * 1440) / 15) * 15))) }">

                    @for ($h = 0; $h < 24; $h++)
                        <div class="border-b border-gray-100 dark:border-white/5"
                             style="height: {{ \App\Filament\Pages\Agenda::ALTURA_HORA }}px"></div>
                    @endfor

                    {{-- a linha de agora, so na coluna de hoje --}}
                    @if ($c['hoje'])
                        <div class="pointer-events-none absolute inset-x-0 z-20 border-t-2 border-red-500"
                             style="top: {{ round(($agora->hour * 60 + $agora->minute) / 1440 * 100, 4) }}%">
                            <span class="absolute -left-1 -top-1 h-2 w-2 rounded-full bg-red-500"></span>
                        </div>
                    @endif

                    @foreach ($c['blocos'] as $b)
                        @php($a = $b['ap'])
                        <div wire:key="bl-{{ $a->id }}"
                             @pointerdown.prevent="pegar($event, {{ $a->id }})"
                             @click.stop
                             :style="fantasma({{ $a->id }})"
                             style="top: {{ $b['topo'] }}%; height: {{ $b['altura'] }}%; left: {{ $b['esq'] }}%; width: calc({{ $b['larg'] }}% - 3px)"
                             title="{{ $a->comeca_em->format('H:i') }} · {{ $a->titulo }}{{ $a->contact ? ' · '.$a->contact->nomeExibicao() : '' }}"
                             @class([
                                 'absolute z-10 cursor-grab touch-none select-none overflow-hidden rounded-md border px-1.5 py-0.5 text-[11px] leading-tight shadow-sm active:cursor-grabbing',
                                 'border-indigo-300 bg-indigo-100 text-indigo-900 dark:border-indigo-400/40 dark:bg-indigo-500/25 dark:text-indigo-100' => ! $a->ehLembrete(),
                                 'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-400/40 dark:bg-amber-500/25 dark:text-amber-100' => $a->ehLembrete(),
                                 'opacity-60 line-through' => $a->concluido(),
                                 'ring-1 ring-red-500' => $a->atrasado(),
                             ])>
                            <span class="font-medium">{{ $a->comeca_em->format('H:i') }}</span>
                            {{ $a->titulo }}
                            @if ($a->contact)
                                <span class="block truncate opacity-70">{{ $a->contact->nomeExibicao() }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</div>
