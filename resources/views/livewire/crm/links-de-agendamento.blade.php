<div class="space-y-6">
    @if (! $formAberto)
        <div class="flex justify-end">
            <x-filament::button wire:click="novo" icon="heroicon-o-plus">{{ __('ui_845f653cbbce') }}</x-filament::button>
        </div>
    @endif

    {{-- ------------------------------------------------------------ o formulario --}}
    @if ($formAberto)
        <x-filament::section>
            <x-slot name="heading">{{ $editando ? 'Editar link' : 'Novo link de agendamento' }}</x-slot>

            <div class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_e88c16c0b308') }}</span>
                        <input type="text" wire:model="titulo" maxlength="120"
                               placeholder="{{ __('ui_9996cc7bc55c') }}"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @error('titulo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_ea725baab3fa') }}</span>
                        <select wire:model="user_id"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            @foreach ($pessoas as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_9f12c6eea6d8') }}</span>
                        <input type="number" wire:model="duracao_min" min="5" max="480" step="5"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @error('duracao_min') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_e5c096ef7047') }}</span>
                        <input type="number" wire:model="intervalo_min" min="0" max="240" step="5"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        <span class="text-[11px] text-gray-400">{{ __('ui_7b815d5ea695') }}</span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_f237f5d4fab8') }}</span>
                        <input type="number" wire:model="antecedencia_horas" min="0" max="720"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        <span class="text-[11px] text-gray-400">{{ __('ui_b07fce0df29b') }}</span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_d9258a36f17d') }}</span>
                        <input type="number" wire:model="janela_dias" min="1" max="365"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_d7c4f8882ccf') }}</span>
                        <input type="number" wire:model="limite_dia" min="1" max="50" placeholder="{{ __('ui_a8ded42e6ec0') }}"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_84f4992268ab') }}</span>
                        <input type="text" wire:model="local" maxlength="160"
                               placeholder="{{ __('ui_d700b2e32214') }}"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    </label>

                    <label class="block sm:col-span-2">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_25311a131ff1') }}</span>
                        <select wire:model="channel_id"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            <option value="">{{ __('ui_8b6e7e68b945') }}</option>
                            @foreach ($canais as $c)
                                <option value="{{ $c->id }}">{{ $c->nome }}</option>
                            @endforeach
                        </select>
                        <span class="text-[11px] text-gray-400">
                            {{ __('ui_56f41ba275c4') }}
                        </span>
                    </label>

                    <label class="block sm:col-span-2">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_e954a6fc8bd0') }}</span>
                        <textarea wire:model="descricao" rows="2" maxlength="1000"
                                  class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800"></textarea>
                    </label>
                </div>

                {{-- --------------------------------------------------- disponibilidade --}}
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('ui_f3b1de42f0e8') }}
                    </p>

                    @error('horarios') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    <div class="mt-2 space-y-1">
                        @foreach ($dias as $n => $nome)
                            <div wire:key="dia-{{ $n }}"
                                 class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
                                <label class="flex w-32 shrink-0 items-center gap-2">
                                    <input type="checkbox" wire:model.live="horarios.{{ $n }}.ativo"
                                           class="rounded border-gray-300 dark:border-white/20">
                                    <span class="text-sm text-gray-700 dark:text-gray-200">{{ $nome }}</span>
                                </label>

                                @if ($horarios[$n]['ativo'] ?? false)
                                    <div class="flex items-center gap-1 text-sm">
                                        <input type="time" wire:model="horarios.{{ $n }}.de1"
                                               class="rounded-lg border-gray-300 py-1 text-xs dark:border-white/20 dark:bg-gray-800">
                                        <span class="text-gray-400">{{ __('ui_6d139d13415e') }}</span>
                                        <input type="time" wire:model="horarios.{{ $n }}.ate1"
                                               class="rounded-lg border-gray-300 py-1 text-xs dark:border-white/20 dark:bg-gray-800">
                                    </div>

                                    <div class="flex items-center gap-1 text-sm">
                                        <span class="text-xs text-gray-400">e</span>
                                        <input type="time" wire:model="horarios.{{ $n }}.de2"
                                               class="rounded-lg border-gray-300 py-1 text-xs dark:border-white/20 dark:bg-gray-800">
                                        <span class="text-gray-400">{{ __('ui_6d139d13415e') }}</span>
                                        <input type="time" wire:model="horarios.{{ $n }}.ate2"
                                               class="rounded-lg border-gray-300 py-1 text-xs dark:border-white/20 dark:bg-gray-800">
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400">{{ __('ui_96832779f556') }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-1 text-[11px] text-gray-400">
                        {{ __('ui_9140daa42efb') }}
                    </p>
                </div>

                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model="por_video"
                           class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500 dark:border-white/20">
                    <span>
                        <span class="text-sm text-gray-700 dark:text-gray-200">{{ __('ui_8ecca23a0336') }}</span>
                        <span class="block text-xs text-gray-400">
                            Toda reserva feita por este link abre uma sala em {{ config('app.name') }},
                            e o link da sala vai na mesma mensagem de confirmação.
                        </span>
                    </span>
                </label>

                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="ativa" class="rounded border-gray-300 dark:border-white/20">
                    <span class="text-sm text-gray-700 dark:text-gray-200">{{ __('ui_5395ba12b39e') }}</span>
                </label>

                @if ($editando)
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_405addc733fc') }}</span>
                        <div class="mt-1 flex items-center gap-1">
                            <span class="text-xs text-gray-400">{{ url('/agendar') }}/</span>
                            <input type="text" wire:model="slugPublico" maxlength="60"
                                   class="w-56 rounded-lg border-gray-300 py-1 text-xs dark:border-white/20 dark:bg-gray-800">
                        </div>
                        <span class="text-[11px] text-gray-400">
                            {{ __('ui_7c44c84d2ce2') }}
                        </span>
                    </label>
                @endif

                <div class="flex flex-wrap gap-2">
                    <x-filament::button wire:click="salvar">{{ __('ui_93bf2d32ec33') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="$set('formAberto', false)">{{ __('ui_c111e0ab9ddb') }}</x-filament::button>

                    @if ($editando)
                        <x-filament::button class="ms-auto" color="danger" wire:click="excluir({{ $editando }})"
                            wire:confirm="Excluir este link? Quem já tem o endereço vai encontrar página não encontrada.">
                            {{ __('ui_8e7c1314fe0b') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- ----------------------------------------------------------------- a lista --}}
    @forelse ($paginas as $p)
        <x-filament::section>
            <div class="flex flex-wrap items-start gap-4">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $p->titulo }}</span>

                        @if ($p->ativa)
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200">{{ __('ui_ee742f937d82') }}</span>
                        @else
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-600 dark:bg-white/10 dark:text-gray-300">fechado</span>
                        @endif
                    </div>

                    <p class="mt-0.5 text-xs text-gray-500">
                        {{ $p->user?->name }} · {{ $p->duracao_min }} min
                        @if ($p->por_video) · por vídeo @endif
                        @if ($p->intervalo_min) · {{ $p->intervalo_min }} min de folga @endif
                        @if ($p->channel_id) · confirma por {{ $p->channel?->nome }} @endif
                    </p>

                    <div class="mt-2 flex items-center gap-1">
                        <a href="{{ $p->url() }}" target="_blank" rel="noopener"
                           class="truncate text-xs text-primary-600 underline dark:text-primary-400">{{ $p->url() }}</a>
                        <x-inbox.copiar :valor="$p->url()" titulo="Copiar o link" />
                    </div>

                    @unless ($p->temDisponibilidade())
                        <p class="mt-2 text-xs text-amber-600">
                            {{ __('ui_e962ec95e830') }}
                        </p>
                    @endunless
                </div>

                <div class="flex shrink-0 gap-1">
                    <x-filament::button size="xs" color="gray" wire:click="alternarAtiva({{ $p->id }})">
                        {{ $p->ativa ? 'Fechar' : 'Reabrir' }}
                    </x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="editar({{ $p->id }})">{{ __('ui_2b5f23437f58') }}</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @empty
        @unless ($formAberto)
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/20">
                <p class="text-base font-medium text-gray-700 dark:text-gray-200">{{ __('ui_47f931c1ca09') }}</p>
                <p class="mx-auto mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">
                    {{ __('ui_681bf7544492') }}
                </p>
                <div class="mt-4">
                    <x-filament::button wire:click="novo">{{ __('ui_bda1cf9035a7') }}</x-filament::button>
                </div>
            </div>
        @endunless
    @endforelse
</div>
