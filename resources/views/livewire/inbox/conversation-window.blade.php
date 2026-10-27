<div @if(config('agenda.edition')) wire:poll.4s @endif class="vc-panel flex flex-1 flex-col overflow-hidden">
    @if ($conversa)
        <div class="vc-toolbar vc-conv-header border-b">
            <div class="vc-conv-identity flex min-w-0 w-full items-center gap-2">
                {{-- So no celular. La a lista sumiu para a conversa caber, e sem este botao
                     o unico caminho de volta seria recarregar a pagina. No computador as duas
                     colunas convivem e a seta seria ruido. --}}
                <button type="button" x-on:click="$dispatch('voltar-para-lista')"
                        class="-ml-1 shrink-0 rounded-full p-2 text-gray-500 transition hover:bg-gray-100 lg:hidden dark:text-gray-400 dark:hover:bg-white/5"
                        title="{{ __('ui_1e78117de087') }}" aria-label="{{ __('ui_1e78117de087') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke-width="2" stroke="currentColor" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                    </svg>
                </button>

                {{-- Foto abre os detalhes; quando privada, mantém o avatar por iniciais. --}}
                <button type="button" wire:click="verDetalhes"
                        class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-full text-xs font-semibold {{ $conversa->contact->fotoWhatsappUrl() ? '' : $conversa->contact->corAvatar() }}"
                        title="{{ __('ui_1ee7140b5336') }}">
                    @if ($conversa->contact->fotoWhatsappUrl())
                        <img src="{{ $conversa->contact->fotoWhatsappUrl() }}" alt="" class="h-full w-full object-cover">
                    @else
                        {{ $conversa->contact->iniciais() }}
                    @endif
                </button>

                <div class="min-w-0 flex-1">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="truncate font-semibold text-gray-800 dark:text-gray-100">
                        {{ $conversa->contact->nomeExibicao() }}
                    </span>

                    {{-- Etiqueta COM nome aqui, e nao so a bolinha da lista: no cabecalho
                         e onde se trabalha, e quem esta escrevendo a resposta precisa ver
                         "Financeiro" sem passar o mouse nem abrir o painel. Tres no
                         maximo — o cabecalho tem os botoes de acao a disputar espaco. --}}
                    @if ($conversa->contact->tags->isNotEmpty())
                        <span class="hidden shrink-0 items-center gap-1 sm:flex"
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
                <div class="vc-conv-meta text-xs text-gray-500 dark:text-gray-400">
                    @if ($conversa->contact->whatsapp_nome && $conversa->contact->whatsapp_nome !== $conversa->contact->nomeExibicao())
                        <span class="truncate text-emerald-700 dark:text-emerald-400">WhatsApp: {{ $conversa->contact->whatsapp_nome }}</span>
                        <span>&middot;</span>
                    @endif
                    <span>{{ $conversa->contact->telefone_e164 }}</span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 dark:bg-white/10">
                        {{ $conversa->rotuloStatus() }} • {{ $conversa->protocolo }}
                    </span>
                    @if ($conversa->atendente)
                        <span>&middot; {{ $conversa->atendente->nomePublico() }}</span>
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

            <div class="vc-conv-actions">
                <livewire:inbox.conversation-actions :conversation-id="$conversa->id" :key="'conversation-actions-'.$conversa->id" />
                @if ($conversa->contact->tipo !== \App\Models\Contact::GRUPO && $equipes->isNotEmpty())
                    <div class="relative" x-data="{ aberto: false }" x-on:click.outside="aberto = false">
                        <button type="button" x-on:click="aberto = !aberto"
                                class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">
                            {{ $conversa->team?->nome ?? 'Sem equipe' }} &#9662;
                        </button>
                        <div x-show="aberto" x-cloak
                             class="absolute right-0 z-20 mt-1 w-56 rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-white/10 dark:bg-gray-800">
                            <p class="px-2 py-1 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('ui_fb6a4c81683a') }}</p>
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
                                    {{ __('ui_fa313673d466') }}
                                </p>
                                <div class="max-h-56 overflow-y-auto">
                                    @foreach ($pessoas as $pessoa)
                                        <button type="button" wire:key="ps-{{ $pessoa->id }}"
                                                wire:click="passarPara({{ $pessoa->id }})" x-on:click="aberto = false"
                                                class="block w-full rounded-lg px-2 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5">
                                            {{ $pessoa->name }}
                                            @if ($pessoa->id === auth()->id())
                                                <span class="text-xs opacity-60">{{ __('ui_488a8ea93fac') }}</span>
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
                @if ($etiquetasDeConversa->isNotEmpty() || auth()->user()?->admin)
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
                                <span class="opacity-70">{{ __('ui_7dcdfecc0dbd') }}</span>
                            @endif
                            &#9662;
                        </button>

                        <div x-show="aberto" x-cloak
                             class="absolute right-0 z-20 mt-1 max-h-64 w-64 overflow-y-auto rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-white/10 dark:bg-gray-800">
                            <p class="px-2 py-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                {{ __('ui_02f2ab445875') }}
                            </p>
                            @include('livewire.inbox.partials.criar-etiqueta-local')

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
                                {{ __('ui_4f2598976ed1') }}
                            </p>
                        </div>
                    </div>
                @endif

                {{-- Fixar no topo. E de QUEM fixou: a conversa que eu prendo nao ocupa o
                     topo da tela de outro atendente. --}}
                @php $fixada = $conversa->fixadaPara(auth()->user()); @endphp
                <button type="button" wire:click="alternarFixada"
                        title="{{ $fixada ? 'Soltar do topo' : 'Fixar no topo da sua lista' }}"
                        class="rounded border p-1.5 {{ $fixada
                            ? 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300'
                            : 'border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/20 dark:text-gray-300 dark:hover:bg-white/5' }}" @disabled(auth()->user()?->ehAuditor())>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="{{ $fixada ? 'currentColor' : 'none' }}"
                         viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z" />
                    </svg>
                </button>

                {{-- Busca DENTRO da conversa. A da lista acha a conversa; esta acha a
                     mensagem. --}}
                <button type="button" x-data x-on:click="$refs.buscaConversa?.focus()"
                        title="{{ __('ui_1e09cfe29423') }}"
                        class="rounded border border-gray-300 p-1.5 text-gray-600 hover:bg-gray-50 dark:border-white/20 dark:text-gray-300 dark:hover:bg-white/5">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                         stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </button>

                {{-- Chamada de video.

                     Some quando o servidor nao tem video configurado, em vez de aparecer e dar
                     erro: botao que so serve para avisar que nao funciona e ruido na barra que
                     o atendente usa o dia inteiro. --}}
                @if (!config('agenda.edition') && \App\Support\Modulos::ativo('video') && app(\App\Services\Video\Livekit::class)->configurado())
                    <button type="button" wire:click="chamarPorVideo"
                            x-data
                            x-on:abrir-sala.window="window.open($event.detail.url, '_blank', 'noopener')"
                            title="{{ __('ui_83b4d246e2b5') }}"
                            class="rounded border border-amber-300 bg-amber-50 p-1.5 text-amber-700 hover:bg-amber-100 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300 dark:hover:bg-amber-500/20" @disabled(auth()->user()?->ehAuditor())>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                             stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </button>
                @endif

                @if ($conversa->contact->tipo !== \App\Models\Contact::GRUPO)
                {{-- "Volto depois". Fecha a conversa junto — marcar como nao lida com ela
                     aberta na frente nao significaria nada. --}}
                <button type="button" wire:click="marcarNaoLida"
                        title="{{ __('ui_bdf186e50c76') }}"
                        class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5" @disabled(auth()->user()?->ehAuditor())>
                    {{ __('ui_e1ba20124063') }}
                </button>

                @if ($conversa->status === \App\Models\Conversation::NOVA)
                    <button type="button" wire:click="assumir"
                            class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5" @disabled(auth()->user()?->ehAuditor())>
                        {{ __('ui_cb8ad426a8a1') }}
                    </button>
                @endif

                @if ($conversa->status === \App\Models\Conversation::ARQUIVADA)
                    @if ($conversa->podeReabrir())
                        <button type="button" wire:click="reabrir"
                                class="rounded bg-violet-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-violet-700" @disabled(auth()->user()?->ehAuditor())>
                            {{ __('ui_3a7b783c8460') }}
                        </button>
                    @else
                        <span class="text-xs text-gray-400" title="{{ __('ui_a73d5cf31336') }}">
                            encerrada
                        </span>
                    @endif
                @else
                    <button type="button" wire:click="finalizar"
                            
                            class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5" @disabled(auth()->user()?->ehAuditor())>
                        {{ __('ui_e9de3306bf15') }}
                    </button>
                @endif
                @endif
            </div>
        </div>

        {{--
            wire:ignore para o Livewire nao apagar o estado do Alpine a cada atualizacao da
            tela, e wire:key com o id da conversa para que TROCAR de conversa troque o
            elemento — e ai o destroy() sai do canal antigo e o init() entra no novo.
        --}}
        @if(!config('agenda.edition'))
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

        @endif
        {{-- A barra da busca. Fica sempre montada e discreta: campo que aparece e some
             obriga a pessoa a lembrar que ele existe. --}}
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-1.5 dark:border-white/5">
            <input type="search" wire:model.live.debounce.400ms="buscaNaConversa"
                   x-ref="buscaConversa" placeholder="{{ __('ui_1e09cfe29423') }}"
                   class="w-full border-0 bg-transparent p-0 text-xs text-gray-700 placeholder:text-gray-400 focus:ring-0 dark:text-gray-200">

            @if ($procurado !== '')
                <span class="shrink-0 whitespace-nowrap text-[11px] text-gray-500">
                    {{ $mensagens->count() }} {{ $mensagens->count() === 1 ? 'mensagem' : 'mensagens' }}
                </span>
                <button type="button" wire:click="limparBusca"
                        class="shrink-0 rounded px-1.5 text-sm leading-none text-gray-400 hover:text-gray-700"
                        title="{{ __('ui_66d1fcb93e07') }}">&times;</button>
            @endif
        </div>

        {{-- Para quem encaminhar. Busca por nome com pelo menos duas letras: a lista
             inteira de contatos num menu nao ajuda ninguem a achar alguem. --}}
        @if ($encaminhando)
            <div class="border-b border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                <div class="flex items-center gap-2">
                    <span class="shrink-0 text-xs font-semibold text-amber-900 dark:text-amber-200">{{ __('ui_c9c096e0a4f4') }}</span>
                    <input type="text" wire:model.live.debounce.300ms="buscaEncaminhar"
                           placeholder="{{ __('ui_ed869fb33b44') }}"
                           class="flex-1 rounded border border-amber-300 bg-white px-2 py-1 text-xs dark:border-amber-500/40 dark:bg-gray-800">
                    <button type="button" wire:click="$set('encaminhando', null)"
                            class="shrink-0 rounded px-2 text-sm leading-none text-amber-700 hover:text-amber-900"
                            title="{{ __('ui_c111e0ab9ddb') }}" @disabled(auth()->user()?->ehAuditor())>&times;</button>
                </div>

                @if ($paraEncaminhar->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach ($paraEncaminhar as $c)
                            <button type="button" wire:key="enc-{{ $c->id }}"
                                    wire:click="encaminhar({{ $encaminhando }}, {{ $c->id }})"
                                    class="rounded-full border border-amber-300 bg-white px-2.5 py-1 text-xs text-gray-700 hover:bg-amber-100 dark:border-amber-500/40 dark:bg-gray-800 dark:text-gray-200">
                                {{ $c->nomeExibicao() }}
                            </button>
                        @endforeach
                    </div>
                @elseif (mb_strlen(trim($buscaEncaminhar)) >= 2)
                    <p class="mt-2 text-xs text-amber-800 dark:text-amber-300">{{ __('ui_4ad68f557175') }}</p>
                @endif
            </div>
        @endif

        {{--
            COMO O ATENDIMENTO TERMINOU.

            Aparece so no momento de encerrar, que e o unico em que a pessoa tem a resposta na
            cabeca. E tem saida sem classificar: obrigar faria o atendente com pressa clicar
            sempre na primeira opcao, e ai o dado mente de um jeito pior — parece preenchido.
        --}}
        @if ($classificando && config('agenda.edition'))
            <div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
                <section role="dialog" aria-modal="true" aria-label="Finalizar atendimento" class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl dark:bg-gray-900">
                    <h2 class="text-lg font-semibold">Finalizar atendimento?</h2>
                    <p class="my-4 text-sm text-gray-500">A conversa ficará em Arquivadas e seu histórico será preservado.</p>
                    <div class="flex justify-end gap-3">
                        <button type="button" wire:click="$set('classificando', false)" class="rounded-lg border px-4 py-2">Voltar</button>
                        <button type="button" wire:click="encerrar" wire:loading.attr="disabled" class="rounded-lg bg-primary-600 px-4 py-2 text-white">Finalizar</button>
                    </div>
                </section>
            </div>
        @elseif ($classificando)
            <div x-data="{ painel: '', nota: $wire.entangle('avaliacaoCliente'), resumo: $wire.entangle('enviarResumoCliente'), pesquisa: $wire.entangle('enviarPesquisa') }"
                 x-trap.inert.noscroll="true" @keydown.escape.window="$wire.set('classificando', false)"
                 style="position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:20px;background:rgba(15,23,42,.38);backdrop-filter:blur(3px)"
                 @click.self="$wire.set('classificando', false)">
                <section role="dialog" aria-modal="true" aria-labelledby="vc-fecho-titulo" style="width:100%;max-width:440px;max-height:90vh;overflow:auto;border-radius:20px;background:#fff;box-shadow:0 24px 80px #0f172a30;color:#111827">
                    <header style="padding:24px 24px 20px;display:flex;align-items:center;justify-content:space-between">
                        <div><h2 id="vc-fecho-titulo" style="font-size:20px;font-weight:650;margin:0">{{ __('ui_3112de7b405e') }}</h2><p style="font-size:12px;color:#6b7280;margin:5px 0 0">{{ __('ui_badf277d70e0') }}</p></div>
                        <button type="button" aria-label="{{ __('ui_7b2006fd7af8') }}" wire:click="$set('classificando', false)" style="border:0;background:#f3f4f6;border-radius:50%;width:30px;height:30px;font-size:20px">{{ __('ui_67fba2f357af') }}</button>
                    </header>
                    <div style="padding:0 24px">
                        <div style="border-top:1px solid #eef0f4;padding:16px 0">
                            <button type="button" @click="painel = painel === 'nota' ? '' : 'nota'" :aria-expanded="painel === 'nota'" style="display:flex;align-items:center;gap:12px;width:100%;background:none;border:0;text-align:left">
                                <span style="color:#7c3aed;font-size:20px">☆</span><span style="flex:1;font-size:14px;font-weight:550">{{ __('ui_fd0a564a1d82') }}</span><span style="font-size:12px;color:#6b7280" x-text="nota ? nota + '/5' : 'Opcional'"></span><span style="color:#9ca3af">›</span>
                            </button>
                            <div x-show="painel === 'nota'" x-cloak style="padding:14px 0 0 30px">
                                <p style="font-size:12px;color:#6b7280;margin-bottom:10px">{{ __('ui_c06d015e58e9') }}</p>
                                <div style="display:flex;gap:8px">
                                    @foreach(range(1,5) as $n)
                                        <button type="button" @click="nota={{ $n }}" aria-label="{{ $n }} estrelas" :aria-pressed="nota === {{ $n }}" :style="'border:0;background:none;font-size:30px;color:' + (nota >= {{ $n }} ? '#7c3aed' : '#d1d5db')">★</button>
                                    @endforeach
                                </div>
                                <button type="button" @click="nota=null" style="font-size:12px;color:#6b7280;background:none;border:0;margin-top:8px">{{ __('ui_b8add85d562f') }}</button>
                            </div>
                            @error('avaliacaoCliente')<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div style="border-top:1px solid #eef0f4;padding:16px 0">
                            <label style="display:flex;align-items:center;gap:12px;cursor:pointer">
                                <span style="color:#7c3aed;font-size:20px">✉</span><span style="flex:1;font-size:14px;font-weight:550">{{ __('ui_e06b4b943839') }}</span>
                                <input type="checkbox" x-model="resumo" style="accent-color:#7c3aed;width:18px;height:18px">
                            </label>
                            <div x-show="resumo" x-cloak style="padding:14px 0 0 30px">
                                <label style="display:block;font-size:12px;color:#6b7280">{{ __('ui_9b840a46fc20') }}<input type="email" wire:model="emailEncerramento" placeholder="cliente@empresa.com.br" style="display:block;width:100%;padding:10px 12px;margin-top:6px;border:1px solid #d1d5db;border-radius:9px;color:#111827"></label>
                                <p style="font-size:11px;color:#6b7280;margin-top:7px">{{ __('ui_777fec9325c9') }}</p>
                            </div>
                            @error('emailEncerramento')<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div style="border-top:1px solid #eef0f4;padding:16px 0">
                            @if($conversa->channel?->ehSite())
                                <p style="font-size:13px;color:#6b7280">{{ __('ui_eac055e1a9ba') }}</p>
                            @elseif($conversa->tenant?->pesquisa_obrigatoria)
                                <p style="font-size:14px"><span style="color:#7c3aed;margin-right:10px">☺</span>{{ __('ui_dac3088c75e5') }} <span style="font-size:11px;color:#7c3aed">{{ __('ui_3ce87084720a') }}</span></p>
                            @else
                                <label style="display:flex;align-items:center;gap:12px;cursor:pointer"><span style="color:#7c3aed;font-size:20px">☺</span><span style="flex:1;font-size:14px;font-weight:550">{{ __('ui_dd5c5e6f2885') }}</span><input type="checkbox" x-model="pesquisa" style="accent-color:#7c3aed;width:18px;height:18px"></label>
                                <p x-show="pesquisa" x-cloak style="padding-left:30px;font-size:12px;color:#6b7280;margin-top:10px">{{ __('ui_cb3a3137709f') }}</p>
                            @endif
                        </div>
                        <p style="padding:12px;background:#f5f3ff;border-radius:10px;font-size:12px;color:#6d28d9">{{ __('ui_8b7ac4d47c95') }}</p>
                        <p style="font-size:11px;color:#9ca3af;margin:10px 0 0">{{ __('ui_d7ff8d2bd270') }}</p>
                        @if($etiquetasDeConversa->isNotEmpty())
                            <details style="margin-top:12px;font-size:12px;color:#6b7280"><summary>{{ __('ui_2ec226789663') }}</summary><div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">@foreach($etiquetasDeConversa as $et)<button type="button" wire:click="encerrarCom({{ $et->id }})" wire:loading.attr="disabled" style="padding:7px;border:1px solid #ddd;border-radius:7px">Finalizar como {{ $et->nome }}</button>@endforeach</div></details>
                        @endif
                    </div>
                    <footer style="padding:22px 24px;display:flex;justify-content:flex-end;gap:10px">
                        <button type="button" wire:click="$set('classificando', false)" style="padding:10px 16px;border:1px solid #e5e7eb;background:#fff;border-radius:9px;font-size:13px">{{ __('ui_c111e0ab9ddb') }}</button>
                        <button type="button" wire:click="encerrar" wire:loading.attr="disabled" style="padding:10px 22px;border:0;background:#7c3aed;color:#fff;border-radius:9px;font-size:13px;font-weight:600"><span wire:loading.remove wire:target="encerrar,encerrarCom">{{ __('ui_e9de3306bf15') }}</span><span wire:loading wire:target="encerrar,encerrarCom">{{ __('ui_e6209fa749af') }}</span></button>
                    </footer>
                </section>
            </div>
        @endif
        @if ($conversa->status === \App\Models\Conversation::ARQUIVADA)
            @php
                $fimResumo = \Illuminate\Support\Facades\DB::table('encerramentos_atendimento')->where('conversation_id',$conversa->id)->orderByDesc('versao')->first();
            @endphp
            @if ($fimResumo)
                <details class="vc-close-summary" wire:poll.10s.visible><summary>Resumo e entrega por e-mail • {{ $conversa->protocolo }}</summary>@if($fimResumo->resumo_estado==='preparando')<p role="status">{{ __('ui_879f045a6c16') }}</p>@elseif($fimResumo->resumo_estado==='falhou')<p role="alert">{{ __('ui_8800be523f5b') }}</p>@endif<h3>{{ $fimResumo->assunto }}</h3>@if($fimResumo->avaliacao_cliente)<p class="text-sm">Avaliação interna do cliente: {{ $fimResumo->avaliacao_cliente }}/5 ★ · não enviada ao cliente</p>@endif<p class="whitespace-pre-line">{{ $fimResumo->resumo }}</p><p>{{ $fimResumo->solucao }}</p><p>{{ $fimResumo->pendencias }}</p>
                    @foreach (\Illuminate\Support\Facades\DB::table('emails_encerramento')->where('encerramento_id',$fimResumo->id)->get() as $entrega)
                        <p>{{ ucfirst($entrega->destinatario_tipo) }}: {{ ['aguardando_resumo'=>'Aguardando resumo','pendente'=>'Aguardando envio','sem_email'=>'Não enviado: e-mail não cadastrado','nao_solicitado'=>'Não enviado: opção não selecionada','enviado'=>'Enviado','falhou'=>'Falha após tentativas'][$entrega->estado] ?? $entrega->estado }}</p>
                    @endforeach
                    @if(!config('agenda.edition') && \App\Support\Modulos::ativo('ia') && auth()->user()?->consultaRelatorios() && !auth()->user()?->ehAuditor())<button type="button" wire:click="reenviarResumo" @disabled(auth()->user()?->ehAuditor())>{{ __('ui_e94f09acbd5f') }}</button>@endif
                </details>
            @endif
        @endif

        @error('video')
            <div class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                {{ $message }}
            </div>
        @enderror

        @error('encaminhar')
            <div class="bg-red-50 px-4 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</div>
        @enderror

        @error('apagar')
            <div class="bg-red-50 px-4 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</div>
        @enderror

        @error('transferir')
            <div class="bg-red-50 px-4 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</div>
        @enderror

        @error('reabrir')
            <div class="bg-red-50 px-4 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</div>
        @enderror

        {{--
            A linha usa toda a largura da conversa. Limitar cada linha a 768px aproximava
            artificialmente mensagens recebidas e enviadas e criava grandes faixas vazias em
            monitores largos. A legibilidade continua protegida pelo max-width do próprio balão.
        --}}
        <div class="vc-thread flex-1 overflow-y-auto px-3 py-4 sm:px-5 lg:px-6"
             wire:key="historico-{{ $conversa->id }}"
             x-data="{
                 acompanhar: true,
                 observador: null,
                 fim() { if (this.acompanhar) this.$el.scrollTop = this.$el.scrollHeight },
                 init() {
                     this.$nextTick(() => {
                         this.fim();
                         this.observador = new ResizeObserver(() => this.fim());
                         this.observador.observe(this.$el.firstElementChild);
                     });
                 },
                 destroy() { this.observador?.disconnect() }
             }"
             x-on:wheel.passive="acompanhar = false"
             x-on:touchstart.passive="acompanhar = false"
             x-on:pointerdown="acompanhar = false"
             x-on:keydown="acompanhar = false">
            <div class="mx-auto w-full max-w-[1150px]">
            @if ($mensagens->count() >= $limite)
                <button type="button" wire:click="carregarMais" class="mx-auto block text-xs text-slate-500 underline dark:text-gray-400">
                    {{ __('ui_3d4d2be05365') }}
                </button>
            @endif

            @if ($procurado !== '' && $mensagens->isEmpty())
                {{-- Lista vazia sem explicacao parece defeito, nao busca sem resultado. --}}
                <p class="py-6 text-center text-xs text-slate-500 dark:text-gray-400">
                    {{ __('ui_53c48ceaf214') }} <strong>{{ $procurado }}</strong>.
                </p>
            @endif

            @foreach ($linha as $item)
                @if ($item instanceof \App\Models\ConversationEvent)
                    <div wire:key="ev-{{ $item->id }}">
                        @include('livewire.inbox.partials.evento', ['ev' => $item])
                    </div>
                    @continue
                @endif

                @if ($item instanceof \App\Models\Meeting)
                    <div wire:key="reu-{{ $item->id }}">
                        @include('livewire.inbox.partials.reuniao', ['r' => $item])
                    </div>
                    @continue
                @endif

                @php
                    $m = $item;
                    $entrada = $m->entrada();
                    $autor = ! $entrada ? $m->autor : null;
                    $notasDaMensagem = $notasPorMensagem->get($m->id, collect());
                    $anterior = $linha->get($loop->index - 1);
                    $proxima = $linha->get($loop->index + 1);
                    $chaveAutor = fn ($msg) => $msg instanceof \App\Models\Message
                        ? ($msg->entrada() ? 'entrada:'.($msg->remetente_jid ?: 'cliente') : 'saida:'.($msg->user_id ?: 'externa'))
                        : null;
                    $perto = fn ($a, $b) => $a instanceof \App\Models\Message
                        && $b instanceof \App\Models\Message
                        && abs(($a->created_at?->getTimestamp() ?? 0) - ($b->created_at?->getTimestamp() ?? 0)) <= 300;
                    $continuaAnterior = $chaveAutor($anterior) === $chaveAutor($m) && $perto($anterior, $m);
                    $continuaDepois = $chaveAutor($proxima) === $chaveAutor($m) && $perto($m, $proxima);
                @endphp
                <div wire:key="msg-{{ $m->id }}" x-data="{ notasAbertas: false }"
                     class="w-full {{ $continuaAnterior ? 'mt-1' : 'mt-3.5' }} first:mt-0">
                    @if ($notasDaMensagem->isNotEmpty())
                        <div x-show="notasAbertas" x-collapse x-cloak class="mb-1 flex {{ $entrada ? 'justify-start' : 'justify-end' }}">
                            <div class="w-full max-w-lg">
                                <div class="space-y-1.5">
                                    @foreach ($notasDaMensagem as $notaVinculada)
                                        <div class="rounded-lg border border-amber-200 bg-amber-50/80 px-3 py-2 text-sm text-slate-800 dark:border-amber-500/25 dark:bg-amber-500/[0.07] dark:text-gray-100">
                                            @if ($editandoNota === $notaVinculada->id)
                                                <textarea wire:model="textoNota" rows="3" class="w-full resize-y rounded-lg border border-amber-300 bg-white px-2 py-1.5 text-sm dark:border-amber-500/40 dark:bg-gray-900"></textarea>
                                                @error('textoNota') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                                <div class="mt-2 flex gap-2">
                                                    <button type="button" wire:click="salvarEdicaoNota" class="rounded-lg bg-amber-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-amber-700" @disabled(auth()->user()?->ehAuditor())>{{ __('ui_93bf2d32ec33') }}</button>
                                                    <button type="button" wire:click="$set('editandoNota', null)" class="px-2 py-1 text-xs text-slate-500" @disabled(auth()->user()?->ehAuditor())>{{ __('ui_c111e0ab9ddb') }}</button>
                                                </div>
                                            @else
                                                <div class="whitespace-pre-wrap">{{ $notaVinculada->descricao }}</div>
                                                <div class="mt-1 flex items-center justify-between gap-2 text-[10px] text-amber-700/80 dark:text-amber-300/70">
                                                    <span>{{ $notaVinculada->user?->name ?: 'Equipe' }} &middot; {{ $notaVinculada->created_at?->format('d/m H:i') }}</span>
                                                    @if ($notaVinculada->user_id === auth()->id() || auth()->user()?->veTudo())
                                                        <span class="flex gap-1">
                                                            <button type="button" wire:click="editarNota({{ $notaVinculada->id }})" class="rounded px-1.5 py-0.5 hover:bg-amber-200/60 dark:hover:bg-amber-500/20" @disabled(auth()->user()?->ehAuditor())>{{ __('ui_2b5f23437f58') }}</button>
                                                            <button type="button" wire:click="excluirNota({{ $notaVinculada->id }})" wire:confirm="Excluir esta nota interna?" class="rounded px-1.5 py-0.5 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" @disabled(auth()->user()?->ehAuditor())>{{ __('ui_8e7c1314fe0b') }}</button>
                                                        </span>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                    <button type="button" wire:click="anotar({{ $m->id }})"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-500/10" @disabled(auth()->user()?->ehAuditor())>
                                        <span aria-hidden="true">+</span> {{ __('ui_55d65681b570') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="group flex items-end gap-1.5 {{ $entrada ? 'justify-start' : 'justify-end' }}">
                    {{--
                        order-first/order-last poe a seta sempre do lado de DENTRO do balao,
                        seja ele da esquerda ou da direita — assim ela ocupa o vao que ja
                        existe, em vez de empurrar a conversa para o lado.

                        Visivel sempre no celular e so no passar do mouse no computador: no
                        telefone nao existe "passar o mouse", e um botao que so aparece no
                        hover simplesmente nao existiria la.
                    --}}
                    <button type="button" wire:click="responder({{ $m->id }})"
                            title="{{ __('ui_7ddb11f18ee0') }}" aria-label="{{ __('ui_1d9f98e31bda') }}"
                            class="shrink-0 rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-gray-200
                                   opacity-100 lg:opacity-0 lg:group-hover:opacity-100
                                   {{ $entrada ? 'order-[2]' : 'order-[-2]' }}" @disabled(auth()->user()?->ehAuditor())>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                             stroke-width="2" stroke="currentColor" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
                    </button>

                    {{-- Encaminhar para outro contato. Vale para mensagem de qualquer lado:
                         o mais comum e repassar o que o CLIENTE mandou — um comprovante, uma
                         foto do problema — para quem vai resolver. --}}
                    @unless ($m->apagada())
                        <button type="button" wire:click="$set('encaminhando', {{ $m->id }})"
                                title="{{ __('ui_c9c096e0a4f4') }}" aria-label="{{ __('ui_e0848f93cbc6') }}"
                                class="shrink-0 rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-gray-200
                                       opacity-100 lg:opacity-0 lg:group-hover:opacity-100
                                       {{ $entrada ? 'order-[2]' : 'order-[-2]' }}" @disabled(auth()->user()?->ehAuditor())>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M15 15l6-6m0 0l-6-6m6 6H9a6 6 0 0 0 0 12h3" />
                            </svg>
                        </button>
                    @endunless

                    {{-- Apagar: so mensagem NOSSA, e so em canal que consegue apagar de
                         verdade. No canal oficial o botao nem aparece — a Meta nao tem essa
                         operacao, e oferecer para falhar depois e pior que nao oferecer. --}}
                    @if (! $entrada && $podeApagar && ! $m->apagada())
                        <button type="button" wire:click="apagar({{ $m->id }})"
                                wire:confirm="Apagar esta mensagem para todos? O cliente deixa de ver."
                                title="{{ __('ui_658cd4d2933b') }}" aria-label="{{ __('ui_2b01d3065fd5') }}"
                                class="shrink-0 order-first rounded-full p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-400
                                       opacity-100 lg:opacity-0 lg:group-hover:opacity-100" @disabled(auth()->user()?->ehAuditor())>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                            </svg>
                        </button>
                    @endif

                    {{-- WhatsApp permite editar texto próprio por uma janela curta. O botão
                         só aparece quando ainda há chance real de o provedor aceitar. --}}
                    @if (! $entrada && $podeEditar && $m->tipo === 'text' && $m->external_id && ! $m->apagada() && $m->created_at?->gte(now()->subMinutes(15)))
                        <button type="button" wire:click="iniciarEdicaoMensagem({{ $m->id }})"
                                title="{{ __('ui_c938a51b174d') }}" aria-label="{{ __('ui_ab06b59dc1e1') }}"
                                class="shrink-0 order-first rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-gray-200 opacity-100 lg:opacity-0 lg:group-hover:opacity-100" @disabled(auth()->user()?->ehAuditor())>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931ZM16.862 4.487 19.5 7.125" />
                            </svg>
                        </button>
                    @endif

                    {{--
                        Reagir. Seis emojis e mais nada: reacao e gesto de um toque, e uma
                        grade de cinquenta transformaria isso numa escolha — que e exatamente
                        o que a pessoa nao quer fazer no meio de um atendimento.

                        Clicar no mesmo emoji de novo tira a reacao, como no WhatsApp.

                        So aparece em canal que tem esse endpoint: Messenger e Instagram nao
                        oferecem reacao por API, e um botao que sempre falha e pior que nenhum.
                    --}}
                    @if ($podeReagir)
                    <div class="relative shrink-0 {{ $entrada ? 'order-[2]' : 'order-[-2]' }}"
                         x-data="{ aberto: false }" x-on:click.outside="aberto = false">
                        <button type="button" x-on:click="aberto = !aberto"
                                title="{{ __('ui_fc580f9e44f8') }}" aria-label="{{ __('ui_65415906abfb') }}"
                                class="rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-gray-200
                                       opacity-100 lg:opacity-0 lg:group-hover:opacity-100">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="2" stroke="currentColor" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M15.182 15.182a4.5 4.5 0 0 1-6.364 0M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75Zm-.375 0h.008v.015h-.008V9.75Zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75Zm-.375 0h.008v.015h-.008V9.75Z" />
                            </svg>
                        </button>

                        <div x-show="aberto" x-cloak id="onchat-reacoes"
                             class="vc-popover absolute bottom-full z-30 mb-1 flex gap-0.5 rounded-full p-1
                                    {{ $entrada ? 'left-0' : 'right-0' }}">
                            @foreach (["\u{1F44D}", "\u{2764}", "\u{1F602}", "\u{1F62E}", "\u{1F622}", "\u{1F64F}"] as $r)
                                <button type="button" wire:key="rc-{{ $m->id }}-{{ $loop->index }}"
                                        wire:click="reagir({{ $m->id }}, @js($r))" x-on:click="aberto = false"
                                        class="rounded-full p-1 text-lg leading-none hover:bg-slate-100 dark:hover:bg-white/10">{{ $r }}</button>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <button type="button"
                            @if ($notasDaMensagem->isNotEmpty())
                                x-on:click="notasAbertas = !notasAbertas" :aria-expanded="notasAbertas"
                                title="{{ $notasDaMensagem->count() }} {{ $notasDaMensagem->count() === 1 ? 'nota interna' : 'notas internas' }}"
                            @else
                                wire:click="anotar({{ $m->id }})" title="{{ __('ui_b9af587b6873') }}"
                            @endif
                            aria-label="{{ $notasDaMensagem->isNotEmpty() ? 'Ver notas internas desta mensagem' : 'Adicionar nota interna sobre esta mensagem' }}"
                            class="relative shrink-0 rounded-full p-1.5 transition hover:bg-amber-50 hover:text-amber-700 dark:hover:bg-amber-500/10 dark:hover:text-amber-300
                                   {{ $notasDaMensagem->isNotEmpty()
                                       ? 'bg-amber-500/15 text-amber-500 ring-1 ring-amber-500/35 opacity-100'
                                       : 'text-slate-400 opacity-100 lg:opacity-0 lg:group-hover:opacity-100' }}
                                   {{ $entrada ? 'order-[1]' : 'order-[-1]' }}">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 4.5h9a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-4.2L8 19v-3.5h-.5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z" /><path stroke-linecap="round" d="M9 8.5h6M9 11.5h4" /></svg>
                        @if ($notasDaMensagem->isNotEmpty())
                            <span class="absolute -right-1 -top-1 grid min-h-4 min-w-4 place-items-center rounded-full bg-amber-500 px-1 text-[9px] font-bold leading-none text-gray-950 ring-2 ring-gray-950">{{ $notasDaMensagem->count() }}</span>
                        @endif
                    </button>

                    @if ($m->reacao_cliente || $m->reacao_nossa)
                        <span class="vc-popover order-last flex shrink-0 items-center gap-0.5 self-end rounded-full px-1.5 py-0.5 text-xs">
                            @if ($m->reacao_cliente)
                                <span title="{{ __('ui_83169f3e76dc') }}">{{ $m->reacao_cliente }}</span>
                            @endif
                            @if ($m->reacao_nossa)
                                <span title="{{ __('ui_0176d44f23f5') }}" class="opacity-70">{{ $m->reacao_nossa }}</span>
                            @endif
                        </span>
                    @endif

                    <div class="min-w-0 max-w-[82%] break-words px-3.5 py-2.5 text-sm leading-relaxed shadow-[0_1px_2px_rgba(0,0,0,0.12)] [overflow-wrap:anywhere] sm:max-w-[68%] lg:max-w-[65%]
                                {{ $entrada
                        ? ($m->mencao
                            ? 'vc-note border-2'
                            : 'vc-message-in')
                        : ($autor ? $autor->classesChat() : 'vc-message-out') }}
                                {{ $entrada
                                    ? ($continuaAnterior ? 'rounded-tl-md' : 'rounded-tl-2xl') .' '. ($continuaDepois ? 'rounded-bl-md' : 'rounded-bl-2xl') .' rounded-r-2xl'
                                    : ($continuaAnterior ? 'rounded-tr-md' : 'rounded-tr-2xl') .' '. ($continuaDepois ? 'rounded-br-md' : 'rounded-br-2xl') .' rounded-l-2xl' }}">
                        {{-- No meio de duzentas mensagens de grupo, a que te chama precisa
                             saltar sem precisar de leitura. --}}
                        @if ($m->mencao)
                            <div class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                                {{ __('ui_27a0056d6427') }}
                            </div>
                        @endif
                        @if ($autor && ! $continuaAnterior)
                            <div class="mb-1 inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold {{ $autor->classeEtiquetaChat() }}">
                                {{ $m->assinatura_nome ?: $autor->nomePublico() }}
                            </div>
                        @endif
                        {{-- A mensagem citada, do jeito que o WhatsApp mostra: faixa curta
                             acima, com quem falou e o comeco do que foi dito. --}}
                        @if ($m->respondeA)
                            <div class="mb-1.5 rounded border-l-2 px-2 py-1 text-xs {{ $entrada ? 'border-emerald-600 bg-slate-100 text-slate-600 dark:bg-black/20 dark:text-gray-300' : 'border-white/70 bg-white/15 text-white/90' }}">
                                <div class="font-semibold">
                                    {{ $m->respondeA->entrada() ? ($m->respondeA->remetente_nome ?: 'Cliente') : 'Você' }}
                                </div>
                                <div class="opacity-90">{{ $m->respondeA->resumo(70) }}</div>
                            </div>
                        @endif

                        @if (!config('agenda.edition') && $entrada && $m->remetente_nome && ! $continuaAnterior)
                            {{-- em grupo, quem falou importa tanto quanto o que foi dito --}}
                            <div class="mb-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">{{ $m->remetente_nome }}</div>
                        @endif

                        @if ($m->apagada())
                            {{-- O texto original nao e mostrado nem em cinza: apagar so vale se
                                 apagar. Fica o registro de que existiu, que e o que o WhatsApp
                                 tambem faz e o que o historico precisa para nao ter buraco. --}}
                            <div class="italic {{ $entrada ? 'text-slate-400 dark:text-gray-500' : 'text-white/70' }}">
                                {{ __('ui_9923a556244b') }}
                            </div>

                        @elseif ($m->tipo === 'text')
                            @if ($editandoMensagem === $m->id)
                                <div class="space-y-2" x-data x-init="$nextTick(() => $refs.edicao?.focus())">
                                    <textarea x-ref="edicao" wire:model="textoMensagem" rows="3"
                                              x-on:keydown.escape.prevent="$wire.cancelarEdicaoMensagem()"
                                              x-on:keydown.ctrl.enter.prevent.stop="$wire.salvarEdicaoMensagem()"
                                              x-on:keydown.meta.enter.prevent.stop="$wire.salvarEdicaoMensagem()"
                                              class="w-full min-w-64 resize-y rounded-lg border border-white/30 bg-black/10 px-3 py-2 text-sm text-inherit outline-none focus:ring-2 focus:ring-white/50"></textarea>
                                    <div class="flex justify-end gap-2">
                                        <button type="button" wire:click="cancelarEdicaoMensagem" class="rounded px-2 py-1 text-xs hover:bg-black/10" @disabled(auth()->user()?->ehAuditor())>{{ __('ui_c111e0ab9ddb') }}</button>
                                        <button type="button" wire:click="salvarEdicaoMensagem" class="rounded bg-white/20 px-2 py-1 text-xs font-semibold hover:bg-white/30" @disabled(auth()->user()?->ehAuditor())>{{ __('ui_93bf2d32ec33') }}</button>
                                    </div>
                                    @error('editarMensagem') <div class="text-xs text-red-200">{{ $message }}</div> @enderror
                                </div>
                            @else
                                <div class="whitespace-pre-wrap">{{ \App\Support\TextoComLinks::render((string) $m->corpo) }}</div>
                            @endif

                        @elseif ($m->temMidia() && ! auth()->user()?->consultaAnexos())
                            <p class="text-xs text-gray-500">{{ __('ui_2f680817082d') }}</p>
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
                                        <summary class="cursor-pointer text-[10px] uppercase tracking-wide opacity-60">{{ __('ui_9c97a4275987') }}</summary>
                                        <div class="mt-1 whitespace-pre-wrap text-xs italic opacity-90">{{ $m->transcricao }}</div>
                                    </details>
                                @elseif ($m->transcricao_status === 'pendente')
                                    <div class="mt-1 text-[10px] opacity-60">transcrevendo&hellip;</div>
                                @elseif ($m->transcricao_status === 'ignorada')
                                    <div class="mt-1 text-[10px] opacity-60">{{ __('ui_82af20b058f9') }}</div>
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

                        @if (!config('agenda.edition') && \App\Support\Modulos::ativo('ia') && $entrada && !$m->apagada() && (($m->tipo === 'text' && trim((string)$m->corpo)) || ($m->tipo === 'audio' && auth()->user()?->consultaAnexos() && trim((string)$m->transcricao))))
                            <div class="mt-2 border-t border-slate-100 pt-2 dark:border-white/10">
                                @if (isset($traducoes[$m->id]))
                                    <div class="mb-1 flex items-center justify-between gap-3 text-[10px] text-violet-600">
                                        <span>{{ ($idiomasTraducoes[$m->id]??'pt_BR') === 'en_GB' ? __('Tradução para inglês · somente para você') : __('Tradução para português · somente para você') }}</span>
                                        <button type="button" wire:click="ocultarTraducao({{ $m->id }})" class="underline">{{ __('Ocultar') }}</button>
                                    </div>
                                    <p class="whitespace-pre-wrap text-sm text-slate-700 dark:text-gray-200">{{ $traducoes[$m->id] }}</p>
                                @endif
                                    <button type="button" wire:click="traduzirMensagem({{ $m->id }})" wire:loading.attr="disabled" wire:target="traduzirMensagem" class="text-xs font-medium text-violet-600 hover:underline disabled:opacity-50">{{ __('Traduzir para português') }}</button>
                                    <button type="button" wire:click="traduzirMensagem({{ $m->id }}, 'en_GB')" wire:loading.attr="disabled" wire:target="traduzirMensagem" class="ml-3 text-xs font-medium text-violet-600 hover:underline disabled:opacity-50">{{ __('Traduzir para inglês') }}</button>
                                <span wire:loading wire:target="traduzirMensagem({{ $m->id }})" class="ml-2 text-xs text-gray-500">{{ __('Traduzindo…') }}</span>
                                @if (isset($errosTraducao[$m->id]))<p role="alert" class="mt-1 text-xs text-red-600">{{ $errosTraducao[$m->id] }}</p>@endif
                            </div>
                        @endif

                        <div class="mt-1 text-[10px] opacity-65 {{ $continuaDepois ? 'hidden group-hover:block group-focus-within:block' : '' }}">
                            {{ $m->created_at?->format('H:i') }}
                            @unless ($entrada) &middot; {{ $m->status }} @endunless
                            @if ($m->automatica) &middot; automática @endif
                            {{-- Sem este selo, a mensagem apareceria como saída normal sem nome
                                 de atendente, e a diferença entre "o sistema mandou" e "alguém
                                 respondeu por fora" — que muda o que a equipe faz a seguir —
                                 ficaria invisível. --}}
                            @if ($m->por_fora) &middot; pelo celular @endif
                        </div>

                        @if ($m->erro)
                            <div class="mt-1 text-[10px] {{ $entrada ? 'text-red-600 dark:text-red-400' : 'text-red-200' }}">{{ $m->erro }}</div>
                        @endif
                    </div>
                    </div>
                </div>
            @endforeach
            </div>
        </div>
    @else
        {{--
            A TELA VAZIA ENSINA, em vez de so constatar o vazio.

            "Selecione uma conversa" sozinho gastava a maior area livre da tela para dizer o
            que a pessoa ja sabia. Aqui e o unico momento em que ela esta parada, sem conversa
            aberta e sem pressa — exatamente quando cabe aprender que existe teclado.
        --}}
        <div class="grid flex-1 place-items-center bg-slate-50 p-6 text-center dark:bg-gray-950/30">
            <div class="vc-thread-empty max-w-xs space-y-3">
                <x-filament::icon icon="tabler-message-2-heart" class="vc-thread-empty-icon" />
                <h2>{{ __('ui_8450a18b1e5f') }}</h2><p>{{ __('ui_1ae53d4be89e') }}</p>

                <p @if(config('agenda.edition')) hidden @endif class="hidden text-xs text-slate-400 lg:block">
                    {{ __('ui_146610cdd31b') }}
                    <kbd class="rounded border border-slate-300 px-1 font-sans dark:border-white/20">?</kbd>
                    {{ __('ui_347848647c66') }}
                </p>
            </div>
        </div>
    @endif
</div>
