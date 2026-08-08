{{--
    ALTURA FIXA, E NADA ROLA.

    Era min-h-screen: a pagina podia crescer para baixo, e crescia. Com uma pessoa so, o quadro
    ocupava a largura inteira e o formato 16:9 fazia a altura passar da tela — a barra de
    controles ia parar embaixo da dobra, e quem quisesse desligar o microfone tinha de ROLAR a
    pagina no meio da chamada.

    h-dvh e nao h-screen por causa do celular: a barra do navegador aparece e some conforme a
    pessoa rola, e o dvh acompanha isso. Com vh, a barra de controles fica escondida atras da
    barra do Safari exatamente na hora de sair da chamada.
--}}
<div class="flex h-dvh flex-col overflow-hidden">
    {{-- ------------------------------------------------------------- cabeçalho --}}
    <header class="flex items-center gap-3 border-b border-white/10 px-4 py-3">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-amber-500 font-bold text-gray-950">
            {{ Str::substr(config('app.name'), 0, 1) }}
        </span>

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-gray-100">{{ $reuniao->titulo ?: 'Reunião' }}</p>
            <p class="text-[11px] text-gray-500">{{ config('app.name') }}</p>
        </div>
    </header>

    <main class="relative flex min-h-0 flex-1 flex-col">
        @if ($recado)
            <div class="border-b border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                {{ $recado }}
            </div>
        @endif

        {{--
            A SALA DE ESPERA, do lado de fora.

            wire:poll e nao aviso empurrado: quem espera nao tem conta, nao tem sessao e nao
            pode assinar canal privado nenhum. Uma pergunta a cada tres segundos, feita por
            alguem que esta parado olhando para a tela, e barata — abrir canal de tempo real
            para desconhecido seria superficie que ninguem precisa.
        --}}
        @if ($aguardando)
            <div class="flex flex-1 items-center justify-center p-6" wire:poll.3s="verificarPedido">
                <div class="w-full max-w-sm rounded-2xl border border-white/10 bg-gray-900 p-6 text-center">
                    <div class="mx-auto grid h-12 w-12 animate-pulse place-items-center rounded-full bg-amber-500/20 text-2xl">
                        &#9203;
                    </div>

                    <h1 class="mt-4 text-lg font-semibold">Esperando liberarem sua entrada</h1>

                    <p class="mt-2 text-sm text-gray-400">
                        Avisamos quem organiza a reunião que você está aqui. Assim que liberarem,
                        você entra sozinho — pode deixar esta tela aberta.
                    </p>

                    <p class="mt-4 text-xs text-gray-500">Você entrará como <strong>{{ $nome }}</strong>.</p>
                </div>
            </div>

        {{-- ---------------------------------------------------- antes de entrar --}}
        @elseif (! $entrou)
            <div class="flex flex-1 items-center justify-center overflow-y-auto p-6">
                <div class="w-full max-w-sm rounded-2xl border border-white/10 bg-gray-900 p-6">
                    @if (! $reuniao->aberta())
                        <h1 class="text-lg font-semibold">Reunião encerrada</h1>
                        <p class="mt-2 text-sm text-gray-400">
                            Esta chamada já terminou. Se precisar continuar, peça um link novo a
                            quem te convidou.
                        </p>
                    @elseif ($reuniao->expirada())
                        <h1 class="text-lg font-semibold">Link expirado</h1>
                        <p class="mt-2 text-sm text-gray-400">
                            Links de reunião valem por {{ \App\Models\Meeting::HORAS_ATE_EXPIRAR }} horas.
                            Peça um novo a quem te convidou.
                        </p>
                    @else
                        <h1 class="text-lg font-semibold">Entrar na chamada</h1>
                        <p class="mt-1 text-sm text-gray-400">
                            Vamos pedir acesso à sua câmera e ao microfone.
                        </p>

                        <label class="mt-5 block">
                            <span class="text-xs font-medium text-gray-400">Como você aparece</span>
                            <input type="text" wire:model="nome" maxlength="80" autocomplete="name"
                                   class="mt-1 w-full rounded-lg border-white/15 bg-gray-800 text-sm text-gray-100 placeholder-gray-500 focus:border-amber-500 focus:ring-amber-500">
                            @error('nome') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
                        </label>

                        {{--
                            A PERMISSAO E PEDIDA AQUI, DENTRO DO CLIQUE.

                            O navegador so abre a caixa de permissao de camera durante um gesto
                            da pessoa. Antes, quem pedia era o codigo que roda DEPOIS da ida ao
                            servidor — e nessa viagem o gesto se perde: o Safari do iPhone
                            recusa na hora, sem nem perguntar.

                            O fluxo e obtido e desligado no mesmo instante. O que interessa nao
                            e o video, e a AUTORIZACAO, que fica valendo para a pagina inteira;
                            quando a sala pedir a camera de novo, ja vem liberada e sem segunda
                            caixa. Desligar na hora tambem evita o iPhone recusar a segunda
                            captura da mesma camera por ela ainda estar em uso.

                            Falhar aqui nao impede de entrar: quem nao tem camera participa
                            ouvindo.
                        --}}
                        <div x-data="{ pedindo: false, bloqueado: false, detalhe: '' }">
                            {{--
                                A PERMISSAO E PEDIDA AQUI, DENTRO DO CLIQUE.

                                O navegador so abre a caixa de permissao durante um gesto da
                                pessoa. Pedir depois da ida ao servidor perde o gesto, e o
                                Safari do iPhone recusa sem nem perguntar.

                                O fluxo e obtido e desligado no mesmo instante: o que interessa
                                nao e o video, e a AUTORIZACAO, que fica valendo para a pagina
                                inteira. Desligar na hora tambem evita o iPhone recusar a
                                segunda captura por a camera ainda estar em uso.

                                FALHAR AQUI NAO IMPEDE DE ENTRAR — so muda o que a tela diz
                                antes. Quem esta numa janela onde a camera nao existe precisa
                                saber disso ANTES de entrar mudo numa reuniao e ficar tentando
                                entender por que ninguem ouve.
                            --}}
                            <button type="button"
                                    x-show="! bloqueado"
                                    x-on:click="
                                        pedindo = true;
                                        try {
                                            const fluxo = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
                                            fluxo.getTracks().forEach(faixa => faixa.stop());
                                            pedindo = false;
                                            $wire.entrar();
                                        } catch (e) {
                                            detalhe = [e?.name, e?.message].filter(Boolean).join(': ');
                                            bloqueado = true;
                                            pedindo = false;
                                        }
                                    "
                                    x-bind:disabled="pedindo"
                                    wire:loading.attr="disabled"
                                    class="mt-4 w-full rounded-lg bg-amber-500 py-2.5 text-sm font-semibold text-gray-950 transition hover:bg-amber-400 disabled:opacity-60">
                                <span x-show="pedindo">Liberando câmera…</span>
                                <span x-show="! pedindo">
                                    <span wire:loading.remove wire:target="entrar">Entrar</span>
                                    <span wire:loading wire:target="entrar">Abrindo…</span>
                                </span>
                            </button>

                            {{-- O caminho mais provavel deste produto: o link viaja pelo
                                 WhatsApp por projeto, e no iPhone ele abre numa janela que a
                                 Apple nao autoriza a usar camera. Nao ha o que a pagina faca —
                                 so ensinar o caminho. --}}
                            <div x-show="bloqueado" x-cloak class="mt-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
                                <p class="text-sm font-medium text-amber-200">Abra em outro navegador</p>
                                <p class="mt-1 text-xs text-amber-100/80">
                                    Esta janela não libera a câmera nem o microfone. Toque em
                                    <strong>•••</strong> ou no ícone de compartilhar e escolha
                                    <strong>Abrir no Safari</strong> (ou no Chrome). O endereço é o mesmo.
                                </p>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button type="button"
                                            x-data="{ copiado: false }"
                                            @click="navigator.clipboard.writeText(window.location.href); copiado = true; setTimeout(() => copiado = false, 1600)"
                                            class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-gray-950">
                                        <span x-show="! copiado">Copiar o link</span>
                                        <span x-show="copiado" x-cloak>Copiado!</span>
                                    </button>

                                    {{-- Entrar assim mesmo continua valendo: as vezes a pessoa
                                         so quer ouvir, e barrar seria pior que entrar mudo. --}}
                                    <button type="button" @click="bloqueado = false; $wire.entrar()"
                                            class="rounded-lg border border-amber-400/40 px-3 py-1.5 text-xs text-amber-100">
                                        Entrar assim mesmo, só ouvindo
                                    </button>
                                </div>

                                <p class="mt-2 text-[10px] text-amber-100/40" x-text="detalhe"></p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @else
            {{-- ------------------------------------------------------- dentro --}}
            {{--
                A FILA DA PORTARIA.

                Fica FORA do wire:ignore de proposito: ela precisa ser redesenhada a cada
                sondagem, e o bloco da chamada precisa nao ser. Por cima do video e nao ao lado,
                porque liberar quem esta esperando e a coisa mais urgente que aparece na tela —
                e porque quem esta em chamada nao pode ter o proprio quadro empurrado por um
                aviso.
            --}}
            @if ($this->souDaEquipe() && $entrou)
                <div wire:poll.3s class="pointer-events-none absolute inset-x-0 top-0 z-40 flex justify-center px-3 pt-3">
                    <div class="pointer-events-auto w-full max-w-sm space-y-2">
                        @foreach ($this->pedidos() as $pedido)
                            <div wire:key="pd-{{ $pedido->id }}"
                                 class="flex items-center gap-3 rounded-xl bg-gray-800 p-3 shadow-xl ring-1 ring-white/10">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-amber-500/20 text-sm font-semibold text-amber-300">
                                    {{ mb_strtoupper(mb_substr($pedido->nome, 0, 1)) }}
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-gray-100">{{ $pedido->nome }}</p>
                                    <p class="text-[11px] text-gray-400">quer entrar</p>
                                </div>

                                <button type="button" wire:click="recusar({{ $pedido->id }})"
                                        class="rounded-lg px-2 py-1.5 text-xs text-gray-400 hover:bg-white/5 hover:text-gray-200">
                                    Recusar
                                </button>

                                <button type="button" wire:click="aceitar({{ $pedido->id }})"
                                        class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-gray-950 hover:bg-amber-400">
                                    Liberar
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{--
                wire:ignore: DEPOIS DE CONECTAR, O LIVEWIRE NAO ENCOSTA MAIS AQUI.

                Gravar um recado e uma ida ao servidor, e toda ida ao servidor faz o Livewire
                redesenhar o HTML do componente. Redesenhar o HTML de uma chamada em andamento e
                como a chamada cai: o elemento de video e trocado e a imagem morre no meio da
                conversa. Os botoes com wire:click continuam funcionando — eles sobem pelo
                elemento raiz, que fica de fora.
            --}}
            <div
                wire:ignore
                x-data="salaDeVideo()"
                x-init="entrar(@js($tokenDeVideo), @js($urlDeVideo), @js($historicoInicial))"
                {{-- min-h-0 em TODO elo da corrente, e nao so no ultimo.

                     Filho de flex nunca fica menor que o proprio conteudo, a menos que se diga
                     que pode. Basta UM elo sem essa permissao para a altura do video empurrar
                     tudo para baixo e a barra de controles sair da tela -- foi exatamente o que
                     aconteceu aqui: os elos de dentro tinham, este nao, e o defeito continuou
                     igual depois do primeiro conserto. --}}
                class="flex min-h-0 flex-1 flex-col overflow-hidden"
            >
                {{-- Aviso, e nao lapide: se a pessoa esta dentro, a sala continua embaixo
                     dele. Vermelho de tela cheia faria ela fechar a aba achando que quebrou. --}}
                <template x-if="erro">
                    <div class="border-b border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                        <div class="flex items-start gap-2">
                            <span class="flex-1" x-text="erro"></span>
                            <button type="button" @click="erro = ''" class="shrink-0 opacity-70">&times;</button>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            {{-- Tentar de novo DENTRO do clique: e o unico momento em que o
                                 navegador aceita abrir a caixa de permissao. Quem negou por
                                 reflexo volta atras sem recarregar e perder a reuniao. --}}
                            <button type="button" @click="tentarMidia()"
                                    class="rounded-full bg-amber-500 px-3 py-1 text-xs font-semibold text-gray-950 hover:bg-amber-400">
                                Tentar câmera de novo
                            </button>

                            <button type="button"
                                    x-data="{ copiado: false }"
                                    @click="navigator.clipboard.writeText(window.location.href); copiado = true; setTimeout(() => copiado = false, 1600)"
                                    class="rounded-full border border-amber-400/40 px-3 py-1 text-xs">
                                <span x-show="! copiado">Copiar o link</span>
                                <span x-show="copiado" x-cloak>Copiado!</span>
                            </button>

                            <span class="text-[10px] opacity-50" x-text="detalhe"></span>
                        </div>
                    </div>
                </template>

                <template x-if="entrando">
                    <div class="flex flex-1 items-center justify-center text-sm text-gray-400">Conectando…</div>
                </template>

                <template x-if="saiu">
                    <div class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
                        {{-- "Você saiu" para quem foi retirado e quase uma mentira: ela não
                             saiu, foi tirada. E quem caiu porque encerraram fica tentando
                             voltar para uma sala que não existe mais. --}}
                        <p class="text-lg font-semibold"
                           x-text="motivoDaSaida || 'Você saiu da chamada'"></p>
                        <button type="button" wire:click="$refresh"
                                class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-gray-950 hover:bg-amber-400">
                            Entrar de novo
                        </button>
                    </div>
                </template>

                <template x-if="dentro">
                    <div class="flex min-h-0 flex-1 flex-col">
                      <div class="relative flex min-h-0 flex-1">

                        {{--
                            AS REAÇÕES SOBEM AQUI, no meio da área de vídeo.

                            pointer-events-none porque isto fica por cima de todo mundo: uma
                            camada que engole clique deixaria os quadros e o painel sem resposta
                            durante os dois segundos e meio da animação.
                        --}}
                        <div class="pointer-events-none absolute inset-0 z-30 flex items-center justify-center">
                            <template x-for="r in reacoes" :key="r.id">
                                <div class="reacao-sobe absolute flex flex-col items-center"
                                     :style="`margin-left: ${r.desvio}px`">
                                    <span class="text-6xl drop-shadow-lg" x-text="r.emoji"></span>
                                    <span class="mt-1 rounded-full bg-black/60 px-2 py-0.5 text-[11px] text-white"
                                          x-text="r.nome"></span>
                                </div>
                            </template>
                        </div>

                        {{-- os quadros --}}
                        <div class="grid min-h-0 flex-1 auto-rows-fr gap-2 p-2" :class="colunas">
                            <template x-for="p in pessoas" :key="p.id">
                                <div class="relative min-h-0 overflow-hidden rounded-xl bg-gray-900 ring-1"
                                     :class="p.falando ? 'ring-amber-400' : 'ring-white/10'">

                                    {{-- -scale-x-100 vira o quadro na horizontal: a pessoa
                                         se ve como num espelho, que e como ela se conhece. Só
                                         na própria câmera — nunca no outro lado nem na tela
                                         compartilhada. --}}
                                    <video autoplay playsinline
                                           :muted="p.souEu"
                                           x-effect="plugarVideo($el, p)"
                                           class="h-full w-full object-cover"
                                           :class="{ 'opacity-0': ! p.temImagem, '-scale-x-100': p.espelhar }"></video>

                                    <audio autoplay x-effect="plugarAudio($el, p)" class="hidden"></audio>

                                    {{-- sem câmera: as iniciais, para o quadro não virar um buraco preto --}}
                                    <template x-if="! p.temImagem">
                                        <div class="absolute inset-0 grid place-items-center">
                                            <span class="grid h-16 w-16 place-items-center rounded-full bg-amber-500/20 text-xl font-semibold text-amber-300"
                                                  x-text="p.nome.trim().charAt(0).toUpperCase()"></span>
                                        </div>
                                    </template>

                                    {{-- A mão fica no alto e não some sozinha: ela é um pedido
                                         de vez, e pedido que some antes de ser atendido não
                                         serve para nada. --}}
                                    <template x-if="p.mao">
                                        <span class="absolute left-2 top-2 grid h-8 w-8 place-items-center rounded-full bg-amber-500 text-base"
                                              title="Pediu a vez">&#9995;</span>
                                    </template>

                                    <div class="absolute inset-x-0 bottom-0 flex items-center gap-1.5 bg-gradient-to-t from-black/70 to-transparent px-2 py-1.5">
                                        <span class="truncate text-xs font-medium text-white" x-text="p.nome"></span>
                                        <template x-if="p.semSom">
                                            <span class="text-[10px] text-red-300">sem som</span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>


                        {{--
                            QUEM ESTA NA SALA.

                            Painel proprio e nao menu em cima de cada quadro: no celular o quadro
                            e pequeno e o dedo e grande, e um menu que abre em cima do rosto de
                            alguem e onde o toque errado acontece. Aqui cada nome tem a sua linha,
                            e o botao de tirar da sala fica longe do de calar.
                        --}}
                        <div x-show="painel === 'gente'" x-cloak
                             class="absolute inset-0 z-30 flex flex-col bg-gray-900 sm:relative sm:inset-auto sm:z-auto sm:w-80 sm:shrink-0 sm:border-l sm:border-white/10">

                            <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                                <p class="text-sm font-medium text-gray-100">
                                    Na sala <span class="text-gray-500" x-text="'(' + pessoas.length + ')'"></span>
                                </p>
                                <button type="button" @click="abrirPainel('')"
                                        class="grid h-8 w-8 place-items-center rounded-full text-gray-400 hover:bg-white/10 hover:text-white">&times;</button>
                            </div>

                            <div class="min-h-0 flex-1 overflow-y-auto px-2 py-2">
                                <template x-for="p in pessoas" :key="p.id">
                                    <div class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-white/5">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-amber-500/20 text-xs font-semibold text-amber-300"
                                              x-text="p.nome.trim().charAt(0).toUpperCase()"></span>

                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm text-gray-100">
                                                <span x-text="p.nome"></span>
                                                <template x-if="p.souEu"><span class="text-gray-500"> (você)</span></template>
                                            </span>
                                            <span class="flex items-center gap-1.5 text-[11px]">
                                                <template x-if="p.semSom"><span class="text-red-400">sem som</span></template>
                                                <template x-if="! p.semSom"><span class="text-gray-500">com som</span></template>
                                                <template x-if="p.mao"><span class="text-amber-400">· pediu a vez</span></template>
                                            </span>
                                        </span>

                                        @if ($this->souDaEquipe())
                                            {{-- Só para os outros: calar a si mesmo já é o botão
                                                 da barra, e tirar-se da sala é o botão vermelho. --}}
                                            <template x-if="! p.souEu">
                                                <span class="flex shrink-0 gap-1">
                                                    <button type="button"
                                                            x-show="! p.semSom"
                                                            @click="$wire.silenciar(p.id)"
                                                            title="Calar o microfone"
                                                            class="rounded-lg px-2 py-1 text-[11px] text-gray-300 hover:bg-white/10">
                                                        Calar
                                                    </button>

                                                    <button type="button"
                                                            @click="if (confirm('Tirar ' + p.nome + ' da chamada?')) $wire.remover(p.id)"
                                                            title="Tirar da chamada"
                                                            class="rounded-lg px-2 py-1 text-[11px] text-red-300 hover:bg-red-500/10">
                                                        Tirar
                                                    </button>
                                                </span>
                                            </template>
                                        @endif
                                    </div>
                                </template>
                            </div>

                            @if ($this->souDaEquipe())
                                <p class="border-t border-white/10 px-4 py-3 text-[11px] text-gray-500">
                                    Calar não é definitivo: a pessoa pode ligar o microfone de novo.
                                    Tirar da chamada derruba na hora e volta a exigir sua permissão
                                    para entrar.
                                </p>
                            @endif
                        </div>

                        {{--
                            O BATE-PAPO DA SALA.

                            AO LADO no computador, POR CIMA no celular. Numa tela estreita nao
                            existe espaco para os dois: dividir daria meia lista de recados e
                            meio video, e nenhum dos dois serviria.

                            O que se digita aqui e GRAVADO, e nao so ao vivo. Num sistema de
                            atendimento, e justamente o que nao pode sumir: o numero de serie
                            que o cliente leu do aparelho, o endereco que ele corrigiu, o link
                            que o tecnico colou.
                        --}}
                        <div x-show="painel === 'chat'" x-cloak
                             class="absolute inset-0 z-30 flex flex-col bg-gray-900 sm:relative sm:inset-auto sm:z-auto sm:w-80 sm:shrink-0 sm:border-l sm:border-white/10">

                            <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                                <div>
                                    <p class="text-sm font-medium text-gray-100">Conversa</p>
                                    <p class="text-[11px] text-gray-500">Fica gravada no atendimento</p>
                                </div>
                                <button type="button" @click="abrirPainel('')"
                                        class="grid h-8 w-8 place-items-center rounded-full text-gray-400 hover:bg-white/10 hover:text-white">&times;</button>
                            </div>

                            <div x-ref="recados" class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3">
                                <template x-if="! recados.length">
                                    <p class="text-xs text-gray-500">
                                        Nada escrito ainda. Dá para mandar um link, um endereço, um
                                        número de série — fica salvo depois da chamada.
                                    </p>
                                </template>

                                <template x-for="(r, i) in recados" :key="i">
                                    <div>
                                        <p class="flex items-baseline gap-2">
                                            <span class="text-xs font-medium text-amber-400" x-text="r.nome"></span>
                                            <span class="text-[10px] text-gray-500" x-text="r.hora"></span>
                                        </p>
                                        {{-- break-words: link comprido colado sem espaco estoura
                                             a coluna e empurra o video para fora da tela. --}}
                                        <p class="whitespace-pre-wrap break-words text-sm text-gray-200" x-text="r.corpo"></p>
                                    </div>
                                </template>
                            </div>

                            <div class="border-t border-white/10 p-3">
                                <div class="flex gap-2">
                                    {{-- Enter manda, Shift+Enter pula linha: e o que o dedo de
                                         quem usa WhatsApp o dia inteiro ja espera. --}}
                                    <textarea x-model="rascunho" rows="1" maxlength="800"
                                              @keydown.enter.prevent="if (! $event.shiftKey) mandarRecado()"
                                              placeholder="Escreva um recado…"
                                              class="min-w-0 flex-1 resize-none rounded-lg border-white/15 bg-gray-800 text-sm text-gray-100 placeholder-gray-500 focus:border-amber-500 focus:ring-amber-500"></textarea>

                                    <button type="button" @click="mandarRecado()"
                                            class="shrink-0 rounded-lg bg-amber-500 px-3 text-sm font-semibold text-gray-950 hover:bg-amber-400">
                                        Enviar
                                    </button>
                                </div>
                            </div>
                        </div>
                      </div>

                        {{--
                            A BARRA DE CONTROLES.

                            Icone redondo em vez de pilula com texto, e a razao e o celular: cinco palavras lado a lado
                            quebram em duas linhas e comem a altura que era do video. Icone tem o mesmo tamanho em
                            qualquer lingua, e todo mundo ja aprendeu o que cada um significa em outro aplicativo.

                            A COR DIZ O ESTADO: apagado e neutro, vermelho e desligado. Vermelho aqui nao e erro — e
                            "os outros nao estao te ouvindo", que e a informacao que a pessoa precisa de relance.
                        --}}
                        <div class="flex flex-wrap items-center justify-center gap-2 border-t border-white/10 px-3 py-3">

                            {{-- ---------------------------------------------------- microfone + aparelhos --}}
                            <div class="flex items-center rounded-full"
                                 :class="microfone ? 'bg-white/10' : 'bg-red-500'"
                                 x-data="{ lista: false }" @click.outside="lista = false">

                                <button type="button" @click="alternarMicrofone()"
                                        :title="microfone ? 'Desligar o microfone' : 'Ligar o microfone'"
                                        class="grid h-11 w-11 place-items-center rounded-full text-white">
                                    <svg x-show="microfone" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                                    </svg>
                                    <svg x-show="! microfone" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M3 3l18 18" />
                                    </svg>
                                </button>

                                {{-- A setinha so aparece quando ha o que escolher. Seta que abre lista de um item
                                     so ensina a pessoa a nao clicar em setinha nenhuma. --}}
                                <template x-if="microfones.length > 1">
                                    <div class="relative">
                                        <button type="button" @click="lista = ! lista" title="Escolher o microfone"
                                                class="grid h-11 w-6 place-items-center rounded-r-full text-white/70 hover:text-white">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
                                            </svg>
                                        </button>

                                        <div x-show="lista" x-cloak
                                             class="absolute bottom-14 left-1/2 z-40 w-64 -translate-x-1/2 overflow-hidden rounded-xl bg-gray-800 py-1 shadow-xl ring-1 ring-white/10">
                                            <template x-for="d in microfones" :key="d.id">
                                                <button type="button" @click="trocarDispositivo('audioinput', d.id); lista = false"
                                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs text-gray-200 hover:bg-white/10">
                                                    <span class="w-3 text-amber-400" x-text="d.id === microfoneAtual ? '✓' : ''"></span>
                                                    <span class="truncate" x-text="d.nome"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            {{-- ------------------------------------------------------ câmera + aparelhos --}}
                            <div class="flex items-center rounded-full"
                                 :class="camera ? 'bg-white/10' : 'bg-red-500'"
                                 x-data="{ lista: false }" @click.outside="lista = false">

                                <button type="button" @click="alternarCamera()"
                                        :title="camera ? 'Desligar a câmera' : 'Ligar a câmera'"
                                        class="grid h-11 w-11 place-items-center rounded-full text-white">
                                    <svg x-show="camera" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                                    </svg>
                                    <svg x-show="! camera" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25ZM3 3l18 18" />
                                    </svg>
                                </button>

                                <template x-if="cameras.length > 1">
                                    <div class="relative">
                                        <button type="button" @click="lista = ! lista" title="Escolher a câmera"
                                                class="grid h-11 w-6 place-items-center rounded-r-full text-white/70 hover:text-white">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
                                            </svg>
                                        </button>

                                        <div x-show="lista" x-cloak
                                             class="absolute bottom-14 left-1/2 z-40 w-64 -translate-x-1/2 overflow-hidden rounded-xl bg-gray-800 py-1 shadow-xl ring-1 ring-white/10">
                                            <template x-for="d in cameras" :key="d.id">
                                                <button type="button" @click="trocarDispositivo('videoinput', d.id); lista = false"
                                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs text-gray-200 hover:bg-white/10">
                                                    <span class="w-3 text-amber-400" x-text="d.id === cameraAtual ? '✓' : ''"></span>
                                                    <span class="truncate" x-text="d.nome"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            {{-- --------------------------------------------------------------- virar câmera --}}
                            {{-- O botão que o atendimento por vídeo existe para ter: "me mostra o aparelho".
                                 Só aparece quando há mais de uma câmera, que na prática quer dizer celular. --}}
                            <template x-if="cameras.length > 1">
                                <button type="button" @click="virarCamera()" title="Virar a câmera"
                                        class="grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white hover:bg-white/15">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M16.023 9.348h4.992V4.356m0 4.992-3.181-3.183a8.25 8.25 0 0 0-13.803 3.7M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7" />
                                    </svg>
                                </button>
                            </template>

                            {{-- ---------------------------------------------------------------- mostrar tela --}}
                            <button type="button" @click="alternarTela()"
                                    :title="compartilhando ? 'Parar de mostrar a tela' : 'Mostrar a minha tela'"
                                    class="grid h-11 w-11 place-items-center rounded-full text-white"
                                    :class="compartilhando ? 'bg-amber-500 text-gray-950' : 'bg-white/10 hover:bg-white/15'">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25" />
                                </svg>
                            </button>

                            {{-- ------------------------------------------------------------- levantar a mão --}}
                            {{-- Serve para pedir a vez sem cortar quem está falando. Numa chamada de três é
                                 cortesia; numa de oito é a diferença entre reunião e algazarra. --}}
                            <button type="button" @click="alternarMao()"
                                    :title="maoLevantada ? 'Baixar a mão' : 'Levantar a mão'"
                                    class="grid h-11 w-11 place-items-center rounded-full"
                                    :class="maoLevantada ? 'bg-amber-500 text-gray-950' : 'bg-white/10 text-white hover:bg-white/15'">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M10.05 4.575a1.575 1.575 0 1 0-3.15 0v3m3.15-3v-1.5a1.575 1.575 0 0 1 3.15 0v1.5m-3.15 0 .075 5.925m3.075.75V4.575m0 0a1.575 1.575 0 0 1 3.15 0V15M6.9 7.575a1.575 1.575 0 1 0-3.15 0v8.175a6.75 6.75 0 0 0 6.75 6.75h2.018a5.25 5.25 0 0 0 3.712-1.538l1.732-1.732a5.25 5.25 0 0 0 1.538-3.712l.003-2.024a.668.668 0 0 1 .198-.471 1.575 1.575 0 1 0-2.228-2.228 3.818 3.818 0 0 0-1.12 2.687M6.9 7.575V12" />
                                </svg>
                            </button>

                            {{-- ------------------------------------------------------------------- reações --}}
                            <div class="relative" x-data="{ aberto: false }" @click.outside="aberto = false">
                                <button type="button" @click="aberto = ! aberto" title="Reagir"
                                        class="grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white hover:bg-white/15">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M15.182 15.182a4.5 4.5 0 0 1-6.364 0M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM9.75 9.75h.008v.008H9.75V9.75Zm4.5 0h.008v.008h-.008V9.75Z" />
                                    </svg>
                                </button>

                                <div x-show="aberto" x-cloak
                                     class="absolute bottom-14 left-1/2 z-40 flex -translate-x-1/2 gap-1 rounded-full bg-gray-800 px-2 py-1.5 shadow-xl ring-1 ring-white/10">
                                    @foreach (['👍', '👏', '❤️', '😂', '😮', '🎉'] as $emoji)
                                        <button type="button" @click="reagir('{{ $emoji }}'); aberto = false"
                                                class="grid h-9 w-9 place-items-center rounded-full text-lg hover:bg-white/10">{{ $emoji }}</button>
                                    @endforeach
                                </div>
                            </div>

                            {{-- ----------------------------------------------------------------- gente --}}
    <button type="button" @click="abrirPainel('gente')" title="Quem está na sala"
            class="relative grid h-11 w-11 place-items-center rounded-full"
            :class="painel === 'gente' ? 'bg-amber-500 text-gray-950' : 'bg-white/10 text-white hover:bg-white/15'">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
        </svg>

        <span class="absolute -right-0.5 -top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-white/20 px-1 text-[10px] font-bold text-white"
              x-text="pessoas.length"></span>
    </button>

    {{-- ------------------------------------------------------------------ chat --}}
    <button type="button" @click="abrirPainel('chat')" title="Conversa"
            class="relative grid h-11 w-11 place-items-center rounded-full"
            :class="painel === 'chat' ? 'bg-amber-500 text-gray-950' : 'bg-white/10 text-white hover:bg-white/15'">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
        </svg>

        {{-- O numero existe porque numa chamada ninguem fica olhando para o icone do chat:
             sem ele, o recado com a resposta que a pessoa esperava passa despercebido ate a
             reuniao acabar. --}}
        <template x-if="naoLidos > 0">
            <span class="absolute -right-0.5 -top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white"
                  x-text="naoLidos > 9 ? '9+' : naoLidos"></span>
        </template>
    </button>

    {{-- ------------------------------------------------------------------ mais --}}
                            <div class="relative" x-data="{ aberto: false }" @click.outside="aberto = false">
                                <button type="button" @click="aberto = ! aberto" title="Mais opções"
                                        class="grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white hover:bg-white/15">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M12 6.75h.008v.008H12V6.75Zm0 5.25h.008v.008H12V12Zm0 5.25h.008v.008H12v-.008Z" />
                                    </svg>
                                </button>

                                <div x-show="aberto" x-cloak
                                     class="absolute bottom-14 right-0 z-40 w-56 overflow-hidden rounded-xl bg-gray-800 py-1 shadow-xl ring-1 ring-white/10">
                                    <button type="button"
                                            x-data="{ copiado: false }"
                                            @click="navigator.clipboard.writeText(window.location.href); copiado = true; setTimeout(() => copiado = false, 1600)"
                                            class="block w-full px-3 py-2 text-left text-xs text-gray-200 hover:bg-white/10">
                                        <span x-show="! copiado">Copiar o link da sala</span>
                                        <span x-show="copiado" x-cloak class="text-emerald-400">Copiado!</span>
                                    </button>

                                    <button type="button" @click="tentarMidia(); aberto = false"
                                            class="block w-full px-3 py-2 text-left text-xs text-gray-200 hover:bg-white/10">
                                        Reconectar câmera e microfone
                                    </button>

                                    {{-- O alerta da mão levantada só toca para a mão dos OUTROS,
                                         então sozinho não dá para saber se ele funciona. Este
                                         item existe para responder isso sem precisar de um
                                         segundo aparelho e de uma segunda pessoa. --}}
                                    <button type="button" @click="apitar()"
                                            class="block w-full px-3 py-2 text-left text-xs text-gray-200 hover:bg-white/10">
                                        Testar o som do alerta
                                    </button>

                                    @if ($this->souDaEquipe())
                                        {{-- Serve para a reunião aberta — treinamento,
                                             apresentação — em que liberar um por um vira
                                             trabalho de porteiro e ninguém consegue prestar
                                             atenção no que está sendo dito. Desligar libera
                                             quem já estava na fila. --}}
                                        <button type="button" wire:click="alternarSalaDeEspera"
                                                class="block w-full px-3 py-2 text-left text-xs text-gray-200 hover:bg-white/10">
                                            {{ $this->reuniao()->sala_de_espera
                                                ? 'Deixar qualquer um entrar direto'
                                                : 'Pedir minha permissão para entrar' }}
                                        </button>
                                    @endif

                                    <button type="button" class="hidden">
                                    </button>

                                    @if ($this->souDaEquipe())
                                        {{-- Encerrar derruba todo mundo, e por isso está aqui dentro e não solto na
                                             barra: distância de um clique a mais do dedo de quem só queria sair. --}}
                                        <button type="button" wire:click="encerrar"
                                                wire:confirm="Encerrar a chamada para todos?"
                                                class="block w-full border-t border-white/10 px-3 py-2 text-left text-xs text-red-300 hover:bg-red-500/10">
                                            Encerrar para todos
                                        </button>
                                    @endif
                                </div>
                            </div>

                            {{-- ------------------------------------------------------------------- sair --}}
                            {{-- Vermelho e telefone deitado: e o unico botao da barra que a pessoa procura com
                                 pressa, e o unico que nao pode ser confundido com outro. --}}
                            <button type="button" @click="sair()" title="Sair da chamada"
                                    class="grid h-11 w-14 place-items-center rounded-full bg-red-500 text-white hover:bg-red-400">
                                <svg class="h-5 w-5 rotate-[135deg]" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        @endif
    </main>
</div>
