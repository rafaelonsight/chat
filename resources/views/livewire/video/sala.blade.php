<div class="flex min-h-screen flex-col">
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

    <main class="flex flex-1 flex-col">
        @if ($recado)
            <div class="border-b border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                {{ $recado }}
            </div>
        @endif

        {{-- ---------------------------------------------------- antes de entrar --}}
        @if (! $entrou)
            <div class="flex flex-1 items-center justify-center p-6">
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
            <div
                x-data="salaDeVideo()"
                x-init="entrar(@js($tokenDeVideo), @js($urlDeVideo))"
                class="flex flex-1 flex-col"
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
                        <p class="text-lg font-semibold">Você saiu da chamada</p>
                        <button type="button" wire:click="$refresh"
                                class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-gray-950 hover:bg-amber-400">
                            Entrar de novo
                        </button>
                    </div>
                </template>

                <template x-if="dentro">
                    <div class="flex flex-1 flex-col">
                        {{-- os quadros --}}
                        <div class="grid flex-1 content-center gap-2 p-2" :class="colunas">
                            <template x-for="p in pessoas" :key="p.id">
                                <div class="relative aspect-video overflow-hidden rounded-xl bg-gray-900 ring-1"
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

                                    <div class="absolute inset-x-0 bottom-0 flex items-center gap-1.5 bg-gradient-to-t from-black/70 to-transparent px-2 py-1.5">
                                        <span class="truncate text-xs font-medium text-white" x-text="p.nome"></span>
                                        <template x-if="p.semSom">
                                            <span class="text-[10px] text-red-300">sem som</span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- os controles --}}
                        <div class="flex flex-wrap items-center justify-center gap-2 border-t border-white/10 px-4 py-3">
                            <button type="button" @click="alternarMicrofone()"
                                    class="rounded-full px-4 py-2 text-xs font-medium transition"
                                    :class="microfone ? 'bg-white/10 text-gray-100 hover:bg-white/15' : 'bg-red-500 text-white hover:bg-red-400'"
                                    x-text="microfone ? 'Microfone' : 'Sem som'"></button>

                            <button type="button" @click="alternarCamera()"
                                    class="rounded-full px-4 py-2 text-xs font-medium transition"
                                    :class="camera ? 'bg-white/10 text-gray-100 hover:bg-white/15' : 'bg-red-500 text-white hover:bg-red-400'"
                                    x-text="camera ? 'Câmera' : 'Sem câmera'"></button>

                            <button type="button" @click="alternarTela()"
                                    class="rounded-full px-4 py-2 text-xs font-medium transition"
                                    :class="compartilhando ? 'bg-amber-500 text-gray-950 hover:bg-amber-400' : 'bg-white/10 text-gray-100 hover:bg-white/15'"
                                    x-text="compartilhando ? 'Parar de mostrar' : 'Mostrar tela'"></button>

                            <button type="button" @click="sair()"
                                    class="rounded-full bg-red-500 px-4 py-2 text-xs font-semibold text-white hover:bg-red-400">
                                Sair
                            </button>

                            @if ($this->souDaEquipe())
                                {{-- Encerrar derruba todo mundo, e por isso e da equipe: quem
                                     obedece e o servidor de midia, pelo token, e nao este botao. --}}
                                <button type="button" wire:click="encerrar"
                                        wire:confirm="Encerrar a chamada para todos?"
                                        class="rounded-full border border-red-400/40 px-4 py-2 text-xs font-medium text-red-300 hover:bg-red-500/10">
                                    Encerrar para todos
                                </button>
                            @endif
                        </div>
                    </div>
                </template>
            </div>
        @endif
    </main>
</div>
