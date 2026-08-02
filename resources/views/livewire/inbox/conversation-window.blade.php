<div class="flex flex-1 flex-col overflow-hidden">
    @if ($conversa)
        <div class="border-b border-slate-200 px-4 py-3 font-semibold text-slate-700">
            {{ $conversa->contact->nomeExibicao() }}
            <span class="ml-2 text-xs font-normal text-slate-500">{{ $conversa->contact->telefone_e164 }}</span>
        </div>

        <div class="flex-1 space-y-2 overflow-y-auto bg-slate-50 p-4">
            @if ($mensagens->count() >= $limite)
                <button type="button" wire:click="carregarMais" class="mx-auto block text-xs text-slate-500 underline">
                    carregar mensagens anteriores
                </button>
            @endif

            @foreach ($mensagens as $m)
                <div wire:key="msg-{{ $m->id }}" class="flex {{ $m->entrada() ? 'justify-start' : 'justify-end' }}">
                    <div class="max-w-lg rounded-lg px-3 py-2 text-sm {{ $m->entrada() ? 'border border-slate-200 bg-white text-slate-800' : 'bg-emerald-600 text-white' }}">
                        <div class="whitespace-pre-wrap">{{ $m->corpo }}</div>
                        <div class="mt-1 text-[10px] opacity-70">
                            {{ $m->created_at?->format('H:i') }}
                            @unless ($m->entrada()) &middot; {{ $m->status }} @endunless
                        </div>
                        @if ($m->erro)
                            <div class="mt-1 text-[10px] {{ $m->entrada() ? 'text-red-600' : 'text-red-200' }}">{{ $m->erro }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="grid flex-1 place-items-center bg-slate-50 text-slate-400">Selecione uma conversa</div>
    @endif
</div>
