@php
    $acao = $acaoAberta ? \App\Models\ChatbotAction::find($acaoAberta) : null;
    $T = \App\Models\ChatbotAction::class;
@endphp

@if (! $acao)
    <p class="text-sm text-gray-500">Ação não encontrada.</p>
@else
    <form wire:submit="salvarAcao" class="space-y-3">
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $acao->rotulo() }}</p>

        @if (in_array($acao->tipo, [$T::MENSAGEM, $T::MENU, $T::PERGUNTA]))
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">
                    {{ $acao->tipo === $T::MENU ? 'Texto antes das opções' : 'Texto' }}
                </label>
                <textarea wire:model="form.texto" rows="4"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>
            </div>
        @endif

        @if ($acao->tipo === $T::MENU)
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Opções</label>
                <div class="space-y-1.5">
                    @foreach ($form['opcoes'] ?? [] as $i => $opcao)
                        <div class="flex items-center gap-1.5" wire:key="op-{{ $i }}">
                            <input type="text" wire:model="form.opcoes.{{ $i }}.gatilho" placeholder="1"
                                   class="w-12 rounded border border-gray-300 px-2 py-1.5 text-center text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                            <input type="text" wire:model="form.opcoes.{{ $i }}.rotulo" placeholder="Financeiro"
                                   class="min-w-0 flex-1 rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                            <button type="button" wire:click="removerOpcao({{ $i }})"
                                    class="shrink-0 rounded p-1 text-gray-400 hover:text-red-600" aria-label="Remover opção">
                                <x-filament::icon icon="heroicon-m-x-mark" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    @endforeach
                </div>
                <button type="button" wire:click="adicionarOpcao"
                        class="mt-1.5 text-xs font-medium text-primary-600 dark:text-primary-400">+ opção</button>
                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                    Cada opção ganha uma saída própria no cartão. Ligue todas: opção sem destino impede publicar.
                </p>
            </div>
        @endif

        @if ($acao->tipo === $T::PERGUNTA)
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Guardar a resposta como</label>
                <input type="text" wire:model="form.guardar_em" placeholder="problema"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Um nome só seu. Serve para o condicional e para citar depois.
                </p>
            </div>
        @endif

        @if ($acao->tipo === $T::ESPERAR)
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Segundos</label>
                <input type="number" min="1" max="300" wire:model="form.segundos"
                       class="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
            </div>
        @endif

        @if ($acao->tipo === $T::CONDICIONAL)
            <div class="space-y-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Resposta guardada</label>
                    <input type="text" wire:model="form.campo" placeholder="problema"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Condição</label>
                    <select wire:model="form.operador"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                        <option value="contem">contém</option>
                        <option value="igual">é igual a</option>
                        <option value="comeca">começa com</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Valor</label>
                    <input type="text" wire:model="form.valor" placeholder="lento"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    O cartão ganha duas saídas: <strong>sim</strong> e <strong>não</strong>. Ligue as duas.
                </p>
            </div>
        @endif

        @if ($acao->tipo === $T::TRANSFERIR)
            <div class="space-y-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Equipe</label>
                    <select wire:model="form.team_id"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Qualquer atendente</option>
                        @foreach ($this->equipes as $id => $nome)
                            <option value="{{ $id }}">{{ $nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Aviso ao cliente</label>
                    <textarea wire:model="form.aviso" rows="2"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    A conversa cai na fila de Novos dessa equipe, sem atendente definido.
                </p>
            </div>
        @endif

        @if ($acao->tipo === $T::ETIQUETA)
            @php $etiquetas = \App\Models\Tag::orderBy('nome')->get(); @endphp

            @if ($etiquetas->isEmpty())
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Nenhuma etiqueta cadastrada ainda. Crie em
                    <strong>Configurações &rarr; Etiquetas</strong>.
                </p>
            @else
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Adicionar</label>
                    <div class="space-y-1">
                        @foreach ($etiquetas as $et)
                            <label class="flex items-center gap-2 text-xs" wire:key="add-{{ $et->id }}">
                                <input type="checkbox" value="{{ $et->id }}" wire:model="form.adicionar"
                                       class="h-3.5 w-3.5 rounded border-gray-300 text-primary-600">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 {{ $et->classes() }}">{{ $et->nome }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Remover</label>
                    <div class="space-y-1">
                        @foreach ($etiquetas as $et)
                            <label class="flex items-center gap-2 text-xs" wire:key="rem-{{ $et->id }}">
                                <input type="checkbox" value="{{ $et->id }}" wire:model="form.remover"
                                       class="h-3.5 w-3.5 rounded border-gray-300 text-primary-600">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 {{ $et->classes() }}">{{ $et->nome }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    A etiqueta fica no contato, não na conversa — e o histórico guarda que
                    foi o chatbot que colocou.
                </p>
            @endif
        @endif

        @if ($acao->tipo === $T::CONCLUIR)
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">Mensagem de despedida</label>
                <textarea wire:model="form.aviso" rows="2"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>
            </div>
        @endif

        <div class="flex items-center gap-2 border-t border-gray-100 pt-3 dark:border-white/5">
            <x-filament::button type="submit" size="sm">Salvar</x-filament::button>

            <button type="button"
                    wire:click="removerAcao({{ $acao->id }})"
                    wire:confirm="Remover esta ação?"
                    class="ms-auto text-xs text-red-600 hover:underline">
                remover ação
            </button>
        </div>
    </form>
@endif
