<x-filament-panels::page>
    @php
        $itens = $this->itens();
        $problemas = collect($itens)->where('nivel', '!=', 'ok');
        $criticos = $problemas->where('nivel', 'critico')->count();
    @endphp

    {{-- O veredito primeiro, em uma linha. Quem abre esta tela quer saber se pode parar de
         se preocupar, nao ler uma lista. --}}
    <div @class([
        'rounded-lg border p-4',
        'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10' => $problemas->isEmpty(),
        'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' => $problemas->isNotEmpty() && $criticos === 0,
        'border-red-200 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10' => $criticos > 0,
    ])>
        <p class="text-base font-semibold text-gray-900 dark:text-gray-100">
            @if ($problemas->isEmpty())
                Tudo certo
            @elseif ($criticos > 0)
                {{ $criticos }} problema(s) crítico(s)
            @else
                {{ $problemas->count() }} ponto(s) de atenção
            @endif
        </p>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Verificado agora, {{ now()->format('d/m/Y H:i') }}.
            @if ($problemas->isEmpty())
                As {{ count($itens) }} verificações abaixo passaram.
            @endif
        </p>
    </div>

    {{-- A lista inteira, e nao so o que quebrou: "tudo certo" sem dizer o que foi olhado
         nao tranquiliza — pode significar que nada foi verificado. --}}
    <div class="mt-4 divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
        @foreach ($itens as $item)
            <div class="flex items-start gap-3 bg-white p-3 dark:bg-gray-900">
                <span @class([
                    'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                    'bg-emerald-500' => $item['nivel'] === 'ok',
                    'bg-amber-500' => $item['nivel'] === 'aviso',
                    'bg-red-500' => $item['nivel'] === 'critico',
                ])></span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm text-gray-800 dark:text-gray-100">{{ $item['descricao'] }}</p>

                    @if ($item['mensagem'])
                        <p @class([
                            'text-xs',
                            'text-amber-700 dark:text-amber-400' => $item['nivel'] === 'aviso',
                            'text-red-700 dark:text-red-400' => $item['nivel'] === 'critico',
                        ])>{{ $item['mensagem'] }}</p>
                    @endif
                </div>

                <span @class([
                    'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium uppercase',
                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' => $item['nivel'] === 'ok',
                    'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' => $item['nivel'] === 'aviso',
                    'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' => $item['nivel'] === 'critico',
                ])>
                    {{ $item['nivel'] === 'ok' ? 'ok' : $item['nivel'] }}
                </span>
            </div>
        @endforeach
    </div>

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        A mesma verificação roda sozinha a cada 5 minutos e avisa quando encontra algo crítico.
        Esta tela existe para a pergunta que aparece no meio do atendimento, quando ninguém
        quer abrir o servidor para saber se o problema é do sistema.
    </p>
</x-filament-panels::page>
