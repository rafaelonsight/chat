
    {{-- O que este numero e, dito antes de mostrar o numero. Base de cobranca sem definicao
         escrita vira discussao no primeiro boleto. --}}
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
        <p><strong>{{ __('ui_e99b0abda068') }}</strong></p>
        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
            {{ __('ui_704fa2ae6164') }}
        </p>
    </div>

    @foreach ($linhas as $linha)
        <x-filament::section>
            <x-slot name="heading">
                {{ $operador ? $linha['conta']->nome : 'Este mês' }}
            </x-slot>

            @if ($operador)
                <x-slot name="description">{{ __('ui_e013c6fc39d9') }}</x-slot>
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
                                <th class="py-2">{{ __('ui_4c1f56058006') }}</th>
                                <th class="py-2 text-right">{{ __('ui_86d0e6f30c0e') }}</th>
                                <th class="py-2 text-right">{{ __('ui_c9929703a8da') }}</th>
                                <th class="py-2 text-right">{{ __('ui_88adb0e2fc58') }}</th>
                                <th class="py-2 text-right">{{ __('ui_422b6c14f240') }}</th>
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
                    {{ __('ui_0b206042b1e0') }}
                </p>
            @else
                <p class="mt-4 text-xs text-gray-500">
                    {{ __('ui_61f7b4688e5d') }}
                </p>
            @endif
        </x-filament::section>
    @endforeach

