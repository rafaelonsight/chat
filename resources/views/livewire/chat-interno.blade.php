{{--
    A BOLHA DO CHAT DA EQUIPE.

    Fica no canto de TODA tela do painel de proposito: falar com um colega e coisa que acontece
    no meio de outra coisa. Se morasse numa pagina propria, sair do atendimento para perguntar
    algo custaria perder o lugar onde se estava — e ninguem perde o lugar, ninguem pergunta.

    QUEM ESTA ONLINE VEM DO CANAL DE PRESENCA, ao vivo. Nao ha coluna "ultimo visto" no banco:
    ela envelheceria sozinha, e a pessoa que fechou o navegador continuaria verde ate alguem
    lembrar de limpar. Aqui, quem cai some.
--}}
<div class="fixed bottom-4 right-4 z-30 print:hidden"
     x-data="{
        online: [],

        init() {
            if (! window.Echo) return;

            window.Echo.join('equipe.{{ auth()->user()->tenant_id }}')
                .here((todos) => { this.online = todos.map((u) => u.id); })
                .joining((u) => { if (! this.online.includes(u.id)) this.online.push(u.id); })
                .leaving((u) => { this.online = this.online.filter((id) => id !== u.id); });
        },

        esta(id) {
            return this.online.includes(id);
        },
     }">

    {{-- ------------------------------------------------------------- o painel --}}
    <div x-show="$wire.aberto" x-cloak x-transition.opacity.duration.150ms
         class="mb-3 flex h-[30rem] w-80 flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-white/10 dark:bg-gray-900">

        {{-- cabecalho: lista ou conversa --}}
        @if ($falandoCom)
            <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                <button type="button" wire:click="voltar"
                        class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10"
                        title="Voltar para a lista">&larr;</button>

                <span class="relative grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-500/15 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                    {{ mb_strtoupper(mb_substr($falandoCom->name, 0, 1)) }}
                    <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white dark:border-gray-900"
                          :class="esta({{ $falandoCom->id }}) ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'"></span>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $falandoCom->name }}</p>
                    <p class="text-[11px]"
                       :class="esta({{ $falandoCom->id }}) ? 'text-emerald-600' : 'text-gray-400'"
                       x-text="esta({{ $falandoCom->id }}) ? 'online agora' : 'offline — recebe quando entrar'"></p>
                </div>
            </div>

            {{--
                As mensagens. wire:key com a CONTAGEM dentro: quando chega ou sai mensagem a
                chave muda, o Livewire troca o elemento e o x-init roda de novo, descendo a
                rolagem. Sem isso a conversa cresce para baixo e a ultima linha fica escondida
                logo abaixo da borda — o lugar exato onde ninguem olha.
            --}}
            <div wire:key="recados-{{ $comQuem }}-{{ $conversa->count() }}"
                 x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                 class="flex-1 space-y-2 overflow-y-auto bg-slate-50 p-3 dark:bg-gray-950/40">

                @forelse ($conversa as $recado)
                    @php $meu = $recado->de_user_id === auth()->id(); @endphp

                    <div class="flex {{ $meu ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[85%] rounded-lg px-2.5 py-1.5 text-sm
                                    {{ $meu
                                        ? 'bg-emerald-600 text-white'
                                        : 'bg-white text-gray-800 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:ring-white/10' }}">
                            <p class="whitespace-pre-wrap break-words">{{ $recado->corpo }}</p>
                            <p class="mt-0.5 text-[10px] {{ $meu ? 'text-emerald-100/80' : 'text-gray-400' }}">
                                {{ $recado->created_at?->format('d/m H:i') }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="px-2 py-6 text-center text-xs text-gray-400">
                        Nenhum recado ainda. Escreva o primeiro — se {{ $falandoCom->primeiroNome() }} estiver fora, ele vê ao entrar.
                    </p>
                @endforelse
            </div>

            <form wire:submit="enviar" class="flex items-center gap-2 border-t border-gray-200 p-2 dark:border-white/10">
                <input type="text" wire:model="texto" autocomplete="off"
                       placeholder="Escreva um recado"
                       class="min-w-0 flex-1 rounded border border-slate-300 px-2.5 py-1.5 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                <button type="submit"
                        class="rounded bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">
                    Enviar
                </button>
            </form>
        @else
            <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2 dark:border-white/10">
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Equipe</p>
                <button type="button" wire:click="$set('aberto', false)"
                        class="rounded px-2 text-lg leading-none text-gray-400 hover:text-gray-700">&times;</button>
            </div>

            @if ($equipes->isNotEmpty())
                <div class="border-b border-gray-100 px-3 py-2 dark:border-white/5">
                    <select wire:model.live="equipe"
                            class="w-full rounded-full border-0 bg-gray-100 py-1 pl-2 pr-6 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                        <option value="">Todas as equipes</option>
                        @foreach ($equipes as $eq)
                            <option value="{{ $eq->id }}">{{ $eq->nome }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            {{--
                A LISTA TRAZ TODO MUNDO, e quem esta online sobe para o topo por CSS (order:-1),
                nao por reordenacao no servidor: a presenca muda a toda hora, e uma ida ao
                servidor por pessoa que entra ou sai encheria a rede para mexer numa lista de
                dez linhas.
            --}}
            <div class="flex flex-1 flex-col overflow-y-auto">
                @forelse ($pessoas as $p)
                    <button type="button" wire:click="abrir({{ $p['id'] }})"
                            :style="esta({{ $p['id'] }}) ? 'order:-1' : ''"
                            class="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/5">

                        <span class="relative grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gray-200 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">
                            {{ $p['inicial'] }}
                            <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white dark:border-gray-900"
                                  :class="esta({{ $p['id'] }}) ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'"></span>
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-gray-800 dark:text-gray-100">{{ $p['nome'] }}</span>
                            <span class="block text-[11px]"
                                  :class="esta({{ $p['id'] }}) ? 'text-emerald-600' : 'text-gray-400'"
                                  x-text="esta({{ $p['id'] }}) ? 'online' : 'offline'"></span>
                        </span>

                        @if (($naoLidas[$p['id']] ?? 0) > 0)
                            <span class="grid h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1.5 text-[11px] font-semibold text-white">
                                {{ $naoLidas[$p['id']] }}
                            </span>
                        @endif
                    </button>
                @empty
                    <p class="p-4 text-center text-xs text-gray-400">
                        @if ($equipe)
                            Ninguém nesta equipe.
                        @else
                            Você é a única pessoa cadastrada por enquanto.
                        @endif
                    </p>
                @endforelse
            </div>
        @endif
    </div>

    {{-- ------------------------------------------------------------- a bolha --}}
    <button type="button" wire:click="$toggle('aberto')"
            class="relative grid h-12 w-12 place-items-center rounded-full bg-emerald-600 text-white shadow-lg transition hover:bg-emerald-700"
            title="Chat da equipe">

        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-6 w-6">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
        </svg>

        {{-- O contador soma TODOS os remetentes: a bolha fechada precisa responder "tem coisa
             para mim?", e nao "de quem". De quem, a lista responde quando abrir. --}}
        @if ($total > 0)
            <span class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[11px] font-bold text-white ring-2 ring-white dark:ring-gray-900">
                {{ $total > 9 ? '9+' : $total }}
            </span>
        @endif
    </button>
</div>
