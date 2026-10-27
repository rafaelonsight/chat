<x-filament-panels::page>
    @error('sequencia')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</div>
    @enderror

    @if (! $formAberto)
        <div class="flex justify-end">
            <x-filament::button wire:click="nova" icon="heroicon-o-plus">{{ __('ui_dd6ce0b91390') }}</x-filament::button>
        </div>
    @endif

    @if ($formAberto)
        <x-filament::section>
            <x-slot name="heading">{{ $editando ? 'Editar sequência' : 'Nova sequência' }}</x-slot>

            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_37399fcb1597') }}</span>
                        <input type="text" wire:model="nome" placeholder="{{ __('ui_13289b277aaa') }}"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @error('nome') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_57a79bb632fb') }}</span>
                        <select wire:model="channel_id"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            @foreach ($canais as $c)
                                <option value="{{ $c->id }}">{{ $c->nome }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div>
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_de40d4cd8ac3') }}</span>
                    <div class="mt-1 space-y-1">
                        @foreach ($gatilhos as $chave => $texto)
                            <label class="flex items-start gap-2 rounded-lg border p-2 text-sm {{ $gatilho === $chave ? 'border-emerald-400 bg-emerald-50 dark:border-emerald-500/40 dark:bg-emerald-500/10' : 'border-gray-200 dark:border-white/10' }}">
                                <input type="radio" wire:model.live="gatilho" value="{{ $chave }}" class="mt-0.5">
                                <span class="text-gray-700 dark:text-gray-200">{{ $texto }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                @if ($gatilho === \App\Models\Sequence::SEM_RESPOSTA)
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_28be481fbbdf') }}</span>
                        <input type="number" wire:model="sem_resposta_horas" min="1" max="720"
                               class="mt-1 w-32 rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        <span class="block text-[11px] text-gray-500">
                            {{ __('ui_702300ac479f') }} <strong>nossa</strong> {{ __('ui_13866163107c') }}
                        </span>
                    </label>
                @endif

                {{-- A regra central do módulo, e por isso ela tem texto e não só um rótulo. --}}
                <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <input type="checkbox" wire:model="parar_ao_responder" class="mt-1 h-4 w-4 rounded border-gray-300 text-emerald-600">
                    <span>
                        <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('ui_6b202601bb60') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            {{ __('ui_ccfef82b629e') }}
                        </span>
                    </span>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_cc2d89614e29') }}</span>
                        <input type="number" wire:model="hora_inicio" min="0" max="23"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_c2a285bd47ef') }}</span>
                        <input type="number" wire:model="hora_fim" min="1" max="23"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @error('hora_fim') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>

                {{-- Os passos. O atraso é contado do passo ANTERIOR: "1 dia, depois 3 dias" é
                     como as pessoas descrevem uma jornada; obrigar a somar faz errar na
                     terceira linha. --}}
                <div>
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_ce89b3f0cf50') }}</span>
                    @error('passos') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror

                    <div class="mt-2 space-y-2">
                        @foreach ($passos as $i => $p)
                            <div wire:key="passo-{{ $i }}" class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-gray-500">{{ $i + 1 }}ª</span>
                                    <input type="number" wire:model="passos.{{ $i }}.atraso_horas" min="0" max="8760"
                                           class="w-24 rounded border-gray-300 text-xs dark:border-white/20 dark:bg-gray-800">
                                    <span class="text-xs text-gray-500">
                                        horas depois {{ $i === 0 ? 'do gatilho' : 'da anterior' }}
                                    </span>
                                    <button type="button" wire:click="removerPasso({{ $i }})"
                                            class="ml-auto rounded px-2 text-sm text-gray-400 hover:text-red-600">&times;</button>
                                </div>
                                <textarea wire:model="passos.{{ $i }}.corpo" rows="2" placeholder="{{ __('ui_b68b2a41e1fe') }}"
                                          class="mt-2 w-full rounded border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800"></textarea>
                                @error("passos.{$i}.corpo") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </div>
                        @endforeach
                    </div>

                    <x-filament::button size="xs" color="gray" wire:click="adicionarPasso" class="mt-2">
                        {{ __('ui_60b86e1fbf64') }}
                    </x-filament::button>
                </div>

                <div class="flex gap-2">
                    <x-filament::button wire:click="salvar">{{ __('ui_93bf2d32ec33') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="$set('formAberto', false)">{{ __('ui_c111e0ab9ddb') }}</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">{{ __('ui_9a98ec95fe9b') }}</x-slot>

        @if ($sequencias->isEmpty())
            <p class="py-6 text-center text-sm text-gray-500">
                {{ __('ui_4048384c6d89') }}
            </p>
        @else
            <div class="space-y-3">
                @foreach ($sequencias as $s)
                    <div wire:key="seq-{{ $s->id }}" class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $s->nome }}</span>
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300' => $s->ativa,
                                        'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400' => ! $s->ativa,
                                    ])>{{ $s->ativa ? 'Ligada' : 'Desligada' }}</span>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ __(\App\Models\Sequence::GATILHOS[$s->gatilho]) }}
                                    · {{ $s->channel?->nome }}
                                    · {{ $s->steps->count() }} {{ $s->steps->count() === 1 ? 'mensagem' : 'mensagens' }}
                                    @unless ($s->parar_ao_responder)
                                        · <span class="text-amber-700 dark:text-amber-400">{{ __('ui_6c6197db7723') }}</span>
                                    @endunless
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-wrap gap-1">
                                <x-filament::button size="xs" color="gray" wire:click="editar({{ $s->id }})">{{ __('ui_2b5f23437f58') }}</x-filament::button>
                                <x-filament::button size="xs" :color="$s->ativa ? 'warning' : 'primary'"
                                    wire:click="alternarAtiva({{ $s->id }})">{{ $s->ativa ? 'Desligar' : 'Ligar' }}</x-filament::button>
                                @if ($s->ativas_count > 0)
                                    <x-filament::button size="xs" color="danger" wire:click="pararTodas({{ $s->id }})"
                                        wire:confirm="Parar a jornada de {{ $s->ativas_count }} pessoa(s)?">{{ __('ui_6d36fab0f09b') }}</x-filament::button>
                                @else
                                    <x-filament::button size="xs" color="gray" wire:click="excluir({{ $s->id }})">{{ __('ui_8e7c1314fe0b') }}</x-filament::button>
                                @endif
                            </div>
                        </div>

                        @if ($s->steps->isNotEmpty())
                            <ol class="mt-3 space-y-1 border-l-2 border-gray-200 pl-3 dark:border-white/10">
                                @foreach ($s->steps as $p)
                                    <li class="text-xs text-gray-600 dark:text-gray-400">
                                        <span class="font-medium">{{ $p->atrasoLegivel() }}:</span>
                                        {{ \Illuminate\Support\Str::limit($p->corpo, 90) }}
                                    </li>
                                @endforeach
                            </ol>
                        @endif

                        <p class="mt-2 text-xs text-gray-500">
                            {{ $s->ativas_count }} em andamento · {{ $s->concluidas_count }} concluíram
                            @if ($s->paradas_count > 0) · {{ $s->paradas_count }} pararam no meio @endif
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
