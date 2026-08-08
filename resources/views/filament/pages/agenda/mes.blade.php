<div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
    <div class="grid grid-cols-7 border-b border-gray-200 dark:border-white/10">
        @foreach (\App\Filament\Pages\Agenda::DIAS as $d)
            <div class="py-2 text-center text-[11px] uppercase tracking-wide text-gray-400">{{ $d }}</div>
        @endforeach
    </div>

    @foreach ($semanas as $semana)
        <div class="grid grid-cols-7">
            @foreach ($semana as $d)
                <div wire:key="cel-{{ $d['data'] }}"
                     data-dia="{{ $d['data'] }}"
                     @click="if (! mexeu) $wire.novoEm('{{ $d['data'] }}')"
                     @class([
                         'min-h-24 border-b border-l border-gray-100 p-1 first:border-l-0 dark:border-white/5',
                         'bg-gray-50/60 dark:bg-white/[0.02]' => ! $d['noMes'],
                     ])>
                    <div class="flex justify-end">
                        <span @class([
                                'grid h-6 w-6 place-items-center rounded-full text-xs',
                                'bg-primary-600 font-semibold text-white' => $d['hoje'],
                                'text-gray-700 dark:text-gray-200' => $d['hoje'] === false && $d['noMes'],
                                'text-gray-400' => ! $d['noMes'] && ! $d['hoje'],
                            ])>{{ $d['numero'] }}</span>
                    </div>

                    <div class="mt-0.5 space-y-0.5">
                        {{-- Tres cabem sem espremer; o resto vira um link para o dia, que e onde
                             tudo cabe. Espremer sete numa celula nao mostra sete, mostra sujeira. --}}
                        @foreach ($d['itens']->take(3) as $a)
                            <div wire:key="mc-{{ $a->id }}"
                                 @pointerdown.prevent="pegar($event, {{ $a->id }})"
                                 @click.stop
                                 :style="fantasma({{ $a->id }})"
                                 title="{{ $a->comeca_em->format('H:i') }} · {{ $a->titulo }}"
                                 @class([
                                     'cursor-grab touch-none select-none truncate rounded px-1 py-0.5 text-[11px] leading-tight',
                                     'bg-indigo-100 text-indigo-900 dark:bg-indigo-500/25 dark:text-indigo-100' => ! $a->ehLembrete(),
                                     'bg-amber-100 text-amber-900 dark:bg-amber-500/25 dark:text-amber-100' => $a->ehLembrete(),
                                     'opacity-60 line-through' => $a->concluido(),
                                 ])>
                                <span class="font-medium">{{ $a->comeca_em->format('H:i') }}</span>@if ($a->ehPorVideo())<span title="Reunião por vídeo">&#127909;</span>@endif {{ $a->titulo }}
                            </div>
                        @endforeach

                        @if ($d['itens']->count() > 3)
                            <button type="button" wire:click.stop="verDia('{{ $d['data'] }}')"
                                    class="px-1 text-[11px] text-gray-500 underline">
                                +{{ $d['itens']->count() - 3 }} mais
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
</div>
