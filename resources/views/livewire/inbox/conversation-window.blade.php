<div class="flex flex-1 flex-col overflow-hidden">
    @if ($conversa)
        <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-white/10">
            <div class="flex min-w-0 items-center gap-2">
                {{-- So no celular. La a lista sumiu para a conversa caber, e sem este botao
                     o unico caminho de volta seria recarregar a pagina. No computador as duas
                     colunas convivem e a seta seria ruido. --}}
                <button type="button" x-on:click="$dispatch('voltar-para-lista')"
                        class="-ml-1 shrink-0 rounded-full p-2 text-gray-500 transition hover:bg-gray-100 lg:hidden dark:text-gray-400 dark:hover:bg-white/5"
                        title="Voltar para a lista" aria-label="Voltar para a lista">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                    </svg>
                </button>

                {{-- detalhes do contato: fica antes do nome, como identidade de quem se fala --}}
                <button type="button" wire:click="verDetalhes"
                        class="shrink-0 rounded-full border border-gray-300 p-1.5 text-gray-500 transition hover:bg-gray-50 hover:text-gray-800 dark:border-white/20 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-100"
                        title="Detalhes do contato">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                </button>

                <div class="min-w-0">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="truncate font-semibold text-gray-800 dark:text-gray-100">
                        {{ $conversa->contact->nomeExibicao() }}
                    </span>

                    {{-- Etiqueta COM nome aqui, e nao so a bolinha da lista: no cabecalho
                         e onde se trabalha, e quem esta escrevendo a resposta precisa ver
                         "Financeiro" sem passar o mouse nem abrir o painel. Tres no
                         maximo — o cabecalho tem os botoes de acao a disputar espaco. --}}
                    @if ($conversa->contact->tags->isNotEmpty())
                        <span class="flex shrink-0 items-center gap-1"
                              title="{{ $conversa->contact->tags->pluck('nome')->join(', ') }}">
                            @foreach ($conversa->contact->tags->take(3) as $etiqueta)
                                <span wire:key="cab-et-{{ $etiqueta->id }}"
                                      class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium ring-1 {{ $etiqueta->classes() }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $etiqueta->pontinho() }}"></span>
                                    {{ $etiqueta->nome }}
                                </span>
                            @endforeach

                            @if ($conversa->contact->tags->count() > 3)
                                <span class="text-[10px] text-gray-400">+{{ $conversa->contact->tags->count() - 3 }}</span>
                            @endif
                        </span>
                    @endif
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <span>{{ $conversa->contact->telefone_e164 }}</span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 dark:bg-white/10">
                        {{ $conversa->rotuloStatus() }}
                    </span>
                    @if ($conversa->atendente)
                        <span>&middot; {{ $conversa->atendente->name }}</span>
                    @endif

                    {{-- Por qual numero esta conversa entrou. Aqui aparece SEMPRE, com um canal
                         ou com dez: quem esta respondendo precisa saber de onde fala, e o
                         cabecalho tem espaco de sobra. --}}
                    @if ($conversa->channel)
                        <span class="inline-flex items-center gap-1"
                              title="{{ $conversa->channel->rotulo() }}">
                            <span>&middot;</span>
                            @include('livewire.inbox.partials.icone-plataforma', [
                                'plataforma' => $conversa->channel->plataforma(),
                                'classe'     => 'h-3.5 w-3.5',
                            ])
                            <span class="h-2 w-2 rounded-full {{ $conversa->channel->cor() }}"></span>
                            <span>{{ $conversa->channel->nome }}</span>
                        </span>
                    @endif
                </div>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($equipes->isNotEmpty())
                    <div class="relative" x-data="{ aberto: false }" x-on:click.outside="aberto = false">
                        <button type="button" x-on:click="aberto = !aberto"
                                class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">
                            {{ $conversa->team?->nome ?? 'Sem equipe' }} &#9662;
                        </button>
                        <div x-show="aberto" x-cloak
                             class="absolute right-0 z-20 mt-1 w-56 rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-white/10 dark:bg-gray-800">
                            <p class="px-2 py-1 text-xs font-semibold text-gray-500 dark:text-gray-400">Transferir para</p>
                            @foreach ($equipes as $eq)
                                <button type="button" wire:key="tr-{{ $eq->id }}"
                                        wire:click="transferir({{ $eq->id }})" x-on:click="aberto = false"
                                        @disabled($conversa->team_id === $eq->id)
                                        class="block w-full rounded-lg px-2 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-40 dark:text-gray-200 dark:hover:bg-white/5">
                                    {{ $eq->nome }}
                                    @if ($conversa->team_id === $eq->id) <span class="text-xs opacity-60">(atual)</span> @endif
                                </button>
                            @endforeach

                            {{-- Passar para uma PESSOA e coisa diferente de transferir para
                                 equipe, e o menu diz isso em vez de misturar: equipe devolve a
                                 conversa para a fila, pessoa ja escolhe o dono. --}}
                            @if ($pessoas->isNotEmpty())
                                <p class="mt-1 border-t border-gray-200 px-2 py-1 pt-2 text-xs font-semibold text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    Passar para
                                </p>
                                <div class="max-h-56 overflow-y-auto">
                                    @foreach ($pessoas as $pessoa)
                                        <button type="button" wire:key="ps-{{ $pessoa->id }}"
                                                wire:click="passarPara({{ $pessoa->id }})" x-on:click="aberto = false"
                                                class="block w-full rounded-lg px-2 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5">
                                            {{ $pessoa->name }}
                                            @if ($pessoa->id === auth()->id())
                                                <span class="text-xs opacity-60">(você)</span>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{--
                    Etiquetas DESTE atendimento.

                    Separadas das do contato, que aparecem coladas no nome la em cima. A
                    diferenca esta escrita no menu, porque as duas coisas parecem a mesma para
                    quem chega: a do contato descreve a pessoa e vale para sempre; esta fica
                    presa ao que aconteceu aqui, e e ela que faz o relatorio do mes passado
                    continuar valendo.
                --}}
                @if ($etiquetasDeConversa->isNotEmpty())
                    <div class="relative" x-data="{ aberto: false }" x-on:click.outside="aberto = false">
                        <button type="button" x-on:click="aberto = !aberto"
                                class="flex items-center gap-1 rounded border border-gray-300 px-2 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">
                            @if ($conversa->tags->isNotEmpty())
                                @foreach ($conversa->tags->take(3) as $et)
                                    <span class="h-2 w-2 rounded-full {{ $et->pontinho() }}"></span>
                                @endforeach
                                @if ($conversa->tags->count() > 3)
                                    <span class="opacity-60">+{{ $conversa->tags->count() - 3 }}</span>
                                @endif
                            @else
                                <span class="opacity-70">Etiquetar</span>
                            @endif
                            &#9662;
                        </button>

                        <div x-show="aberto" x-cloak
                             class="absolute right-0 z-20 mt-1 max-h-64 w-64 overflow-y-auto rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-white/10 dark:bg-gray-800">
                            <p class="px-2 py-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                Etiquetas deste atendimento
                            </p>

                            @foreach ($etiquetasDeConversa as $et)
                                @php $posta = $conversa->tags->contains($et->id); @endphp
                                <button type="button" wire:key="etc-{{ $et->id }}"
                                        wire:click="alternarEtiquetaDaConversa({{ $et->id }})"
                                        class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $et->pontinho() }}"></span>
                                    <span class="flex-1 truncate {{ $posta ? 'font-semibold text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-300' }}">
                                        {{ $et->nome }}
                                    </span>
                                    @if ($posta)
                                        <span class="text-emerald-600">&check;</span>
                                    @endif
                                </button>
                            @endforeach

                            <p class="border-t border-gray-100 px-2 pt-1.5 pb-1 text-[10px] leading-snug text-gray-400 dark:border-white/5">
                                Descrevem o atendimento, não a pessoa. As do contato ficam no
                                painel de detalhes.
                            </p>
                        </div>
                    </div>
                @endif

                {{-- "Volto depois". Fecha a conversa junto — marcar como nao lida com ela
                     aberta na frente nao significaria nada. --}}
                <button type="button" wire:click="marcarNaoLida"
                        title="Marcar como não lida e voltar para a lista"
                        class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">
                    Não lida
                </button>

                @if ($conversa->status === \App\Models\Conversation::NOVA)
                    <button type="button" wire:click="assumir"
                            class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">
                        Assumir
                    </button>
                @endif

                @if ($conversa->status === \App\Models\Conversation::ARQUIVADA)
                    @if ($conversa->podeReabrir())
                        <button type="button" wire:click="reabrir"
                                class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
                            Reabrir
                        </button>
                    @else
                        <span class="text-xs text-gray-400" title="Ja existe conversa aberta com este contato">
                            encerrada
                        </span>
                    @endif
                @else
                    <button type="button" wire:click="finalizar"
                            wire:confirm="Finalizar este atendimento?"
                            class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">
                        Finalizar
                    </button>
                @endif
            </div>
        </div>

        {{--
            wire:ignore para o Livewire nao apagar o estado do Alpine a cada atualizacao da
            tela, e wire:key com o id da conversa para que TROCAR de conversa troque o
            elemento — e ai o destroy() sai do canal antigo e o init() entra no novo.
        --}}
        <div wire:key="presenca-{{ $conversa->id }}" wire:ignore
             x-data="presencaDaConversa({{ $conversa->id }}, {{ auth()->id() }})">
            <div x-show="outros.length" x-cloak
                 class="flex items-center gap-2 bg-amber-50 px-4 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                     stroke="currentColor" class="h-4 w-4 shrink-0">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                </svg>
                <span x-text="aviso"></span>
            </div>
        </div>

        @error('apagar')
            <div class="bg-red-50 px-4 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</div>
        @enderror

        @error('transferir')
            <div class="bg-red-50 px-4 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</div>
        @enderror

        @error('reabrir')
            <div class="bg-red-50 px-4 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</div>
        @enderror

        <div class="flex-1 space-y-2 overflow-y-auto bg-slate-50 p-4">
            @if ($mensagens->count() >= $limite)
                <button type="button" wire:click="carregarMais" class="mx-auto block text-xs text-slate-500 underline">
                    carregar mensagens anteriores
                </button>
            @endif

            @foreach ($linha as $item)
                @if ($item instanceof \App\Models\ConversationEvent)
                    <div wire:key="ev-{{ $item->id }}">
                        @include('livewire.inbox.partials.evento', ['ev' => $item])
                    </div>
                    @continue
                @endif

                @php $m = $item; $entrada = $m->entrada(); @endphp
                <div wire:key="msg-{{ $m->id }}" class="group flex items-end gap-1 {{ $entrada ? 'justify-start' : 'justify-end' }}">
                    {{--
                        order-first/order-last poe a seta sempre do lado de DENTRO do balao,
                        seja ele da esquerda ou da direita — assim ela ocupa o vao que ja
                        existe, em vez de empurrar a conversa para o lado.

                        Visivel sempre no celular e so no passar do mouse no computador: no
                        telefone nao existe "passar o mouse", e um botao que so aparece no
                        hover simplesmente nao existiria la.
                    --}}
                    <button type="button" wire:click="responder({{ $m->id }})"
                            title="Responder citando" aria-label="Responder citando esta mensagem"
                            class="shrink-0 rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700
                                   opacity-100 lg:opacity-0 lg:group-hover:opacity-100
                                   {{ $entrada ? 'order-last' : 'order-first' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                             stroke-width="2" stroke="currentColor" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
                    </button>

                    {{-- Apagar: so mensagem NOSSA, e so em canal que consegue apagar de
                         verdade. No canal oficial o botao nem aparece — a Meta nao tem essa
                         operacao, e oferecer para falhar depois e pior que nao oferecer. --}}
                    @if (! $entrada && $podeApagar && ! $m->apagada())
                        <button type="button" wire:click="apagar({{ $m->id }})"
                                wire:confirm="Apagar esta mensagem para todos? O cliente deixa de ver."
                                title="Apagar para todos" aria-label="Apagar esta mensagem para todos"
                                class="shrink-0 order-first rounded-full p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600
                                       opacity-100 lg:opacity-0 lg:group-hover:opacity-100">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                            </svg>
                        </button>
                    @endif

                    {{--
                        Reagir. Seis emojis e mais nada: reacao e gesto de um toque, e uma
                        grade de cinquenta transformaria isso numa escolha — que e exatamente
                        o que a pessoa nao quer fazer no meio de um atendimento.

                        Clicar no mesmo emoji de novo tira a reacao, como no WhatsApp.
                    --}}
                    <div class="relative shrink-0 {{ $entrada ? 'order-last' : 'order-first' }}"
                         x-data="{ aberto: false }" x-on:click.outside="aberto = false">
                        <button type="button" x-on:click="aberto = !aberto"
                                title="Reagir" aria-label="Reagir a esta mensagem"
                                class="rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700
                                       opacity-100 lg:opacity-0 lg:group-hover:opacity-100">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M15.182 15.182a4.5 4.5 0 0 1-6.364 0M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75Zm-.375 0h.008v.015h-.008V9.75Zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75Zm-.375 0h.008v.015h-.008V9.75Z" />
                            </svg>
                        </button>

                        <div x-show="aberto" x-cloak id="onchat-reacoes"
                             class="absolute bottom-full z-30 mb-1 flex gap-0.5 rounded-full border border-slate-200 bg-white p-1 shadow-lg
                                    {{ $entrada ? 'left-0' : 'right-0' }}">
                            @foreach (["\u{1F44D}", "\u{2764}", "\u{1F602}", "\u{1F62E}", "\u{1F622}", "\u{1F64F}"] as $r)
                                <button type="button" wire:key="rc-{{ $m->id }}-{{ $loop->index }}"
                                        wire:click="reagir({{ $m->id }}, @js($r))" x-on:click="aberto = false"
                                        class="rounded-full p-1 text-lg leading-none hover:bg-slate-100">{{ $r }}</button>
                            @endforeach
                        </div>
                    </div>

                    @if ($m->reacao_cliente || $m->reacao_nossa)
                        <span class="order-last flex shrink-0 items-center gap-0.5 self-end rounded-full border border-slate-200 bg-white px-1.5 py-0.5 text-xs shadow-sm">
                            @if ($m->reacao_cliente)
                                <span title="Reação do cliente">{{ $m->reacao_cliente }}</span>
                            @endif
                            @if ($m->reacao_nossa)
                                <span title="Sua reação" class="opacity-70">{{ $m->reacao_nossa }}</span>
                            @endif
                        </span>
                    @endif

                    <div class="max-w-lg rounded-lg px-3 py-2 text-sm {{ $entrada ? 'border border-slate-200 bg-white text-slate-800' : 'bg-emerald-600 text-white' }}">
                        {{-- A mensagem citada, do jeito que o WhatsApp mostra: faixa curta
                             acima, com quem falou e o comeco do que foi dito. --}}
                        @if ($m->respondeA)
                            <div class="mb-1.5 rounded border-l-2 px-2 py-1 text-xs {{ $entrada ? 'border-emerald-600 bg-slate-100 text-slate-600' : 'border-white/70 bg-white/15 text-white/90' }}">
                                <div class="font-semibold">
                                    {{ $m->respondeA->entrada() ? ($m->respondeA->remetente_nome ?: 'Cliente') : 'Você' }}
                                </div>
                                <div class="opacity-90">{{ $m->respondeA->resumo(70) }}</div>
                            </div>
                        @endif

                        @if ($entrada && $m->remetente_nome)
                            {{-- em grupo, quem falou importa tanto quanto o que foi dito --}}
                            <div class="mb-0.5 text-xs font-semibold text-emerald-700">{{ $m->remetente_nome }}</div>
                        @endif

                        @if ($m->apagada())
                            {{-- O texto original nao e mostrado nem em cinza: apagar so vale se
                                 apagar. Fica o registro de que existiu, que e o que o WhatsApp
                                 tambem faz e o que o historico precisa para nao ter buraco. --}}
                            <div class="italic {{ $entrada ? 'text-slate-400' : 'text-white/70' }}">
                                Mensagem apagada
                            </div>

                        @elseif ($m->tipo === 'text')
                            <div class="whitespace-pre-wrap">{{ $m->corpo }}</div>

                        @elseif ($m->temMidia())
                            @if (in_array($m->tipo, ['image', 'sticker']))
                                <a href="{{ $m->midiaUrl() }}" target="_blank" rel="noopener">
                                    <img src="{{ $m->midiaUrl() }}" alt="{{ $m->media_nome }}"
                                         class="max-h-72 rounded {{ $m->tipo === 'sticker' ? 'bg-transparent' : '' }}">
                                </a>

                            @elseif ($m->tipo === 'video')
                                <video controls preload="metadata" class="max-h-72 rounded">
                                    <source src="{{ $m->midiaUrl() }}" type="{{ $m->media_mime }}">
                                </video>

                            @elseif ($m->tipo === 'audio')
                                <audio controls preload="metadata" class="w-64">
                                    <source src="{{ $m->midiaUrl() }}" type="{{ $m->media_mime }}">
                                </audio>
                                @if ($m->media_duracao)
                                    <div class="text-[10px] opacity-70">{{ $m->media_duracao }}s</div>
                                @endif

                                @if ($m->transcricao)
                                    <details class="mt-1" open>
                                        <summary class="cursor-pointer text-[10px] uppercase tracking-wide opacity-60">transcrição</summary>
                                        <div class="mt-1 whitespace-pre-wrap text-xs italic opacity-90">{{ $m->transcricao }}</div>
                                    </details>
                                @elseif ($m->transcricao_status === 'pendente')
                                    <div class="mt-1 text-[10px] opacity-60">transcrevendo&hellip;</div>
                                @elseif ($m->transcricao_status === 'ignorada')
                                    <div class="mt-1 text-[10px] opacity-60">áudio longo: não transcrito</div>
                                @endif

                            @else
                                <a href="{{ $m->midiaUrl() }}" target="_blank" rel="noopener"
                                   class="flex items-center gap-2 underline">
                                    <span>&#128196;</span>
                                    <span class="truncate">{{ $m->media_nome ?: 'documento' }}</span>
                                    @if ($m->tamanhoLegivel())
                                        <span class="shrink-0 text-[10px] opacity-70">{{ $m->tamanhoLegivel() }}</span>
                                    @endif
                                </a>
                            @endif

                            @if ($m->legenda)
                                <div class="mt-1 whitespace-pre-wrap">{{ $m->legenda }}</div>
                            @endif

                        @else
                            {{-- midia anunciada pelo webhook mas o download falhou --}}
                            <div class="italic opacity-80">
                                [{{ $m->tipo }}] nao foi possivel baixar o arquivo
                            </div>
                            @if ($m->legenda)
                                <div class="mt-1 whitespace-pre-wrap">{{ $m->legenda }}</div>
                            @endif
                        @endif

                        <div class="mt-1 text-[10px] opacity-70">
                            {{ $m->created_at?->format('H:i') }}
                            @unless ($entrada) &middot; {{ $m->status }} @endunless
                            @if ($m->automatica) &middot; automática @endif
                        </div>

                        @if ($m->erro)
                            <div class="mt-1 text-[10px] {{ $entrada ? 'text-red-600' : 'text-red-200' }}">{{ $m->erro }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="grid flex-1 place-items-center bg-slate-50 text-slate-400">Selecione uma conversa</div>
    @endif
</div>
