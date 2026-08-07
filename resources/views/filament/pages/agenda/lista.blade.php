@forelse ($grupos as $rotuloGrupo => $itens)
    <x-filament::section>
        <x-slot name="heading">
            <span class="{{ $rotuloGrupo === 'Atrasados' ? 'text-red-600 dark:text-red-400' : '' }}">
                {{ $rotuloGrupo }} <span class="text-xs opacity-60">({{ $itens->count() }})</span>
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
                    <button type="button" wire:click="concluir({{ $a->id }})" title="Marcar como feito"
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
                Marque uma visita, um retorno de orçamento, ou só um lembrete para você mesmo.
                Compromisso a equipe vê; lembrete é só seu.
            </p>
            <div class="mt-4">
                <x-filament::button wire:click="novo">Marcar alguma coisa</x-filament::button>
            </div>
        </div>
    @endunless
@endforelse
