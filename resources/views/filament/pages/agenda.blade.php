<x-filament-panels::page>
    @if (! $formAberto)
        <div class="flex justify-end">
            <x-filament::button wire:click="novo" icon="heroicon-o-plus">Novo</x-filament::button>
        </div>
    @endif

    {{-- ------------------------------------------------------------ formulario --}}
    @if ($formAberto)
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
                        <div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-600 sm:col-span-1 dark:bg-white/5 dark:text-gray-300">
                            Lembrete é seu: <strong>ninguém mais vê</strong>, e ele não vai para a
                            agenda da equipe.
                        </div>
                    @endif
                </div>

                {{-- Contato é OPCIONAL: "ligar para o contador" não tem contato cadastrado, e
                     exigir um faria a pessoa inventar cadastro para conseguir anotar. --}}
                <div>
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Com quem (opcional)</span>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <input type="text" wire:model.live.debounce.300ms="buscaContato"
                               placeholder="nome do contato"
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

                <div class="flex gap-2">
                    <x-filament::button wire:click="salvar">Salvar</x-filament::button>
                    <x-filament::button color="gray" wire:click="$set('formAberto', false)">Cancelar</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- --------------------------------------------------------------- a lista --}}
    @forelse ($grupos as $rotulo => $itens)
        <x-filament::section>
            <x-slot name="heading">
                <span class="{{ $rotulo === 'Atrasados' ? 'text-red-600 dark:text-red-400' : '' }}">
                    {{ $rotulo }} <span class="text-xs opacity-60">({{ $itens->count() }})</span>
                </span>
            </x-slot>

            <div class="space-y-2">
                @foreach ($itens as $a)
                    <div wire:key="ag-{{ $a->id }}"
                         @class([
                             'flex flex-wrap items-start gap-3 rounded-lg border p-3',
                             'border-red-200 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10' => $a->atrasado(),
                             'border-gray-200 dark:border-white/10' => ! $a->atrasado(),
                         ])>
                        <button type="button" wire:click="concluir({{ $a->id }})"
                                title="Marcar como feito"
                                class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded border border-gray-400 text-transparent hover:border-emerald-600 hover:text-emerald-600 dark:border-white/30">
                            &check;
                        </button>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $a->titulo }}</span>

                                @if ($a->ehLembrete())
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-600 dark:bg-white/10 dark:text-gray-300"
                                          title="Só você vê">lembrete</span>
                                @endif
                            </div>

                            <p class="mt-0.5 text-xs text-gray-500">
                                {{ $a->comeca_em->format('d/m H:i') }}
                                @if ($a->duracao_min) · {{ $a->duracao_min }} min @endif
                                @if ($a->contact) · {{ $a->contact->nomeExibicao() }} @endif
                                @unless ($a->ehLembrete()) · {{ $a->user?->name }} @endunless
                            </p>

                            @if ($a->descricao)
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $a->descricao }}</p>
                            @endif
                        </div>

                        <div class="flex shrink-0 gap-1">
                            @if ($a->contact)
                                <x-filament::button size="xs" color="gray" tag="a"
                                    href="{{ \App\Filament\Resources\Contacts\ContactResource::getUrl('edit', ['record' => $a->contact]) }}">
                                    Contato
                                </x-filament::button>
                            @endif
                            <x-filament::button size="xs" color="gray" wire:click="editar({{ $a->id }})">Editar</x-filament::button>
                            <x-filament::button size="xs" color="danger" wire:click="excluir({{ $a->id }})"
                                wire:confirm="Excluir da agenda?">Excluir</x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @empty
        @unless ($formAberto)
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/20">
                <p class="text-base font-medium text-gray-700 dark:text-gray-200">Nada marcado</p>
                <p class="mx-auto mt-2 max-w-lg text-sm text-gray-500 dark:text-gray-400">
                    Marque uma visita, um retorno de orçamento, ou só um lembrete para você
                    mesmo. Compromisso a equipe vê; lembrete é só seu.
                </p>
                <div class="mt-4">
                    <x-filament::button wire:click="novo">Marcar alguma coisa</x-filament::button>
                </div>
            </div>
        @endunless
    @endforelse

    {{-- ------------------------------------------------------------ concluidos --}}
    <div>
        <button type="button" wire:click="$toggle('mostrarConcluidos')"
                class="text-xs text-gray-500 underline">
            {{ $mostrarConcluidos ? 'esconder o que já foi feito' : 'ver o que já foi feito' }}
        </button>

        @if ($mostrarConcluidos && $concluidos->isNotEmpty())
            <div class="mt-2 space-y-1">
                @foreach ($concluidos as $a)
                    <div wire:key="ok-{{ $a->id }}" class="flex items-center gap-2 rounded-lg border border-gray-100 px-3 py-2 text-xs text-gray-500 dark:border-white/5">
                        <button type="button" wire:click="concluir({{ $a->id }})"
                                title="Desmarcar" class="text-emerald-600">&check;</button>
                        <span class="line-through">{{ $a->titulo }}</span>
                        <span class="opacity-60">{{ $a->comeca_em->format('d/m H:i') }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
