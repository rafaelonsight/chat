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
                 'base de '.$primeiraResposta['base'].' conversa(s)'],
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

    @if ($primeiraResposta['sem_resposta'] > 0)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <strong>{{ $primeiraResposta['sem_resposta'] }}</strong> conversa(s) do periodo receberam mensagem
            do cliente e <strong>nunca foram respondidas</strong>. Elas ficam fora da media acima de
            proposito — entrariam como zero e esconderiam o problema.
        </div>
    @endif

    {{-- tabelas --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([['Por canal', $porCanal, 'canal'], ['Por atendente', $porAtendente, 'atendente']] as [$titulo, $linhas, $campo])
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
    </div>
</x-filament-panels::page>
