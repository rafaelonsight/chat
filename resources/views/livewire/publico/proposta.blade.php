{{--
    A PROPOSTA COMO O CLIENTE VE.

    O que faz esta pagina parecer premium nao e efeito: e espaco em branco, uma cor de destaque
    so, e o nome DELE no topo em vez de "Proposta Comercial 001". Gradiente e sombra fazem
    parecer modelo de apresentacao; respiro e tipografia fazem parecer consultoria.

    E ela e a MESMA folha que sai na impressora: a regra de impressao no fim do arquivo tira a
    barra, os botoes e as cores de fundo. Por isso nao existe biblioteca de PDF no projeto — o
    papel sai identico ao que o cliente leu na tela, sem um segundo desenho para manter.
--}}
<div class="min-h-dvh bg-[#faf9f7] text-[#1c1917] antialiased">

    {{-- barra: o numero e o estado. Fica fora do papel na impressao. --}}
    <div class="no-print sticky top-0 z-20 border-b border-black/5 bg-[#faf9f7]/90 backdrop-blur">
        <div class="mx-auto flex max-w-3xl items-center gap-3 px-6 py-3">
            <span class="font-mono text-xs tracking-wide text-stone-500">{{ $p->numero }}</span>

            @php
                $rotulo = match (true) {
                    $p->aceita_em !== null   => ['Aceita', 'bg-emerald-100 text-emerald-800'],
                    $p->recusada_em !== null => ['Recusada', 'bg-rose-100 text-rose-800'],
                    $p->vencida()            => ['Vencida', 'bg-stone-200 text-stone-700'],
                    default                  => ['Em análise', 'bg-amber-100 text-amber-900'],
                };
            @endphp

            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $rotulo[1] }}">{{ $rotulo[0] }}</span>

            <button type="button" onclick="window.print()"
                    class="ml-auto rounded-full border border-stone-300 px-3 py-1 text-xs font-medium text-stone-600 transition hover:border-stone-400 hover:text-stone-900">
                Salvar em PDF
            </button>
        </div>
    </div>

    <div class="mx-auto max-w-3xl px-6 pb-24 pt-14 sm:pt-20">

        {{-- ---------------------------------------------------------------- capa --}}
        <header>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">Proposta</p>

            {{-- text-wrap:balance para o nome do cliente nao quebrar deixando uma palavra sozinha --}}
            <h1 class="mt-3 text-4xl font-semibold leading-[1.1] text-balance sm:text-5xl">
                {{ $p->cliente_nome }}
            </h1>

            <p class="mt-4 max-w-xl text-lg leading-relaxed text-stone-600 text-pretty">{{ $p->titulo }}</p>

            <dl class="mt-8 flex flex-wrap gap-x-10 gap-y-3 border-t border-stone-200 pt-6 text-sm">
                <div>
                    <dt class="text-stone-500">Emitida</dt>
                    <dd class="font-medium">{{ $p->created_at?->format('d/m/Y') }}</dd>
                </div>

                @if ($p->validade)
                    <div>
                        <dt class="text-stone-500">Válida até</dt>
                        <dd class="font-medium {{ $p->vencida() ? 'text-rose-700' : '' }}">
                            {{ $p->validade->format('d/m/Y') }}
                        </dd>
                    </div>
                @endif

                @if ($p->autor)
                    <div>
                        <dt class="text-stone-500">Responsável</dt>
                        <dd class="font-medium">{{ $p->autor->name }}</dd>
                    </div>
                @endif
            </dl>
        </header>

        {{-- ------------------------------------------------------------- conteudo --}}
        @foreach (($p->blocos ?? []) as $bloco)
            @php $texto = trim((string) ($bloco['corpo'] ?? '')); @endphp

            @if ($texto !== '' || ! empty($bloco['titulo']))
                <section class="mt-14">
                    @if (! empty($bloco['titulo']))
                        <h2 class="text-xl font-semibold tracking-tight">{{ $bloco['titulo'] }}</h2>
                    @endif

                    {{-- whitespace-pre-line: o paragrafo digitado com enter vira paragrafo lido
                         com enter, sem exigir que ele escreva HTML. --}}
                    <div class="mt-3 whitespace-pre-line text-[15px] leading-[1.75] text-stone-700 text-pretty">{{ $texto }}</div>
                </section>
            @endif
        @endforeach

        {{-- ----------------------------------------------------------- investimento --}}
        @if ($itens->isNotEmpty())
            <section class="mt-16">
                <h2 class="text-xl font-semibold tracking-tight">Investimento</h2>

                @foreach ([['unicos', $unicos, 'Uma vez'], ['recorrente', $recorrente, 'Mensal']] as [$chave, $lista, $legenda])
                    @if ($lista->isNotEmpty())
                        <div class="mt-6">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-400">{{ $legenda }}</p>

                            <table class="mt-2 w-full text-[15px]">
                                <tbody>
                                    @foreach ($lista as $item)
                                        <tr class="border-b border-stone-200/70">
                                            <td class="py-3 pr-4 align-top">
                                                {{ $item->descricao }}
                                                @if ((float) $item->quantidade != 1)
                                                    <span class="text-stone-500">
                                                        &times; {{ rtrim(rtrim(number_format((float) $item->quantidade, 2, ',', '.'), '0'), ',') }}
                                                    </span>
                                                @endif
                                            </td>
                                            {{-- tabular-nums: numero alinhado coluna a coluna, senao
                                                 a tabela de preco parece torta. --}}
                                            <td class="whitespace-nowrap py-3 text-right align-top font-medium tabular-nums">
                                                R$ {{ number_format($item->total(), 2, ',', '.') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                            {{--
                                O DESCONTO FICA COLADO NO GRUPO DE ONDE ELE SAI, e com o nome
                                dizendo isso.

                                Antes ele aparecia depois dos itens mensais — e o cliente leria
                                que o desconto era da mensalidade, quando o calculo desconta da
                                implantacao. Num documento de preco, essa e a pior ambiguidade
                                possivel: a que o cliente so descobre na primeira fatura.
                            --}}
                            @if ($chave === 'unicos' && (float) $p->desconto > 0)
                                <div class="flex justify-between border-b border-stone-200/70 py-3 text-[15px] text-emerald-700">
                                    <span>Desconto na implantação</span>
                                    <span class="font-medium tabular-nums">− R$ {{ number_format((float) $p->desconto, 2, ',', '.') }}</span>
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach

                {{--
                    DOIS TOTAIS, e nao um.
                    Somar implantacao com mensalidade daria um numero que nao existe na vida
                    real. Separados, o cliente entende o que paga uma vez e o que paga sempre —
                    e a duvida que sobra e "quando comecamos", nao "quanto e mesmo".
                --}}
                <div class="mt-8 rounded-2xl bg-white p-6 ring-1 ring-stone-200/80">
                    <div class="flex flex-wrap items-end justify-between gap-6">
                        @if ((float) $p->total_unico > 0)
                            <div>
                                <p class="text-xs uppercase tracking-[0.14em] text-stone-500">Implantação</p>
                                <p class="mt-1 text-3xl font-semibold tabular-nums">
                                    R$ {{ number_format((float) $p->total_unico, 2, ',', '.') }}
                                </p>
                            </div>
                        @endif

                        @if ((float) $p->total_recorrente > 0)
                            <div>
                                <p class="text-xs uppercase tracking-[0.14em] text-stone-500">Mensalidade</p>
                                <p class="mt-1 text-3xl font-semibold tabular-nums">
                                    R$ {{ number_format((float) $p->total_recorrente, 2, ',', '.') }}
                                    <span class="text-base font-normal text-stone-500">/mês</span>
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        {{-- ------------------------------------------------------------- o aceite --}}
        <section class="mt-16">
            @if ($p->aceita_em)
                {{-- O RECIBO. Depois do aceite a pagina deixa de vender e passa a comprovar:
                     quem confirmou, quando. E o que o cliente reabre para conferir. --}}
                <div class="rounded-2xl bg-emerald-50 p-6 ring-1 ring-emerald-200">
                    <p class="text-lg font-semibold text-emerald-900">Proposta aceita</p>
                    <p class="mt-2 text-sm text-emerald-800">
                        Confirmada por <strong>{{ $p->aceita_por }}</strong>
                        em {{ $p->aceita_em->format('d/m/Y \à\s H:i') }}.
                    </p>
                    <p class="mt-3 text-sm text-emerald-800">
                        Recebemos o aceite e vamos falar com você para começar. Obrigado pela confiança.
                    </p>
                </div>
            @elseif ($p->recusada_em)
                <div class="rounded-2xl bg-stone-100 p-6 ring-1 ring-stone-200">
                    <p class="font-semibold">Proposta recusada</p>
                    <p class="mt-2 text-sm text-stone-600">
                        Registramos sua resposta em {{ $p->recusada_em->format('d/m/Y') }}. Se algo mudar, é só chamar.
                    </p>
                </div>
            @elseif ($p->vencida())
                {{-- Vencida NAO oferece o botao: aceitar preco de meses atras cria um problema
                     pior do que o de renegociar. --}}
                <div class="rounded-2xl bg-stone-100 p-6 ring-1 ring-stone-200">
                    <p class="font-semibold">Esta proposta venceu em {{ $p->validade->format('d/m/Y') }}</p>
                    <p class="mt-2 text-sm text-stone-600">
                        Os valores precisam ser confirmados antes de seguir. Fale com
                        {{ $p->autor?->name ?? 'a gente' }} e emitimos uma atualizada no mesmo dia.
                    </p>
                </div>
            @else
                <div class="no-print rounded-2xl bg-white p-6 ring-1 ring-stone-200/80 sm:p-8">
                    <h2 class="text-xl font-semibold tracking-tight">Aceitar esta proposta</h2>
                    <p class="mt-2 text-sm leading-relaxed text-stone-600">
                        Escreva seu nome e confirme. Guardamos a data e a hora do aceite, e você recebe
                        uma cópia desta página pelo mesmo link.
                    </p>

                    <form wire:submit="aceitar" class="mt-5 flex flex-col gap-3 sm:flex-row">
                        <div class="flex-1">
                            <input type="text" wire:model="nomeDeQuemAceita"
                                   placeholder="Seu nome completo"
                                   class="w-full rounded-xl border border-stone-300 px-4 py-3 text-[15px] outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                            @error('nomeDeQuemAceita')
                                <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit" wire:loading.attr="disabled"
                                class="rounded-xl bg-[#1c1917] px-6 py-3 text-[15px] font-semibold text-white transition hover:bg-black disabled:opacity-60">
                            <span wire:loading.remove wire:target="aceitar">Aceitar proposta</span>
                            <span wire:loading wire:target="aceitar">Registrando&hellip;</span>
                        </button>
                    </form>

                    {{-- A recusa fica discreta, e nao lado a lado com o aceite: dar o mesmo peso
                         visual as duas opcoes e pedir para a pessoa considerar as duas. --}}
                    <div class="mt-5 border-t border-stone-200 pt-4 text-sm">
                        @if (! $recusando)
                            <button type="button" wire:click="$set('recusando', true)"
                                    class="text-stone-500 underline decoration-stone-300 underline-offset-4 hover:text-stone-800">
                                Não é o que eu esperava
                            </button>
                        @else
                            <form wire:submit="recusar" class="space-y-3">
                                <p class="text-stone-600">O que faltou? Isso ajuda a ajustar.</p>
                                <textarea wire:model="motivoDaRecusa" rows="3"
                                          class="w-full rounded-xl border border-stone-300 px-3 py-2 text-sm outline-none focus:border-stone-400"></textarea>
                                <div class="flex gap-2">
                                    <button type="submit" class="rounded-lg bg-stone-800 px-4 py-2 text-sm font-medium text-white">Enviar</button>
                                    <button type="button" wire:click="$set('recusando', false)" class="px-3 py-2 text-sm text-stone-500">Cancelar</button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </section>

        <footer class="mt-16 border-t border-stone-200 pt-6 text-xs text-stone-400">
            {{ config('app.name') }} &middot; {{ $p->numero }}
        </footer>
    </div>

    {{--
        A MESMA PAGINA, NO PAPEL.

        Tira o que e de tela (barra, botoes, formulario), apaga o fundo para nao gastar tinta, e
        evita corte de secao no meio. E por isso que nao ha biblioteca de PDF: um desenho so para
        manter, e o papel sai identico ao que o cliente leu.
    --}}
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            section, header { break-inside: avoid; }
            @page { margin: 18mm 16mm; }
        }
    </style>
</div>
