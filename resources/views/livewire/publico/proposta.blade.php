{{--
    A PROPOSTA COMO O CLIENTE VE — versao escura.

    POR QUE ESCURA. A marca e ambar sobre grafite. No papel claro o ambar tem 2:1 de contraste e
    nao pode ser usado em texto: sobra como enfeite. No escuro ele da 9,6:1 — passa a poder
    carregar titulo, numero e valor. A cor da marca deixa de ser decoracao e vira a voz da pagina.
    Nao e moda: e o unico fundo em que ESTA marca funciona inteira.

    A ORDEM DA PAGINA E UM ARGUMENTO, e nao um indice. Diagnostico (entendi o problema), plano
    (o que faco), cronograma (quando), quem assina (por que eu) e so entao preco. Preco antes do
    resto e cotacao; preco no fim e decisao.

    DIAGNOSTICO E PLANO FICAM LADO A LADO de proposito, e nao um embaixo do outro: dor 01 na
    esquerda, solucao 01 na direita, na mesma linha do olho. E a leitura em par que faz o
    argumento — separados por meia tela, o cliente perde o vinculo.

    O MOVIMENTO TEM FUNCAO, e nao enfeite: cada secao sobe 14px ao entrar na tela para marcar
    "isto e novo", e os itens escalonam em 40ms para o olho ler na ordem numerada. Com
    prefers-reduced-motion tudo aparece parado, sem excecao.

    E CONTINUA SENDO A MESMA FOLHA QUE SAI NA IMPRESSORA. As cores vivem em variaveis, e o bloco
    @media print troca as variaveis por papel branco e tinta escura. A armadilha que isso resolve:
    texto com gradiente usa -webkit-text-fill-color: transparent, e sem o reset ele sairia
    INVISIVEL no papel — a pagina imprimiria com buracos onde estao os titulos.
--}}

@php
    $conta = $p->tenant;
    $ancoraUnica = $p->ancora('unico');
    $ancoraMensal = $p->ancora('recorrente');
    $selos = collect($p->selos ?? [])->filter()->take(3);
    $emAberto = $p->aceita_em === null && $p->recusada_em === null && ! $p->vencida();

    $blocos = collect($p->blocos ?? []);

    // O par diagnostico/solucao sai da fila para ser desenhado junto, na posicao do primeiro dos
    // dois. O resto segue na ordem em que ele escreveu.
    $dores = $blocos->firstWhere('type', 'diagnostico');
    $solucoes = $blocos->firstWhere('type', 'solucao');
    $posicaoDoPar = $blocos->search(fn ($b) => in_array($b['type'] ?? '', ['diagnostico', 'solucao'], true));

    $itensDoBloco = fn (?array $bloco) => collect($bloco['data']['itens'] ?? [])
        ->filter(fn ($i) => filled($i['titulo'] ?? null))
        ->values();

    $estado = match (true) {
        $p->aceita_em !== null   => ['Aceita', 'text-emerald-300 ring-emerald-400/30 bg-emerald-400/10'],
        $p->recusada_em !== null => ['Recusada', 'text-rose-300 ring-rose-400/30 bg-rose-400/10'],
        $p->vencida()            => ['Vencida', 'text-[#8a8071] ring-white/10 bg-white/5'],
        default                  => ['Em análise', 'text-[#F8C830] ring-[#E8A924]/30 bg-[#E8A924]/10'],
    };
@endphp

<div class="proposta min-h-dvh antialiased">

    {{-- ============================================================ o desenho --}}
    <style>
        .proposta {
            /* Quase-preto MORNO, e nao preto puro: preto puro em tela OLED espalha o texto, e num
               documento com a marca ambar o cinza frio brigaria com o calor da cor. */
            --ground: #0b0a09;
            --ground-2: #100e0c;
            --surface: #171310;
            --surface-2: #1f1a15;
            --linha: rgb(255 255 255 / 7%);
            --linha-forte: rgb(248 200 48 / 22%);
            --ink: #f6f2ea;
            --ink-2: #b9af9e;
            --ink-3: #8a8071;
            --ambar: #e8a924;
            --ambar-claro: #f8c830;
            --ambar-escuro: #e09028;
            --rust: #c25a1e;
            --curva: cubic-bezier(0.16, 1, 0.3, 1);

            background: var(--ground);
            color: var(--ink);
        }

        /* O layout publico pinta o body de cinza claro, e ele apareceria na borracha do scroll e
           atras de conteudo curto. Pintar so quando ESTA pagina esta dentro dele deixa a pagina de
           agendamento, que divide o mesmo layout, como estava. */
        body:has(.proposta) { background: #0b0a09; }

        /* ------------------------------------------------------------ movimento */

        [data-revelar] {
            opacity: 0;
            transform: translateY(14px);
            transition: opacity 0.6s var(--curva), transform 0.6s var(--curva);
            transition-delay: calc(var(--atraso, 0) * 40ms);
        }

        [data-revelar].dentro {
            opacity: 1;
            transform: none;
        }

        /* O brilho de fundo respira devagar. Rapido viraria pisca-pisca; parado viraria mancha. */
        @keyframes respirar {
            from { transform: translate3d(0, 0, 0) scale(1); opacity: 0.55; }
            to   { transform: translate3d(2%, -3%, 0) scale(1.12); opacity: 0.8; }
        }

        .brilho {
            animation: respirar 19s var(--curva) infinite alternate;
            will-change: transform, opacity;
        }

        /*
         * MOVIMENTO E UMA PREFERENCIA DO SISTEMA, e nao uma opiniao nossa. Quem pediu menos
         * movimento tem enxaqueca, labirintite ou simplesmente nao quer — e ve a pagina inteira,
         * parada, sem perder nada.
         */
        @media (prefers-reduced-motion: reduce) {
            [data-revelar] { opacity: 1; transform: none; transition: none; }
            .brilho { animation: none; }
            .proposta * { scroll-behavior: auto; }
        }

        /* ------------------------------------------------------------ tipografia */

        .display {
            font-weight: 700;
            letter-spacing: -0.03em;
            line-height: 0.98;
            text-wrap: balance;
        }

        /* O gradiente do texto e o MESMO caminho de tom do "V" do logotipo. */
        .gradiente {
            background: linear-gradient(100deg, var(--ambar-escuro), var(--ambar-claro) 55%, var(--ambar));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent;
        }

        .rotulo {
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.2em;
            text-transform: uppercase;
        }

        /* ------------------------------------------------------------ pecas */

        .cartao {
            background: var(--surface);
            border: 1px solid var(--linha);
            border-radius: 1rem;
            transition: border-color 0.3s var(--curva), background 0.3s var(--curva), transform 0.3s var(--curva);
        }

        .cartao:hover {
            border-color: var(--linha-forte);
            background: var(--surface-2);
        }

        /* A bolha do numero encosta na borda do cartao, como um selo colado. */
        .selo-numero {
            display: grid;
            place-items: center;
            width: 2.25rem;
            height: 2.25rem;
            flex: none;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: #14100b;
        }

        .selo-dor { background: linear-gradient(140deg, #e2731f, var(--rust)); }
        .selo-solucao { background: linear-gradient(140deg, var(--ambar-claro), var(--ambar-escuro)); }

        .fio {
            height: 1px;
            background: linear-gradient(to right, transparent, var(--linha-forte), transparent);
        }

        /* ------------------------------------------------------------ o papel */

        @media print {
            /*
             * O MESMO DOCUMENTO, EM PAPEL BRANCO. Trocar as variaveis troca a pagina inteira de
             * uma vez — sem isso, imprimir gastaria um cartucho de tinta preta por copia.
             */
            .proposta {
                --ground: #fff;
                --ground-2: #fff;
                --surface: #fff;
                --surface-2: #fff;
                --linha: #d8d2c8;
                --linha-forte: #b9895f;
                --ink: #1a1613;
                --ink-2: #46403a;
                --ink-3: #6b6459;
                background: #fff;
            }

            /* Gradiente em texto usa preenchimento transparente: sem este reset, TODO titulo
               sairia em branco no papel. */
            .gradiente {
                background: none !important;
                -webkit-text-fill-color: #8a5a10 !important;
                color: #8a5a10 !important;
            }

            .selo-numero { color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body:has(.proposta) { background: #fff; }
            .no-print, .brilho { display: none !important; }
            [data-revelar] { opacity: 1 !important; transform: none !important; }
            section, header, footer { break-inside: avoid; }
            @page { margin: 16mm 14mm; }
        }
    </style>

    {{-- ==================================================== barra de progresso --}}
    <div class="no-print fixed inset-x-0 top-0 z-50 h-[2px] bg-transparent">
        <div class="h-full origin-left bg-gradient-to-r from-[#e09028] to-[#f8c830]"
             style="transform: scaleX(0)"
             x-data
             x-init="
                const pintar = () => {
                    const alcance = document.documentElement.scrollHeight - window.innerHeight;
                    $el.style.transform = 'scaleX(' + (alcance > 0 ? window.scrollY / alcance : 0) + ')';
                };
                pintar();
                window.addEventListener('scroll', pintar, { passive: true });
                window.addEventListener('resize', pintar);
             "></div>
    </div>

    {{-- ============================================================== a barra --}}
    <div class="no-print sticky top-0 z-40 border-b border-white/5 bg-[#0b0a09]/80 backdrop-blur-xl">
        <div class="mx-auto flex max-w-6xl items-center gap-3 px-5 py-3 sm:px-8">
            <img src="{{ asset('marca/virtus-chat-claro.png') }}" alt="{{ config('app.name') }}" class="h-6">

            <span class="ml-1 font-mono text-[11px] tracking-wider text-[#8a8071]">{{ $p->numero }}</span>

            <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $estado[1] }}">
                {{ $estado[0] }}
            </span>

            <button type="button" onclick="window.print()"
                    class="ml-auto inline-flex items-center gap-1.5 rounded-full border border-white/10 px-3.5 py-1.5 text-xs font-medium text-[#b9af9e] transition hover:border-[#e8a924]/40 hover:text-[#f6f2ea] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#e8a924]">
                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5 2.5A1.5 1.5 0 0 1 6.5 1h7A1.5 1.5 0 0 1 15 2.5V5h.5A2.5 2.5 0 0 1 18 7.5v5A2.5 2.5 0 0 1 15.5 15H15v2.5a1.5 1.5 0 0 1-1.5 1.5h-7A1.5 1.5 0 0 1 5 17.5V15h-.5A2.5 2.5 0 0 1 2 12.5v-5A2.5 2.5 0 0 1 4.5 5H5V2.5Zm1.5 0h7V5h-7V2.5Zm7 11h-7v4h7v-4Z" clip-rule="evenodd"/>
                </svg>
                PDF
            </button>
        </div>
    </div>

    {{-- ================================================================ a capa --}}
    <header class="relative overflow-hidden">
        {{-- Luz de ambiente. Aria-hidden porque nao ha nada para ler aqui. --}}
        <div class="no-print pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="brilho absolute -right-40 -top-40 size-[42rem] rounded-full opacity-60"
                 style="background: radial-gradient(closest-side, rgb(232 169 36 / 26%), transparent 70%)"></div>
            <div class="brilho absolute -bottom-56 -left-40 size-[34rem] rounded-full opacity-40"
                 style="background: radial-gradient(closest-side, rgb(194 90 30 / 22%), transparent 70%); animation-delay: -7s"></div>
        </div>

        <div class="mx-auto grid max-w-6xl items-center gap-14 px-5 pb-16 pt-16 sm:px-8 sm:pb-24 sm:pt-24 lg:grid-cols-[1.15fr_0.85fr] lg:gap-10">

            <div>
                <p class="rotulo flex items-center gap-2 text-[#8a8071]" data-revelar>
                    <span class="inline-block size-1.5 rotate-45 bg-[#e8a924]"></span>
                    Proposta comercial
                    <span class="text-[#4d463c]">·</span>
                    <span class="font-mono tracking-normal normal-case">{{ $p->numero }}</span>
                </p>

                <h1 class="display mt-5 text-[clamp(2.5rem,7vw,4.5rem)]" data-revelar style="--atraso: 1">
                    {{ $p->cliente_nome }}
                </h1>

                <p class="display gradiente mt-3 max-w-[26ch] text-[clamp(1.35rem,3vw,2rem)]"
                   data-revelar style="--atraso: 2">
                    {{ $p->titulo }}
                </p>

                @if ($p->autor)
                    <div class="cartao mt-8 inline-flex items-center gap-3 px-4 py-3" data-revelar style="--atraso: 3">
                        <span class="grid size-9 place-items-center rounded-full bg-[#e8a924]/12 text-sm font-bold text-[#f8c830]">
                            {{ mb_strtoupper(mb_substr($p->autor->name, 0, 1)) }}
                        </span>
                        <span class="text-left">
                            <span class="rotulo block text-[#8a8071]">Preparada por</span>
                            <span class="block text-sm font-semibold">{{ $p->autor->name }}</span>
                        </span>
                    </div>
                @endif

                {{--
                    O CONTADOR SO EXISTE COM A PROPOSTA EM ABERTO. Numa aceita seria ameaca sem
                    sentido; numa vencida, a contagem de algo que ja acabou. Conta ate o FIM do dia
                    da validade: quem le "vale ate 15/08" espera poder aceitar no dia 15.
                --}}
                @if ($p->validade && $emAberto)
                    <div class="no-print mt-9" data-revelar style="--atraso: 4"
                         x-data="{
                            alvo: @js($p->venceEm()->getTimestampMs()),
                            d: '--', h: '--', m: '--', s: '--',
                            tick() {
                                const t = Math.floor(Math.max(0, this.alvo - Date.now()) / 1000);
                                const dois = (n) => String(n).padStart(2, '0');
                                this.d = dois(Math.floor(t / 86400));
                                this.h = dois(Math.floor((t % 86400) / 3600));
                                this.m = dois(Math.floor((t % 3600) / 60));
                                this.s = dois(t % 60);
                            },
                            init() { this.tick(); setInterval(() => this.tick(), 1000); }
                         }">
                        <p class="rotulo text-[#8a8071]">Esta condição vale até {{ $p->validade->format('d/m/Y') }}</p>

                        <div class="mt-3 flex gap-2.5">
                            @foreach ([['d', 'dias'], ['h', 'horas'], ['m', 'min'], ['s', 'seg']] as [$chave, $legenda])
                                <div class="cartao min-w-[4.25rem] px-3 py-2.5 text-center">
                                    <span class="block text-2xl font-bold tabular-nums text-[#f8c830]" x-text="{{ $chave }}">--</span>
                                    <span class="mt-0.5 block text-[10px] uppercase tracking-widest text-[#8a8071]">{{ $legenda }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($selos->isNotEmpty())
                    <ul class="mt-8 flex flex-wrap gap-2" data-revelar style="--atraso: 5">
                        @foreach ($selos as $selo)
                            <li class="inline-flex items-center gap-1.5 rounded-full bg-[#e8a924]/10 px-3 py-1.5 text-[13px] font-medium text-[#f0d9a0] ring-1 ring-[#e8a924]/25">
                                <svg class="size-3.5 shrink-0 text-[#f8c830]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 0 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/>
                                </svg>
                                {{ $selo }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{--
                A MARCA COMO IMAGEM DA CAPA, e nao uma foto de banco.

                A referencia que ele mandou usa uma ilustracao de IA no lado direito. Nos nao temos
                banco de imagem, e imagem generica de tecnologia envelhece em seis meses. O simbolo
                da propria marca, grande e com a luz por tras, diz de quem e a proposta sem fingir
                ser outra empresa.
            --}}
            <div class="no-print relative hidden lg:block" aria-hidden="true" data-revelar style="--atraso: 3">
                <div class="relative mx-auto grid aspect-square max-w-sm place-items-center">
                    <div class="absolute inset-6 rounded-full"
                         style="background: radial-gradient(closest-side, rgb(232 169 36 / 16%), transparent 72%)"></div>
                    <div class="absolute inset-0 rounded-full border border-[#e8a924]/12"></div>
                    <div class="absolute inset-10 rounded-full border border-[#e8a924]/8"></div>
                    <img src="{{ asset('marca/virtus-icone-512.png') }}" alt="" class="relative w-1/2 opacity-95">
                </div>
            </div>
        </div>

        <div class="fio no-print mx-auto max-w-6xl"></div>
    </header>

    <div class="mx-auto max-w-6xl px-5 pb-28 sm:px-8">

        @foreach ($blocos as $indice => $bloco)
            @php $tipo = $bloco['type'] ?? 'texto'; $d = $bloco['data'] ?? []; @endphp

            {{-- O par sai da fila: ele e desenhado junto, mais abaixo, na posicao do primeiro. --}}
            @if (in_array($tipo, ['diagnostico', 'solucao'], true))
                @if ($indice === $posicaoDoPar)
                    @php
                        $listaDores = $itensDoBloco($dores);
                        $listaSolucoes = $itensDoBloco($solucoes);
                    @endphp

                    @if ($listaDores->isNotEmpty() || $listaSolucoes->isNotEmpty())
                        <section class="pt-20 sm:pt-28">
                            <div class="text-center" data-revelar>
                                <p class="rotulo text-[#8a8071]">Diagnóstico e plano de ação</p>
                                <h2 class="display mt-4 text-[clamp(2rem,5vw,3.25rem)]">
                                    O que trava hoje,<br>
                                    <span class="gradiente">e como resolvemos</span>
                                </h2>
                                <div class="mx-auto mt-5 h-[3px] w-16 rounded-full bg-gradient-to-r from-[#e09028] to-[#f8c830]"></div>
                            </div>

                            {{-- Lado a lado no desktop: a dor 01 fica na mesma linha do olho que a
                                 solucao 01. Empilhado no celular, onde nao ha duas colunas. --}}
                            <div class="mt-14 grid gap-6 lg:grid-cols-2 lg:gap-8">
                                @foreach ([['dor', $dores, $listaDores, 'Dificuldades'], ['solucao', $solucoes, $listaSolucoes, 'Soluções']] as [$lado, $blocoDoLado, $lista, $legendaPadrao])
                                    @if ($lista->isNotEmpty())
                                        <div>
                                            <div class="mb-5 flex items-baseline gap-3" data-revelar>
                                                <h3 class="text-lg font-semibold">
                                                    {{ $blocoDoLado['data']['titulo'] ?? $legendaPadrao }}
                                                </h3>
                                                @if (filled($blocoDoLado['data']['chamada'] ?? null))
                                                    <p class="text-[13px] text-[#8a8071]">{{ $blocoDoLado['data']['chamada'] }}</p>
                                                @endif
                                            </div>

                                            <ol class="flex flex-col gap-3">
                                                @foreach ($lista as $i => $item)
                                                    <li class="cartao flex gap-4 p-4 sm:p-5" data-revelar style="--atraso: {{ $i + 1 }}">
                                                        <span class="selo-numero {{ $lado === 'dor' ? 'selo-dor' : 'selo-solucao' }}">
                                                            {{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}
                                                        </span>

                                                        <div class="min-w-0">
                                                            <p class="text-[13px] font-bold uppercase tracking-wide">{{ $item['titulo'] }}</p>

                                                            @if (filled($item['corpo'] ?? null))
                                                                <p class="mt-2 whitespace-pre-line text-[14px] leading-[1.75] text-[#b9af9e] text-pretty">{{ $item['corpo'] }}</p>
                                                            @endif
                                                        </div>
                                                    </li>
                                                @endforeach
                                            </ol>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endif

                @continue
            @endif

            @switch ($tipo)

                {{-- ............................................... texto corrido --}}
                @case ('texto')
                    @php $corpo = trim((string) ($d['corpo'] ?? '')); @endphp

                    @if ($corpo !== '' || filled($d['titulo'] ?? null))
                        <section class="pt-20 sm:pt-28">
                            <div class="mx-auto max-w-3xl">
                                @if (filled($d['titulo'] ?? null))
                                    <h2 class="display text-[clamp(1.65rem,4vw,2.5rem)]" data-revelar>{{ $d['titulo'] }}</h2>
                                @endif

                                {{-- Texto longo alinhado a esquerda e em coluna medida, e nao
                                     centralizado como na referencia: paragrafo centralizado obriga
                                     o olho a procurar o inicio de cada linha. --}}
                                <div class="mt-5 max-w-[65ch] whitespace-pre-line text-[16px] leading-[1.8] text-[#b9af9e] text-pretty"
                                     data-revelar style="--atraso: 1">{{ $corpo }}</div>
                            </div>
                        </section>
                    @endif
                    @break

                {{-- .................................................. cronograma --}}
                @case ('cronograma')
                    @php
                        $etapas = collect($d['etapas'] ?? [])->filter(fn ($e) => filled($e['periodo'] ?? null));
                        $passo = 0;
                    @endphp

                    @if ($etapas->isNotEmpty())
                        <section class="pt-20 sm:pt-28">
                            <div class="text-center" data-revelar>
                                <p class="rotulo text-[#8a8071]">Cronograma</p>
                                <h2 class="display mt-4 text-[clamp(2rem,5vw,3.25rem)]">
                                    <span class="gradiente">{{ $d['titulo'] ?? 'Etapas do projeto' }}</span>
                                </h2>
                                <div class="mx-auto mt-5 h-[3px] w-16 rounded-full bg-gradient-to-r from-[#e09028] to-[#f8c830]"></div>
                            </div>

                            <div class="mx-auto mt-14 max-w-3xl">
                                @foreach ($etapas as $indiceEtapa => $etapa)
                                    <div class="grid gap-x-8 gap-y-4 pb-10 sm:grid-cols-[9rem_1fr]" data-revelar>
                                        <div class="sm:pt-1">
                                            <p class="text-base font-bold text-[#f8c830]">{{ $etapa['periodo'] }}</p>

                                            @if (filled($etapa['foco'] ?? null))
                                                <p class="mt-1 text-[13px] leading-snug text-[#8a8071]">{{ $etapa['foco'] }}</p>
                                            @endif
                                        </div>

                                        {{-- A espinha em ambar liga as etapas: sem ela, cada etapa
                                             pareceria uma lista solta em vez de um caminho. E a
                                             numeracao CORRE por todas — o cliente le um projeto. --}}
                                        <ul class="relative border-l border-[#e8a924]/20 pl-6">
                                            @foreach (collect($etapa['itens'] ?? [])->filter() as $item)
                                                @php $passo++; @endphp
                                                <li class="relative pb-3.5 last:pb-0">
                                                    <span class="absolute -left-[1.6rem] top-1.5 size-2 rounded-full bg-[#e8a924] ring-4 ring-[#0b0a09]"></span>
                                                    <span class="font-mono text-[11px] tabular-nums text-[#6b6459]">{{ str_pad((string) $passo, 2, '0', STR_PAD_LEFT) }}</span>
                                                    <span class="ml-2 text-[15px] leading-[1.7] text-[#b9af9e] text-pretty">{{ is_array($item) ? ($item['item'] ?? '') : $item }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>

                                    @unless ($loop->last)
                                        <div class="fio mb-10"></div>
                                    @endunless
                                @endforeach
                            </div>
                        </section>
                    @endif
                    @break

                {{-- ................................................. quem assina --}}
                @case ('assinante')
                    @if (filled($d['nome'] ?? null))
                        @php
                            $foto = $d['foto'] ?? null;
                            $foto = is_array($foto) ? ($foto[0] ?? null) : $foto;
                            $numeros = collect($d['numeros'] ?? [])->filter(fn ($n) => filled($n['valor'] ?? null));
                        @endphp

                        <section class="pt-20 sm:pt-28">
                            <div class="cartao relative overflow-hidden p-7 sm:p-10" data-revelar>
                                <div class="no-print pointer-events-none absolute -right-24 -top-24 size-72 rounded-full"
                                     style="background: radial-gradient(closest-side, rgb(232 169 36 / 14%), transparent 70%)" aria-hidden="true"></div>

                                <p class="rotulo text-[#8a8071]">Quem assina</p>

                                <div class="mt-6 flex flex-col gap-6 sm:flex-row sm:items-start sm:gap-8">
                                    @if ($foto)
                                        {{-- O anel em ambar em volta da foto e o mesmo gesto do
                                             logotipo: a marca encostando na pessoa. --}}
                                        <div class="relative size-28 shrink-0 rounded-full p-[2px]"
                                             style="background: linear-gradient(140deg, var(--ambar-claro), var(--rust))">
                                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($foto) }}"
                                                 alt="{{ $d['nome'] }}"
                                                 class="size-full rounded-full object-cover">
                                        </div>
                                    @endif

                                    <div class="min-w-0">
                                        <p class="text-2xl font-bold tracking-tight">{{ $d['nome'] }}</p>

                                        @if (filled($d['cargo'] ?? null))
                                            <p class="rotulo mt-1.5 text-[#f8c830]">{{ $d['cargo'] }}</p>
                                        @endif

                                        @if (filled($d['texto'] ?? null))
                                            <p class="mt-5 max-w-[62ch] whitespace-pre-line text-[15px] leading-[1.8] text-[#b9af9e] text-pretty">{{ $d['texto'] }}</p>
                                        @endif
                                    </div>
                                </div>

                                @if ($numeros->isNotEmpty())
                                    <dl class="mt-9 flex flex-wrap gap-x-12 gap-y-6 border-t border-white/5 pt-7">
                                        @foreach ($numeros as $i => $numero)
                                            <div class="min-w-[8rem]" data-revelar style="--atraso: {{ $i + 1 }}">
                                                <dt class="display gradiente text-4xl">{{ $numero['valor'] }}</dt>
                                                <dd class="mt-1.5 text-[13px] leading-snug text-[#8a8071]">{{ $numero['rotulo'] ?? '' }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        </section>
                    @endif
                    @break

            @endswitch
        @endforeach

        {{-- ======================================================= investimento --}}
        @if ($itens->isNotEmpty())
            <section class="pt-20 sm:pt-28">
                <div class="text-center" data-revelar>
                    <p class="rotulo text-[#8a8071]">Investimento</p>
                    <h2 class="display mt-4 text-[clamp(2rem,5vw,3.25rem)]">
                        O que está incluído,<br>
                        <span class="gradiente">e quanto é</span>
                    </h2>
                    <div class="mx-auto mt-5 h-[3px] w-16 rounded-full bg-gradient-to-r from-[#e09028] to-[#f8c830]"></div>
                </div>

                <div class="mx-auto mt-14 max-w-3xl">
                    @foreach ([['unicos', $unicos, 'Uma vez'], ['recorrente', $recorrente, 'Mensal']] as [$chave, $lista, $legenda])
                        @if ($lista->isNotEmpty())
                            <div class="mb-8" data-revelar>
                                <p class="rotulo text-[#6b6459]">{{ $legenda }}</p>

                                <table class="mt-3 w-full text-[15px]">
                                    <tbody>
                                        @foreach ($lista as $item)
                                            <tr class="border-b border-white/5">
                                                <td class="py-3.5 pr-4 align-top">
                                                    {{ $item->descricao }}
                                                    @if ((float) $item->quantidade != 1)
                                                        <span class="text-[#8a8071]">
                                                            &times; {{ rtrim(rtrim(number_format((float) $item->quantidade, 2, ',', '.'), '0'), ',') }}
                                                        </span>
                                                    @endif
                                                </td>
                                                {{-- tabular-nums: numero alinhado coluna a coluna,
                                                     senao a tabela de preco parece torta. --}}
                                                <td class="whitespace-nowrap py-3.5 text-right align-top font-semibold tabular-nums">
                                                    R$ {{ number_format($item->total(), 2, ',', '.') }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                {{--
                                    O DESCONTO FICA COLADO NO GRUPO DE ONDE ELE SAI, com o nome
                                    dizendo isso. Depois dos itens mensais, o cliente leria que o
                                    desconto e da mensalidade — e o calculo desconta da implantacao.
                                    Num documento de preco essa e a pior ambiguidade possivel: a que
                                    so se descobre na primeira fatura.
                                --}}
                                @if ($chave === 'unicos' && (float) $p->desconto > 0)
                                    <div class="flex justify-between border-b border-white/5 py-3.5 text-[15px] text-emerald-300">
                                        <span>Desconto na implantação</span>
                                        <span class="font-semibold tabular-nums">− R$ {{ number_format((float) $p->desconto, 2, ',', '.') }}</span>
                                    </div>
                                @endif
                            </div>
                        @endif
                    @endforeach

                    {{--
                        DOIS TOTAIS, e nao um. Somar implantacao com mensalidade daria um numero que
                        nao existe na vida real.

                        O VALOR CHEIO RISCADO so aparece quando e MAIOR que o proposto — a guarda
                        vive no modelo, porque ancora menor seria erro de digitacao virando anuncio
                        de aumento.
                    --}}
                    <div class="cartao mt-10 p-7 sm:p-9" data-revelar>
                        <div class="grid gap-8 sm:grid-cols-2">
                            @if ((float) $p->total_unico > 0)
                                <div>
                                    <p class="rotulo text-[#8a8071]">Implantação</p>

                                    @if ($ancoraUnica)
                                        <p class="mt-2 text-sm tabular-nums text-[#6b6459] line-through">
                                            R$ {{ number_format($ancoraUnica['cheio'], 2, ',', '.') }}
                                        </p>
                                    @endif

                                    <p class="display mt-1 text-[clamp(2rem,4vw,2.75rem)] tabular-nums">
                                        R$ {{ number_format((float) $p->total_unico, 2, ',', '.') }}
                                    </p>
                                </div>
                            @endif

                            @if ((float) $p->total_recorrente > 0)
                                <div>
                                    <p class="rotulo text-[#8a8071]">Mensalidade</p>

                                    @if ($ancoraMensal)
                                        <p class="mt-2 text-sm tabular-nums text-[#6b6459] line-through">
                                            R$ {{ number_format($ancoraMensal['cheio'], 2, ',', '.') }}
                                        </p>
                                    @endif

                                    <p class="display mt-1 text-[clamp(2rem,4vw,2.75rem)] tabular-nums">
                                        R$ {{ number_format((float) $p->total_recorrente, 2, ',', '.') }}
                                        <span class="text-base font-medium text-[#8a8071]">/mês</span>
                                    </p>
                                </div>
                            @endif
                        </div>

                        @if ($p->vencimento_dia || $p->primeiro_pagamento)
                            <p class="mt-7 border-t border-white/5 pt-5 text-[13px] text-[#b9af9e]">
                                @if ($p->vencimento_dia)
                                    Vencimento todo dia <strong class="font-semibold text-[#f6f2ea]">{{ $p->vencimento_dia }}</strong>.
                                @endif

                                @if ($p->primeiro_pagamento)
                                    Primeiro pagamento em
                                    <strong class="font-semibold text-[#f6f2ea]">{{ $p->primeiro_pagamento->format('d/m/Y') }}</strong>.
                                @endif
                            </p>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        {{-- ============================================================ o aceite --}}
        <section class="pt-20 sm:pt-28">
            <div class="mx-auto max-w-3xl">
                @if ($p->aceita_em)
                    {{-- O RECIBO. Depois do aceite a pagina deixa de vender e passa a comprovar. --}}
                    <div class="cartao border-emerald-400/25 bg-emerald-400/5 p-7 sm:p-9" data-revelar>
                        <p class="text-xl font-bold text-emerald-200">Proposta aceita</p>
                        <p class="mt-3 text-sm text-emerald-100/80">
                            Confirmada por <strong class="font-semibold">{{ $p->aceita_por }}</strong>
                            em {{ $p->aceita_em->format('d/m/Y \à\s H:i') }}.
                        </p>
                        <p class="mt-3 text-sm text-emerald-100/80">
                            Recebemos o aceite e vamos falar com você para começar. Obrigado pela confiança.
                        </p>
                    </div>
                @elseif ($p->recusada_em)
                    <div class="cartao p-7 sm:p-9" data-revelar>
                        <p class="text-lg font-bold">Proposta recusada</p>
                        <p class="mt-3 text-sm text-[#b9af9e]">
                            Registramos sua resposta em {{ $p->recusada_em->format('d/m/Y') }}. Se algo mudar, é só chamar.
                        </p>
                    </div>
                @elseif ($p->vencida())
                    {{-- Vencida NAO oferece o botao: aceitar preco de meses atras cria problema pior
                         que o de renegociar. --}}
                    <div class="cartao p-7 sm:p-9" data-revelar>
                        <p class="text-lg font-bold">Esta proposta venceu em {{ $p->validade->format('d/m/Y') }}</p>
                        <p class="mt-3 text-sm text-[#b9af9e]">
                            Os valores precisam ser confirmados antes de seguir. Fale com
                            {{ $p->autor?->name ?? 'a gente' }} e emitimos uma atualizada no mesmo dia.
                        </p>
                    </div>
                @else
                    <div class="cartao no-print relative overflow-hidden p-7 sm:p-10" data-revelar>
                        <div class="no-print pointer-events-none absolute -left-20 -top-24 size-80 rounded-full"
                             style="background: radial-gradient(closest-side, rgb(232 169 36 / 12%), transparent 70%)" aria-hidden="true"></div>

                        <h2 class="display text-[clamp(1.5rem,3vw,2rem)]">Aceitar esta proposta</h2>
                        <p class="mt-3 max-w-[58ch] text-sm leading-relaxed text-[#b9af9e]">
                            Escreva seu nome e confirme. Guardamos a data e a hora do aceite, e você continua
                            com esta página no mesmo link.
                        </p>

                        <form wire:submit="aceitar" class="mt-7 flex flex-col gap-3 sm:flex-row">
                            <div class="flex-1">
                                <label for="nome-aceite" class="sr-only">Seu nome completo</label>
                                <input id="nome-aceite" type="text" wire:model="nomeDeQuemAceita"
                                       placeholder="Seu nome completo"
                                       class="w-full rounded-xl border border-white/10 bg-black/30 px-4 py-3.5 text-[15px] text-[#f6f2ea] placeholder:text-[#6b6459] outline-none transition focus:border-[#e8a924]/60 focus:ring-2 focus:ring-[#e8a924]/25">
                                @error('nomeDeQuemAceita')
                                    <p class="mt-2 text-xs text-rose-300" role="alert">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- O botao usa o ambar da marca com texto GRAFITE, e nao branco: ambar
                                 com branco em cima da 2:1 de contraste; com grafite passa de 7:1 e
                                 continua sendo a cor da marca. E a pressao encolhe 3%, que e o
                                 unico jeito de um botao parecer um botao de verdade. --}}
                            <button type="submit" wire:loading.attr="disabled"
                                    class="rounded-xl bg-gradient-to-r from-[#f8c830] to-[#e09028] px-7 py-3.5 text-[15px] font-bold text-[#14100b] shadow-lg shadow-[#e8a924]/20 transition duration-200 hover:brightness-110 active:scale-[0.97] disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f8c830]">
                                <span wire:loading.remove wire:target="aceitar">Aceitar proposta</span>
                                <span wire:loading wire:target="aceitar">Registrando&hellip;</span>
                            </button>
                        </form>

                        {{-- A recusa fica discreta, e nao lado a lado com o aceite: dar o mesmo peso
                             visual as duas opcoes e pedir para a pessoa considerar as duas. --}}
                        <div class="mt-6 border-t border-white/5 pt-5 text-sm">
                            @if (! $recusando)
                                <button type="button" wire:click="$set('recusando', true)"
                                        class="text-[#8a8071] underline decoration-white/15 underline-offset-4 transition hover:text-[#f6f2ea]">
                                    Não é o que eu esperava
                                </button>
                            @else
                                <form wire:submit="recusar" class="space-y-3">
                                    <label for="motivo" class="block text-[#b9af9e]">O que faltou? Isso ajuda a ajustar.</label>
                                    <textarea id="motivo" wire:model="motivoDaRecusa" rows="3"
                                              class="w-full rounded-xl border border-white/10 bg-black/30 px-3.5 py-2.5 text-sm text-[#f6f2ea] outline-none focus:border-white/25"></textarea>
                                    <div class="flex gap-2">
                                        <button type="submit" class="rounded-lg bg-white/10 px-4 py-2 text-sm font-medium transition hover:bg-white/15">Enviar</button>
                                        <button type="button" wire:click="$set('recusando', false)" class="px-3 py-2 text-sm text-[#8a8071]">Cancelar</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </section>

        {{-- O rodape identifica quem propoe: documento de preco sem CNPJ de quem assina e
             documento que o financeiro do cliente devolve. --}}
        <footer class="mt-24 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-white/5 pt-7 text-xs text-[#6b6459]">
            <img src="{{ asset('marca/virtus-icone-192.png') }}" alt="" class="h-4 w-4">

            <span>{{ $conta?->razao_social ?: ($conta?->nome ?: config('app.name')) }}</span>

            @if ($conta?->documento)
                <span>CNPJ {{ \App\Support\Documento::formatar($conta->documento) }}</span>
            @endif

            @if ($conta?->cidade)
                <span>{{ $conta->cidade }}{{ $conta->uf ? '/'.$conta->uf : '' }}</span>
            @endif

            <span class="ml-auto font-mono">{{ $p->numero }}</span>
        </footer>
    </div>

    {{--
        A REVELACAO AO ROLAR.

        IntersectionObserver e nao evento de scroll: o navegador avisa quando o elemento entra, em
        vez de nos perguntarmos a cada pixel rolado. Uma vez revelado, para de observar — animar de
        novo ao subir a pagina faria o documento piscar na segunda leitura.

        E LIGA DE NOVO DEPOIS DE CADA ATUALIZACAO DO LIVEWIRE. Sem isso, o recibo que aparece no
        lugar do formulario ao aceitar nasceria com opacity 0 e FICARIA INVISIVEL: o cliente
        clicaria em "Aceitar", o bloco trocaria, e a tela pareceria vazia — no exato momento em que
        ele mais precisa de confirmacao.

        A guarda de reduced-motion esta aqui TAMBEM, e nao so no CSS: sem ela o observador rodaria
        de qualquer jeito, gastando trabalho para nada.
    --}}
    <script>
        (() => {
            const parado = window.matchMedia('(prefers-reduced-motion: reduce)').matches
                || ! ('IntersectionObserver' in window);

            const olho = parado ? null : new IntersectionObserver((entradas) => {
                entradas.forEach((entrada) => {
                    if (entrada.isIntersecting) {
                        entrada.target.classList.add('dentro');
                        olho.unobserve(entrada.target);
                    }
                });
            }, { rootMargin: '0px 0px -12% 0px', threshold: 0.05 });

            const ligar = () => {
                document.querySelectorAll('[data-revelar]:not(.dentro)').forEach((el) => {
                    parado ? el.classList.add('dentro') : olho.observe(el);
                });
            };

            ligar();

            let ligado = false;
            const pendurar = () => {
                if (ligado || ! window.Livewire) {
                    return;
                }
                ligado = true;
                window.Livewire.hook('morphed', ligar);
            };

            // O Livewire pode ter iniciado antes ou depois deste trecho: cobre os dois casos.
            pendurar();
            document.addEventListener('livewire:init', pendurar);
        })();
    </script>
</div>
