<div class="flex flex-1 flex-col overflow-hidden">

    {{-- linha 1: o balde. Palavra para o dia a dia, icone para consulta. --}}
    <div class="flex shrink-0 items-center gap-1 border-b border-gray-200 px-2 py-2 dark:border-white/10">
        @foreach (['novos', 'meus', 'outros'] as $chave)
            <button type="button" wire:key="bl-{{ $chave }}" wire:click="selecionarBalde('{{ $chave }}')"
                    {{-- Maior no celular: dedo nao acerta alvo de 24px. No computador volta
                         ao tamanho antigo, onde o ponteiro e preciso e o espaco e curto. --}}
                    class="flex items-center gap-1.5 rounded-full px-3 py-2 text-sm font-medium transition lg:px-2.5 lg:py-1 lg:text-xs
                           {{ $balde === $chave
                                ? 'bg-gray-200 text-gray-900 dark:bg-white/15 dark:text-white'
                                : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' }}">
                {{ $baldes[$chave] }}
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
            <button type="button" wire:click="selecionarBalde('grupos')" title="Grupos"
                    class="relative rounded p-1.5 transition {{ $balde === 'grupos' ? 'bg-gray-200 text-gray-900 dark:bg-white/15 dark:text-white' : 'text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                </svg>
                @if ($badges['grupos'] > 0)
                    <span class="absolute -right-0.5 -top-0.5 rounded-full bg-gray-400 px-1 text-[9px] font-semibold text-white dark:bg-white/40">{{ $badges['grupos'] }}</span>
                @endif
            </button>

            <button type="button" wire:click="selecionarBalde('arquivadas')" title="Arquivadas"
                    class="rounded p-1.5 transition {{ $balde === 'arquivadas' ? 'bg-gray-200 text-gray-900 dark:bg-white/15 dark:text-white' : 'text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                </svg>
            </button>
        </span>
    </div>

    {{-- linha 2: o recorte. Ortogonal, combina com qualquer balde. --}}
    <div x-data="{ busca: @js(trim($busca) !== ''), ordem: false }"
         class="shrink-0 border-b border-gray-200 dark:border-white/10">

        <div class="flex items-center gap-1 px-2 py-1.5">
            {{-- Recorte por canal. So com mais de um: com um canal so, um menu de uma
                 opcao e um clique que nao faz nada. --}}
            @if ($multiCanal)
                <select wire:model.live="canal"
                        class="min-w-0 max-w-32 shrink truncate rounded-full border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200">
                    <option value="">Todos os canais</option>
                    @foreach ($canais as $c)
                        <option value="{{ $c->id }}">{{ $c->nome }}</option>
                    @endforeach
                </select>
            @endif

            {{-- so aparece quando ha equipe: sem nenhuma, a barra fica como antes --}}
            @if ($equipes->isNotEmpty())
                <select wire:model.live="equipe"
                        class="mr-1 max-w-[8.5rem] rounded-full border-0 bg-gray-100 py-1 pl-2 pr-6 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                    <option value="minhas">Minhas equipes</option>
                    <option value="todas">Todas</option>
                    <option value="sem">Sem equipe</option>
                    @foreach ($equipes as $eq)
                        <option value="{{ $eq->id }}">{{ $eq->nome }}</option>
                    @endforeach
                </select>
            @endif

            <button type="button" wire:click="$set('somenteNaoLidas', false)"
                    class="rounded-full px-2.5 py-1 text-xs font-medium transition
                           {{ ! $somenteNaoLidas ? 'bg-indigo-600 text-white' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' }}">
                Todas
            </button>
            <button type="button" wire:click="$set('somenteNaoLidas', true)"
                    class="rounded-full px-2.5 py-1 text-xs font-medium transition
                           {{ $somenteNaoLidas ? 'bg-indigo-600 text-white' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' }}">
                Não lidas
            </button>

            <span class="ml-auto flex items-center gap-0.5">
                <button type="button" title="Buscar"
                        x-on:click="busca = !busca; if (!busca) { $wire.set('busca', '') }"
                        x-bind:class="busca ? 'bg-gray-200 text-gray-900 dark:bg-white/15 dark:text-white' : 'text-gray-400'"
                        class="rounded p-1.5 transition hover:bg-gray-100 dark:hover:bg-white/5">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </button>

                {{-- Aviso do sistema operacional. Aparece por cima de qualquer janela, que
                     e o caso que som e titulo nao cobrem: navegador minimizado porque a pessoa
                     foi ao ERP ou a planilha. Tambem por maquina, pelo mesmo motivo do som. --}}
                <button type="button"
                        x-data="{ ligado: true }"
                        x-init="ligado = window.onchatAvisoLigado ? window.onchatAvisoLigado() : true"
                        x-on:click="ligado = window.onchatAlternarAviso ? await window.onchatAlternarAviso() : ligado"
                        x-bind:title="ligado ? 'Avisos na tela ligados — clique para desligar' : 'Avisos na tela desligados — clique para ligar'"
                        x-bind:class="ligado ? 'text-gray-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'"
                        class="rounded p-1.5 transition hover:bg-gray-100 dark:hover:bg-white/5">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6Z" />
                    </svg>
                </button>

                {{-- Som do alerta. Vive no navegador (localStorage) e nao no banco: e
                     escolha da MAQUINA, nao da pessoa — o mesmo atendente quer som no
                     posto de atendimento e silencio no notebook de casa. --}}
                <button type="button"
                        x-data="{ ligado: true }"
                        x-init="ligado = window.onchatSomLigado ? window.onchatSomLigado() : true"
                        x-on:click="ligado = window.onchatAlternarSom ? window.onchatAlternarSom() : ligado"
                        x-bind:title="ligado ? 'Som ligado — clique para silenciar' : 'Som desligado — clique para ligar'"
                        x-bind:class="ligado ? 'text-gray-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'"
                        class="rounded p-1.5 transition hover:bg-gray-100 dark:hover:bg-white/5">
                    <svg x-show="ligado" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                    </svg>
                    <svg x-show="! ligado" x-cloak xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.143 17.082a24.248 24.248 0 0 0 3.844.148m-3.844-.148a23.856 23.856 0 0 1-5.455-1.31 8.964 8.964 0 0 0 2.3-5.542m3.155 6.852a3 3 0 0 0 5.667 1.97m1.965-2.277L3 3m18 18-4.5-4.5" />
                    </svg>
                </button>

                {{-- Etiqueta como ICONE, no mesmo grupo de buscar e ordenar: um select
                     a mais na linha de cima nao caberia, e o padrao da tela ja e
                     icone que abre menu. O botao fica aceso enquanto o recorte
                     estiver valendo, senao a lista curta parece fila vazia. --}}
                <div class="relative" x-data="{ etq: false }" x-on:click.outside="etq = false">
                    <button type="button" x-on:click="etq = !etq" title="Filtrar por etiqueta"
                            class="rounded p-1.5 transition hover:bg-gray-100 dark:hover:bg-white/5
                                   {{ $etiquetaAtiva
                                        ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                        : 'text-gray-400' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
                        </svg>
                    </button>

                    <div x-show="etq" x-cloak x-transition.opacity
                         class="absolute right-0 z-20 mt-1 w-64 rounded-xl border border-gray-200 bg-white p-2 shadow-lg dark:border-white/10 dark:bg-gray-800">
                        <p class="px-2 py-1 text-xs font-semibold text-gray-500 dark:text-gray-400">Filtrar por etiqueta:</p>

                        <button type="button" wire:click="filtrarEtiqueta(null)" x-on:click="etq = false"
                                class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm transition
                                       {{ ! $etiquetaAtiva
                                            ? 'bg-indigo-50 text-indigo-900 dark:bg-indigo-500/15 dark:text-indigo-200'
                                            : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' }}">
                            Todas
                        </button>

                        @forelse ($etiquetas as $et)
                            <button type="button" wire:key="fet-{{ $et->id }}"
                                    wire:click="filtrarEtiqueta('{{ $et->id }}')" x-on:click="etq = false"
                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm transition
                                           {{ $etiquetaAtiva?->id === $et->id
                                                ? 'bg-indigo-50 text-indigo-900 dark:bg-indigo-500/15 dark:text-indigo-200'
                                                : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' }}">
                                <span class="h-2 w-2 shrink-0 rounded-full {{ $et->pontinho() }}"></span>
                                <span class="truncate">{{ $et->nome }}</span>
                            </button>
                        @empty
                            <p class="px-2 py-2 text-xs text-gray-400">
                                Nenhuma etiqueta cadastrada.
                            </p>
                        @endforelse
                    </div>
                </div>

                {{-- ordenar --}}
                <div class="relative" x-on:click.outside="ordem = false">
                    <button type="button" x-on:click="ordem = !ordem" title="Ordenar conversas"
                            x-bind:class="ordem ? 'bg-gray-200 text-gray-900 dark:bg-white/15 dark:text-white' : 'text-gray-400'"
                            class="rounded p-1.5 transition hover:bg-gray-100 dark:hover:bg-white/5">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h5.25m5.25-.75L17.25 9m0 0L21 12.75M17.25 9v12" />
                        </svg>
                    </button>

                    <div x-show="ordem" x-cloak x-transition.opacity
                         class="absolute right-0 z-20 mt-1 w-72 rounded-xl border border-gray-200 bg-white p-2 shadow-lg dark:border-white/10 dark:bg-gray-800">
                        <p class="px-2 py-1 text-xs font-semibold text-gray-500 dark:text-gray-400">Ordenar por:</p>

                        @foreach ($ordens as $chave => $rotulo)
                            <button type="button" wire:key="or-{{ $chave }}"
                                    wire:click="selecionarOrdem('{{ $chave }}')" x-on:click="ordem = false"
                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm transition
                                           {{ $ordemEfetiva === $chave
                                                ? 'bg-indigo-50 text-indigo-900 dark:bg-indigo-500/15 dark:text-indigo-200'
                                                : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' }}">
                                @if ($chave === 'recentes')
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-5 w-5 shrink-0 text-indigo-500">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h5.25m5.25-.75L17.25 9m0 0L21 12.75M17.25 9v12" />
                                    </svg>
                                @else
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-5 w-5 shrink-0 text-indigo-500">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                @endif

                                <span class="flex-1">{{ $rotulo }}</span>

                                <span class="grid h-4 w-4 shrink-0 place-items-center rounded-full border-2 {{ $ordemEfetiva === $chave ? 'border-indigo-600' : 'border-gray-300 dark:border-white/30' }}">
                                    @if ($ordemEfetiva === $chave)
                                        <span class="h-2 w-2 rounded-full bg-indigo-600"></span>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </span>
        </div>

        {{-- busca: dentro do mesmo escopo Alpine, senao o icone nao a alcanca --}}
        <div x-show="busca" x-cloak class="px-2 pb-2">
            <input type="text" wire:model.live.debounce.400ms="busca"
                   placeholder="Buscar nome, telefone ou mensagem"
                   x-on:keydown.escape="busca = false; $wire.set('busca', '')"
                   class="w-full rounded border border-gray-300 px-2 py-1.5 text-xs dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
        </div>
    </div>

    {{-- lista --}}
    <div class="flex-1 overflow-y-auto">
        @forelse ($conversas as $conversa)
            <button type="button" wire:key="conv-{{ $conversa->id }}"
                    wire:click="selecionar({{ $conversa->id }})"
                    class="block w-full border-b border-gray-100 px-4 py-3 text-left hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5
                           {{ $selecionada === $conversa->id ? 'bg-emerald-50 dark:bg-emerald-500/10' : '' }}">
                <div class="flex items-center justify-between gap-2">
                    <span class="flex min-w-0 items-center gap-1.5">
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
                                 class="h-3 w-3 shrink-0 text-amber-500" title="Fixada no topo">
                                <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z" />
                            </svg>
                        @endif

                        <span class="truncate font-medium text-gray-800 dark:text-gray-100">
                            {{ $conversa->contact->nomeExibicao() }}
                        </span>
                    </span>
                    @if ($conversa->nao_lidas > 0)
                        <span class="shrink-0 rounded-full bg-emerald-600 px-2 py-0.5 text-xs text-white">{{ $conversa->nao_lidas }}</span>
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
                    <div class="truncate text-xs text-gray-600 dark:text-gray-400">
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
                            {{-- De qual numero e esta conversa. So com mais de um canal: com um
                                 so, a marca nao separaria nada. --}}
                            @if ($multiCanal && $conversa->channel)
                                <span class="flex shrink-0 items-center gap-1"
                                      title="Canal: {{ $conversa->channel->rotulo() }}">
                                    <span class="h-2 w-2 rounded-full {{ $conversa->channel->cor() }}"></span>
                                    <span class="max-w-24 truncate">{{ $conversa->channel->nomeCurto() }}</span>
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
            </button>
        @empty
            <p class="p-4 text-sm text-gray-500 dark:text-gray-400">
                @if (trim($busca) !== '')
                    Nada encontrado para "{{ $busca }}".
                @elseif ($etiquetaAtiva)
                    {{-- Diz o motivo: lista vazia com filtro ligado e lida como fila
                         vazia, e o atendente vai embora achando que nao ha trabalho. --}}
                    Nenhuma conversa com a etiqueta <strong>{{ $etiquetaAtiva->nome }}</strong> aqui.
                @elseif ($somenteNaoLidas)
                    Nada sem ler aqui.
                @else
                    @switch($balde)
                        @case('novos') Nenhuma conversa aguardando resposta. @break
                        @case('meus') Você não está atendendo ninguém agora. @break
                        @case('outros') Ninguém mais atendendo agora. @break
                        @case('grupos') Nenhum grupo com conversa. @break
                        @default Nenhuma conversa arquivada.
                    @endswitch
                @endif
            </p>
        @endforelse

        {{-- Nunca truncar calado: se sobrou fila, ela precisa estar visivel. --}}
        @if ($restantes > 0)
            <button type="button" wire:click="carregarMais"
                    class="block w-full border-b border-gray-100 px-4 py-3 text-center text-xs text-gray-600 hover:bg-gray-50 dark:border-white/5 dark:text-gray-300 dark:hover:bg-white/5">
                carregar mais
                <span class="opacity-70">({{ $conversas->count() }} de {{ $total }})</span>
            </button>
        @elseif ($total > \App\Livewire\Inbox\ConversationList::PAGINA)
            <p class="px-4 py-3 text-center text-[11px] text-gray-400">{{ $total }} conversas &middot; fim da lista</p>
        @endif
    </div>
</div>
