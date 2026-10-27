<div @if(config('agenda.edition')) wire:poll.4s @else wire:poll.30s="$refresh" @endif class="vc-queue-body flex flex-1 flex-col overflow-hidden">

    {{-- linha 1: o balde. Palavra para o dia a dia, icone para consulta. --}}
    <div class="vc-list-tabs vc-toolbar flex shrink-0 items-center gap-1 border-b px-2 py-2">
        @foreach (['novos', 'meus', 'outros'] as $chave)
            <button type="button" wire:key="bl-{{ $chave }}" wire:click="selecionarBalde('{{ $chave }}')" aria-pressed="{{ $balde === $chave ? 'true' : 'false' }}"
                    {{-- Maior no celular: dedo nao acerta alvo de 24px. No computador volta
                         ao tamanho antigo, onde o ponteiro e preciso e o espaco e curto. --}}
                    class="flex items-center gap-1.5 rounded-full px-3 py-2 text-sm font-medium transition lg:px-2.5 lg:py-1 lg:text-xs
                           {{ $balde === $chave
                                ? 'vc-selected'
                                : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' }}">
                {{ __($baldes[$chave]) }}
                @if ($badges[$chave] > 0)
                    {{-- vermelho so em Novos: e a unica fila que cobra resposta --}}
                    <span class="rounded-full px-1.5 text-[10px] font-semibold text-white
                                 {{ $chave === 'novos' ? 'bg-red-600' : 'bg-gray-400 dark:bg-white/30' }}">
                        {{ $badges[$chave] }}
                    </span>
                @endif
            </button>
        @endforeach

        <span class="ml-auto flex items-center gap-1">
            <button type="button" wire:click="selecionarBalde('grupos')" title="{{ __('ui_83f3ebbc6aa9') }}" aria-label="{{ __('ui_83f3ebbc6aa9') }}" aria-pressed="{{ $balde === 'grupos' ? 'true' : 'false' }}"
                    class="relative rounded p-1.5 transition {{ $balde === 'grupos' ? 'vc-selected' : 'text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                </svg>
                @if ($badges['grupos'] > 0)
                    <span class="absolute -right-0.5 -top-0.5 rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white">{{ $badges['grupos'] }}</span>
                @endif
            </button>

            <button type="button" wire:click="selecionarBalde('arquivadas')" title="{{ __('ui_5b73be9f52c8') }}" aria-label="{{ __('ui_5b73be9f52c8') }}" aria-pressed="{{ $balde === 'arquivadas' ? 'true' : 'false' }}"
                    class="rounded p-1.5 transition {{ $balde === 'arquivadas' ? 'vc-selected' : 'text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                </svg>
            </button>
        </span>
    </div>

    {{-- linha 2: o recorte. Ortogonal, combina com qualquer balde. --}}
    <div x-data="{ busca: @js(trim($busca) !== ''), ordem: false }"
         class="vc-toolbar shrink-0 border-b">
        {{-- busca: dentro do mesmo escopo Alpine, senao o icone nao a alcanca --}}
        <div class="vc-list-search"
             {{-- O atalho "/" avisa daqui de fora; abrir e focar tem de acontecer neste
                  escopo, que e quem conhece o estado do campo. O nextTick espera o x-show
                  soltar o display:none — focar antes disso nao faz nada. --}}
             x-on:onchat-abrir-busca.window="busca = true; $nextTick(() => $refs.campoBusca?.focus())">
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="vc-search-icon" />
            <input type="search" aria-label="{{ __('ui_5197896dff7a') }}" wire:model.live.debounce.400ms="busca" x-ref="campoBusca"
                   placeholder="{{ __('ui_50c0c0e7cf44') }}"
                   x-on:keydown.escape="$wire.set('busca', '')"
                   class="w-full rounded border border-gray-300 px-2 py-1.5 text-xs dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
        </div>

        {{--
            QUEBRA LINHA EM VEZ DE ESMAGAR.

            Sao seis controles nesta fileira, e em coluna estreita eles nao cabem. Sem
            flex-wrap o navegador resolve o aperto encolhendo alguem — e eu ja vi as duas
            versoes desse estrago: o rotulo "Não lidas" partido em duas linhas, e depois os
            dois seletores reduzidos a uma setinha sem texto, que e pior, porque controle
            ilegivel nao se sabe nem para que serve.

            Com wrap, ninguem fica ilegivel: quando falta largura, a fileira usa duas linhas.
            Custa 28px de altura, e so quando falta mesmo — e altura e justamente o que esta
            tela acabou de ganhar de volta.
        --}}
        <div class="vc-list-filters flex flex-wrap items-center gap-x-1 gap-y-1.5 px-2 py-1.5">
            {{-- Recorte por canal. So com mais de um: com um canal so, um menu de uma
                 opcao e um clique que nao faz nada. --}}
            @if ($multiCanal)
                <select wire:model.live="canal" aria-label="{{ __('ui_0a37cd397953') }}"
                        class="min-w-0 max-w-32 shrink truncate rounded-full border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200">
                    <option value="">{{ __('ui_44265ea60bfd') }}</option>
                    @foreach ($canais as $c)
                        <option value="{{ $c->id }}">{{ $c->nome }}</option>
                    @endforeach
                </select>
            @endif

            {{-- so aparece quando ha equipe: sem nenhuma, a barra fica como antes --}}
            @if ($equipes->isNotEmpty())
                {{-- min-w-0 e shrink: numa coluna de 320px alguem tem de ceder, e a
                     PRIORIDADE ESTAVA INVERTIDA. Sem isto o seletor mantinha a largura
                     inteira e o aperto sobrava para o chip ao lado, que quebrava "Não
                     lidas" em duas linhas — um chip de duas linhas numa fileira de chips
                     de uma, que e o tipo de detalhe que faz a tela parecer desleixada.
                     Seletor truncado continua legivel: ele mostra a escolha de novo ao
                     abrir. Rotulo de filtro cortado no meio nao tem essa segunda chance. --}}
                <select wire:model.live="equipe" aria-label="{{ __('ui_31dcc0f867f7') }}"
                        class="mr-1 min-w-0 max-w-[8.5rem] rounded-full border-0 bg-gray-100 py-1 pl-2 pr-6 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                    <option value="minhas">{{ __('ui_c4c2345014ea') }}</option>
                    <option value="todas">{{ __('ui_8a3c3440366a') }}</option>
                    {{-- "Sem equipe" so para quem pode ve-las: para quem esta restrito a times,
                         essa opcao devolveria lista vazia sempre, e filtro que nunca acha nada
                         se le como sistema quebrado. --}}
                    @unless ($restritoAoTime)
                        <option value="sem">{{ __('ui_47b0c61bd8ba') }}</option>
                    @endunless
                    @foreach ($equipes as $eq)
                        <option value="{{ $eq->id }}">{{ $eq->nome }}</option>
                    @endforeach
                </select>
            @endif

            {{-- PAR DE ALTERNANCIA, e por isso os dois andam juntos.
                 Sao dois estados de UMA escolha, nao dois botoes independentes. Com o wrap
                 da fileira eles podiam cair em linhas diferentes — "Todas" numa, "Não lidas"
                 na outra — e ai a alternancia deixava de se ler como alternancia: viravam duas
                 acoes soltas, e o estado aceso perdia o contra o que ele contrasta. --}}
            <span class="flex shrink-0 items-center gap-1">
                <button type="button" wire:click="$set('somenteNaoLidas', false)" aria-pressed="{{ ! $somenteNaoLidas ? 'true' : 'false' }}"
                        class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium transition
                               {{ ! $somenteNaoLidas ? 'bg-violet-600 text-white' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' }}">
                    {{ __('ui_8a3c3440366a') }}
                </button>
                <button type="button" wire:click="$set('somenteNaoLidas', true)" aria-pressed="{{ $somenteNaoLidas ? 'true' : 'false' }}"
                        class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium transition
                               {{ $somenteNaoLidas ? 'bg-violet-600 text-white' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' }}">
                    {{ __('ui_6f2f8e376387') }}
                </button>
            </span>

            <span class="vc-list-tools ml-auto flex items-center gap-0.5">


                @if(!config('agenda.edition'))
                {{-- Aviso do sistema operacional. Aparece por cima de qualquer janela, que
                     e o caso que som e titulo nao cobrem: navegador minimizado porque a pessoa
                     foi ao ERP ou a planilha. Tambem por maquina, pelo mesmo motivo do som. --}}
                <button type="button"
                        x-data="{ ligado: false, ocupado: false }"
                        x-init="ligado = window.onchatAvisoLigado ? window.onchatAvisoLigado() : true"
                        x-bind:disabled="ocupado" aria-label="{{ __('ui_ce22081ca9c1') }}"
                        x-on:click="ocupado = true; try { ligado = window.onchatAlternarAviso ? await window.onchatAlternarAviso() : ligado } finally { ocupado = false }"
                        x-bind:title="ligado ? @js(__('Notificações ativadas')) : @js(__('Ativar notificações'))"
                        x-bind:class="ligado ? 'vc-tool-on' : 'vc-tool-off'" x-bind:aria-pressed="ligado"
                        class="rounded p-1.5 transition hover:bg-gray-100 dark:hover:bg-white/5">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                        <rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4" stroke-linecap="round"/>
                    </svg>
                </button>

                {{-- Som do alerta. Vive no navegador (localStorage) e nao no banco: e
                     escolha da MAQUINA, nao da pessoa — o mesmo atendente quer som no
                     posto de atendimento e silencio no notebook de casa. --}}
                <button type="button"
                        x-data="{ ligado: true }"
                        x-init="ligado = window.onchatSomLigado ? window.onchatSomLigado() : true"
                        x-on:click="ligado = window.onchatAlternarSom ? window.onchatAlternarSom() : ligado"
                        x-bind:title="ligado ? @js(__('Silenciar alertas')) : @js(__('Ativar som dos alertas'))" x-bind:aria-label="ligado ? @js(__('Silenciar alertas')) : @js(__('Ativar som dos alertas'))"
                        x-bind:class="ligado ? 'vc-tool-on' : 'vc-tool-off'" x-bind:aria-pressed="ligado"
                        class="rounded p-1.5 transition hover:bg-gray-100 dark:hover:bg-white/5">
                    <svg x-show="ligado" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                        <path d="m11 5-6 4H2v6h3l6 4V5Z" stroke-linejoin="round"/><path d="M15 8a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14" stroke-linecap="round"/>
                    </svg>
                    <svg x-show="! ligado" x-cloak xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                        <path d="m11 5-6 4H2v6h3l6 4V5Z" stroke-linejoin="round"/><path d="m16 9 6 6m0-6-6 6" stroke-linecap="round"/>
                    </svg>
                </button>

                @endif
                {{-- Etiqueta como ICONE, no mesmo grupo de buscar e ordenar: um select
                     a mais na linha de cima nao caberia, e o padrao da tela ja e
                     icone que abre menu. O botao fica aceso enquanto o recorte
                     estiver valendo, senao a lista curta parece fila vazia. --}}
                <div class="relative" x-data="{ etq: false }" x-on:click.outside="etq = false">
                    <button type="button" x-on:click="etq = !etq" title="{{ __('ui_d7dcdd820f34') }}"
                            class="rounded p-1.5 transition hover:bg-gray-100 dark:hover:bg-white/5
                                   {{ $etiquetaAtiva
                                        ? 'bg-violet-600 text-white hover:bg-violet-700'
                                        : 'text-gray-400' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
                        </svg>
                    </button>

                    <div x-show="etq" x-cloak x-transition.opacity
                         class="absolute right-0 z-20 mt-1 w-64 rounded-xl border border-gray-200 bg-white p-2 shadow-lg dark:border-white/10 dark:bg-gray-800">
                        <p class="px-2 py-1 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('ui_d9fa6242cb27') }}</p>

                        <button type="button" wire:click="filtrarEtiqueta(null)" x-on:click="etq = false"
                                class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm transition
                                       {{ ! $etiquetaAtiva
                                            ? 'vc-selected'
                                            : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' }}">
                            {{ __('ui_8a3c3440366a') }}
                        </button>

                        @forelse ($etiquetas as $et)
                            <button type="button" wire:key="fet-{{ $et->id }}"
                                    wire:click="filtrarEtiqueta('{{ $et->id }}')" x-on:click="etq = false"
                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm transition
                                           {{ $etiquetaAtiva?->id === $et->id
                                                ? 'vc-selected'
                                                : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' }}">
                                <span class="h-2 w-2 shrink-0 rounded-full {{ $et->pontinho() }}"></span>
                                <span class="truncate">{{ $et->nome }}</span>
                            </button>
                        @empty
                            <p class="px-2 py-2 text-xs text-gray-400">
                                {{ __('ui_dccb87751f75') }}
                            </p>
                        @endforelse
                    </div>
                </div>

                {{-- ordenar --}}
                <div class="relative" x-on:click.outside="ordem = false">
                    <button type="button" x-on:click="ordem = !ordem" title="{{ __('ui_40de493f7641') }}"
                            x-bind:class="ordem ? 'vc-selected' : 'text-gray-400'"
                            class="rounded p-1.5 transition hover:bg-gray-100 dark:hover:bg-white/5">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h5.25m5.25-.75L17.25 9m0 0L21 12.75M17.25 9v12" />
                        </svg>
                    </button>

                    <div x-show="ordem" x-cloak x-transition.opacity
                         class="absolute right-0 z-20 mt-1 w-72 rounded-xl border border-gray-200 bg-white p-2 shadow-lg dark:border-white/10 dark:bg-gray-800">
                        <p class="px-2 py-1 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('ui_d9be419fd753') }}</p>

                        @foreach ($ordens as $chave => $rotulo)
                            <button type="button" wire:key="or-{{ $chave }}"
                                    wire:click="selecionarOrdem('{{ $chave }}')" x-on:click="ordem = false"
                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm transition
                                           {{ $ordemEfetiva === $chave
                                                ? 'vc-selected'
                                                : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' }}">
                                @if ($chave === 'recentes')
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-5 w-5 shrink-0 text-violet-600">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h5.25m5.25-.75L17.25 9m0 0L21 12.75M17.25 9v12" />
                                    </svg>
                                @else
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-5 w-5 shrink-0 text-violet-600">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                @endif

                                <span class="flex-1">{{ __($rotulo) }}</span>

                                <span class="grid h-4 w-4 shrink-0 place-items-center rounded-full border-2 {{ $ordemEfetiva === $chave ? 'border-violet-600' : 'border-gray-300 dark:border-white/30' }}">
                                    @if ($ordemEfetiva === $chave)
                                        <span class="h-2 w-2 rounded-full bg-violet-600"></span>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </span>
        </div>


    </div>

    <div class="shrink-0 border-b px-3 py-2">
        <button type="button" wire:click="$toggle('somenteSemResposta')" aria-pressed="{{ $somenteSemResposta ? 'true' : 'false' }}" class="rounded-full px-3 py-1 text-xs font-medium {{ $somenteSemResposta ? 'bg-amber-100 text-amber-800' : 'text-gray-500 hover:bg-gray-100' }}">{{ __('Sem resposta') }}</button>
    </div>
    {{-- lista --}}
    <div class="vc-conversation-scroll flex-1 overflow-y-auto">
        <div class="vc-list-caption"><span>{{ __($baldes[$balde] ?? 'Conversas') }}</span><span>{{ $total }}</span></div>
        @forelse ($conversas as $conversa)
            @if(isset($atrasos[$conversa->id]))
                <div class="border-l-[3px] border-amber-400 bg-amber-50 px-4 py-1 text-xs font-medium text-amber-800 dark:bg-amber-500/10">{{ __('Aguardando há :min min', ['min'=>$atrasos[$conversa->id]['minutos']]) }}</div>
            @endif
            <div wire:key="conv-{{ $conversa->id }}" class="relative">
            <button type="button"
                    wire:click="selecionar({{ $conversa->id }})"
                    class="vc-conversation-row w-full border-b border-l-[3px] px-4 py-3 text-left transition-colors hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5
                           {{ $balde === 'arquivadas' && auth()->user()->veTudo() ? 'pr-12' : '' }}
                           {{ $selecionada === $conversa->id
                                ? 'border-l-violet-600 bg-violet-50 dark:border-l-violet-400 dark:bg-violet-500/10'
                                : 'border-l-transparent border-b-gray-100' }}">
                    @if ($conversa->contact->fotoWhatsappUrl())
                        <img src="{{ $conversa->contact->fotoWhatsappUrl() }}" alt="" class="vc-avatar h-8 w-8 shrink-0 rounded-full object-cover">
                    @else
                        <span class="vc-avatar grid h-8 w-8 shrink-0 place-items-center rounded-full text-xs font-semibold {{ $conversa->contact->corAvatar() }}">{{ $conversa->contact->iniciais() }}</span>
                    @endif
                <div class="vc-conversation-summary">
                <div class="flex items-center justify-between gap-2">

                    <span class="flex min-w-0 flex-1 items-center gap-1.5">
                        @if ($conversa->contact->eGrupo())
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                 stroke="currentColor" class="h-3.5 w-3.5 shrink-0 text-gray-400">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72M18 18.72m0 0a5.971 5.971 0 0 1-.941 3.197m0 0A5.995 5.995 0 0 1 12 21.75c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                            </svg>
                        @endif
                        {{-- etiquetas do contato: so a cor aqui. Nome de etiqueta na
                             lista roubaria o espaco do nome do cliente, que e o que
                             o atendente precisa ler primeiro. O nome aparece no
                             painel de detalhes. --}}
                        @if ($conversa->contact->tags->isNotEmpty())
                            <span class="flex shrink-0 items-center gap-0.5"
                                  title="{{ $conversa->contact->tags->pluck('nome')->join(', ') }}">
                                @foreach ($conversa->contact->tags->take(4) as $etiqueta)
                                    <span class="h-2 w-2 rounded-full {{ $etiqueta->pontinho() }}"></span>
                                @endforeach
                                @if ($conversa->contact->tags->count() > 4)
                                    <span class="text-[10px] text-gray-400">+{{ $conversa->contact->tags->count() - 4 }}</span>
                                @endif
                            </span>
                        @endif

                        {{-- De qual plataforma o cliente escreveu. Parar o mouse em cima diz
                             qual canal e qual numero — o icone sozinho nao distingue tres
                             numeros de WhatsApp, e e isso que o Rafael tem hoje. --}}
                        @if ($conversa->channel)
                            <span class="flex shrink-0 items-center"
                                  title="{{ $conversa->channel->rotulo() }}">
                                @include('livewire.inbox.partials.icone-plataforma', [
                                    'plataforma' => $conversa->channel->plataforma(),
                                    'classe'     => 'h-3.5 w-3.5',
                                ])
                            </span>
                        @endif

                        @if ($conversa->fixadaPara(auth()->user()))
                            <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"
                                 class="h-3 w-3 shrink-0 text-amber-500" title="{{ __('ui_82ed4f2a2df6') }}">
                                <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z" />
                            </svg>
                        @endif

                        <span class="vc-contact-name truncate font-medium text-gray-800 dark:text-gray-100">
                            {{ $conversa->contact->nomeExibicao() }}
                        </span>
                    </span>
                    {{--
                        MENCAO GANHA DO CONTADOR.

                        "12 nao lidas" num grupo nao diz nada — grupo sempre tem nao lidas. O
                        que muda o dia e "te chamaram ali". Entao quando ha mencao pendente, a
                        marca vira @ em ambar, e o numero sai da frente: dois sinais no mesmo
                        canto competem, e quem perde e o que importa.
                    --}}
                    @if ($conversa->mencao_em)
                        <span class="flex shrink-0 items-center gap-1 rounded-full bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white"
                              title="{{ __('ui_86132c62ceeb') }}">
                            @
                            @if ($conversa->nao_lidas > 0)
                                <span class="opacity-90">{{ $conversa->nao_lidas }}</span>
                            @endif
                        </span>
                    @elseif ($conversa->nao_lidas > 0)
                        <span class="shrink-0 rounded-full bg-violet-600 px-2 py-0.5 text-xs text-white">{{ $conversa->nao_lidas }}</span>
                    @endif
                </div>

                @php
                    $ultima = $conversa->ultimaMensagem;
                    $previa = match (true) {
                        ! $ultima                   => null,
                        $ultima->tipo === 'text'    => $ultima->corpo,
                        $ultima->tipo === 'image'   => 'Foto',
                        $ultima->tipo === 'video'   => 'Video',
                        $ultima->tipo === 'audio'   => 'Audio',
                        $ultima->tipo === 'sticker' => 'Figurinha',
                        default                     => $ultima->media_nome ?: 'Documento',
                    };
                @endphp
                @if ($previa)
                    <div class="truncate text-xs {{ $conversa->mencao_em ? 'text-amber-700 dark:text-amber-300' : 'text-gray-600 dark:text-gray-400' }}">
                        @if ($conversa->mencao_em)
                            <span class="font-semibold">{{ __('ui_1e84007d90c8') }}</span> &middot;
                        @endif
                        @unless ($ultima->entrada()) <span class="opacity-60">voce:</span> @endunless
                        {{ \Illuminate\Support\Str::limit($previa, 44) }}
                    </div>
                @endif

                <div class="flex items-center justify-between gap-2 text-xs text-gray-400">
                    @if ($balde === 'arquivadas')
                        <span title="atendimento de {{ $conversa->created_at?->format('d/m/Y H:i') }} a {{ $conversa->ultima_msg_em?->format('d/m/Y H:i') }}">
                            {{ $conversa->created_at?->format('d/m H:i') }} &rarr; {{ $conversa->ultima_msg_em?->format('d/m H:i') }}
                        </span>
                        <span class="shrink-0">{{ $conversa->messages_count }} msg</span>
                    @else
                        <span class="flex min-w-0 items-center gap-1.5">
                            {{-- De qual numero e esta conversa. So quando a lista MISTURA
                                 canais: com todas vindas do mesmo numero, a marca se repetiria
                                 em toda linha sem separar nada. --}}
                            @if ($marcarCanal && $conversa->channel)
                                <span class="flex shrink-0 items-center gap-1"
                                      title="Canal: {{ $conversa->channel->rotulo() }}">
                                    <span class="h-2 w-2 rounded-full {{ $conversa->channel->cor() }}"></span>
                                    {{-- O NOME SO EM TELA LARGA. Numa coluna de 320px, um
                                         numero cortado em "+55419849199…" gasta um terco da
                                         linha para nao distinguir nada: todo canal de WhatsApp
                                         comeca igual, e o que diferencia esta justamente no
                                         fim, que e o pedaco que a reticencia come. O pontinho
                                         colorido separa os canais em qualquer largura, e o
                                         numero inteiro continua ao parar o mouse. --}}
                                    <span class="hidden max-w-24 truncate xl:inline">{{ $conversa->channel->nomeCurto() }}</span>
                                </span>
                                <span class="shrink-0 opacity-40">&middot;</span>
                            @endif
                            <span class="truncate">{{ $conversa->ultima_msg_em?->diffForHumans() }}</span>

                            {{-- Ha quanto tempo ESPERA RESPOSTA. Conta da ultima mensagem do
                                 cliente, e some quando a ultima palavra foi nossa: ai a bola
                                 esta com ele, e marcar atraso seria inventar culpa. --}}
                            @php $esperando = \App\Livewire\Inbox\ConversationList::esperandoHa($conversa); @endphp
                            @if ($esperando !== null && $esperando >= 30)
                                <span class="shrink-0 rounded px-1 font-medium {{ $esperando >= 120 ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' }}"
                                      title="Sem resposta há {{ $esperando }} minuto(s)">
                                    {{ $esperando >= 60 ? intdiv($esperando, 60).'h' : $esperando.'min' }}
                                </span>
                            @endif
                        </span>
                        @if ($conversa->team)
                            <span class="truncate">{{ $conversa->team->nome }}</span>
                        @elseif ($conversa->atendente)
                            <span class="truncate">{{ $conversa->atendente->name }}</span>
                        @endif
                    @endif
                </div>
                </div>
            </button>
            @if ($balde === 'arquivadas' && auth()->user()->veTudo())
                <button type="button"
                        wire:click.stop="excluirArquivada({{ $conversa->id }})"
                        wire:confirm="Excluir definitivamente esta conversa arquivada? Todas as mensagens, notas e mídias deste atendimento serão apagadas. Esta ação não pode ser desfeita."
                        title="{{ __('ui_89e834e0883c') }}"
                        aria-label="Excluir conversa arquivada de {{ $conversa->contact->nomeExibicao() }}"
                        class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673A2.25 2.25 0 0 1 15.916 21.75H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0V4.477c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                    </svg>
                </button>
            @endif
            </div>
        @empty
            <div class="vc-list-empty" role="status"><x-filament::icon icon="tabler-message-2-heart" class="vc-empty-icon" /><h3>{{ __('ui_cac1feaa3c9e') }}</h3><p>
                @if (trim($busca) !== '')
                    Nada encontrado para "{{ $busca }}".
                @elseif ($etiquetaAtiva)
                    {{-- Diz o motivo: lista vazia com filtro ligado e lida como fila
                         vazia, e o atendente vai embora achando que nao ha trabalho. --}}
                    Nenhuma conversa com a etiqueta <strong>{{ $etiquetaAtiva->nome }}</strong> aqui.
                @elseif ($somenteNaoLidas)
                    {{ __('ui_daa27ee3d179') }}
                @else
                    @switch($balde)
                        @case('novos') {{ __('ui_f123ca857714') }} @break
                        @case('meus') {{ __('ui_d8442bf1fd40') }} @break
                        @case('outros') {{ __('ui_1f342c845403') }} @break
                        @case('grupos') {{ __('ui_ad052aadfc99') }} @break
                        @default {{ __('ui_93a8484af201') }}
                    @endswitch

                    {{--
                        E O MOTIVO POSSIVEL, quando o acesso e restrito.

                        Lista vazia por falta de trabalho e lista vazia por falta de permissao
                        sao a mesma imagem na tela e coisas opostas na vida: uma manda ir tomar
                        um cafe, a outra manda chamar o administrador. Sem esta linha, o
                        atendente novo passa a manha achando que o dia esta calmo.
                    --}}
                    @if ($acessoRestrito)
                        <span class="mt-1 block text-xs text-gray-400">
                            {{ __('ui_d96b501a93b9') }}
                            @if ($restritoAoTime)
                                {{ __('ui_951a9cd3a52b') }}
                            @endif
                        </span>
                    @endif
                @endif
            </p>
            @if (trim($busca) !== '' || $etiquetaAtiva || $somenteNaoLidas || $canal)
                <button type="button" class="vc-clear-filters" title="{{ __('ui_df612b347932') }}" wire:click="limparFiltros">{{ __('ui_d22f68306a54') }}</button>
            @endif
            </div>
        @endforelse

        {{-- Nunca truncar calado: se sobrou fila, ela precisa estar visivel. --}}
        @if ($restantes > 0)
            <button type="button" wire:click="carregarMais"
                    class="block w-full border-b border-gray-100 px-4 py-3 text-center text-xs text-gray-600 hover:bg-gray-50 dark:border-white/5 dark:text-gray-300 dark:hover:bg-white/5">
                {{ __('ui_c108a245d7a7') }}
                <span class="opacity-70">({{ $conversas->count() }} de {{ $total }})</span>
            </button>
        @elseif ($total > \App\Livewire\Inbox\ConversationList::PAGINA)
            <p class="px-4 py-3 text-center text-[11px] text-gray-400">{{ $total }} conversas &middot; fim da lista</p>
        @endif
    </div>
</div>
