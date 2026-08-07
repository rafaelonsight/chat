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

                        @if ($m->tipo === 'text')
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
