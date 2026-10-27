<x-filament::section>
    <x-slot name="heading">{{ $editando ? 'Editar' : 'Novo na agenda' }}</x-slot>

    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block sm:col-span-2">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_6639f5f40b18') }}</span>
                <input type="text" wire:model="titulo" maxlength="120"
                       placeholder="{{ __('ui_d9b9797cbd72') }}"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                @error('titulo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_6cc6198d9e75') }}</span>
                <select wire:model.live="tipo"
                        class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    @foreach (\App\Models\Appointment::TIPOS as $chave => $rotulo)
                        <option value="{{ $chave }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_9cd63d01d858') }}</span>
                <input type="datetime-local" wire:model="quando"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                @error('quando') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>

            @if ($tipo === \App\Models\Appointment::COMPROMISSO)
                <label class="block">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_9f12c6eea6d8') }}</span>
                    <input type="number" wire:model="duracao_min" min="5" max="1440" step="5"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_73ea0fdf1d49') }}</span>
                    <select wire:model="user_id"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @foreach ($pessoas as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </label>
            @else
                <div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    {{ __('ui_4053d8b05c38') }} <strong>{{ __('ui_ac2161d846f8') }}</strong>{{ __('ui_594008f7407f') }}
                </div>
            @endif
        </div>

        {{-- Contato é OPCIONAL: "ligar para o contador" não tem contato cadastrado, e exigir um
             faria a pessoa inventar cadastro para conseguir anotar. --}}
        <div>
            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_bcde2f581dab') }}</span>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <input type="text" wire:model.live.debounce.300ms="buscaContato" placeholder="{{ __('ui_ed869fb33b44') }}"
                       class="min-w-52 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                @if ($contact_id)
                    <x-filament::button size="xs" color="gray" wire:click="tirarContato">{{ __('ui_f409bdf3a555') }}</x-filament::button>
                @endif
            </div>

            @if ($candidatos->isNotEmpty())
                <div class="mt-2 flex flex-wrap gap-1">
                    @foreach ($candidatos as $c)
                        <button type="button" wire:key="ct-{{ $c->id }}" wire:click="escolherContato({{ $c->id }})"
                                class="rounded-full border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-100 dark:border-white/20 dark:hover:bg-white/5">
                            {{ $c->nomeExibicao() }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ------------------------------------------------------- convidados --}}
        @if ($tipo === \App\Models\Appointment::COMPROMISSO)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('ui_3b67003f87e0') }}
                </p>

                @if (count($convidados))
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach ($convidados as $i => $c)
                            <span wire:key="cv-{{ $i }}"
                                  class="flex items-center gap-1.5 rounded-full bg-gray-100 py-1 pl-2.5 pr-1 text-xs dark:bg-white/10">
                                <span class="text-gray-800 dark:text-gray-100">{{ $c['nome'] }}</span>

                                @if (! empty($c['email']))
                                    <span class="text-gray-400">{{ $c['email'] }}</span>
                                @else
                                    {{-- Sem e-mail o convite por e-mail nao alcança essa pessoa,
                                         e vale dizer antes e nao depois de tentar. --}}
                                    <span class="text-amber-600 dark:text-amber-400" title="{{ __('ui_f1dd5abbe590') }}">{{ __('ui_96d62ba29019') }}</span>
                                @endif

                                <button type="button" wire:click="tirarConvidado({{ $i }})"
                                        title="{{ __('ui_5caafa8d4742') }}"
                                        class="grid h-4 w-4 place-items-center rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-700 dark:hover:bg-white/10">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @else
                    <p class="mt-1 text-xs text-gray-400">
                        {{ __('ui_c907a981e07b') }}
                    </p>
                @endif

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('ui_2333d3efc920') }}</span>
                        <input type="text" wire:model.live.debounce.300ms="buscaConvidado"
                               placeholder="{{ __('ui_ed869fb33b44') }}"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">

                        @if ($paraConvidar->isNotEmpty())
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($paraConvidar as $p)
                                    <button type="button" wire:key="pc-{{ $p->id }}"
                                            wire:click="convidarContato({{ $p->id }})"
                                            class="rounded-full border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-100 dark:border-white/20 dark:hover:bg-white/5">
                                        + {{ $p->nomeExibicao() }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('ui_85325c5d9b97') }}</span>
                        <div class="mt-1 flex gap-1">
                            <input type="email" wire:model="emailNovo" wire:keydown.enter="convidarEmail"
                                   placeholder="alguem@empresa.com.br"
                                   class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            <x-filament::button size="xs" color="gray" wire:click="convidarEmail">{{ __('ui_61cc55aa0453') }}</x-filament::button>
                        </div>
                        @error('emailNovo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>

                {{-- ------------------------------------------------ como avisar --}}
                @if ($editando)
                    @php($convite = $this->textoDoConvite())

                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-white/5">
                        {{-- O copiar existe porque os outros dois falham: convidado sem e-mail e
                             sem WhatsApp, grupo de família, Telegram. Um bloco pronto resolve
                             todos esses de uma vez, sem depender de integração nenhuma. --}}
                        <button type="button"
                                x-data="{ copiado: false }"
                                x-on:click="
                                    navigator.clipboard.writeText(@js($convite));
                                    copiado = true;
                                    setTimeout(() => copiado = false, 1600);
                                "
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">
                            <span x-show="! copiado">{{ __('ui_58328ed34f87') }}</span>
                            <span x-show="copiado" x-cloak class="text-emerald-600 dark:text-emerald-400">{{ __('ui_094a8b9530ed') }}</span>
                        </button>

                        <x-filament::button size="xs" color="gray" wire:click="enviarPorEmail"
                                            icon="heroicon-o-envelope">
                            {{ __('ui_b442dd857d41') }}
                        </x-filament::button>

                        <x-filament::button size="xs" color="gray" wire:click="abrirAviso"
                                            icon="heroicon-o-chat-bubble-left-right">
                            {{ __('ui_3166899ff849') }}
                        </x-filament::button>
                    </div>

                    {{-- O texto que o botão copia, à mostra: quem cola em outro lugar quer ver
                         antes o que vai colar. --}}
                    <details class="mt-2">
                        <summary class="cursor-pointer text-[11px] text-gray-400">{{ __('ui_500857bf9e84') }}</summary>
                        <pre class="mt-1 whitespace-pre-wrap rounded-lg bg-gray-50 p-2 text-[11px] text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $convite }}</pre>
                    </details>
                @else
                    <p class="mt-3 border-t border-gray-100 pt-3 text-[11px] text-gray-400 dark:border-white/5">
                        {{ __('ui_55a04936a12c') }}
                    </p>
                @endif
            </div>
        @endif

        {{-- ------------------------------------------------------------- por vídeo --}}
        @if ($tipo === \App\Models\Appointment::COMPROMISSO)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model.live="por_video"
                           class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500 dark:border-white/20">
                    <span>
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-100">
                            {{ __('ui_8ecca23a0336') }}
                        </span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            Abre uma sala em {{ config('app.name') }} e manda o link no WhatsApp de
                            quem foi convidado. Não precisa instalar nada.
                        </span>
                    </span>
                </label>

                @if ($por_video && ! app(\App\Services\Video\Chamada::class)->disponivel())
                    <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                        {{ __('ui_db365ae15f5d') }}
                    </p>
                @elseif ($por_video && ! $contact_id)
                    {{-- Sem contato nao ha para quem mandar: a sala nasce e o link fica na tela. --}}
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('ui_c5bea6960478') }}
                    </p>
                @endif

                {{-- O link do compromisso que já é por vídeo, para copiar e remandar. --}}
                @if ($editando)
                    @php($jaTem = \App\Models\Appointment::find($editando)?->meeting)

                    @if ($jaTem)
                        <div class="mt-3 flex items-center gap-1 border-t border-gray-100 pt-3 dark:border-white/5">
                            <a href="{{ $jaTem->url() }}" target="_blank" rel="noopener"
                               class="truncate text-xs text-primary-600 underline dark:text-primary-400">{{ $jaTem->url() }}</a>
                            <x-inbox.copiar :valor="$jaTem->url()" titulo="Copiar o link da reunião" />
                        </div>
                    @endif
                @endif
            </div>
        @endif

        {{-- --------------------------------------------------- outro em cima da hora --}}
        @php($choques = $this->conflitos())

        @if ($choques->isNotEmpty())
            {{-- Avisa e nao impede: marcar duas coisas na mesma hora as vezes e proposital, e o
                 horario ja fica travado onde importa — o link publico nao oferece vaga ocupada. --}}
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="font-medium">{{ __('ui_98194276e147') }}</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($choques as $c)
                        <li>· {{ $c->comeca_em->format('H:i') }} — {{ $c->titulo }}</li>
                    @endforeach
                </ul>
                <p class="mt-1 opacity-80">{{ __('ui_90ab7c140d00') }}</p>
            </div>
        @endif

        <label class="block">
            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_9e84999d3dca') }}</span>
            <textarea wire:model="descricao" rows="2"
                      class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800"></textarea>
        </label>

        <div class="flex flex-wrap gap-2">
            <x-filament::button wire:click="salvar">{{ __('ui_93bf2d32ec33') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="$set('formAberto', false)">{{ __('ui_c111e0ab9ddb') }}</x-filament::button>

            @if ($editando)
                <x-filament::button color="gray" wire:click="concluir({{ $editando }})">{{ __('ui_acd7386a0c0f') }}</x-filament::button>
                <x-filament::button class="ms-auto" color="danger" wire:click="excluir({{ $editando }})"
                                    wire:confirm="Excluir da agenda?">{{ __('ui_8e7c1314fe0b') }}</x-filament::button>
            @endif
        </div>
    </div>
</x-filament::section>
