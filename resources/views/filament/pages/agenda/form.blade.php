<x-filament::section>
    <x-slot name="heading">{{ $editando ? 'Editar' : 'Novo na agenda' }}</x-slot>

    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block sm:col-span-2">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">O quê</span>
                <input type="text" wire:model="titulo" maxlength="120"
                       placeholder="Visita técnica, retorno de orçamento, ligar para o cliente…"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                @error('titulo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Tipo</span>
                <select wire:model.live="tipo"
                        class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    @foreach (\App\Models\Appointment::TIPOS as $chave => $rotulo)
                        <option value="{{ $chave }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Quando</span>
                <input type="datetime-local" wire:model="quando"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                @error('quando') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>

            @if ($tipo === \App\Models\Appointment::COMPROMISSO)
                <label class="block">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Duração (min)</span>
                    <input type="number" wire:model="duracao_min" min="5" max="1440" step="5"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">De quem é</span>
                    <select wire:model="user_id"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @foreach ($pessoas as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </label>
            @else
                <div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    Lembrete é seu: <strong>ninguém mais vê</strong>, e ele não vai para a
                    agenda da equipe.
                </div>
            @endif
        </div>

        {{-- Contato é OPCIONAL: "ligar para o contador" não tem contato cadastrado, e exigir um
             faria a pessoa inventar cadastro para conseguir anotar. --}}
        <div>
            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Com quem (opcional)</span>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <input type="text" wire:model.live.debounce.300ms="buscaContato" placeholder="nome do contato"
                       class="min-w-52 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                @if ($contact_id)
                    <x-filament::button size="xs" color="gray" wire:click="tirarContato">Tirar</x-filament::button>
                @endif
            </div>

            @if ($candidatos->isNotEmpty())
                <div class="mt-2 flex flex-wrap gap-1">
                    @foreach ($candidatos as $c)
                        <button type="button" wire:key="ct-{{ $c->id }}" wire:click="escolherContato({{ $c->id }})"
                                class="rounded-full border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-100 dark:border-white/20 dark:hover:bg-white/5">
                            {{ $c->nomeExibicao() }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <label class="block">
            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Observação (opcional)</span>
            <textarea wire:model="descricao" rows="2"
                      class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800"></textarea>
        </label>

        <div class="flex flex-wrap gap-2">
            <x-filament::button wire:click="salvar">Salvar</x-filament::button>
            <x-filament::button color="gray" wire:click="$set('formAberto', false)">Cancelar</x-filament::button>

            @if ($editando)
                <x-filament::button color="gray" wire:click="concluir({{ $editando }})">Marcar como feito</x-filament::button>
                <x-filament::button class="ms-auto" color="danger" wire:click="excluir({{ $editando }})"
                                    wire:confirm="Excluir da agenda?">Excluir</x-filament::button>
            @endif
        </div>
    </div>
</x-filament::section>
