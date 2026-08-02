<div class="w-80 shrink-0 overflow-y-auto border-r border-slate-200">
    <div class="border-b border-slate-200 px-4 py-3 font-semibold text-slate-700">Conversas</div>

    @forelse ($conversas as $conversa)
        <button type="button" wire:key="conv-{{ $conversa->id }}"
                wire:click="selecionar({{ $conversa->id }})"
                class="block w-full border-b border-slate-100 px-4 py-3 text-left hover:bg-slate-50 {{ $selecionada === $conversa->id ? 'bg-emerald-50' : '' }}">
            <div class="flex items-center justify-between gap-2">
                <span class="truncate font-medium text-slate-800">{{ $conversa->contact->nomeExibicao() }}</span>
                @if ($conversa->nao_lidas > 0)
                    <span class="shrink-0 rounded-full bg-emerald-600 px-2 py-0.5 text-xs text-white">{{ $conversa->nao_lidas }}</span>
                @endif
            </div>
            <div class="text-xs text-slate-500">{{ $conversa->ultima_msg_em?->diffForHumans() }}</div>
        </button>
    @empty
        <p class="p-4 text-sm text-slate-500">Nenhuma conversa ainda.</p>
    @endforelse
</div>
