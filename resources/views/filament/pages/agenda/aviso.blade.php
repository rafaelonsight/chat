{{--
    Avisar por WhatsApp.

    O CANAL E ESCOLHIDO E NAO ADIVINHADO: com mais de um numero, mandar pelo primeiro sairia do
    numero errado sem avisar — no canal oficial isso custa dinheiro e chega ao cliente com a
    identidade trocada. Com um canal so, ele ja vem marcado, porque nao ha escolha a fazer.
--}}
<div class="fixed inset-0 z-50 grid place-items-center bg-gray-900/60 p-4"
     wire:click.self="$set('avisando', false)">

    <div class="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('ui_3166899ff849') }}</h2>
                <p class="mt-0.5 text-xs text-gray-500">
                    {{ __('ui_e66e07321334') }}
                </p>
            </div>

            <button type="button" wire:click="$set('avisando', false)"
                    class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">&times;</button>
        </div>

        {{-- ------------------------------------------------------------- o canal --}}
        <label class="mt-4 block">
            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_472bdba0a139') }}</span>
            <select wire:model="canalDoAviso"
                    class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                <option value="">{{ __('ui_81a27b6effdb') }}</option>
                @foreach ($canais as $c)
                    <option value="{{ $c->id }}">{{ $c->nome }}</option>
                @endforeach
            </select>
            @error('canalDoAviso') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        </label>

        {{-- ---------------------------------------------------------- os contatos --}}
        <div class="mt-4">
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_c29a34269479') }}</span>
                <span class="text-[11px] text-gray-400">
                    {{ count($paraAvisar) }} {{ count($paraAvisar) === 1 ? 'selecionado' : 'selecionados' }}
                </span>
            </div>

            <input type="text" wire:model.live.debounce.300ms="buscaParaAvisar"
                   placeholder="{{ __('ui_05076fbfefe2') }}"
                   class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">

            @error('paraAvisar') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

            <div class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-white/5 dark:border-white/10">
                @forelse ($contatosDoAviso as $c)
                    <label wire:key="av-{{ $c->id }}"
                           class="flex cursor-pointer items-center gap-2 px-3 py-2 hover:bg-gray-50 dark:hover:bg-white/5">
                        <input type="checkbox"
                               wire:click="alternarParaAvisar({{ $c->id }})"
                               @checked(in_array($c->id, $paraAvisar, true))
                               class="rounded border-gray-300 text-amber-600 focus:ring-amber-500 dark:border-white/20">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-gray-800 dark:text-gray-100">{{ $c->nomeExibicao() }}</span>
                            <span class="block text-[11px] text-gray-400">{{ $c->telefoneDiscavel() ?? $c->telefone_e164 }}</span>
                        </span>
                    </label>
                @empty
                    <p class="px-3 py-4 text-center text-xs text-gray-400">
                        {{ __('ui_0675efed02f8') }}
                    </p>
                @endforelse
            </div>

            @if ($contatosDoAviso->count() >= 40)
                {{-- Dizer que a lista foi cortada, em vez de deixar a pessoa achar que o contato
                     dela nao existe. --}}
                <p class="mt-1 text-[11px] text-gray-400">
                    {{ __('ui_5a8a705a5974') }}
                </p>
            @endif
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <x-filament::button color="gray" wire:click="$set('avisando', false)">{{ __('ui_c111e0ab9ddb') }}</x-filament::button>
            <x-filament::button wire:click="avisarPorWhatsapp" wire:loading.attr="disabled">
                {{ __('ui_2f0d5e838b70') }}
            </x-filament::button>
        </div>
    </div>
</div>
