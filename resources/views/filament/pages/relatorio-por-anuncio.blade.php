<x-filament-panels::page>
    {{-- Periodo: os mesmos tres da visao geral, para o gestor nao ter que reaprender. --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach ([7 => '7 dias', 30 => '30 dias', 90 => '90 dias'] as $qtd => $rotulo)
            <button type="button" wire:click="periodo({{ $qtd }})" @class([
                'rounded px-3 py-1.5 text-sm',
                'bg-primary-600 text-white' => $dias === $qtd,
                'border border-gray-300 text-gray-700 dark:border-white/20 dark:text-gray-300' => $dias !== $qtd,
            ])>{{ $rotulo }}</button>
        @endforeach
    </div>

    @if ($linhas->isEmpty())
        <div class="mt-4 rounded-lg border border-gray-200 p-6 text-center dark:border-white/10">
            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
                Nenhuma conversa veio de anúncio neste período
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Esta tela se enche sozinha quando alguém clica num anúncio Click-to-WhatsApp:
                a Meta informa qual anúncio junto da primeira mensagem, e o OnChat guarda.
                Nada para configurar aqui.
            </p>
        </div>
    @else
        <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Anúncio</th>
                        <th class="px-3 py-2 text-right">Conversas</th>
                        <th class="px-3 py-2 text-right">Encerradas</th>
                        <th class="px-3 py-2">ID</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($linhas as $linha)
                        <tr>
                            <td class="px-3 py-2">
                                <span class="text-gray-800 dark:text-gray-100">{{ $linha['titulo'] }}</span>
                                @if ($linha['tipo'] && $linha['tipo'] !== 'ad')
                                    <span class="ml-1 rounded bg-gray-100 px-1 text-[10px] text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                        {{ $linha['tipo'] }}
                                    </span>
                                @endif
                                @if ($linha['url'])
                                    <a href="{{ $linha['url'] }}" target="_blank" rel="noopener noreferrer"
                                       class="ml-1 text-xs text-emerald-700 hover:underline dark:text-emerald-400">abrir</a>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right font-medium text-gray-900 dark:text-gray-100">{{ $linha['conversas'] }}</td>
                            <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-400">{{ $linha['encerradas'] }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $linha['origem_id'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- O contexto que impede a leitura errada: sem ele, a tela sugere que anuncio e a
         principal porta de entrada, e a decisao de orcamento sai torta. --}}
    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        No período: <strong>{{ $total }}</strong> conversa(s) no total,
        <strong>{{ $total - $outras }}</strong> de anúncio e
        <strong>{{ $outras }}</strong> por outros caminhos.
    </p>
</x-filament-panels::page>
