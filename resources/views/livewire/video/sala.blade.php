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

                        <button type="button" wire:click="entrar" wire:loading.attr="disabled"
                                class="mt-4 w-full rounded-lg bg-amber-500 py-2.5 text-sm font-semibold text-gray-950 transition hover:bg-amber-400 disabled:opacity-60">
                            <span wire:loading.remove wire:target="entrar">Entrar</span>
                            <span wire:loading wire:target="entrar">Abrindo…</span>
                        </button>
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
                <template x-if="erro">
                    <div class="border-b border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="erro"></div>
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

                                    <video autoplay playsinline
                                           :muted="p.souEu"
                                           x-effect="plugarVideo($el, p)"
                                           class="h-full w-full object-cover"
                                           :class="{ 'opacity-0': ! (p.video || p.tela) }"></video>

                                    <audio autoplay x-effect="plugarAudio($el, p)" class="hidden"></audio>

                                    {{-- sem câmera: as iniciais, para o quadro não virar um buraco preto --}}
                                    <template x-if="! (p.video || p.tela)">
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
