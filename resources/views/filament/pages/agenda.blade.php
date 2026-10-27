<x-filament-panels::page>
    {{--
        O ARRASTO VIVE AQUI, no elemento que embrulha tudo, e nao em cada bloco.

        Remarcar e a coisa mais comum que acontece com um compromisso, e arrastar e o gesto que
        todo mundo ja tem na mao. Quem solta decide para onde: o alvo e lido do documento no
        momento em que o dedo levanta (data-dia), entao a mesma logica serve para a grade de
        horas e para a folha do mes, sem cada visao saber da outra.

        Mexeu menos de 4px nao foi arrasto, foi clique — e clique abre para editar. Sem esse
        limiar, a mao tremida de quem so queria abrir remarcaria o compromisso.
    --}}
    <div
        x-data="{
            id: null, off: 0, dx: 0, dy: 0, x0: 0, y0: 0, mexeu: false,

            pegar(e, id) {
                if (e.button !== 0 && e.pointerType === 'mouse') return
                this.id = id
                this.off = e.clientY - e.currentTarget.getBoundingClientRect().top
                this.x0 = e.clientX; this.y0 = e.clientY
                this.dx = 0; this.dy = 0; this.mexeu = false
            },

            mexer(e) {
                if (this.id === null) return
                this.dx = e.clientX - this.x0
                this.dy = e.clientY - this.y0
                if (Math.abs(this.dx) > 4 || Math.abs(this.dy) > 4) this.mexeu = true
            },

            soltar(e) {
                const id = this.id, mexeu = this.mexeu
                if (id === null) return
                this.id = null; this.dx = 0; this.dy = 0; this.mexeu = false

                if (! mexeu) { $wire.editar(id); return }

                const cel = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-dia]')
                if (! cel) return

                if (cel.dataset.horas === '1') {
                    const r = cel.getBoundingClientRect()
                    const m = Math.round((((e.clientY - this.off) - r.top) / r.height * 1440) / 15) * 15
                    $wire.mover(id, cel.dataset.dia, Math.max(0, Math.min(1425, m)))
                } else {
                    $wire.mover(id, cel.dataset.dia, null)
                }
            },

            /* Objeto, e nao texto: assim o Alpine junta com o style que ja posiciona o bloco. */
            fantasma(id) {
                return this.id === id && this.mexeu
                    ? { transform: `translate(${this.dx}px, ${this.dy}px)`, zIndex: 40, opacity: 0.85, pointerEvents: 'none' }
                    : {}
            },
        }"
        @pointermove.window="mexer($event)"
        @pointerup.window="soltar($event)"
        class="space-y-4"
    >
        <div class="flex items-start gap-5">
            {{-- Lateral inspirada no Google Agenda: a pessoa navega no tempo e enxerga as
                 paginas publicas sem abandonar a grade onde esta trabalhando. --}}
            <aside class="hidden w-56 shrink-0 space-y-5 lg:block">
                <section class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
                    <div class="mb-2 flex items-center justify-between">
                        <button type="button" wire:click="miniAnterior" aria-label="{{ __('ui_9ef462c393bb') }}"
                                class="grid h-7 w-7 place-items-center rounded-md text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10">&lsaquo;</button>
                        <span class="text-xs font-semibold text-gray-800 dark:text-gray-100">{{ $mesMini }}</span>
                        <button type="button" wire:click="miniProximo" aria-label="{{ __('ui_a6bc9247779d') }}"
                                class="grid h-7 w-7 place-items-center rounded-md text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10">&rsaquo;</button>
                    </div>

                    <div class="grid grid-cols-7 text-center text-[10px] text-gray-400">
                        @foreach (['D', 'S', 'T', 'Q', 'Q', 'S', 'S'] as $dia)
                            <span class="py-1">{{ $dia }}</span>
                        @endforeach

                        @foreach ($miniSemanas as $semana)
                            @foreach ($semana as $dia)
                                <button type="button" wire:click="irParaDia('{{ $dia['data'] }}')"
                                        @class([
                                            'mx-auto grid h-7 w-7 place-items-center rounded-full text-[11px] transition',
                                            'bg-primary-600 font-semibold text-white' => $dia['selecionado'],
                                            'font-semibold text-primary-700 dark:text-primary-300' => $dia['hoje'] && ! $dia['selecionado'],
                                            'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10' => $dia['noMes'] && ! $dia['selecionado'] && ! $dia['hoje'],
                                            'text-gray-300 dark:text-gray-600' => ! $dia['noMes'] && ! $dia['selecionado'],
                                        ])>
                                    {{ $dia['numero'] }}
                                </button>
                            @endforeach
                        @endforeach
                    </div>
                </section>

                <section class="border-t border-gray-200 pt-4 dark:border-white/10">
                    <div class="mb-2 flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('ui_9e2ce8ff14d9') }}</h3>
                            <p class="text-[11px] text-gray-500">{{ __('ui_90cdc53c74a0') }}</p>
                        </div>
                        <button type="button" wire:click="verComo('link')" title="{{ __('ui_3952ef8b5b8e') }}"
                                class="grid h-7 w-7 place-items-center rounded-md text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10">+</button>
                    </div>

                    <div class="space-y-1">
                        @forelse ($paginasReserva as $pagina)
                            <button type="button" wire:click="verComo('link')"
                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left hover:bg-gray-100 dark:hover:bg-white/5">
                                <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-emerald-500' => $pagina->ativa, 'bg-gray-400' => ! $pagina->ativa])></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-xs font-medium text-gray-700 dark:text-gray-200">{{ $pagina->titulo }}</span>
                                    <span class="block truncate text-[10px] text-gray-500">{{ $pagina->user?->primeiroNome() }} · {{ $pagina->duracao_min }} min</span>
                                </span>
                            </button>
                        @empty
                            <button type="button" wire:click="verComo('link')" class="w-full rounded-lg border border-dashed border-gray-300 px-3 py-3 text-left text-xs text-gray-500 hover:bg-gray-50 dark:border-white/15 dark:hover:bg-white/5">
                                {{ __('ui_e4fefc924f28') }}
                            </button>
                        @endforelse
                    </div>

                    @if ($paginasReserva->isNotEmpty())
                        <button type="button" wire:click="verComo('link')" class="mt-2 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('ui_2134d16958a1') }}</button>
                    @endif
                </section>
            </aside>

            <div class="min-w-0 flex-1 space-y-4">
        {{-- ------------------------------------------------------------ barra --}}
        <div class="flex flex-wrap items-center gap-2">
            @unless ($this->visao === 'link')
                <x-filament::button wire:click="novo" icon="heroicon-o-plus">{{ __('ui_887bef1273bb') }}</x-filament::button>
            @endunless

            @unless (in_array($this->visao, \App\Filament\Pages\Agenda::SEM_PERIODO, true))
                <x-filament::button color="gray" size="sm" wire:click="hoje">{{ __('ui_ba0084278453') }}</x-filament::button>

                <div class="flex">
                    <button type="button" wire:click="anterior" aria-label="{{ __('ui_d97f1097b8f3') }}"
                            class="grid h-8 w-8 place-items-center rounded-l-lg border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/20 dark:text-gray-300 dark:hover:bg-white/5">&lsaquo;</button>
                    <button type="button" wire:click="proximo" aria-label="{{ __('ui_64b2d0d63475') }}"
                            class="-ml-px grid h-8 w-8 place-items-center rounded-r-lg border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/20 dark:text-gray-300 dark:hover:bg-white/5">&rsaquo;</button>
                </div>
            @endunless

            <h2 class="mr-auto text-base font-semibold text-gray-900 dark:text-gray-100">{{ $rotulo }}</h2>

            {{-- Agenda de quem. Sem filtro e a equipe inteira, que e o que faz o calendario
                 valer alguma coisa: saber que o colega ja esta na rua as 14h. --}}
            @unless ($this->visao === 'link')
                <select wire:model.live="quem"
                        class="h-8 rounded-lg border-gray-300 py-0 text-xs dark:border-white/20 dark:bg-gray-800">
                    <option value="">{{ __('ui_52bec9247857') }}</option>
                    @foreach ($pessoas as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            @endunless

            <div class="flex overflow-hidden rounded-lg border border-gray-300 dark:border-white/20">
                @foreach (\App\Filament\Pages\Agenda::VISOES as $chave => $nome)
                    <button type="button" wire:click="verComo('{{ $chave }}')"
                            @class([
                                'border-l border-gray-300 px-3 py-1.5 text-xs first:border-l-0 dark:border-white/20',
                                'bg-primary-600 font-medium text-white' => $this->visao === $chave,
                                'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $this->visao !== $chave,
                            ])>
                        {{ $nome }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($recadoDoVideo)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                {{ $recadoDoVideo }}
                <button type="button" wire:click="$set('recadoDoVideo', null)"
                        class="ml-2 underline opacity-70">fechar</button>
            </div>
        @endif

        {{-- ------------------------------------------------------------ form --}}
        @if ($formAberto && $this->visao !== 'link')
            @include('filament.pages.agenda.form')
        @endif

        @if ($avisando)
            @include('filament.pages.agenda.aviso')
        @endif

        {{-- ---------------------------------------------------------- a tela --}}
        @if ($this->visao === 'mes')
            @include('filament.pages.agenda.mes')
        @elseif ($this->visao === 'lista')
            @include('filament.pages.agenda.lista')
        @elseif ($this->visao === 'link')
            {{-- Componente proprio, e nao mais uma pagina ao lado: configurar quando se aceita
                 visita e olhar a semana sao a mesma cabeca no mesmo minuto. --}}
            @livewire(\App\Livewire\Crm\LinksDeAgendamento::class)
        @else
            @include('filament.pages.agenda.grade')
        @endif

        @unless (in_array($this->visao, \App\Filament\Pages\Agenda::SEM_PERIODO, true))
            <p class="text-xs text-gray-400">
                {{ __('ui_bb629fce6842') }}
                <span class="inline-block h-2 w-2 rounded-sm bg-indigo-400 align-middle"></span> {{ __('ui_c8d5f4bf74dc') }}
                <span class="inline-block h-2 w-2 rounded-sm bg-amber-400 align-middle"></span> {{ __('ui_ed3fbc8e1cf9') }}
            </p>
        @endunless
            </div>
        </div>
    </div>
</x-filament-panels::page>
