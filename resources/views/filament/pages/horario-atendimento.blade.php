<x-filament-panels::page>
    <p class="max-w-3xl text-sm text-gray-500 dark:text-gray-400">
        Define quando sua empresa atende. Serve para dois fins: o relatório passa a contar
        <strong>só o tempo dentro do horário</strong> — mensagem das 23h respondida às 8h35 deixa de
        aparecer como 9h35 de espera — e a resposta automática sabe quando disparar.
    </p>

    @if ($abertoAgora !== null)
        <div class="flex items-center gap-2 text-sm">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium
                         {{ $abertoAgora ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300' }}">
                <span class="h-1.5 w-1.5 rounded-full {{ $abertoAgora ? 'bg-emerald-600' : 'bg-gray-500' }}"></span>
                {{ $abertoAgora ? 'Aberto agora' : 'Fechado agora' }}
            </span>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ $agora }} no fuso da conta
                @if (! $abertoAgora && $proxima) &middot; volta {{ $proxima }} @endif
            </span>
        </div>
    @endif

    {{-- de quem e esta grade --}}
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="mr-1 text-xs font-medium text-gray-500 dark:text-gray-400">Horário de</span>

        <button type="button" wire:click="trocarEscopo('conta')"
                class="rounded-full px-3 py-1 text-xs font-medium {{ $escopo === 'conta' ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300' }}">
            Conta (padrão)
        </button>

        @foreach ($equipes as $eq)
            <button type="button" wire:key="esc-{{ $eq->id }}" wire:click="trocarEscopo('equipe:{{ $eq->id }}')"
                    class="rounded-full px-3 py-1 text-xs font-medium {{ $escopo === 'equipe:'.$eq->id ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300' }}">
                {{ $eq->nome }}
            </button>
        @endforeach
    </div>

    @if ($escopo !== 'conta')
        @if ($herdando)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                Esta equipe <strong>herda o horário da conta</strong>. Os campos abaixo mostram o que ela usa hoje;
                salvar cria uma grade própria para ela.
            </div>
        @else
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                <span>Esta equipe tem <strong>grade própria</strong>, que prevalece sobre a da conta.</span>
                <button type="button" wire:click="limparEscopo"
                        class="rounded border border-emerald-300 px-2 py-0.5 font-medium hover:bg-emerald-100 dark:border-emerald-500/40 dark:hover:bg-emerald-500/20">
                    voltar a herdar da conta
                </button>
            </div>
        @endif

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Fuso horário, resposta automática e exceções de data valem para a <strong>conta inteira</strong>, não por equipe.
        </p>
    @endif

    <form wire:submit="salvar" class="space-y-6">
        {{-- grade --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium">Dia da semana</th>
                        <th class="px-3 py-3 text-left font-medium">Ativo</th>
                        <th class="px-3 py-3 text-left font-medium">Início</th>
                        <th class="px-3 py-3 text-left font-medium">Início do almoço</th>
                        <th class="px-3 py-3 text-left font-medium">Fim do almoço</th>
                        <th class="px-3 py-3 text-left font-medium">Fim</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($nomesDias as $dia => $nome)
                        <tr wire:key="dia-{{ $dia }}">
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100">{{ $nome }}</td>
                            <td class="px-3 py-3">
                                <input type="checkbox" wire:model="dias.{{ $dia }}.ativo"
                                       class="h-4 w-4 rounded border-gray-300 text-emerald-600">
                            </td>
                            @foreach (['inicio', 'almoco_inicio', 'almoco_fim', 'fim'] as $campo)
                                <td class="px-3 py-3">
                                    <input type="time" wire:model="dias.{{ $dia }}.{{ $campo }}"
                                           @disabled(! ($dias[$dia]['ativo'] ?? false))
                                           class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm disabled:opacity-40 dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @error('dias.*.inicio')
                <p class="border-t border-gray-200 px-4 py-2 text-xs text-red-600 dark:border-white/10">{{ $message }}</p>
            @enderror
            <p class="border-t border-gray-200 px-4 py-2 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                Para um dia <strong>sem pausa</strong>, deixe início e fim do almoço iguais.
            </p>
        </div>

        {{-- fuso --}}
        <div class="max-w-sm">
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Fuso horário</label>
            <select wire:model="fuso_horario"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                @foreach ($fusos as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">É o mesmo campo do Cadastro da conta.</p>
            @error('fuso_horario') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        {{--
            A assinatura mora ao lado da resposta automatica porque as duas sao a mesma
            familia: coisas que o sistema escreve no lugar da pessoa. Nao tem a ver com
            horario, mas separar em outra tela por pureza faria o dono procurar em dois
            lugares o que ele pensa como um assunto so.
        --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="assinatura_ativa"
                       class="mt-1 h-4 w-4 rounded border-gray-300 text-emerald-600">
                <span>
                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Assinar as mensagens</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        Põe o primeiro nome de quem respondeu em negrito, na primeira linha.
                        O cliente vê <strong>um número só</strong> — sem isso, três pessoas
                        diferentes respondendo parecem a mesma pessoa mudando de ideia.
                        Não vale para nota interna nem para resposta automática.
                    </span>
                </span>
            </label>
        </div>

        {{-- pesquisa de satisfacao --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="pesquisa_ativa"
                       class="mt-1 h-4 w-4 rounded border-gray-300 text-emerald-600">
                <span>
                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Pesquisa de satisfação</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        Pergunta a nota de 1 a 5 quando o atendimento é encerrado. A resposta
                        do cliente <strong>não cria conversa nova</strong>: ela é guardada no
                        atendimento que acabou e a média aparece nos Relatórios.
                        Não pergunta quando a janela de 24h do canal oficial está fechada.
                    </span>
                </span>
            </label>

            @if ($pesquisa_ativa)
                <div class="mt-4">
                    <textarea wire:model="pesquisa_texto" rows="2"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>
                    @error('pesquisa_texto') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        {{-- resposta automatica --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="resposta_ativa"
                       class="mt-1 h-4 w-4 rounded border-gray-300 text-emerald-600">
                <span>
                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Resposta automática</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        Responde o cliente quando a mensagem chega fora do horário.
                        Envia <strong>uma vez por conversa</strong> e rearma quando um atendente responder —
                        cinco mensagens às 22h não geram cinco respostas.
                        <strong>Nunca dispara em grupo.</strong>
                    </span>
                </span>
            </label>

            @if ($resposta_ativa)
                <div class="mt-4">
                    <textarea wire:model="resposta_texto" rows="3"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Marcadores:
                        @foreach ($marcadores as $tag => $desc)
                            <code class="rounded bg-gray-100 px-1 dark:bg-white/10">{{ $tag }}</code> {{ $desc }}@if (! $loop->last) &middot; @endif
                        @endforeach
                    </p>
                    @error('resposta_texto') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        <x-filament::button type="submit" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="salvar">Salvar</span>
            <span wire:loading wire:target="salvar">Salvando…</span>
        </x-filament::button>
    </form>

    {{-- excecoes --}}
    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">Feriados e exceções</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                A grade semanal não sabe que 25/12 é Natal. Sem exceção, o sistema diria que está aberto
                e o relatório puniria a equipe pelo feriado.
            </p>
        </div>

        @if ($excecoes->isNotEmpty())
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($excecoes as $ex)
                        <tr wire:key="ex-{{ $ex->id }}">
                            <td class="px-4 py-2 text-gray-800 dark:text-gray-100">{{ $ex->data->format('d/m/Y') }}</td>
                            <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ $ex->descricao ?: '—' }}</td>
                            <td class="px-4 py-2">
                                @if ($ex->fechado)
                                    <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs dark:bg-white/10">fechado</span>
                                @else
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300">
                                        {{ $ex->intervalos[0]['inicio'] ?? '?' }}–{{ $ex->intervalos[0]['fim'] ?? '?' }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="removerExcecao({{ $ex->id }})"
                                        class="text-xs text-red-600 hover:underline">remover</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">Nenhuma exceção cadastrada.</p>
        @endif

        <div class="flex flex-wrap items-end gap-3 border-t border-gray-200 px-4 py-3 dark:border-white/10">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Data</label>
                <input type="date" wire:model="ex_data"
                       class="rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                @error('ex_data') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Descrição</label>
                <input type="text" wire:model="ex_descricao" placeholder="Natal"
                       class="rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
            </div>
            <label class="flex items-center gap-2 pb-1.5 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" wire:model.live="ex_fechado" class="h-4 w-4 rounded border-gray-300 text-emerald-600">
                Fechado o dia todo
            </label>
            @unless ($ex_fechado)
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Início</label>
                    <input type="time" wire:model="ex_inicio" class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Fim</label>
                    <input type="time" wire:model="ex_fim" class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                    @error('ex_fim') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endunless
            <x-filament::button type="button" color="gray" wire:click="adicionarExcecao">Adicionar</x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
