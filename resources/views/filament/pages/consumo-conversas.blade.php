<x-filament-panels::page>
    {{-- O que este numero e, dito antes de mostrar o numero. Base de cobranca sem definicao
         escrita vira discussao no primeiro boleto. --}}
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
        <p><strong>A conta é por conversa iniciada.</strong></p>
        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
            Uma conversa nasce quando alguém fala com você e não há atendimento aberto. Se o
            mesmo cliente escreve cinco vezes no mesmo atendimento, continua sendo uma. Se ele
            volta no mês seguinte, é outra.
            As mensagens aparecem ao lado como informação — não entram no cálculo.
        </p>
    </div>

    @foreach ($linhas as $linha)
        <x-filament::section>
            <x-slot name="heading">
                {{ $operador ? $linha['conta']->nome : 'Este mês' }}
            </x-slot>

            @if ($operador)
                <x-slot name="description">Mês corrente, ainda em andamento</x-slot>
            @endif

            <div class="grid gap-4 sm:grid-cols-4">
                @foreach ([
                    ['Conversas', $linha['atual']['conversas'], 'é o que conta na fatura'],
                    ['Pessoas', $linha['atual']['contatos'], 'contatos diferentes'],
                    ['Recebidas', $linha['atual']['recebidas'], 'mensagens do cliente'],
                    ['Enviadas', $linha['atual']['enviadas'], 'suas mensagens'],
                ] as [$titulo, $valor, $nota])
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $titulo }}</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $valor }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ $nota }}</p>
                    </div>
                @endforeach
            </div>

            @if ($linha['fechados']->isNotEmpty())
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10">
                                <th class="py-2">Mês fechado</th>
                                <th class="py-2 text-right">Conversas</th>
                                <th class="py-2 text-right">Pessoas</th>
                                <th class="py-2 text-right">Recebidas</th>
                                <th class="py-2 text-right">Enviadas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($linha['fechados'] as $m)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 capitalize">{{ $m->mesLegivel() }}</td>
                                    <td class="py-2 text-right font-medium">{{ $m->conversas }}</td>
                                    <td class="py-2 text-right text-gray-500">{{ $m->contatos_alcancados }}</td>
                                    <td class="py-2 text-right text-gray-500">{{ $m->mensagens_recebidas }}</td>
                                    <td class="py-2 text-right text-gray-500">{{ $m->mensagens_enviadas }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-2 text-xs text-gray-400">
                    Os meses fechados são uma foto tirada no dia 1 e não mudam mais — nem se um
                    canal for apagado depois.
                </p>
            @else
                <p class="mt-4 text-xs text-gray-500">
                    Nenhum mês fechado ainda. A primeira foto sai no dia 1 do mês que vem.
                </p>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
