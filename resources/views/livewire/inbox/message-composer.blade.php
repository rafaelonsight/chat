<div class="border-t border-slate-200 p-3">
    @if ($conversationId)
        <form wire:submit="enviar" class="flex gap-2">
            <input type="text" wire:model="corpo" autocomplete="off" placeholder="Escreva uma mensagem"
                   class="flex-1 rounded border border-slate-300 px-3 py-2 text-sm">
            <button type="submit" class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                Enviar
            </button>
        </form>
        @error('corpo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @else
        <p class="text-center text-xs text-slate-400">Selecione uma conversa para responder</p>
    @endif
</div>
