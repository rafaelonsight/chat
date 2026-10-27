<x-filament-panels::page>
    {{-- periodo --}}
    <div class="flex items-center gap-2">
        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('ui_6993ec6931a8') }}</span>
        @foreach ([7 => '7 dias', 30 => '30 dias', 90 => '90 dias'] as $d => $rotulo)
            <button type="button" wire:key="per-{{ $d }}" wire:click="periodo({{ $d }})"
                    class="rounded px-3 py-1.5 text-xs font-medium transition
                           {{ $dias === $d
                                ? 'bg-emerald-600 text-white'
                                : 'border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/20 dark:text-gray-300 dark:hover:bg-white/5' }}">
                {{ $rotulo }}
            </button>
        @endforeach

        <label class="text-xs">{{ __('ui_e03e3996dc23') }}<select wire:model.live="equipe" class="rounded border border-gray-300 p-2"><option value="">{{ __('ui_f2645f822bc1') }}</option>@foreach(\App\Models\Team::orderBy('nome')->get() as $team)<option value="{{ $team->id }}">{{ $team->nome }}</option>@endforeach</select></label>
        <label class="text-xs">{{ __('ui_1642e770673d') }}<select wire:model.live="atendente" class="rounded border border-gray-300 p-2"><option value="">{{ __('ui_043ca37e441a') }}</option>@foreach(\App\Models\User::where('perfil','!=','auditor')->orderBy('name')->get() as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></label>
        @if ($etiquetas->isNotEmpty())
            {{-- Recorte por etiqueta: vale para TODOS os numeros da pagina, nao so para
                 a tabela de etiquetas. Filtro que pega em parte da tela faria o gestor
                 comparar conversas de um recorte com mensagens de outro. --}}
            <span class="ml-4 text-sm text-gray-500 dark:text-gray-400">{{ __('ui_30a3e4d3e02a') }}</span>
            <select wire:model.live="etiqueta"
                    class="rounded border border-gray-300 px-2 py-1.5 text-xs text-gray-700 dark:border-white/20 dark:bg-gray-800 dark:text-gray-200">
                <option value="">{{ __('ui_8a3c3440366a') }}</option>
                @foreach ($etiquetas->groupBy('contexto') as $contexto=>$grupo)<optgroup label="{{ $contexto==='contato'?'Etiquetas do contato':'Etiquetas do atendimento' }}">@foreach($grupo as $et)<option value="{{ $et->id }}">{{ $et->nome }}</option>@endforeach</optgroup>@endforeach
            </select>

            @if ($etiquetaEscolhida?->contexto === 'contato')<small>{{ __('ui_7c873bddd51b') }}</small>@endif
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
                // Comentario de PHP e nao {{-- --}}: isto esta DENTRO de um @php, e ali o
                // Blade nao interpreta comentario dele — vira erro de sintaxe na view.
                //
                // A base vem junto do numero de proposito: 5,0 com duas respostas nao e a
                // mesma coisa que 4,6 com duzentas, e media sozinha faz decidir por duas
                // pessoas. Sem resposta nenhuma mostra um traco, nao um zero — zero seria
                // lido como "nota zero".
                ['Satisfação', $resumo['satisfacao'] !== null ? number_format($resumo['satisfacao'], 1, ',', '') : '—',
                 $resumo['satisfacao_base'].' de '.$resumo['pesquisas_enviadas'].' responderam'],
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
            {{ __('ui_4e41168217ad') }} <strong>{{ __('ui_ae4b16ba3b5d') }}</strong>{{ __('ui_d0b55954b95b') }}
            <a href="/admin/horario-atendimento" class="underline">{{ __('ui_eb302bdd1369') }}</a> {{ __('ui_7897066b7616') }}
        </div>
    @endunless

    @if ($primeiraResposta['sem_resposta'] > 0)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <strong>{{ $primeiraResposta['sem_resposta'] }}</strong> {{ __('ui_9695dc980c9e') }} <strong>{{ __('ui_070df253b667') }}</strong>{{ __('ui_3ea592872d03') }}
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
                    <p class="p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('ui_dce0cbc9c203') }}</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="text-xs text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">{{ ucfirst($campo) }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('ui_86d0e6f30c0e') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('ui_8bd3b20f6a25') }}</th>
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
                {{ __('ui_cc1287209395') }}
            </div>

            @if ($porEtiqueta->isEmpty() && $semEtiqueta === 0)
                <p class="p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('ui_dce0cbc9c203') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">{{ __('ui_07287eda176e') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ __('ui_86d0e6f30c0e') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ __('ui_8bd3b20f6a25') }}</th>
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
                                        {{ __('ui_4db34548a8e4') }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right text-gray-500 dark:text-gray-400">{{ $semEtiqueta }}</td>
                                <td class="px-4 py-2 text-right text-gray-400">—</td>
                            </tr>
                        @endif
                    </tbody>
                </table>

                <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                    {{ __('ui_7d02c007c791') }}
                    <strong>hoje</strong>{{ __('ui_cc0b85b6ae13') }}
                </p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
