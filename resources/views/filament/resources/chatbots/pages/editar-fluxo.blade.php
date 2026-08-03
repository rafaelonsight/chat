{{--
    Construtor de fluxo. Duas decisoes que moldam o arquivo:

    1. LIGAR E POR CLIQUE, nao por arrastar. Clica na saida, clica no cartao
       destino. Arrastar linha e bonito e falha em tela sensivel ao toque, em
       zoom e com a mao tremida; clicar acerta sempre e desfaz com Esc.

    2. A GEOMETRIA DAS LINHAS SAI DO LAYOUT (offsetTop), nao de constantes.
       Chutar altura de cabecalho e de linha funciona ate alguem mudar um padding
       e as linhas passarem a sair do lugar errado.
--}}
<x-filament-panels::page>
    @php
        $desenho = $this->desenho;
        $problemas = $this->problemas;
        $bot = $this->record;
    @endphp

    <div
        x-data="construtorDeFluxo(@js($desenho))"
        x-on:keydown.escape="cancelarLigacao()"
        class="flex flex-col gap-3"
    >
        {{-- ------------------------------------------------------ barra de acoes --}}
        <div class="flex flex-wrap items-center gap-2">
            <span @class([
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300' => $bot->publicado(),
                'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' => ! $bot->publicado(),
            ])>
                <span @class([
                    'h-1.5 w-1.5 rounded-full',
                    'bg-emerald-600' => $bot->publicado(),
                    'bg-amber-500' => ! $bot->publicado(),
                ])></span>
                {{ $bot->publicado() ? 'Publicado' : 'Rascunho' }}
            </span>

            <span class="text-xs text-gray-500 dark:text-gray-400">versão {{ $bot->versao }}</span>

            <div class="ms-auto flex items-center gap-2">
                {{-- zoom --}}
                <div class="flex items-center rounded-lg border border-gray-200 dark:border-white/10">
                    <button type="button" x-on:click="ajustarZoom(-0.1)"
                            class="px-2 py-1 text-sm text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                            aria-label="Diminuir zoom">&minus;</button>
                    <span class="w-12 text-center text-xs text-gray-500 tabular-nums" x-text="Math.round(escala * 100) + '%'"></span>
                    <button type="button" x-on:click="ajustarZoom(0.1)"
                            class="px-2 py-1 text-sm text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                            aria-label="Aumentar zoom">+</button>
                </div>

                <x-filament::button color="gray" size="sm" icon="heroicon-o-arrows-pointing-in" x-on:click="enquadrar()">
                    Enquadrar
                </x-filament::button>

                @if (count($desenho) <= 1)
                    <x-filament::button color="gray" size="sm" icon="heroicon-o-sparkles" wire:click="criarExemplo">
                        Fluxo de exemplo
                    </x-filament::button>
                @endif

                <x-filament::button color="gray" size="sm" icon="heroicon-o-plus" x-on:click="novoPasso()">
                    Novo grupo
                </x-filament::button>

                <x-filament::button
                    size="sm"
                    icon="heroicon-o-arrow-up-tray"
                    wire:click="publicar"
                    :color="count($problemas) ? 'gray' : 'primary'"
                >
                    Publicar
                </x-filament::button>
            </div>
        </div>

        {{-- ------------------------------------------------------------ problemas --}}
        @if (count($problemas))
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="mb-1 font-medium">
                    Falta resolver {{ count($problemas) }} {{ count($problemas) === 1 ? 'coisa' : 'coisas' }} antes de publicar:
                </p>
                <ul class="space-y-0.5">
                    @foreach (array_slice($problemas, 0, 6) as $problema)
                        <li>&bull; {{ $problema }}</li>
                    @endforeach
                    @if (count($problemas) > 6)
                        <li class="opacity-70">&bull; e mais {{ count($problemas) - 6 }}&hellip;</li>
                    @endif
                </ul>
            </div>
        @endif

        {{-- aviso do modo ligar: sem isto o usuario clica numa saida e nao entende
             por que o cursor mudou --}}
        <div x-show="ligando" x-cloak
             class="flex items-center gap-2 rounded-lg border border-primary-300 bg-primary-50 px-3 py-2 text-xs text-primary-900 dark:border-primary-500/30 dark:bg-primary-500/10 dark:text-primary-200">
            <span>Agora clique no grupo que deve receber essa saída.</span>
            <button type="button" x-on:click="cancelarLigacao()" class="font-medium underline">cancelar (Esc)</button>
        </div>

        <div class="flex gap-3">
            {{-- ------------------------------------------------------------ canvas --}}
            <div
                x-ref="viewport"
                x-on:pointerdown="iniciarPan($event)"
                x-on:wheel.prevent="rolar($event)"
                class="relative flex-1 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-950"
                style="height: calc(100dvh - 19rem); min-height: 30rem;
                       background-image: radial-gradient(circle, rgb(0 0 0 / 0.07) 1px, transparent 1px);
                       background-size: 22px 22px;"
                x-bind:style="{
                    backgroundPosition: `${pan.x}px ${pan.y}px`,
                    backgroundSize: `${22 * escala}px ${22 * escala}px`,
                }"
            >
                <div
                    x-ref="mundo"
                    class="absolute left-0 top-0 origin-top-left"
                    x-bind:style="`transform: translate(${pan.x}px, ${pan.y}px) scale(${escala}); width: 6000px; height: 4000px`"
                >
                    {{-- linhas por baixo dos cartoes --}}
                    <svg class="pointer-events-none absolute left-0 top-0 h-full w-full overflow-visible">
                        <defs>
                            <marker id="pontaFluxo" viewBox="0 0 8 8" refX="7" refY="4"
                                    markerWidth="7" markerHeight="7" orient="auto">
                                <path d="M0,0 L8,4 L0,8 z" class="fill-gray-400 dark:fill-gray-500" />
                            </marker>
                        </defs>

                        @foreach ($desenho as $passo)
                            @foreach ($passo['handles'] as $h)
                                @php $destino = $passo['saidas'][$h['handle']] ?? null; @endphp
                                @if ($destino)
                                    @php $chave = $passo['id'].':'.$h['handle']; @endphp
                                    <path x-bind:d="linhas[@js($chave)] ?? ''" fill="none"
                                          class="stroke-gray-300 dark:stroke-gray-700"
                                          stroke-width="3.5" />
                                    <path x-bind:d="linhas[@js($chave)] ?? ''" fill="none"
                                          marker-end="url(#pontaFluxo)"
                                          class="stroke-gray-400 dark:stroke-gray-500"
                                          stroke-width="1.75" />
                                @endif
                            @endforeach
                        @endforeach

                        {{-- linha fantasma enquanto escolhe o destino --}}
                        <path x-show="ligando" :d="fantasma" fill="none" stroke-dasharray="5 4"
                              class="stroke-primary-500" stroke-width="1.75" />
                    </svg>

                    {{-- ------------------------------------------------------ cartoes --}}
                    @foreach ($desenho as $passo)
                        <div
                            wire:key="passo-{{ $passo['id'] }}"
                            data-step="{{ $passo['id'] }}"
                            data-x="{{ $passo['x'] }}"
                            data-y="{{ $passo['y'] }}"
                            x-on:pointerup.stop="alvoClicado({{ $passo['id'] }})"
                            @class([
                                'absolute w-72 select-none rounded-xl bg-white shadow-sm ring-1 transition-shadow dark:bg-gray-900',
                                'ring-amber-300 dark:ring-amber-500/40' => $passo['inicio'],
                                'ring-gray-200 dark:ring-white/10' => ! $passo['inicio'],
                            ])
                            style="left: {{ $passo['x'] }}px; top: {{ $passo['y'] }}px"
                            x-bind:class="{ 'ring-2 ring-primary-500 shadow-lg': ligando && ligando.de !== {{ $passo['id'] }} }"
                        >
                            {{-- cabecalho: e por aqui que se arrasta --}}
                            <div
                                data-ancora-entrada
                                x-on:pointerdown.stop="iniciarArraste($event, {{ $passo['id'] }})"
                                @class([
                                    'flex cursor-grab items-center gap-2 rounded-t-xl px-3 py-2.5 active:cursor-grabbing',
                                    'bg-amber-50 dark:bg-amber-500/10' => $passo['inicio'],
                                    'bg-gray-50 dark:bg-white/5' => ! $passo['inicio'],
                                ])
                            >
                                @if ($passo['inicio'])
                                    <x-filament::icon icon="heroicon-m-bolt" class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                @else
                                    <x-filament::icon icon="heroicon-m-rectangle-stack" class="h-4 w-4 shrink-0 text-gray-400" />
                                @endif

                                <span class="flex-1 truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                                    {{ $passo['nome'] }}
                                </span>

                                @unless ($passo['inicio'])
                                    <button type="button"
                                            x-on:pointerdown.stop
                                            wire:click="removerPasso({{ $passo['id'] }})"
                                            wire:confirm="Remover o grupo {{ $passo['nome'] }}?"
                                            class="shrink-0 rounded p-0.5 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
                                            aria-label="Remover grupo">
                                        <x-filament::icon icon="heroicon-m-trash" class="h-3.5 w-3.5" />
                                    </button>
                                @endunless
                            </div>

                            {{-- acoes --}}
                            @if ($passo['inicio'])
                                <p class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                    Quando o cliente manda a primeira mensagem.
                                </p>
                            @else
                                <div class="divide-y divide-gray-100 dark:divide-white/5">
                                    @forelse ($passo['acoes'] as $acao)
                                        <button type="button"
                                                wire:key="acao-{{ $acao['id'] }}"
                                                x-on:pointerdown.stop
                                                wire:click="abrirAcao({{ $acao['id'] }})"
                                                class="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/5">
                                            <span @class([
                                                'mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded text-[10px] font-semibold',
                                                match ($acao['tipo']) {
                                                    'mensagem'    => 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
                                                    'menu'        => 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
                                                    'pergunta'    => 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-500/20 dark:text-fuchsia-300',
                                                    'esperar'     => 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300',
                                                    'condicional' => 'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300',
                                                    'transferir'  => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
                                                    default       => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
                                                },
                                            ])>
                                                {{ mb_strtoupper(mb_substr($acao['tipo'], 0, 1)) }}
                                            </span>

                                            <span class="min-w-0 flex-1">
                                                <span class="block text-xs font-medium text-gray-700 dark:text-gray-200">{{ $acao['rotulo'] }}</span>
                                                @if ($acao['resumo'] !== '')
                                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $acao['resumo'] }}</span>
                                                @endif
                                            </span>
                                        </button>
                                    @empty
                                        <p class="px-3 py-3 text-xs text-gray-400">
                                            Nenhuma ação. Este grupo não faz nada ainda.
                                        </p>
                                    @endforelse
                                </div>

                                <button type="button"
                                        x-on:pointerdown.stop
                                        wire:click="abrirPaleta({{ $passo['id'] }})"
                                        class="flex w-full items-center gap-1.5 border-t border-gray-100 px-3 py-2 text-xs font-medium text-primary-600 hover:bg-primary-50 dark:border-white/5 dark:text-primary-400 dark:hover:bg-primary-500/10">
                                    <x-filament::icon icon="heroicon-m-plus" class="h-3.5 w-3.5" />
                                    Adicionar ação
                                </button>
                            @endif

                            {{-- saidas --}}
                            @if (count($passo['handles']))
                                <div class="space-y-1 rounded-b-xl border-t border-gray-100 bg-gray-50/60 px-3 py-2 dark:border-white/5 dark:bg-white/[0.02]">
                                    @foreach ($passo['handles'] as $h)
                                        @php $ligado = $passo['saidas'][$h['handle']] ?? null; @endphp
                                        <div class="flex items-center gap-1.5"
                                             data-handle-row
                                             data-from="{{ $passo['id'] }}"
                                             data-handle="{{ $h['handle'] }}">
                                            <span class="min-w-0 flex-1 truncate text-[11px] text-gray-500 dark:text-gray-400">
                                                {{ $h['rotulo'] ?? 'seguir' }}
                                            </span>

                                            @if ($ligado)
                                                <button type="button"
                                                        x-on:pointerdown.stop
                                                        wire:click="desligar({{ $passo['id'] }}, '{{ $h['handle'] }}')"
                                                        class="rounded px-1 text-[10px] text-gray-400 hover:text-red-600"
                                                        aria-label="Desfazer ligação">desligar</button>
                                            @endif

                                            <button type="button"
                                                    x-on:pointerdown.stop
                                                    x-on:click="armarLigacao({{ $passo['id'] }}, '{{ $h['handle'] }}')"
                                                    @class([
                                                        'h-3 w-3 shrink-0 rounded-full ring-2 ring-white transition dark:ring-gray-900',
                                                        'bg-primary-500' => $ligado,
                                                        'bg-gray-300 hover:bg-primary-400 dark:bg-gray-600' => ! $ligado,
                                                    ])
                                                    x-bind:class="{ 'scale-125 bg-primary-600': ligando && ligando.de === {{ $passo['id'] }} && ligando.handle === '{{ $h['handle'] }}' }"
                                                    :aria-label="'{{ $h['rotulo'] ?? 'seguir' }}: escolher destino'"></button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- vazio --}}
                @if (count($desenho) <= 1)
                    <div class="pointer-events-none absolute inset-0 grid place-items-center">
                        <p class="rounded-lg bg-white/80 px-4 py-2 text-sm text-gray-500 backdrop-blur dark:bg-gray-900/80 dark:text-gray-400">
                            Comece com <strong>Fluxo de exemplo</strong> ou crie um grupo.
                        </p>
                    </div>
                @endif
            </div>

            {{-- ------------------------------------------------------------ gaveta --}}
            @if ($paletaAberta || $acaoAberta)
                <div class="w-80 shrink-0 overflow-y-auto rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
                     style="height: calc(100dvh - 19rem); min-height: 30rem;">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                            {{ $paletaAberta ? 'Ações disponíveis' : 'Configurar ação' }}
                        </h3>
                        <button type="button" wire:click="fecharGaveta"
                                class="rounded p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Fechar">
                            <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                        </button>
                    </div>

                    @if ($paletaAberta)
                        @include('filament.resources.chatbots.pages.partials.paleta')
                    @else
                        @include('filament.resources.chatbots.pages.partials.config-acao')
                    @endif
                </div>
            @endif
        </div>
    </div>

    @script
    <script>
        window.construtorDeFluxo = (desenho) => ({
            escala: 1,
            pan: { x: 40, y: 20 },
            linhas: {},
            ligando: null,
            fantasma: '',
            desenho,

            init() {
                this.$nextTick(() => this.recalcular());

                // Depois de cada atualizacao do Livewire os cartoes podem ter mudado
                // de tamanho (acao adicionada, saida nova). Sem redesenhar aqui, as
                // linhas ficam apontando para onde as saidas estavam antes.
                Livewire.hook('morph.updated', () => this.$nextTick(() => this.recalcular()));

                window.addEventListener('resize', () => this.recalcular());
            },

            // -------------------------------------------------------------- geometria

            /**
             * As coordenadas saem do LAYOUT (offsetTop/offsetWidth), nao de constantes
             * de altura. Chutar a altura funciona ate alguem mexer num padding.
             */
            recalcular() {
                const linhas = {};

                this.$el.querySelectorAll('[data-handle-row]').forEach((linha) => {
                    const deId = linha.dataset.from;
                    const handle = linha.dataset.handle;

                    const cartaoDe = this.$el.querySelector(`[data-step="${deId}"]`);
                    if (! cartaoDe) return;

                    const destinoId = this.destinoDe(deId, handle);
                    if (! destinoId) return;

                    const cartaoPara = this.$el.querySelector(`[data-step="${destinoId}"]`);
                    if (! cartaoPara) return;

                    const bola = linha.querySelector('button:last-child');
                    if (! bola) return;

                    const x1 = this.coordX(cartaoDe) + cartaoDe.offsetWidth;
                    const y1 = this.coordY(cartaoDe) + bola.offsetTop + bola.offsetHeight / 2;

                    const ancora = cartaoPara.querySelector('[data-ancora-entrada]');
                    const x2 = this.coordX(cartaoPara);
                    const y2 = this.coordY(cartaoPara) + (ancora ? ancora.offsetTop + ancora.offsetHeight / 2 : 20);

                    linhas[`${deId}:${handle}`] = this.curva(x1, y1, x2, y2);
                });

                this.linhas = linhas;
            },

            destinoDe(deId, handle) {
                const passo = this.desenho.find((p) => String(p.id) === String(deId));
                return passo ? (passo.saidas?.[handle] ?? null) : null;
            },

            coordX(el) { return parseFloat(el.dataset.x || 0); },
            coordY(el) { return parseFloat(el.dataset.y || 0); },

            curva(x1, y1, x2, y2) {
                // Alca proporcional a distancia: curva suave de perto e de longe.
                const alca = Math.max(40, Math.min(160, Math.abs(x2 - x1) * 0.5));
                return `M ${x1},${y1} C ${x1 + alca},${y1} ${x2 - alca},${y2} ${x2},${y2}`;
            },

            // ---------------------------------------------------------------- arraste

            iniciarArraste(e, id) {
                if (e.button !== 0 || this.ligando) return;

                const cartao = e.currentTarget.closest('[data-step]');
                const iniX = e.clientX, iniY = e.clientY;
                const origX = this.coordX(cartao), origY = this.coordY(cartao);
                let moveu = false;

                const mover = (ev) => {
                    // Divide pela escala: com zoom em 50%, mover 10px na tela deve
                    // mover 20px no mundo, senao o cartao "escapa" do cursor.
                    const nx = Math.round(origX + (ev.clientX - iniX) / this.escala);
                    const ny = Math.round(origY + (ev.clientY - iniY) / this.escala);

                    if (Math.abs(nx - origX) > 2 || Math.abs(ny - origY) > 2) moveu = true;

                    cartao.dataset.x = nx;
                    cartao.dataset.y = ny;
                    cartao.style.left = nx + 'px';
                    cartao.style.top = ny + 'px';
                    this.recalcular();
                };

                const soltar = () => {
                    window.removeEventListener('pointermove', mover);
                    window.removeEventListener('pointerup', soltar);

                    if (moveu) {
                        $wire.moverPasso(id, parseInt(cartao.dataset.x), parseInt(cartao.dataset.y));
                    }
                };

                window.addEventListener('pointermove', mover);
                window.addEventListener('pointerup', soltar);
            },

            // -------------------------------------------------------------------- pan

            iniciarPan(e) {
                // So no fundo: pointerdown no cartao ja foi parado com .stop
                if (e.target !== this.$refs.viewport && e.target !== this.$refs.mundo) return;
                if (e.button !== 0) return;

                const iniX = e.clientX, iniY = e.clientY;
                const origem = { ...this.pan };

                const mover = (ev) => {
                    this.pan = { x: origem.x + (ev.clientX - iniX), y: origem.y + (ev.clientY - iniY) };
                };
                const soltar = () => {
                    window.removeEventListener('pointermove', mover);
                    window.removeEventListener('pointerup', soltar);
                };

                window.addEventListener('pointermove', mover);
                window.addEventListener('pointerup', soltar);
            },

            rolar(e) {
                if (e.ctrlKey || e.metaKey) {
                    this.ajustarZoom(e.deltaY > 0 ? -0.08 : 0.08);
                    return;
                }

                this.pan = { x: this.pan.x - e.deltaX, y: this.pan.y - e.deltaY };
            },

            ajustarZoom(delta) {
                this.escala = Math.min(1.6, Math.max(0.4, Math.round((this.escala + delta) * 100) / 100));
            },

            enquadrar() {
                const cartoes = [...this.$el.querySelectorAll('[data-step]')];
                if (! cartoes.length) return;

                const xs = cartoes.map((c) => this.coordX(c));
                const ys = cartoes.map((c) => this.coordY(c));
                const x2 = Math.max(...cartoes.map((c) => this.coordX(c) + c.offsetWidth));
                const y2 = Math.max(...cartoes.map((c) => this.coordY(c) + c.offsetHeight));

                const larg = this.$refs.viewport.clientWidth - 80;
                const alt = this.$refs.viewport.clientHeight - 80;

                this.escala = Math.min(1, Math.max(0.4,
                    Math.min(larg / (x2 - Math.min(...xs)), alt / (y2 - Math.min(...ys)))));

                this.pan = { x: 40 - Math.min(...xs) * this.escala, y: 40 - Math.min(...ys) * this.escala };
                this.$nextTick(() => this.recalcular());
            },

            // ---------------------------------------------------------------- ligacao

            armarLigacao(de, handle) {
                this.ligando = { de, handle };
                this.fantasma = '';
            },

            cancelarLigacao() {
                this.ligando = null;
                this.fantasma = '';
            },

            alvoClicado(id) {
                if (! this.ligando) return;

                if (this.ligando.de === id) {
                    // Ligar um passo a ele mesmo e laco infinito; o servico e o banco
                    // tambem recusam, mas avisar aqui evita a viagem.
                    this.cancelarLigacao();
                    return;
                }

                $wire.ligar(this.ligando.de, this.ligando.handle, id);
                this.cancelarLigacao();
            },

            novoPasso() {
                // Nasce no centro do que esta a vista, nao numa coordenada fixa: com
                // pan aplicado, coordenada fixa criaria o cartao fora da tela.
                const x = Math.round((this.$refs.viewport.clientWidth / 2 - this.pan.x) / this.escala) - 144;
                const y = Math.round((this.$refs.viewport.clientHeight / 2 - this.pan.y) / this.escala) - 60;

                $wire.criarPasso(Math.max(0, x), Math.max(0, y));
            },
        });
    </script>
    @endscript
</x-filament-panels::page>
