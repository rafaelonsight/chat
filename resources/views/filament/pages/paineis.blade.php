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
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex min-w-0 flex-wrap items-center gap-2" role="tablist" aria-label="Painéis do CRM">
                    @foreach ($funis as $f)
                        <a role="tab" aria-selected="{{ $f->id === $funilId ? 'true' : 'false' }}"
                                wire:key="fn-{{ $f->id }}" wire:navigate
                                href="{{ route('filament.admin.pages.paineis', ['painel' => $f->id]) }}"
                                @class([
                                    'rounded-lg px-3 py-2 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900',
                                    'bg-emerald-600 text-white shadow-sm' => $f->id === $funilId,
                                    'border border-gray-200 text-gray-700 hover:border-emerald-300 hover:bg-emerald-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-emerald-500/10' => $f->id !== $funilId,
                                ])>
                            {{ $f->nome }}
                        </a>
                    @endforeach
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <x-filament::button size="sm" color="gray" wire:click="$toggle('editandoFunis')">
                        Gerenciar
                    </x-filament::button>
                    <x-filament::button size="sm" icon="heroicon-o-plus" wire:click="abrirCriacao">
                        Novo painel
                    </x-filament::button>
                </div>
            </div>

            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Cada painel tem colunas e cartões próprios. Trocar de aba não altera os outros painéis.
            </p>
        </div>
    @endif

    @if ($criandoFunil)
        <x-filament::section>
            <x-slot name="heading">Criar novo painel</x-slot>
            <x-slot name="description">O painel atual será mantido. O novo começa com cinco colunas que você poderá editar.</x-slot>

            <form wire:submit="criarFunil" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <label class="min-w-0 flex-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Nome do novo painel</span>
                    <input type="text" wire:model="nomeDoNovoFunil" maxlength="60" autofocus
                           placeholder="Ex.: Comercial, Suporte ou Cobrança"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    @error('nomeDoNovoFunil') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="flex gap-2">
                    <x-filament::button type="submit">Criar painel</x-filament::button>
                    <x-filament::button type="button" color="gray" wire:click="cancelarCriacao">Cancelar</x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @endif

    {{-- ----------------------------------------------------- gerenciar os funis --}}
    @if ($editandoFunis)
        <x-filament::section>
            <x-slot name="heading">Gerenciar painéis</x-slot>
            <x-slot name="description">
                Cada painel tem as próprias etapas e os próprios cartões. Uma conversa fica em
                <strong>um painel de cada vez</strong> — o cartão é a conversa, e ela está num
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

            <div class="mt-4 flex justify-end">
                <x-filament::button color="gray" wire:click="$set('editandoFunis', false)">Fechar</x-filament::button>
            </div>
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

    {{-- -------------------------------------------- campos personalizados do painel --}}
    @if ($editandoCampos)
        <x-filament::section>
            <x-slot name="heading">Campos dos cartões</x-slot>
            <x-slot name="description">
                Estes campos aparecem somente nos cartões deste painel. Contato, responsável,
                prioridade e prazo já fazem parte de todos os painéis.
            </x-slot>

            <div class="space-y-2">
                @foreach ($campos as $i => $campo)
                    <div wire:key="campo-{{ $i }}" class="grid gap-2 rounded-lg border border-gray-200 p-3 md:grid-cols-[1fr_11rem_1fr_auto_auto] md:items-center dark:border-white/10">
                        <input type="text" wire:model="campos.{{ $i }}.nome" placeholder="Nome do campo"
                               class="rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">

                        <select wire:model.live="campos.{{ $i }}.tipo"
                                class="rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            @foreach (\App\Models\BoardField::TIPOS as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>

                        @if (($campo['tipo'] ?? '') === 'lista')
                            <input type="text" wire:model="campos.{{ $i }}.opcoes" placeholder="Opção A, Opção B, Opção C"
                                   class="rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @else
                            <span class="hidden text-xs text-gray-400 md:block">—</span>
                        @endif

                        <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <input type="checkbox" wire:model="campos.{{ $i }}.obrigatorio" class="rounded border-gray-300">
                            obrigatório
                        </label>

                        <button type="button" wire:click="removerCampo({{ $i }})"
                                class="rounded px-2 py-1 text-sm text-gray-400 hover:bg-red-50 hover:text-red-600">Remover</button>

                        @error("campos.{$i}.nome") <span class="text-xs text-red-600 md:col-span-5">{{ $message }}</span> @enderror
                    </div>
                @endforeach

                @if (empty($campos))
                    <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        Este painel ainda usa apenas os campos padrão.
                    </p>
                @endif
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <x-filament::button size="sm" color="gray" wire:click="adicionarCampo">Adicionar campo</x-filament::button>
                <x-filament::button size="sm" wire:click="salvarCampos">Salvar campos</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="$set('editandoCampos', false)">Cancelar</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- ------------------------------------------------ criar ou editar cartao generico --}}
    @if ($editandoItem)
        <x-filament::section>
            <x-slot name="heading">{{ $itemId ? 'Editar item' : 'Novo item' }}</x-slot>
            <x-slot name="description">Pode ser uma oportunidade, chamado, tarefa, cobrança ou qualquer outro trabalho.</x-slot>

            <form wire:submit="salvarItem" class="space-y-4">
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="md:col-span-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Título</span>
                        <input type="text" wire:model="tituloDoItem" maxlength="160" autofocus
                               placeholder="O que precisa ser acompanhado?"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @error('tituloDoItem') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Contato</span>
                        <select wire:model="contatoDoItem" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            <option value="">Sem contato</option>
                            @foreach ($contatos as $contato)
                                <option value="{{ $contato->id }}">{{ $contato->nomeExibicao() }}{{ $contato->telefone_e164 ? ' · '.$contato->telefone_e164 : '' }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Responsável</span>
                        <select wire:model="responsavelDoItem" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            <option value="">Sem responsável</option>
                            @foreach ($responsaveis as $pessoa)
                                <option value="{{ $pessoa->id }}">{{ $pessoa->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <fieldset class="md:col-span-2">
                        <legend class="text-sm font-medium text-gray-700 dark:text-gray-200">Etiquetas</legend>
                        @if ($etiquetasDeItem->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($etiquetasDeItem as $etiqueta)
                                    @php $selecionada = in_array($etiqueta->id, array_map('intval', $etiquetasDoItem), true); @endphp
                                    <button type="button" wire:key="etiqueta-item-{{ $etiqueta->id }}"
                                            wire:click="alternarEtiqueta({{ $etiqueta->id }})"
                                            aria-pressed="{{ $selecionada ? 'true' : 'false' }}"
                                            @class([
                                                'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs ring-1 transition focus:outline-none focus:ring-2 focus:ring-emerald-500',
                                                $etiqueta->classes(),
                                                'opacity-60 hover:opacity-90' => ! $selecionada,
                                                'font-semibold opacity-100 ring-2' => $selecionada,
                                            ])>
                                        @if ($selecionada)<span aria-hidden="true">✓</span>@endif
                                        {{ $etiqueta->nome }}
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Nenhuma etiqueta de item cadastrada. Crie em Configurações → Etiquetas.
                            </p>
                        @endif
                    </fieldset>

                    <label>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Prioridade</span>
                        <select wire:model="prioridadeDoItem" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            <option value="baixa">Baixa</option>
                            <option value="normal">Normal</option>
                            <option value="alta">Alta</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </label>

                    <label>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Prazo</span>
                        <input type="datetime-local" wire:model="prazoDoItem"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    </label>

                    @foreach ($camposDoPainel as $campo)
                        <label>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                {{ $campo->nome }} @if ($campo->obrigatorio)<span class="text-red-500">*</span>@endif
                            </span>

                            @if ($campo->tipo === 'lista')
                                <select wire:model="valoresDoItem.{{ $campo->id }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                                    <option value="">Selecione</option>
                                    @foreach ($campo->opcoes ?? [] as $opcao)<option value="{{ $opcao }}">{{ $opcao }}</option>@endforeach
                                </select>
                            @elseif ($campo->tipo === 'checkbox')
                                <span class="mt-2 flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model="valoresDoItem.{{ $campo->id }}" class="rounded border-gray-300"> Sim
                                </span>
                            @else
                                <input type="{{ in_array($campo->tipo, ['numero', 'dinheiro']) ? 'number' : ($campo->tipo === 'data' ? 'date' : 'text') }}"
                                       @if ($campo->tipo === 'dinheiro') step="0.01" @endif
                                       wire:model="valoresDoItem.{{ $campo->id }}"
                                       class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            @endif
                            @error('valoresDoItem.'.$campo->id) <span class="mt-1 block text-xs text-red-600">Campo obrigatório.</span> @enderror
                        </label>
                    @endforeach

                    <label class="md:col-span-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Descrição</span>
                        <textarea wire:model="descricaoDoItem" rows="3" placeholder="Contexto, próximos passos ou detalhes"
                                  class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800"></textarea>
                    </label>
                </div>

                <div class="flex flex-wrap justify-between gap-2">
                    <div>
                        @if ($itemId)
                            <x-filament::button type="button" size="sm" color="danger" wire:click="arquivarItem({{ $itemId }})" wire:confirm="Arquivar este item?">
                                Arquivar
                            </x-filament::button>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <x-filament::button type="button" color="gray" wire:click="cancelarItem">Cancelar</x-filament::button>
                        <x-filament::button type="submit">Salvar item</x-filament::button>
                    </div>
                </div>
            </form>
            @if ($itemId)
                <livewire:board-item-discussion :item-id="$itemId" :key="'discussion-'.$itemId" />
            @endif
        </x-filament::section>
    @endif

    {{-- ------------------------------------------------------------- o quadro --}}
    @if ($etapas->isNotEmpty() && ! $editandoColunas)
        <div class="flex flex-wrap justify-end gap-2">
            <x-filament::button size="sm" color="gray" wire:click="editarCampos">Campos dos cartões</x-filament::button>
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
                @php $itensDaEtapa = $itens[$etapa->id] ?? collect(); @endphp

                <div wire:key="etapa-{{ $etapa->id }}"
                     x-on:dragover.prevent
                     x-on:drop.prevent="
                        const item = $event.dataTransfer.getData('item');
                        const conversa = $event.dataTransfer.getData('cartao');
                        if (item) $wire.moverItem(parseInt(item), {{ $etapa->id }});
                        else if (conversa) $wire.mover(parseInt(conversa), {{ $etapa->id }});
                     "
                     class="flex w-64 shrink-0 flex-col rounded-xl border border-gray-200 bg-gray-50 p-2 dark:border-white/10 dark:bg-white/5">

                    <div class="mb-2 flex items-center gap-2 px-1">
                        <span class="h-2.5 w-2.5 rounded-full {{ $etapa->pontinho() }}"></span>
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $etapa->nome }}</span>
                        <span class="ml-auto text-xs text-gray-500">{{ $cartoes->count() + $itensDaEtapa->count() }}</span>
                    </div>

                    <div class="space-y-2">
                        @foreach ($itensDaEtapa as $item)
                            <button type="button" wire:key="item-{{ $item->id }}" draggable="true"
                                    x-on:dragstart="$event.dataTransfer.setData('item', '{{ $item->id }}')"
                                    wire:click="editarItem({{ $item->id }})"
                                    class="block w-full cursor-grab rounded-lg border border-gray-200 bg-white p-2 text-left shadow-sm transition hover:border-emerald-300 hover:shadow active:cursor-grabbing dark:border-white/10 dark:bg-gray-900">
                                <div class="flex items-start gap-2">
                                    <span @class([
                                        'mt-1 h-2 w-2 shrink-0 rounded-full',
                                        'bg-gray-300' => $item->prioridade === 'baixa',
                                        'bg-blue-400' => $item->prioridade === 'normal',
                                        'bg-amber-500' => $item->prioridade === 'alta',
                                        'bg-red-500' => $item->prioridade === 'urgente',
                                    ])></span>
                                    <span class="line-clamp-2 text-sm font-medium text-gray-800 dark:text-gray-100">{{ $item->titulo }}</span>
                                </div>

                                @if ($item->contact)
                                    <div class="mt-1 truncate text-xs text-emerald-700 dark:text-emerald-400">{{ $item->contact->nomeExibicao() }}</div>
                                @endif

                                @if ($item->tags->isNotEmpty())
                                    <div class="mt-1.5 flex flex-wrap gap-1">
                                        @foreach ($item->tags as $etiqueta)
                                            <span class="rounded-full px-1.5 py-0.5 text-[10px] ring-1 {{ $etiqueta->classes() }}">{{ $etiqueta->nome }}</span>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="mt-1.5 flex items-center justify-between gap-2 text-[11px] text-gray-500">
                                    <span class="truncate">{{ $item->responsavel?->name ?? 'sem responsável' }}</span>
                                    @if ($item->prazo_em)
                                        <span @class(['text-red-600' => $item->prazo_em->isPast()]) title="Prazo">
                                            {{ $item->prazo_em->format('d/m H:i') }}
                                        </span>
                                    @endif
                                </div>
                            </button>
                        @endforeach

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

                        @if ($cartoes->isEmpty() && $itensDaEtapa->isEmpty())
                            <p class="px-1 py-3 text-center text-xs text-gray-400">vazia</p>
                        @endif

                        <button type="button" wire:click="abrirNovoItem({{ $etapa->id }})"
                                class="w-full rounded-lg border border-dashed border-gray-300 px-2 py-2 text-xs font-medium text-gray-500 transition hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-700 dark:border-white/15 dark:hover:bg-emerald-500/10">
                            + Adicionar item
                        </button>
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
