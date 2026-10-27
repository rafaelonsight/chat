<x-filament-panels::page>
    <p class="max-w-3xl text-sm text-gray-500 dark:text-gray-400">
        {{ __('ui_5f8615b2102d') }}
        <strong>{{ __('ui_caca900aa2ee') }}</strong> {{ __('ui_9a138f21b3ec') }}
    </p>

    @if ($abertoAgora !== null)
        <div class="flex items-center gap-2 text-sm">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium
                         {{ $abertoAgora ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300' }}">
                <span class="h-1.5 w-1.5 rounded-full {{ $abertoAgora ? 'bg-emerald-600' : 'bg-gray-500' }}"></span>
                {{ $abertoAgora ? 'Aberto agora' : 'Fechado agora' }}
            </span>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ $agora }} no fuso da conta
                @if (! $abertoAgora && $proxima) &middot; volta {{ $proxima }} @endif
            </span>
        </div>
    @endif

    {{-- de quem e esta grade --}}
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="mr-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('ui_7701492d9d7d') }}</span>

        <button type="button" wire:click="trocarEscopo('conta')"
                class="rounded-full px-3 py-1 text-xs font-medium {{ $escopo === 'conta' ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300' }}">
            {{ __('ui_931fa6c0b21d') }}
        </button>

        @foreach ($equipes as $eq)
            <button type="button" wire:key="esc-{{ $eq->id }}" wire:click="trocarEscopo('equipe:{{ $eq->id }}')"
                    class="rounded-full px-3 py-1 text-xs font-medium {{ $escopo === 'equipe:'.$eq->id ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300' }}">
                {{ $eq->nome }}
            </button>
        @endforeach
    </div>

    @if ($escopo !== 'conta')
        @if ($herdando)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                {{ __('ui_2814c431043d') }} <strong>{{ __('ui_2cf920f7f371') }}</strong>{{ __('ui_02f904d69ea2') }}
            </div>
        @else
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                <span>{{ __('ui_93daef836b93') }} <strong>{{ __('ui_e6ac859c7c5c') }}</strong>{{ __('ui_6b77954e3c7c') }}</span>
                <button type="button" wire:click="limparEscopo"
                        class="rounded border border-emerald-300 px-2 py-0.5 font-medium hover:bg-emerald-100 dark:border-emerald-500/40 dark:hover:bg-emerald-500/20">
                    {{ __('ui_64308dfb7afe') }}
                </button>
            </div>
        @endif

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('ui_1647c2b26678') }} <strong>{{ __('ui_36325387085c') }}</strong>{{ __('ui_f68532557d74') }}
        </p>
    @endif

    <form wire:submit="salvar" class="space-y-6">
        {{-- grade --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium">{{ __('ui_a0d5b0ef1d64') }}</th>
                        <th class="px-3 py-3 text-left font-medium">{{ __('ui_8263fde6ccc4') }}</th>
                        <th class="px-3 py-3 text-left font-medium">{{ __('ui_fc2c74003cb1') }}</th>
                        <th class="px-3 py-3 text-left font-medium">{{ __('ui_9bc1b300b400') }}</th>
                        <th class="px-3 py-3 text-left font-medium">{{ __('ui_a8a2e75826e8') }}</th>
                        <th class="px-3 py-3 text-left font-medium">{{ __('ui_1b0369058b98') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($nomesDias as $dia => $nome)
                        <tr wire:key="dia-{{ $dia }}">
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100">{{ $nome }}</td>
                            <td class="px-3 py-3">
                                <input type="checkbox" wire:model="dias.{{ $dia }}.ativo"
                                       class="h-4 w-4 rounded border-gray-300 text-emerald-600">
                            </td>
                            @foreach (['inicio', 'almoco_inicio', 'almoco_fim', 'fim'] as $campo)
                                <td class="px-3 py-3">
                                    <input type="time" wire:model="dias.{{ $dia }}.{{ $campo }}"
                                           @disabled(! ($dias[$dia]['ativo'] ?? false))
                                           class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm disabled:opacity-40 dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @error('dias.*.inicio')
                <p class="border-t border-gray-200 px-4 py-2 text-xs text-red-600 dark:border-white/10">{{ $message }}</p>
            @enderror
            <p class="border-t border-gray-200 px-4 py-2 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                {{ __('ui_0d800af5856f') }} <strong>{{ __('ui_11e8b6c8cc1c') }}</strong>{{ __('ui_67c60c1ab801') }}
            </p>
        </div>

        {{-- fuso --}}
        <div class="max-w-sm">
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('ui_e596aca45bd9') }}</label>
            <select wire:model="fuso_horario"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                @foreach ($fusos as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('ui_98db1175a985') }}</p>
            @error('fuso_horario') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        {{--
            A assinatura mora ao lado da resposta automatica porque as duas sao a mesma
            familia: coisas que o sistema escreve no lugar da pessoa. Nao tem a ver com
            horario, mas separar em outra tela por pureza faria o dono procurar em dois
            lugares o que ele pensa como um assunto so.
        --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="assinatura_ativa"
                       class="mt-1 h-4 w-4 rounded border-gray-300 text-emerald-600">
                <span>
                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('ui_e1aae871bf99') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        {{ __('ui_5aa9e340bcc0') }} <strong>{{ __('ui_d6af0c0b1af5') }}</strong> {{ __('ui_8fa3e9a51b05') }}
                    </span>
                </span>
            </label>
        </div>

        {{-- pesquisa de satisfacao --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="pesquisa_ativa"
                       class="mt-1 h-4 w-4 rounded border-gray-300 text-emerald-600">
                <span>
                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('ui_6185d648895e') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        {{ __('ui_69ca2a2a5cbb') }} <strong>{{ __('ui_cd25e826232e') }}</strong>{{ __('ui_af8fa5c09649') }}
                    </span>
                </span>
            </label>

            @if ($pesquisa_ativa)
                <label class="mt-4 flex items-start gap-3">
                    <input type="checkbox" wire:model="pesquisa_obrigatoria" class="mt-1 h-4 w-4 rounded">
                    <span><strong class="block text-sm">{{ __('ui_1047a351347c') }}</strong><span class="block text-xs text-gray-500">{{ __('ui_f470edb85a4c') }}</span></span>
                </label>
                <div class="mt-4">
                    <textarea wire:model="pesquisa_texto" rows="2"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>
                    @error('pesquisa_texto') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        {{-- resposta automatica --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="resposta_ativa"
                       class="mt-1 h-4 w-4 rounded border-gray-300 text-emerald-600">
                <span>
                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('ui_147192e4a679') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        {{ __('ui_71e4427d79d8') }} <strong>{{ __('ui_df732a175b4c') }}</strong> {{ __('ui_56009ea7394d') }}
                        <strong>{{ __('ui_f91ec989d3d5') }}</strong>
                    </span>
                </span>
            </label>

            @if ($resposta_ativa)
                <div class="mt-4">
                    <textarea wire:model="resposta_texto" rows="3"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Marcadores:
                        @foreach ($marcadores as $tag => $desc)
                            <code class="rounded bg-gray-100 px-1 dark:bg-white/10">{{ $tag }}</code> {{ $desc }}@if (! $loop->last) &middot; @endif
                        @endforeach
                    </p>
                    @error('resposta_texto') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        <x-filament::button type="submit" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="salvar">{{ __('ui_93bf2d32ec33') }}</span>
            <span wire:loading wire:target="salvar">{{ __('ui_87ef46e99fb3') }}</span>
        </x-filament::button>
    </form>

    {{-- excecoes --}}
    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('ui_9da509cd55ff') }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('ui_6d2f5325c237') }}
            </p>
        </div>

        @if ($excecoes->isNotEmpty())
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($excecoes as $ex)
                        <tr wire:key="ex-{{ $ex->id }}">
                            <td class="px-4 py-2 text-gray-800 dark:text-gray-100">{{ $ex->data->format('d/m/Y') }}</td>
                            <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ $ex->descricao ?: '—' }}</td>
                            <td class="px-4 py-2">
                                @if ($ex->fechado)
                                    <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs dark:bg-white/10">fechado</span>
                                @else
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300">
                                        {{ $ex->intervalos[0]['inicio'] ?? '?' }}–{{ $ex->intervalos[0]['fim'] ?? '?' }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="removerExcecao({{ $ex->id }})"
                                        class="text-xs text-red-600 hover:underline">remover</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('ui_44015ab56e15') }}</p>
        @endif

        <div class="flex flex-wrap items-end gap-3 border-t border-gray-200 px-4 py-3 dark:border-white/10">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_e5e429bcc9c2') }}</label>
                <input type="date" wire:model="ex_data"
                       class="rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                @error('ex_data') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_469768598e0f') }}</label>
                <input type="text" wire:model="ex_descricao" placeholder="{{ __('ui_e21a148aea31') }}"
                       class="rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
            </div>
            <label class="flex items-center gap-2 pb-1.5 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" wire:model.live="ex_fechado" class="h-4 w-4 rounded border-gray-300 text-emerald-600">
                {{ __('ui_690068c474d8') }}
            </label>
            @unless ($ex_fechado)
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_fc2c74003cb1') }}</label>
                    <input type="time" wire:model="ex_inicio" class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_1b0369058b98') }}</label>
                    <input type="time" wire:model="ex_fim" class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                    @error('ex_fim') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endunless
            <x-filament::button type="button" color="gray" wire:click="adicionarExcecao">{{ __('ui_fadcf2d5a3d5') }}</x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
