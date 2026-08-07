<div class="border-t border-slate-200 p-3">
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
            <div class="mb-2 flex items-center gap-3 rounded border border-slate-200 bg-slate-50 p-2">
                @php
                    $mime = method_exists($anexo, 'getMimeType') ? ($anexo->getMimeType() ?? '') : '';
                    $nome = method_exists($anexo, 'getClientOriginalName') ? $anexo->getClientOriginalName() : 'arquivo';
                @endphp

                @if (str_starts_with($mime, 'image/'))
                    <img src="{{ $anexo->temporaryUrl() }}" alt="" class="h-14 w-14 rounded object-cover">
                @elseif (str_starts_with($mime, 'audio/'))
                    <span class="grid h-14 w-14 place-items-center rounded bg-emerald-100 text-emerald-700">&#9835;</span>
                @elseif (str_starts_with($mime, 'video/'))
                    <span class="grid h-14 w-14 place-items-center rounded bg-slate-200 text-slate-600">&#9654;</span>
                @else
                    <span class="grid h-14 w-14 place-items-center rounded bg-slate-200 text-slate-600">&#128196;</span>
                @endif

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm text-slate-700">{{ $nome }}</p>
                    <p class="text-xs text-slate-500">{{ $mime ?: 'arquivo' }}</p>
                </div>

                <button type="button" wire:click="removerAnexo"
                        class="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50">
                    remover
                </button>
            </div>
        @endif

        <div wire:loading wire:target="anexo" class="mb-2 text-xs text-slate-500">
            enviando arquivo para o servidor&hellip;
        </div>

        @if ($nota)
            <div class="mb-2 flex items-center gap-2 rounded border border-amber-300 bg-amber-50 px-2 py-1.5 text-xs text-amber-800">
                <span>&#128274;</span>
                <span>Nota interna: fica no histórico da conversa e <strong>não é enviada ao cliente</strong>.</span>
            </div>
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
            <div class="mb-2 flex items-start gap-2 rounded-lg border-l-4 border-emerald-500 bg-slate-50 px-3 py-2">
                <div class="min-w-0 flex-1">
                    <div class="text-xs font-semibold text-emerald-700">
                        Respondendo {{ $citada->entrada() ? ($citada->remetente_nome ?: 'o cliente') : 'você mesmo' }}
                    </div>
                    <div class="truncate text-xs text-slate-600">{{ $citada->resumo(120) }}</div>
                </div>

                <button type="button" wire:click="cancelarResposta"
                        class="shrink-0 rounded px-2 text-lg leading-none text-slate-400 hover:text-slate-700"
                        title="Não citar" aria-label="Cancelar a citação">&times;</button>
            </div>
        @endif

        <form wire:submit="enviar" class="flex items-end gap-2 {{ $nota ? 'rounded-lg bg-amber-50/60 p-1.5 ring-1 ring-amber-200' : '' }}">
            {{-- anexar: escondido em nota interna, o arquivo iria para o cliente --}}
            @unless ($nota)
                <label class="cursor-pointer rounded border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                       title="Anexar imagem, video, audio ou documento">
                    &#128206;
                    <input type="file" wire:model="anexo" class="hidden"
                           accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
                </label>
            @endunless

            {{-- modelos de mensagem --}}
            @if ($modelos->isNotEmpty() && ! $nota)
                <div class="relative shrink-0" x-data="{ aberto: false }" x-on:click.outside="aberto = false">
                    <button type="button" x-on:click="aberto = !aberto" title="Modelos de mensagem"
                            class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
                        &#9998;
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

            {{-- gravar nota de voz (audio vai para o cliente: nao cabe em nota) --}}
            <div x-data="gravadorDeVoz()" class="shrink-0" @if ($nota) hidden @endif>
                <button type="button"
                        x-show="!gravando"
                        x-on:click="iniciar()"
                        class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                        title="Gravar nota de voz">
                    &#127908;
                </button>
                <button type="button"
                        x-show="gravando"
                        x-on:click="parar()"
                        class="rounded bg-red-600 px-3 py-2 text-sm text-white"
                        title="Parar e anexar">
                    <span x-text="'■ ' + segundos + 's'"></span>
                </button>
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
                            class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
                        &#128512;
                    </button>

                    <div x-show="aberto" x-cloak id="onchat-emojis"
                         class="absolute bottom-full left-0 z-30 mb-1 w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                        <div class="grid grid-cols-8 gap-0.5">
                            @foreach (["\u{1F44D}", "\u{1F44C}", "\u{1F64F}", "\u{1F60A}", "\u{1F600}", "\u{1F602}", "\u{1F605}", "\u{1F609}",
                                       "\u{1F60D}", "\u{1F618}", "\u{1F44F}", "\u{1F64C}", "\u{1F4AA}", "\u{1F91D}", "\u{1F389}", "\u{2705}",
                                       "\u{274C}", "\u{26A0}", "\u{2764}", "\u{1F525}", "\u{2B50}", "\u{1F4CC}", "\u{1F4CE}", "\u{1F4DE}",
                                       "\u{1F4F1}", "\u{1F4AC}", "\u{1F4B0}", "\u{1F4B3}", "\u{1F69A}", "\u{1F4E6}", "\u{1F550}", "\u{1F4C5}",
                                       "\u{1F4CD}", "\u{1F642}", "\u{1F615}", "\u{1F622}", "\u{1F621}", "\u{1F44B}", "\u{1F914}", "\u{1F4A1}"] as $emoji)
                                <button type="button" wire:key="em-{{ $loop->index }}"
                                        x-on:click="$wire.corpo = ($wire.corpo ?? '') + @js($emoji)"
                                        class="rounded p-1 text-lg leading-none hover:bg-slate-100">{{ $emoji }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endunless

            {{-- alternar entre responder o cliente e escrever nota interna --}}
            <button type="button" wire:click="alternarNota"
                    title="{{ $nota ? 'Voltar a responder o cliente' : 'Escrever nota interna (nao vai para o cliente)' }}"
                    class="shrink-0 rounded border px-3 py-2 text-sm {{ $nota ? 'border-amber-400 bg-amber-100 text-amber-800' : 'border-slate-300 text-slate-600 hover:bg-slate-50' }}">
                &#128274;
            </button>

            {{-- throttle e nao debounce: com debounce o aviso so sairia depois que a pessoa
                 PARASSE de escrever, que e exatamente quando ele deixa de ser util. Tres
                 segundos e o intervalo entre avisos; o Baileys mantem o "digitando" aceso
                 nesse meio tempo. --}}
            <input type="text" wire:model="corpo" autocomplete="off"
                   x-on:input.throttle.3000ms="$wire.digitando(true)"
                   x-on:blur="$wire.digitando(false)"
                   placeholder="{{ $nota ? 'Nota interna, só a equipe vê' : ($anexo ? 'Legenda (opcional)' : 'Escreva uma mensagem') }}"
                   class="flex-1 rounded border px-3 py-2 text-sm {{ $nota ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-slate-300' }}">

            <button type="submit" wire:loading.attr="disabled" wire:target="enviar,anexo"
                    class="rounded px-4 py-2 text-sm font-medium text-white disabled:opacity-60 {{ $nota ? 'bg-amber-600 hover:bg-amber-700' : 'bg-emerald-600 hover:bg-emerald-700' }}">
                {{ $nota ? 'Salvar nota' : 'Enviar' }}
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
        gravando: false,
        segundos: 0,
        rec: null,
        pedacos: [],
        cronometro: null,

        formatoSuportado() {
            const opcoes = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
            return opcoes.find((t) => window.MediaRecorder?.isTypeSupported?.(t)) ?? '';
        },

        async iniciar() {
            if (!navigator.mediaDevices?.getUserMedia) {
                alert('Este navegador nao permite gravar audio.');
                return;
            }

            let stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (e) {
                alert('Precisa autorizar o microfone para gravar.');
                return;
            }

            const tipo = this.formatoSuportado();
            this.pedacos = [];
            this.rec = tipo ? new MediaRecorder(stream, { mimeType: tipo }) : new MediaRecorder(stream);

            this.rec.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) this.pedacos.push(e.data);
            };

            this.rec.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                clearInterval(this.cronometro);

                const mime = this.rec.mimeType || 'audio/webm';
                const ext = mime.includes('ogg') ? 'ogg' : mime.includes('mp4') ? 'm4a' : 'webm';
                const blob = new Blob(this.pedacos, { type: mime.split(';')[0] });
                const arquivo = new File([blob], `nota-de-voz.${ext}`, { type: mime.split(';')[0] });

                $wire.upload('anexo', arquivo);
            };

            this.rec.start();
            this.gravando = true;
            this.segundos = 0;
            this.cronometro = setInterval(() => {
                this.segundos += 1;
                if (this.segundos >= 300) this.parar(); // teto de 5 min
            }, 1000);
        },

        parar() {
            this.gravando = false;
            if (this.rec && this.rec.state !== 'inactive') this.rec.stop();
        },
    });
</script>
@endscript
