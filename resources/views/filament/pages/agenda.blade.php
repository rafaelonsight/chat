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
        {{-- ------------------------------------------------------------ barra --}}
        <div class="flex flex-wrap items-center gap-2">
            @unless ($this->visao === 'link')
                <x-filament::button wire:click="novo" icon="heroicon-o-plus">Novo</x-filament::button>
            @endunless

            @unless (in_array($this->visao, \App\Filament\Pages\Agenda::SEM_PERIODO, true))
                <x-filament::button color="gray" size="sm" wire:click="hoje">Hoje</x-filament::button>

                <div class="flex">
                    <button type="button" wire:click="anterior" aria-label="Anterior"
                            class="grid h-8 w-8 place-items-center rounded-l-lg border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/20 dark:text-gray-300 dark:hover:bg-white/5">&lsaquo;</button>
                    <button type="button" wire:click="proximo" aria-label="Próximo"
                            class="-ml-px grid h-8 w-8 place-items-center rounded-r-lg border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/20 dark:text-gray-300 dark:hover:bg-white/5">&rsaquo;</button>
                </div>
            @endunless

            <h2 class="mr-auto text-base font-semibold text-gray-900 dark:text-gray-100">{{ $rotulo }}</h2>

            {{-- Agenda de quem. Sem filtro e a equipe inteira, que e o que faz o calendario
                 valer alguma coisa: saber que o colega ja esta na rua as 14h. --}}
            @unless ($this->visao === 'link')
                <select wire:model.live="quem"
                        class="h-8 rounded-lg border-gray-300 py-0 text-xs dark:border-white/20 dark:bg-gray-800">
                    <option value="">Equipe toda</option>
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
                Clique num espaço vazio para marcar. Arraste para remarcar.
                <span class="inline-block h-2 w-2 rounded-sm bg-indigo-400 align-middle"></span> compromisso ·
                <span class="inline-block h-2 w-2 rounded-sm bg-amber-400 align-middle"></span> lembrete, só você vê
            </p>
        @endunless
    </div>
</x-filament-panels::page>
