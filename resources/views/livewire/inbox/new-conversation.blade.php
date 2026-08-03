<div class="border-b border-slate-200 p-3">
    @if (! $aberto)
        <button type="button" wire:click="alternar"
                class="w-full rounded bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">
            + Nova conversa
        </button>
    @else
        <form wire:submit="iniciar" class="space-y-2">
            <input type="text" wire:model="numero" autocomplete="off"
                   placeholder="(84) 99614-3373"
                   class="w-full rounded border border-slate-300 px-3 py-2 text-sm">

            <textarea wire:model="primeiraMensagem" rows="2"
                      placeholder="Primeira mensagem (opcional)"
                      class="w-full rounded border border-slate-300 px-3 py-2 text-sm"></textarea>

            @error('numero')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror

            <div class="flex gap-2">
                <button type="submit" wire:loading.attr="disabled"
                        class="flex-1 rounded bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                    <span wire:loading.remove wire:target="iniciar">Iniciar</span>
                    <span wire:loading wire:target="iniciar">Verificando…</span>
                </button>
                <button type="button" wire:click="alternar"
                        class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-600">
                    Cancelar
                </button>
            </div>
        </form>
    @endif
</div>
