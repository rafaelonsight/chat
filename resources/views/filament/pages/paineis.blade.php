<x-filament-panels::page>
    {{--
        AS ABAS DOS QUADROS.

        Uma empresa que vende e tambem faz suporte precisa de dois processos: "Orçamento,
        Negociação, Fechado" não descreve um chamado técnico, e forçar os dois no mesmo quadro
        faz a pessoa inventar etapas que não servem para nenhum dos dois.

        Abas e não menu suspenso: com dois ou três quadros, ver todos de uma vez é o que faz
        alguém lembrar que o outro existe.
    --}}
    @if ($funis->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 pb-2 dark:border-white/10">
            @foreach ($funis as $f)
                <button type="button" wire:key="fn-{{ $f->id }}" wire:click="abrirFunil({{ $f->id }})"
                        @class([
                            'rounded-full px-3 py-1.5 text-sm transition',
                            'bg-indigo-600 text-white' => $f->id === $funilId,
                            'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $f->id !== $funilId,
                        ])>
                    {{ $f->nome }}
                    <span class="ml-1 text-[11px] opacity-70">{{ $f->stages()->count() }}</span>
                </button>
            @endforeach

            <button type="button" wire:click="$toggle('editandoFunis')"
                    class="rounded-full border border-dashed border-gray-300 px-3 py-1.5 text-sm text-gray-500 hover:bg-gray-50 dark:border-white/20 dark:text-gray-400 dark:hover:bg-white/5"
                    title="Criar, renomear ou excluir funis">
                + funil
            </button>
        </div>
    @endif

    {{-- ----------------------------------------------------- gerenciar os funis --}}
    @if ($editandoFunis)
        <x-filament::section>
            <x-slot name="heading">Seus funis</x-slot>
            <x-slot name="description">
                Cada funil tem as próprias etapas e os próprios cartões. Uma conversa fica em
                <strong>um funil de cada vez</strong> — o cartão é a conversa, e ela está num
                ponto de um processo.
            </x-slot>

            <div class="space-y-2">
                @foreach ($funis as $f)
                    <div wire:key="gf-{{ $f->id }}" class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 p-2 dark:border-white/10">
                        <input type="text" value="{{ $f->nome }}" maxlength="60"
                               wire:change="renomearFunil({{ $f->id }}, $event.target.value)"
                               class="min-w-44 flex-1 rounded border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">

                        <span class="text-xs text-gray-500">{{ $f->stages()->count() }} etapa(s)</span>

                        <x-filament::button size="xs" color="danger" wire:click="excluirFunil({{ $f->id }})"
                            wire:confirm="Excluir este funil? As etapas vão junto e os cartões voltam para fora do funil. As conversas não são apagadas.">
                            Excluir
                        </x-filament::button>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-2">
                <label class="min-w-52 flex-1">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Novo funil</span>
                    <input type="text" wire:model="nomeDoNovoFunil" placeholder="Suporte, Pós-venda, Cobrança…"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    @error('nomeDoNovoFunil') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <x-filament::button wire:click="criarFunil">Criar</x-filament::button>
                <x-filament::button color="gray" wire:click="$set('editandoFunis', false)">Fechar</x-filament::button>
            </div>

            <p class="mt-3 text-xs text-gray-500">
                O funil novo já nasce com as cinco colunas comuns. Renomeie as que não servirem.
            </p>
        </x-filament::section>
    @endif

    @if ($etapas->isEmpty() && ! $editandoColunas && ! $editandoFunis)
        {{-- Funil vazio nao ensina nada: a pessoa abre, ve um quadro em branco e fecha. --}}
        <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/20">
            <p class="text-base font-medium text-gray-700 dark:text-gray-200">Você ainda não tem nenhum funil</p>
            <p class="mx-auto mt-2 max-w-lg text-sm text-gray-500 dark:text-gray-400">
                Comece com as cinco mais comuns — Novo, Orçamento, Negociação, Fechado e
                Perdido — e renomeie o que não servir. Leva trinta segundos.
            </p>
            <div class="mt-4 flex justify-center gap-2">
                <x-filament::button wire:click="criarPadrao">Criar meu primeiro funil</x-filament::button>
                <x-filament::button color="gray" wire:click="editarColunas">Montar do zero</x-filament::button>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------- editor de colunas --}}
    @if ($editandoColunas)
        <x-filament::section>
            <x-slot name="heading">As colunas do seu funil</x-slot>
            <x-slot name="description">
                A ordem aqui é a ordem no quadro. Apagar uma coluna <strong>não apaga as
                conversas</strong> — elas voltam para fora do funil.
            </x-slot>

            @error('colunas') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="space-y-2">
                @foreach ($colunas as $i => $c)
                    <div wire:key="col-{{ $i }}" class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 p-2 dark:border-white/10">
                        <span class="text-xs text-gray-400">{{ $i + 1 }}</span>

                        <input type="text" wire:model="colunas.{{ $i }}.nome" placeholder="Nome da coluna"
                               class="min-w-40 flex-1 rounded border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">

                        <select wire:model="colunas.{{ $i }}.cor"
                                class="rounded border-gray-300 text-xs dark:border-white/20 dark:bg-gray-800">
                            @foreach (['cinza','azul','verde','ambar','vermelho','roxo','turquesa'] as $cor)
                                <option value="{{ $cor }}">{{ ucfirst($cor) }}</option>
                            @endforeach
                        </select>

                        <label class="flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
                            <input type="checkbox" wire:model="colunas.{{ $i }}.encerra" class="rounded border-gray-300">
                            encerra o negócio
                        </label>

                        <button type="button" wire:click="removerColuna({{ $i }})"
                                class="ml-auto rounded px-2 text-sm text-gray-400 hover:text-red-600">&times;</button>

                        @error("colunas.{$i}.nome") <span class="w-full text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                @endforeach
            </div>

            <div class="mt-3 flex gap-2">
                <x-filament::button size="sm" color="gray" wire:click="adicionarColuna">Mais uma coluna</x-filament::button>
                <x-filament::button size="sm" wire:click="salvarColunas">Salvar</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="$set('editandoColunas', false)">Cancelar</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- ------------------------------------------------------------- o quadro --}}
    @if ($etapas->isNotEmpty() && ! $editandoColunas)
        <div class="flex justify-end">
            <x-filament::button size="sm" color="gray" wire:click="editarColunas">Editar colunas</x-filament::button>
        </div>

        {{--
            Arrastar com HTML puro, sem biblioteca: dragstart guarda o id, dragover libera o
            alvo, drop chama o servidor. Uma biblioteca de kanban traria trezentos kilobytes
            para fazer estas tres linhas.

            E OS BOTOES DE SETA EXISTEM PORQUE ARRASTAR NAO FUNCIONA NO CELULAR — o atendente
            trabalha do telefone o dia todo, e um quadro que so responde a mouse seria um
            quadro que metade da equipe nao usa.
        --}}
        <div class="flex gap-3 overflow-x-auto pb-4">
            @foreach ($etapas as $indice => $etapa)
                @php $cartoes = $conversas[$etapa->id] ?? collect(); @endphp

                <div wire:key="etapa-{{ $etapa->id }}"
                     x-on:dragover.prevent
                     x-on:drop.prevent="$wire.mover(parseInt($event.dataTransfer.getData('cartao')), {{ $etapa->id }})"
                     class="flex w-64 shrink-0 flex-col rounded-xl border border-gray-200 bg-gray-50 p-2 dark:border-white/10 dark:bg-white/5">

                    <div class="mb-2 flex items-center gap-2 px-1">
                        <span class="h-2.5 w-2.5 rounded-full {{ $etapa->pontinho() }}"></span>
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $etapa->nome }}</span>
                        <span class="ml-auto text-xs text-gray-500">{{ $cartoes->count() }}</span>
                    </div>

                    <div class="space-y-2">
                        @foreach ($cartoes as $c)
                            <div wire:key="cartao-{{ $c->id }}" draggable="true"
                                 x-on:dragstart="$event.dataTransfer.setData('cartao', '{{ $c->id }}')"
                                 class="cursor-grab rounded-lg border border-gray-200 bg-white p-2 shadow-sm active:cursor-grabbing dark:border-white/10 dark:bg-gray-900">

                                <div class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                                    {{ $c->contact?->nomeExibicao() }}
                                </div>

                                <div class="mt-0.5 flex items-center justify-between gap-2 text-[11px] text-gray-500">
                                    <span>{{ $c->atendente?->name ?? 'sem dono' }}</span>
                                    {{-- Ha quanto tempo parado NESTA etapa. E a unica pergunta
                                         que faz um funil valer alguma coisa. --}}
                                    <span title="nesta coluna desde {{ $c->etapa_em?->format('d/m H:i') }}">
                                        {{ $c->etapa_em?->diffForHumans(short: true) }}
                                    </span>
                                </div>

                                <div class="mt-1.5 flex items-center gap-1">
                                    @if ($indice > 0)
                                        <button type="button" wire:click="mover({{ $c->id }}, {{ $etapas[$indice - 1]->id }})"
                                                title="Voltar para {{ $etapas[$indice - 1]->nome }}"
                                                class="rounded px-1.5 text-xs text-gray-400 hover:bg-gray-100 hover:text-gray-700">&larr;</button>
                                    @endif

                                    <a href="{{ route('filament.admin.pages.atendimento') }}?conversa={{ $c->id }}"
                                       class="rounded px-1.5 text-xs text-gray-400 hover:text-emerald-700">abrir</a>

                                    @if ($indice < $etapas->count() - 1)
                                        <button type="button" wire:click="mover({{ $c->id }}, {{ $etapas[$indice + 1]->id }})"
                                                title="Avançar para {{ $etapas[$indice + 1]->nome }}"
                                                class="ml-auto rounded px-1.5 text-xs text-gray-400 hover:bg-gray-100 hover:text-gray-700">&rarr;</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach

                        @if ($cartoes->isEmpty())
                            <p class="px-1 py-3 text-center text-xs text-gray-400">vazia</p>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- De onde saem os primeiros cartoes. --}}
            <div class="flex w-64 shrink-0 flex-col rounded-xl border border-dashed border-gray-300 p-2 dark:border-white/20">
                <p class="mb-2 px-1 text-sm font-medium text-gray-600 dark:text-gray-300">Fora do funil</p>

                @forelse ($foraDoFunil as $c)
                    <div wire:key="fora-{{ $c->id }}" draggable="true"
                         x-on:dragstart="$event.dataTransfer.setData('cartao', '{{ $c->id }}')"
                         class="mb-2 cursor-grab rounded-lg border border-gray-200 bg-white p-2 dark:border-white/10 dark:bg-gray-900">
                        <div class="truncate text-sm text-gray-800 dark:text-gray-100">{{ $c->contact?->nomeExibicao() }}</div>
                        <button type="button" wire:click="mover({{ $c->id }}, {{ $etapas->first()->id }})"
                                class="mt-1 text-[11px] text-emerald-700 hover:underline">
                            pôr em {{ $etapas->first()->nome }}
                        </button>
                    </div>
                @empty
                    <p class="px-1 py-3 text-center text-xs text-gray-400">
                        Todas as conversas abertas já estão no funil.
                    </p>
                @endforelse
            </div>
        </div>
    @endif
</x-filament-panels::page>
