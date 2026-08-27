<section wire:poll.10s class="mt-6 rounded-xl border border-gray-200 p-4 dark:border-white/10">
    <h3 class="text-base font-semibold">Conversa e histórico da tarefa</h3>
    <p class="mt-1 text-xs text-gray-500">Interno — somente colaboradores desta conta.</p>
    <form wire:submit="alterarStatus" class="my-4 flex items-center gap-2">
        <label for="status-{{ $itemId }}">Status</label>
        <select id="status-{{ $itemId }}" wire:model="status" class="rounded-lg border-gray-300 dark:bg-gray-800">
            @foreach ($etapas as $etapa)<option value="{{ $etapa->id }}">{{ $etapa->nome }}</option>@endforeach
        </select>
        <x-filament::button type="submit" size="sm">Atualizar status</x-filament::button>
    </form>
    <div class="max-h-80 space-y-3 overflow-y-auto" aria-live="polite">
        @forelse ($atualizacoes as $atualizacao)
            <article wire:key="item-update-{{ $atualizacao->id }}" class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                <p class="text-xs text-gray-500">{{ $atualizacao->user?->name ?? 'Sistema' }} · {{ $atualizacao->created_at->format('d/m H:i') }}</p>
                <p class="whitespace-pre-wrap break-words text-sm">{{ \App\Support\TextoComLinks::render($atualizacao->corpo) }}</p>
                @foreach ($pessoas->whereIn('id', $atualizacao->mencoes ?? []) as $mencionado)
                    <span class="text-xs font-medium text-amber-600">{{ '@'.$mencionado->name }}</span>
                @endforeach
            </article>
        @empty <p class="text-sm text-gray-500">Nenhuma atualização ainda.</p> @endforelse
    </div>
    <form wire:submit="enviar" class="mt-4 space-y-3">
        <label class="block text-sm">Mensagem
            <textarea wire:model="texto" rows="3" maxlength="5000" placeholder="Escreva uma atualização para a equipe" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-800"></textarea>
        </label>
        @error('texto')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <fieldset class="flex flex-wrap gap-3"><legend class="mb-2 text-sm">@ Mencionar colaboradores</legend>
            @foreach ($pessoas as $pessoa)
                <label class="flex items-center gap-1 text-sm"><input type="checkbox" wire:model="mencoes" value="{{ $pessoa->id }}">{{ '@'.$pessoa->name }}</label>
            @endforeach
        </fieldset>
        <x-filament::button type="submit" wire:loading.attr="disabled">Enviar mensagem</x-filament::button>
    </form>
</section>
