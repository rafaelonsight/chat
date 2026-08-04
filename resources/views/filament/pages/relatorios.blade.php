<x-filament-panels::page>
    {{-- periodo --}}
    <div class="flex items-center gap-2">
        <span class="text-sm text-gray-500 dark:text-gray-400">Periodo:</span>
        @foreach ([7 => '7 dias', 30 => '30 dias', 90 => '90 dias'] as $d => $rotulo)
            <button type="button" wire:key="per-{{ $d }}" wire:click="periodo({{ $d }})"
                    class="rounded px-3 py-1.5 text-xs font-medium transition
                           {{ $dias === $d
                                ? 'bg-emerald-600 text-white'
                                : 'border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/20 dark:text-gray-300 dark:hover:bg-white/5' }}">
                {{ $rotulo }}
            </button>
        @endforeach

        @if ($etiquetas->isNotEmpty())
            {{-- Recorte por etiqueta: vale para TODOS os numeros da pagina, nao so para
                 a tabela de etiquetas. Filtro que pega em parte da tela faria o gestor
                 comparar conversas de um recorte com mensagens de outro. --}}
            <span class="ml-4 text-sm text-gray-500 dark:text-gray-400">Etiqueta:</span>
            <select wire:model.live="etiqueta"
                    class="rounded border border-gray-300 px-2 py-1.5 text-xs text-gray-700 dark:border-white/20 dark:bg-gray-800 dark:text-gray-200">
                <option value="">Todas</option>
                @foreach ($etiquetas as $et)
                    <option value="{{ $et->id }}">{{ $et->nome }}</option>
                @endforeach
            </select>

            @if ($etiquetaEscolhida)
                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $etiquetaEscolhida->classes() }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $etiquetaEscolhida->pontinho() }}"></span>
                    {{ $etiquetaEscolhida->nome }}
                </span>
                <button type="button" wire:click="$set('etiqueta', null)"
                        class="text-xs text-gray-500 underline hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">
                    limpar
                </button>
            @endif
        @endif
    </div>

    {{-- cartoes --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @php
            $cartoes = [
                ['Conversas no periodo', $resumo['conversas'], 'iniciadas nos ultimos '.$resumo['dias'].' dias'],
                ['Encerradas', $resumo['encerradas'], 'do total do periodo'],
                ['Abertas agora', $resumo['abertas'], 'independente do periodo'],
                ['Na fila (Novas)', $resumo['na_fila'], 'aguardando primeira resposta'],
            ];
        @endphp
        @foreach ($cartoes as [$titulo, $valor, $nota])
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $titulo }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $valor }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ $nota }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @php
            $cartoes2 = [
                ['Mensagens', $resumo['mensagens'], 'no periodo'],
                ['Recebidas', $resumo['recebidas'], 'do cliente'],
                ['Enviadas', $resumo['enviadas'], 'nossas'],
                ['1a resposta (media)', \App\Filament\Pages\Relatorios::formatarDuracao($primeiraResposta['media']),
                 'base de '.$primeiraResposta['base'].' conversa(s)'.($emHorarioUtil ? ' · em horário útil' : '')],
            ];
        @endphp
        @foreach ($cartoes2 as [$titulo, $valor, $nota])
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $titulo }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $valor }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ $nota }}</p>
            </div>
        @endforeach
    </div>

    @unless ($emHorarioUtil)
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
            O tempo de primeira resposta está contando <strong>relógio de parede</strong>. Configure o
            <a href="/admin/horario-atendimento" class="underline">horário de atendimento</a> para que a noite,
            o fim de semana e os feriados deixem de entrar na conta.
        </div>
    @endunless

    @if ($primeiraResposta['sem_resposta'] > 0)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <strong>{{ $primeiraResposta['sem_resposta'] }}</strong> conversa(s) do periodo receberam mensagem
            do cliente e <strong>nunca foram respondidas</strong>. Elas ficam fora da media acima de
            proposito — entrariam como zero e esconderiam o problema.
        </div>
    @endif

    {{-- tabelas --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([['Por canal', $porCanal, 'canal'], ['Por atendente', $porAtendente, 'atendente'], ['Por equipe', $porEquipe, 'equipe']] as [$titulo, $linhas, $campo])
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <div class="border-b border-gray-200 px-4 py-3 text-sm font-medium text-gray-700 dark:border-white/10 dark:text-gray-200">
                    {{ $titulo }}
                </div>
                @if ($linhas->isEmpty())
                    <p class="p-4 text-sm text-gray-500 dark:text-gray-400">Nada no periodo.</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="text-xs text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">{{ ucfirst($campo) }}</th>
                                <th class="px-4 py-2 text-right font-medium">Conversas</th>
                                <th class="px-4 py-2 text-right font-medium">Encerradas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($linhas as $linha)
                                <tr>
                                    <td class="px-4 py-2 text-gray-800 dark:text-gray-100">{{ $linha->{$campo} }}</td>
                                    <td class="px-4 py-2 text-right text-gray-800 dark:text-gray-100">{{ $linha->conversas }}</td>
                                    <td class="px-4 py-2 text-right text-gray-500 dark:text-gray-400">{{ $linha->encerradas }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach

        {{-- Fora do laco porque tem o ponto de cor e a nota de rodape: etiqueta e o
             unico corte em que uma conversa cabe em mais de uma linha. --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-4 py-3 text-sm font-medium text-gray-700 dark:border-white/10 dark:text-gray-200">
                Por etiqueta
            </div>

            @if ($porEtiqueta->isEmpty() && $semEtiqueta === 0)
                <p class="p-4 text-sm text-gray-500 dark:text-gray-400">Nada no periodo.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">Etiqueta</th>
                            <th class="px-4 py-2 text-right font-medium">Conversas</th>
                            <th class="px-4 py-2 text-right font-medium">Encerradas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($porEtiqueta as $linha)
                            <tr wire:key="rel-et-{{ $loop->index }}">
                                <td class="px-4 py-2 text-gray-800 dark:text-gray-100">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full {{ \App\Models\Tag::ponto($linha->cor) }}"></span>
                                        {{ $linha->etiqueta }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right text-gray-800 dark:text-gray-100">{{ $linha->conversas }}</td>
                                <td class="px-4 py-2 text-right text-gray-500 dark:text-gray-400">{{ $linha->encerradas }}</td>
                            </tr>
                        @endforeach

                        @if ($semEtiqueta > 0)
                            {{-- Cobertura: cem conversas etiquetadas parecem otimo ate se
                                 descobrir que houve mil. --}}
                            <tr class="bg-gray-50/60 dark:bg-white/5">
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full bg-gray-300 dark:bg-white/20"></span>
                                        sem etiqueta
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right text-gray-500 dark:text-gray-400">{{ $semEtiqueta }}</td>
                                <td class="px-4 py-2 text-right text-gray-400">—</td>
                            </tr>
                        @endif
                    </tbody>
                </table>

                <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                    A etiqueta fica no contato, não na conversa: conta quem tem a etiqueta
                    <strong>hoje</strong>. Contato com duas etiquetas aparece nas duas, então a
                    soma passa do total.
                </p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
