<div class="border-t border-slate-200 p-3 dark:border-white/10">
    @if ($conversationId)
        {{-- Janela de 24h. So aparece em canal que TEM janela: avisar de um limite
             onde ele nao vale ensina o atendente a ignorar o aviso, inclusive quando
             for verdade. --}}
        @if ($exigeJanela)
            @if ($janelaAberta)
                <div class="mb-2 flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    Janela de atendimento aberta — fecha em <strong>{{ $janelaRestante }}</strong>.
                </div>
            @else
                <div class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="font-semibold">Janela de 24 horas fechada.</p>
                    <p class="mt-0.5">
                        Neste canal, passadas 24h da última mensagem do cliente, só um
                        <strong>template aprovado</strong> pode sair — texto livre é recusado pela Meta.
                        Escolha um template abaixo.
                    </p>
                </div>
            @endif
        @endif
        {{-- previa do anexo antes de enviar --}}
        @if ($anexo)
            <div class="mb-2 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-2 dark:border-white/10 dark:bg-white/5">
                @php
                    $mime = method_exists($anexo, 'getMimeType') ? ($anexo->getMimeType() ?? '') : '';
                    $nome = method_exists($anexo, 'getClientOriginalName') ? $anexo->getClientOriginalName() : 'arquivo';
                @endphp

                @if (str_starts_with($mime, 'image/'))
                    <img src="{{ $anexo->temporaryUrl() }}" alt="" class="h-14 w-14 rounded object-cover">
                @elseif (str_starts_with($mime, 'audio/'))
                    <span class="grid h-14 w-14 place-items-center rounded bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">&#9835;</span>
                @elseif (str_starts_with($mime, 'video/'))
                    <span class="grid h-14 w-14 place-items-center rounded bg-slate-200 text-slate-600 dark:bg-white/10 dark:text-gray-300">&#9654;</span>
                @else
                    <span class="grid h-14 w-14 place-items-center rounded bg-slate-200 text-slate-600 dark:bg-white/10 dark:text-gray-300">&#128196;</span>
                @endif

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm text-slate-700 dark:text-gray-100">{{ $nome }}</p>
                    <p class="text-xs text-slate-500 dark:text-gray-400">{{ $mime ?: 'arquivo' }}</p>
                </div>

                <button type="button" wire:click="removerAnexo"
                        class="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">
                    remover
                </button>
            </div>
        @endif

        <div wire:loading wire:target="anexo" class="mb-2 text-xs text-slate-500 dark:text-gray-400">
            enviando arquivo para o servidor&hellip;
        </div>

        @if ($nota)
            <div class="mb-2 rounded-lg border border-amber-300 bg-amber-50 px-2 py-1.5 text-xs text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                <div class="flex items-center gap-2">
                    <span>&#128274;</span>
                    <span>Nota interna: fica no histórico da conversa e <strong>não é enviada ao cliente</strong>. Escreva <strong>@</strong> para chamar alguém.</span>
                </div>

                {{--
                    QUEM VAI SER AVISADO, MOSTRADO ANTES DE SALVAR.

                    Aviso que a pessoa descobre depois de mandar e aviso que ela nao queria — e
                    aqui isso importa mais que o normal, porque o texto da nota viaja dentro do
                    aviso. Ver os nomes antes e a diferenca entre chamar alguem e vazar um
                    comentario para quem nao era.
                --}}
                @php
                    $chamados = collect($mencionaveis)->whereIn('id', $mencionados);
                @endphp

                @if ($chamados->isNotEmpty())
                    <div class="mt-1.5 flex flex-wrap items-center gap-1 border-t border-amber-200 pt-1.5 dark:border-amber-500/30">
                        <span class="text-amber-700 dark:text-amber-300">Avisando:</span>
                        @foreach ($chamados as $quem)
                            <button type="button"
                                    wire:click="$set('mencionados', {{ json_encode(array_values(array_diff($mencionados, [$quem['id']]))) }})"
                                    class="group flex items-center gap-1 rounded-full bg-amber-200/70 px-2 py-0.5 font-medium text-amber-900 hover:bg-amber-300 dark:bg-amber-500/20 dark:text-amber-200 dark:hover:bg-amber-500/30"
                                    title="Não avisar {{ $quem['nome'] }}">
                                {{ $quem['primeiro'] }}
                                <span class="text-amber-700 group-hover:text-amber-900 dark:text-amber-300 dark:group-hover:text-amber-100">&times;</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($anotada = $this->mensagemAnotada())
                <div class="mb-2 flex items-start gap-2 rounded-lg border-l-4 border-amber-500 bg-amber-50 px-3 py-2 dark:bg-amber-500/10">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-semibold text-amber-800 dark:text-amber-200">Nota vinculada a esta mensagem</div>
                        <div class="truncate text-xs text-amber-700/80 dark:text-amber-300/80">{{ $anotada->resumo(120) }}</div>
                    </div>
                    <button type="button" wire:click="cancelarAnotacao" title="Remover vínculo"
                            class="rounded px-2 text-lg leading-none text-amber-600 hover:bg-amber-100 dark:hover:bg-amber-500/20">&times;</button>
                </div>
            @endif
        @endif

        @php
            // Canal com janela, janela fechada e nao e nota: texto livre nao sai daqui.
            // A tela troca de modo em vez de aceitar algo que a Meta vai recusar.
            $modoTemplate = $exigeJanela && ! $janelaAberta && ! $nota;
        @endphp

        @if ($modoTemplate)
            <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                @if ($templatesDisponiveis->isEmpty())
                    <p class="text-xs text-slate-500 dark:text-gray-400">
                        Nenhum template disponível neste canal. Eles são criados no painel da Meta
                        e trazidos em <strong>Configurações &rarr; Templates da Meta</strong>.
                    </p>
                @elseif (! $templateEscolhido)
                    <p class="mb-2 text-xs font-medium text-slate-600 dark:text-gray-300">
                        Escolha um template aprovado — cada envio é cobrado pela Meta.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($templatesDisponiveis as $disponivel)
                            <button type="button" wire:key="tpl-{{ $disponivel->id }}"
                                    wire:click="escolherTemplate({{ $disponivel->id }})"
                                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-left text-xs hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/5">
                                <span class="block font-medium text-slate-800 dark:text-gray-100">{{ $disponivel->nome }}</span>
                                <span class="block text-slate-500 dark:text-gray-400">
                                    {{ $disponivel->idioma }}
                                    @if ($disponivel->variaveis)
                                        &middot; {{ $disponivel->variaveis }} campo(s) a preencher
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </div>
                @else
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-slate-700 dark:text-gray-200">
                            {{ $templateEscolhido->nome }} &middot; {{ $templateEscolhido->idioma }}
                        </span>
                        <button type="button" wire:click="limparTemplate"
                                class="text-xs text-slate-500 underline hover:text-slate-700 dark:text-gray-400">
                            trocar
                        </button>
                    </div>

                    @if ($templateEscolhido->variaveis)
                        <div class="mb-2 space-y-2">
                            @for ($i = 0; $i < $templateEscolhido->variaveis; $i++)
                                <input type="text" wire:model.live.debounce.400ms="valoresTemplate.{{ $i }}"
                                       placeholder="valor {{ $i + 1 }}"
                                       class="w-full rounded border border-slate-300 px-3 py-1.5 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                            @endfor
                        </div>
                    @endif

                    {{-- Previa: o atendente le o que o cliente vai ler ANTES de gastar um
                         envio cobrado. Sem isso, conferir o texto exigiria enviar. --}}
                    <div class="mb-2 whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700 dark:bg-white/5 dark:text-gray-200">{{ $templateEscolhido->renderizar($valoresTemplate) }}</div>

                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="enviarTemplate"
                                wire:loading.attr="disabled" wire:target="enviarTemplate"
                                class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                            Enviar template
                        </button>
                        <button type="button" wire:click="alternarNota"
                                class="text-xs text-slate-500 underline hover:text-slate-700 dark:text-gray-400">
                            escrever nota interna
                        </button>
                    </div>
                @endif

                @error('template') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        @else
        {{-- O que esta sendo citado, acima do campo. Sem esta faixa, escolher "responder" e
             uma acao sem retorno visivel: a pessoa nao sabe se pegou, nem qual pegou, e so
             descobre depois de enviar. --}}
        @if ($citada = $this->mensagemCitada())
            <div class="mb-2 flex items-start gap-2 rounded-lg border-l-4 border-emerald-500 bg-slate-50 px-3 py-2 dark:bg-white/5">
                <div class="min-w-0 flex-1">
                    <div class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                        Respondendo {{ $citada->entrada() ? ($citada->remetente_nome ?: 'o cliente') : 'você mesmo' }}
                    </div>
                    <div class="truncate text-xs text-slate-600 dark:text-gray-300">{{ $citada->resumo(120) }}</div>
                </div>

                <button type="button" wire:click="cancelarResposta"
                        class="shrink-0 rounded px-2 text-lg leading-none text-slate-400 hover:text-slate-700 dark:text-gray-500 dark:hover:text-gray-200"
                        title="Não citar" aria-label="Cancelar a citação">&times;</button>
            </div>
        @endif

        {{--
            RASCUNHO POR CONVERSA, guardado no navegador.

            Antes, trocar de conversa apagava o texto — o que era o menos ruim entre duas
            opcoes ruins, porque a outra era o texto ATRAVESSAR para a conversa seguinte e ser
            mandado para o cliente errado. Agora cada conversa lembra o seu.

            No navegador e nao no banco: rascunho e da MAQUINA de quem escreve. Metade de uma
            frase minha aparecendo no computador de outro atendente seria constrangedor, e
            gravar a cada tecla seria uma escrita no banco por letra digitada.
        --}}
        <div wire:key="rascunho-{{ $conversationId }}"
             x-data="{ chave: 'onchat.rascunho.{{ $conversationId }}' }"
             x-init="if (! $wire.corpo) { const g = localStorage.getItem(chave); if (g) $wire.corpo = g; }"
             x-effect="$wire.corpo ? localStorage.setItem(chave, $wire.corpo) : localStorage.removeItem(chave)">
        </div>

        <form wire:submit="enviar" class="flex items-end gap-2 {{ $nota ? 'rounded-lg bg-amber-50/60 p-1.5 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30' : '' }}">
            {{-- anexar: escondido em nota interna, o arquivo iria para o cliente --}}
            @unless ($nota)
                <label class="grid h-11 w-11 shrink-0 cursor-pointer place-items-center rounded-xl border border-slate-300 bg-white text-slate-600 shadow-sm transition duration-150 hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-700 focus-within:ring-2 focus-within:ring-emerald-500/40 dark:border-white/15 dark:bg-white/[0.03] dark:text-slate-300 dark:hover:border-emerald-500/60 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-300"
                       title="Anexar arquivo" aria-label="Anexar imagem, vídeo, áudio ou documento">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 11.25-8.69 8.69a6 6 0 0 1-8.49-8.49l9.19-9.19a4.5 4.5 0 0 1 6.36 6.36l-9.19 9.19a3 3 0 0 1-4.24-4.24l8.69-8.69" />
                    </svg>
                    <input type="file" wire:model="anexo" class="hidden"
                           accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
                </label>
            @endunless

            {{-- modelos de mensagem --}}
            @if ($modelos->isNotEmpty() && ! $nota)
                <div class="relative shrink-0" x-data="{ aberto: false }" x-on:click.outside="aberto = false">
                    <button type="button" x-on:click="aberto = !aberto" title="Modelos de mensagem"
                            aria-label="Abrir modelos de mensagem"
                            class="grid h-11 w-11 place-items-center rounded-xl border border-slate-300 bg-white text-slate-600 shadow-sm transition duration-150 hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40 dark:border-white/15 dark:bg-white/[0.03] dark:text-slate-300 dark:hover:border-emerald-500/60 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-300">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h4.5M6.75 3.75h7.69a2 2 0 0 1 1.42.59l1.8 1.8a2 2 0 0 1 .59 1.42v10.69a2 2 0 0 1-2 2h-9.5a2 2 0 0 1-2-2V5.75a2 2 0 0 1 2-2Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 3.9v3.35a.5.5 0 0 0 .5.5h3.35" />
                        </svg>
                    </button>
                    <div x-show="aberto" x-cloak
                         class="absolute bottom-full left-0 z-20 mb-1 w-72 max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-white/10 dark:bg-gray-800">
                        @foreach ($modelos as $modelo)
                            <button type="button" wire:key="mod-{{ $modelo->id }}"
                                    wire:click="usarModelo({{ $modelo->id }})" x-on:click="aberto = false"
                                    class="block w-full rounded-lg px-2 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/5">
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ $modelo->titulo }}</span>
                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">/{{ $modelo->atalho }} &middot; {{ \Illuminate\Support\Str::limit($modelo->corpo, 48) }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{--
                NOTA DE VOZ COMO NO WHATSAPP: segurar para gravar, soltar para enviar.

                Antes eram tres toques — clicar para gravar, clicar para parar, clicar em
                enviar — e o audio ficava esperando anexado no meio do caminho. Ninguem grava
                audio assim; grava-se falando e soltando.

                Tres jeitos de usar, porque nem todo mundo usa igual:
                  segurar e soltar ......... push-to-talk, envia ao soltar
                  arrastar para cima ....... trava e continua gravando sem segurar
                  toque rapido ............. tambem trava (e o caminho de quem usa teclado,
                                             que nao tem como "segurar")
                  arrastar para a esquerda . cancela
            --}}
            <div x-data="gravadorDeVoz()" class="shrink-0" @if ($nota) hidden @endif>
                {{-- touch-action none: sem isto, arrastar no celular rola a pagina em vez de
                     travar a gravacao. --}}
                <button type="button"
                        x-show="!gravando && !iniciando && !enviando"
                        x-on:pointerdown.prevent="segureParaGravar($event)"
                        style="touch-action: none"
                        aria-label="Gravar mensagem de voz"
                        class="grid h-11 w-11 place-items-center rounded-xl border border-slate-300 bg-white text-slate-600 shadow-sm transition duration-150 hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40 dark:border-white/15 dark:bg-white/[0.03] dark:text-slate-300 dark:hover:border-emerald-500/60 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-300"
                        title="Segure para gravar. Arraste para cima para travar.">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                        <rect x="8.25" y="3" width="7.5" height="12" rx="3.75" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5.75 11.25a6.25 6.25 0 0 0 12.5 0M12 17.5V21m-3 0h6" />
                    </svg>
                </button>

                <div x-show="iniciando" x-cloak
                     class="flex items-center gap-2 rounded border border-slate-300 px-3 py-2 text-sm text-slate-600 dark:border-white/15 dark:text-gray-300">
                    <span class="h-3 w-3 animate-spin rounded-full border-2 border-slate-300 border-t-emerald-600"></span>
                    <span>Liberando microfone…</span>
                </div>

                <div x-show="enviando" x-cloak
                     class="flex items-center gap-2 rounded bg-emerald-600 px-3 py-2 text-sm text-white">
                    <span class="h-3 w-3 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                    <span>Enviando áudio…</span>
                </div>

                {{-- Gravando e ainda segurando: a dica muda conforme o dedo anda, para a
                     pessoa descobrir o gesto no meio do caminho e nao antes. --}}
                <div x-show="gravando && !travado" x-cloak
                     class="flex items-center gap-2 rounded bg-red-600 px-3 py-2 text-sm text-white">
                    <span class="h-2 w-2 animate-pulse rounded-full bg-white"></span>
                    <span x-text="segundos + 's'"></span>
                    <span class="text-[11px] opacity-80"
                          x-text="perto ? 'solte para cancelar' : 'arraste \u2191 para travar'"></span>
                </div>

                {{-- Travado: as maos livres. Enviar manda DIRETO, sem parar antes. --}}
                <div x-show="gravando && travado" x-cloak class="flex items-center gap-1">
                    <button type="button" x-on:click="cancelar()"
                            class="rounded border border-slate-300 px-2 py-2 text-sm text-slate-500 hover:bg-slate-50 dark:border-white/15 dark:text-gray-400 dark:hover:bg-white/5"
                            title="Descartar">&times;</button>

                    <span class="flex items-center gap-1.5 rounded bg-red-600 px-2 py-2 text-sm text-white">
                        <span class="h-2 w-2 animate-pulse rounded-full bg-white"></span>
                        <span x-text="segundos + 's'"></span>
                    </span>

                    <button type="button" x-on:click="pararEEnviar()"
                            class="rounded bg-emerald-600 px-3 py-2 text-sm text-white hover:bg-emerald-700"
                            title="Enviar agora" aria-label="Enviar áudio agora">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 14-8-4.5 16-3-6.5L5 12Zm6.5 1.5L19 4" /></svg>
                    </button>
                </div>

                <div x-show="erro" x-cloak class="flex max-w-xs items-center gap-2 text-xs text-red-600 dark:text-red-300">
                    <span x-text="erro"></span>
                    <button type="button" x-on:click="erro = ''" class="underline">fechar</button>
                </div>
            </div>

            {{--
                Seletor de emoji escrito a mao, com uma lista curta.

                Sem biblioteca de proposito: as prontas trazem alguns megabytes, uma fonte
                inteira de imagens e, quase sempre, um pedido a um servidor de terceiro. E o
                atendente nao precisa de tres mil figurinhas — precisa das vinte que ele usa
                todo dia, na mao.

                A insercao acontece no NAVEGADOR, sem ida ao servidor: emoji e digitacao, e
                digitacao com espera de rede no meio e insuportavel.
            --}}
            @unless ($nota)
                <div class="relative shrink-0" x-data="{ aberto: false }" x-on:click.outside="aberto = false">
                    <button type="button" x-on:click="aberto = !aberto"
                            title="Emojis" aria-label="Escolher um emoji"
                            class="grid h-11 w-11 place-items-center rounded-xl border border-slate-300 bg-white text-slate-600 shadow-sm transition duration-150 hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40 dark:border-white/15 dark:bg-white/[0.03] dark:text-slate-300 dark:hover:border-emerald-500/60 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-300">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                            <circle cx="12" cy="12" r="8.75" /><path stroke-linecap="round" d="M8.5 14.25c.9 1.15 2.05 1.75 3.5 1.75s2.6-.6 3.5-1.75" /><path stroke-linecap="round" d="M8.75 9.5h.01M15.25 9.5h.01" stroke-width="2.5" />
                        </svg>
                    </button>

                    <div x-show="aberto" x-cloak id="onchat-emojis"
                         class="absolute bottom-full left-0 z-30 mb-1 w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-lg dark:border-white/10 dark:bg-gray-800">
                        <div class="grid grid-cols-8 gap-0.5">
                            @foreach (["\u{1F44D}", "\u{1F44C}", "\u{1F64F}", "\u{1F60A}", "\u{1F600}", "\u{1F602}", "\u{1F605}", "\u{1F609}",
                                       "\u{1F60D}", "\u{1F618}", "\u{1F44F}", "\u{1F64C}", "\u{1F4AA}", "\u{1F91D}", "\u{1F389}", "\u{2705}",
                                       "\u{274C}", "\u{26A0}", "\u{2764}", "\u{1F525}", "\u{2B50}", "\u{1F4CC}", "\u{1F4CE}", "\u{1F4DE}",
                                       "\u{1F4F1}", "\u{1F4AC}", "\u{1F4B0}", "\u{1F4B3}", "\u{1F69A}", "\u{1F4E6}", "\u{1F550}", "\u{1F4C5}",
                                       "\u{1F4CD}", "\u{1F642}", "\u{1F615}", "\u{1F622}", "\u{1F621}", "\u{1F44B}", "\u{1F914}", "\u{1F4A1}"] as $emoji)
                                <button type="button" wire:key="em-{{ $loop->index }}"
                                        x-on:click="$wire.corpo = ($wire.corpo ?? '') + @js($emoji)"
                                        class="rounded p-1 text-lg leading-none hover:bg-slate-100 dark:hover:bg-white/10">{{ $emoji }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endunless

            {{-- alternar entre responder o cliente e escrever nota interna --}}
            <button type="button" wire:click="alternarNota"
                    title="{{ $nota ? 'Voltar a responder o cliente' : 'Escrever nota interna (nao vai para o cliente)' }}"
                    aria-label="{{ $nota ? 'Voltar a responder o cliente' : 'Escrever nota interna' }}"
                    class="grid h-11 w-11 shrink-0 place-items-center rounded-xl border shadow-sm transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500/40 {{ $nota ? 'border-amber-400 bg-amber-100 text-amber-800 dark:border-amber-500/50 dark:bg-amber-500/15 dark:text-amber-200' : 'border-slate-300 bg-white text-slate-600 hover:border-amber-400 hover:bg-amber-50 hover:text-amber-700 dark:border-white/15 dark:bg-white/[0.03] dark:text-gray-300 dark:hover:border-amber-500/60 dark:hover:bg-amber-500/10 dark:hover:text-amber-300' }}">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                    <rect x="5.25" y="10" width="13.5" height="10" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 10V7.75a3.75 3.75 0 0 1 7.5 0V10M12 14v2" />
                </svg>
            </button>

            {{-- throttle e nao debounce: com debounce o aviso so sairia depois que a pessoa
                 PARASSE de escrever, que e exatamente quando ele deixa de ser util. Tres
                 segundos e o intervalo entre avisos; o Baileys mantem o "digitando" aceso
                 nesse meio tempo. --}}
            {{--
                O CAMPO, E A LISTA DO "@" EM CIMA DELE.

                A lista vive no Alpine e nao no servidor: filtrar nome enquanto se digita e
                estado de tela, e cada tecla virando ida ao servidor daria a sensacao de campo
                travado justamente em quem digita rapido. A escolha, sim, avisa o servidor —
                e o proprio nome escolhido e o que a nota vai guardar.

                A lista SO OFERECE quem consegue abrir esta conversa (User::quePodemVer): chamar
                quem nao tem acesso seria armar uma decepcao com clique e tudo.
            --}}
            {{--
                A LISTA VEM DO ATRIBUTO, E NAO DE DENTRO DO x-data.

                Parece detalhe e nao e: o x-data e avaliado UMA VEZ, no primeiro desenho do
                elemento. Ligar o modo nota faz o Livewire redesenhar, mas o Alpine mantem o
                estado que ja tinha — entao a lista ficava congelada no vazio do momento em que
                a nota estava desligada, e o "@" nao abria nada. Sem erro no console, sem nada:
                so uma lista que nunca aparecia.

                Lendo do atributo na hora do uso, o valor e sempre o do ultimo desenho, porque
                atributo o Livewire atualiza.
            --}}
            <div wire:key="mencoes-compositor-{{ $conversationId }}" class="relative flex-1"
                 data-mencionaveis="{{ json_encode($mencionaveis) }}"
                 data-pode-mencionar-grupo="{{ $podeMencionarGrupo ? '1' : '0' }}"
                 x-data="{
                    pessoas: [],
                    participantesCarregados: false,
                    aberto: false,
                    busca: '',
                    indice: 0,

                    get achados() {
                        const b = this.busca.toLowerCase();

                        return this.pessoas
                            .filter((p) => (p.primeiro || p.nome).toLowerCase().startsWith(b) || p.nome.toLowerCase().includes(b) || (p.telefone || '').includes(b))
                            .slice(0, 6);
                    },

                    /* Abre so quando o cursor esta DENTRO de um @palavra: um arroba no meio de
                       um e-mail digitado nao e um pedido de mencao. */
                    async aoDigitar(el) {
                        /*
                           A LISTA E RELIDA A CADA TECLA, do atributo, pelo elemento que veio no
                           evento — e nao por this.$el.

                           Duas armadilhas juntas moram aqui. A primeira: x-data e avaliado uma
                           vez so, entao uma lista lida ali dentro fica congelada no primeiro
                           desenho, quando o modo nota ainda estava desligado e ela era vazia.
                           A segunda: this.$el nao existe dentro de um getter do Alpine, e como
                           eu tinha posto um try/catch em volta, o erro virava silencio e a
                           lista voltava vazia sem nenhuma pista no console.

                           Aqui nao ha nem estado velho nem silencio: o elemento vem no evento,
                           e o atributo e sempre o do ultimo desenho.
                        */
                        const caixa = el.closest('[data-mencionaveis]');
                        const antes = el.value.slice(0, el.selectionStart);
                        const achou = antes.match(/@([\p{L}0-9._-]*)$/u);

                        if (!achou) {
                            this.aberto = false;
                            return;
                        }

                        if (caixa?.dataset.podeMencionarGrupo === '1' && !this.participantesCarregados) {
                            const participantes = await $wire.carregarParticipantesGrupo();
                            this.pessoas = [
                                { id: '__todos__', jid: '__todos__', nome: 'Todos do grupo', primeiro: 'todos', telefone: '', todos: true },
                                ...participantes.map((p) => ({ ...p, id: p.jid, primeiro: p.nome.split(' ')[0] || p.telefone }))
                            ];
                            this.participantesCarregados = true;
                        } else if (caixa?.dataset.podeMencionarGrupo !== '1') {
                            this.pessoas = JSON.parse(caixa?.dataset.mencionaveis || '[]');
                        }

                        this.aberto = Boolean(achou) && this.pessoas.length > 0;
                        this.busca = achou ? achou[1] : '';
                        this.indice = 0;
                    },

                    escolher(p, el) {
                        if (! p) return;

                        const pos = el.selectionStart;
                        const rotulo = p.todos ? 'todos' : p.primeiro;
                        const antes = el.value.slice(0, pos).replace(/@([\p{L}0-9._-]*)$/u, '@' + rotulo + ' ');

                        el.value = antes + el.value.slice(pos);

                        /* corpo em segundo plano (false) e mencionados ao vivo: a segunda
                           chamada leva a primeira de carona no mesmo pedido, e a volta redesenha
                           as etiquetas de quem sera avisado. */
                        $wire.set('corpo', el.value, false);
                        if (p.jid) {
                            const atuais = $wire.get('mencionadosWhatsapp') || [];
                            const escolhidos = p.todos
                                ? this.pessoas.filter((x) => x.jid && !x.todos).map((x) => x.jid)
                                : [p.jid];
                            $wire.set('mencionadosWhatsapp', [...new Set([...atuais, ...escolhidos])]);
                        } else {
                            $wire.set('mencionados', [...new Set([...$wire.get('mencionados'), p.id])]);
                        }

                        this.aberto = false;
                        el.focus();
                    },

                    andar(passo) {
                        const total = this.achados.length;
                        if (total) this.indice = (this.indice + passo + total) % total;
                    },

                    novaLinha(el) {
                        const inicio = el.selectionStart;
                        const fim = el.selectionEnd;
                        el.setRangeText('\n', inicio, fim, 'end');
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                    },
                 }">

                @if ($nota)
                    {{-- Enter salva; Ctrl/Cmd + Enter abre uma nova linha sem enviar. --}}
                    <textarea wire:model="corpo" rows="2" autocomplete="off" data-atalho="responder"
                              x-on:input="aoDigitar($el)"
                              x-on:keydown.arrow-down="if (aberto) { $event.preventDefault(); andar(1) }"
                              x-on:keydown.arrow-up="if (aberto) { $event.preventDefault(); andar(-1) }"
                              x-on:keydown.escape="aberto = false"
                              x-on:keydown.ctrl.enter.prevent.stop="aberto = false; novaLinha($el)"
                              x-on:keydown.meta.enter.prevent.stop="aberto = false; novaLinha($el)"
                              x-on:keydown.enter="
                                  if (aberto) {
                                      $event.preventDefault();
                                      escolher(achados[indice], $el);
                                  } else if (! $event.ctrlKey && ! $event.metaKey) {
                                      $event.preventDefault();
                                      $el.form.requestSubmit();
                                  }
                              "
                              placeholder="Nota interna, só a equipe vê — @ chama alguém"
                              class="max-h-32 min-h-10 w-full resize-y rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/50 dark:bg-amber-500/10 dark:text-amber-100"></textarea>
                @else
                    <input type="text" wire:model="corpo" autocomplete="off" data-atalho="responder"
                           x-on:input.throttle.3000ms="$wire.digitando(true)"
                           x-on:input="aoDigitar($el)"
                           x-on:blur="$wire.digitando(false); setTimeout(() => aberto = false, 150)"
                           x-on:keydown.arrow-down="if (aberto) { $event.preventDefault(); andar(1) }"
                           x-on:keydown.arrow-up="if (aberto) { $event.preventDefault(); andar(-1) }"
                           x-on:keydown.escape="aberto = false"
                           x-on:keydown.enter="if (aberto) { $event.preventDefault(); escolher(achados[indice], $el) }"
                           placeholder="{{ $anexo ? 'Legenda (opcional)' : 'Escreva uma mensagem' }}"
                           class="w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/15 dark:bg-gray-800 dark:text-gray-100">
                @endif

                {{-- Sobe em vez de descer: embaixo do campo ela cairia atras da barra de
                     escrever, que e o unico lugar onde nao se pode ler nada. --}}
                <div x-show="aberto && achados.length" x-cloak
                     class="absolute bottom-full left-0 z-30 mb-1 w-64 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-white/10 dark:bg-gray-800">
                    <template x-for="(p, i) in achados" :key="p.id">
                        <button type="button"
                                x-on:mousedown.prevent="escolher(p, $el.closest('div').parentElement.querySelector('[data-atalho=responder]'))"
                                :class="i === indice ? 'bg-amber-50 dark:bg-amber-500/10' : ''"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-amber-50 dark:hover:bg-amber-500/10">
                            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-amber-500/20 text-[11px] font-semibold text-amber-700 dark:text-amber-300"
                                  x-text="p.primeiro.charAt(0).toUpperCase()"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-gray-800 dark:text-gray-100" x-text="p.nome"></span>
                                <span x-show="p.telefone" class="block truncate text-[11px] text-gray-500" x-text="'+' + p.telefone"></span>
                            </span>
                        </button>
                    </template>
                </div>
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="enviar,anexo"
                    class="inline-flex h-11 shrink-0 items-center gap-2 rounded-xl px-4 text-sm font-semibold text-white shadow-sm transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 {{ $nota ? 'bg-amber-600 hover:bg-amber-700 focus-visible:ring-amber-500' : 'bg-emerald-600 hover:bg-emerald-700 focus-visible:ring-emerald-500' }}">
                <span>{{ $nota ? 'Salvar nota' : 'Enviar' }}</span>
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 14-8-4.5 16-3-6.5L5 12Zm6.5 1.5L19 4" /></svg>
            </button>
        </form>
        @endif

        @error('corpo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('anexo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @else
        <p class="text-center text-xs text-slate-400">Selecione uma conversa para responder</p>
    @endif
</div>

@script
<script>
    // Grava pelo navegador e entrega o blob ao Livewire como se fosse upload de
    // arquivo. O webm sai daqui e o servidor converte para OGG/Opus com ffmpeg —
    // sem isso o WhatsApp mostra como arquivo anexado, nao como nota de voz.
    window.gravadorDeVoz = () => ({
        iniciando: false,
        gravando: false,
        travado: false,
        pressionado: false,
        soltouDuranteInicio: false,
        enviando: false,
        erro: '',
        perto: false,          // arrastou o bastante para a esquerda: soltar cancela
        segundos: 0,
        rec: null,
        stream: null,
        pedacos: [],
        cronometro: null,
        inicioX: 0,
        inicioY: 0,
        comecouEm: 0,
        aoSoltar: null,        // o que fazer quando o dedo levantar

        // Menos de um segundo nao e nota de voz, e um toque sem querer. Enviar um "tec" de
        // 200ms para o cliente e pior que nao enviar nada.
        MINIMO_MS: 900,

        formatoSuportado() {
            const opcoes = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
            return opcoes.find((t) => window.MediaRecorder?.isTypeSupported?.(t)) ?? '';
        },

        /**
         * O dedo desceu no microfone. Comeca a gravar e passa a seguir o movimento.
         *
         * Os ouvintes ficam no DOCUMENTO e nao no botao: o dedo sai de cima dele em qualquer
         * arrasto, e ouvinte preso ao botao perderia o movimento exatamente quando ele
         * comeca a importar.
         */
        async segureParaGravar(evento) {
            if (this.iniciando || this.gravando || this.enviando) return;

            this.inicioX = evento.clientX;
            this.inicioY = evento.clientY;
            this.comecouEm = Date.now();
            this.perto = false;
            this.travado = false;
            this.pressionado = true;
            this.soltouDuranteInicio = false;
            this.erro = '';

            const mover = (e) => this.aoMover(e);
            const soltar = (e) => {
                document.removeEventListener('pointermove', mover);
                document.removeEventListener('pointerup', soltar);
                document.removeEventListener('pointercancel', cancelado);
                this.pressionado = false;

                if (this.iniciando) {
                    this.soltouDuranteInicio = true;
                    return;
                }

                this.aoLevantar();
            };
            // pointercancel (o sistema tomou o gesto) TRAVA em vez de cancelar: perder um
            // audio ja gravado porque o navegador decidiu rolar a tela seria o pior desfecho.
            const cancelado = () => {
                document.removeEventListener('pointermove', mover);
                document.removeEventListener('pointerup', soltar);
                document.removeEventListener('pointercancel', cancelado);
                this.pressionado = false;
                if (this.iniciando) this.soltouDuranteInicio = true;
                if (this.gravando) this.travado = true;
            };

            document.addEventListener('pointermove', mover);
            document.addEventListener('pointerup', soltar);
            document.addEventListener('pointercancel', cancelado);

            await this.iniciar();
        },

        aoMover(e) {
            if (!this.gravando || this.travado) return;

            const dy = e.clientY - this.inicioY;
            const dx = e.clientX - this.inicioX;

            if (dy < -60) { this.travado = true; this.perto = false; return; }

            this.perto = dx < -90;
        },

        aoLevantar() {
            if (!this.gravando || this.travado) return;

            if (this.perto) { this.cancelar(); return; }

            // Toque curto tambem trava, em vez de nao fazer nada. E o caminho de quem usa
            // teclado ou leitor de tela, que nao tem como "segurar" — sem isso o recurso
            // simplesmente nao existiria para essas pessoas.
            if (Date.now() - this.comecouEm < this.MINIMO_MS) { this.travado = true; return; }

            this.pararEEnviar();
        },

        async iniciar() {
            if (!navigator.mediaDevices?.getUserMedia) {
                this.erro = 'Este navegador não permite gravar áudio.';
                return;
            }

            this.iniciando = true;

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (e) {
                this.erro = e?.name === 'NotAllowedError'
                    ? 'Autorize o microfone nas permissões do navegador.'
                    : 'Não foi possível iniciar o microfone. Tente novamente.';
                this.iniciando = false;
                this.pressionado = false;
                this.comecouEm = 0;
                return;
            }

            this.iniciando = false;

            // O dedo pode ter levantado enquanto o navegador pedia a permissao. Sem esta
            // checagem a gravacao comecaria depois de a pessoa ja ter desistido.
            if (this.comecouEm === 0) {
                this.stream.getTracks().forEach((t) => t.stop());
                return;
            }

            const tipo = this.formatoSuportado();
            this.pedacos = [];
            this.rec = tipo ? new MediaRecorder(this.stream, { mimeType: tipo }) : new MediaRecorder(this.stream);

            this.rec.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) this.pedacos.push(e.data);
            };

            this.rec.onstop = () => {
                this.stream?.getTracks().forEach((t) => t.stop());
                clearInterval(this.cronometro);

                const enviar = this.aoSoltar === 'enviar';
                this.aoSoltar = null;

                if (!enviar) { this.pedacos = []; return; }

                const mime = this.rec.mimeType || 'audio/webm';
                const ext = mime.includes('ogg') ? 'ogg' : mime.includes('mp4') ? 'm4a' : 'webm';
                const blob = new Blob(this.pedacos, { type: mime.split(';')[0] });
                const arquivo = new File([blob], `nota-de-voz.${ext}`, { type: mime.split(';')[0] });

                if (!blob.size) {
                    this.erro = 'O áudio ficou vazio. Grave novamente.';
                    this.pedacos = [];
                    return;
                }

                // O envio so acontece DEPOIS que o arquivo terminou de subir. Chamar enviar()
                // antes mandaria uma mensagem sem anexo — e o audio ficaria orfao no servidor.
                this.enviando = true;
                $wire.upload(
                    'anexo',
                    arquivo,
                    () => {
                        $wire.enviar()
                            .then(() => { this.enviando = false; this.pedacos = []; })
                            .catch(() => { this.enviando = false; this.erro = 'Não foi possível enviar o áudio. Tente novamente.'; });
                    },
                    () => { this.enviando = false; this.erro = 'Falha ao carregar o áudio. Verifique a conexão.'; },
                );
            };

            this.rec.start();
            this.gravando = true;
            this.segundos = 0;
            this.cronometro = setInterval(() => {
                this.segundos += 1;
                if (this.segundos >= 300) this.pararEEnviar(); // teto de 5 min
            }, 1000);

            // Se o usuário soltou enquanto o navegador ainda liberava o microfone, não
            // perdemos mais o gesto. A gravação abre travada, com cancelar e enviar visíveis.
            if (this.soltouDuranteInicio || !this.pressionado) {
                this.travado = true;
                this.soltouDuranteInicio = false;
            }
        },

        pararEEnviar() {
            if (!this.gravando) return;
            this.aoSoltar = 'enviar';
            this.encerrar();
        },

        cancelar() {
            if (this.iniciando) {
                this.comecouEm = 0;
                this.soltouDuranteInicio = false;
                return;
            }
            this.aoSoltar = 'descartar';
            this.encerrar();
        },

        encerrar() {
            this.gravando = false;
            this.travado = false;
            this.perto = false;
            this.comecouEm = 0;

            if (this.rec && this.rec.state !== 'inactive') {
                this.rec.stop();
            } else {
                clearInterval(this.cronometro);
                this.stream?.getTracks().forEach((t) => t.stop());
            }
        },
    });
</script>
@endscript
