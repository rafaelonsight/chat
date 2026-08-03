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
                @php $entrada = $m->entrada(); @endphp
                <div wire:key="msg-{{ $m->id }}" class="flex {{ $entrada ? 'justify-start' : 'justify-end' }}">
                    <div class="max-w-lg rounded-lg px-3 py-2 text-sm {{ $entrada ? 'border border-slate-200 bg-white text-slate-800' : 'bg-emerald-600 text-white' }}">

                        @if ($m->tipo === 'text')
                            <div class="whitespace-pre-wrap">{{ $m->corpo }}</div>

                        @elseif ($m->temMidia())
                            @if (in_array($m->tipo, ['image', 'sticker']))
                                <a href="{{ $m->midiaUrl() }}" target="_blank" rel="noopener">
                                    <img src="{{ $m->midiaUrl() }}" alt="{{ $m->media_nome }}"
                                         class="max-h-72 rounded {{ $m->tipo === 'sticker' ? 'bg-transparent' : '' }}">
                                </a>

                            @elseif ($m->tipo === 'video')
                                <video controls preload="metadata" class="max-h-72 rounded">
                                    <source src="{{ $m->midiaUrl() }}" type="{{ $m->media_mime }}">
                                </video>

                            @elseif ($m->tipo === 'audio')
                                <audio controls preload="metadata" class="w-64">
                                    <source src="{{ $m->midiaUrl() }}" type="{{ $m->media_mime }}">
                                </audio>
                                @if ($m->media_duracao)
                                    <div class="text-[10px] opacity-70">{{ $m->media_duracao }}s</div>
                                @endif

                            @else
                                <a href="{{ $m->midiaUrl() }}" target="_blank" rel="noopener"
                                   class="flex items-center gap-2 underline">
                                    <span>&#128196;</span>
                                    <span class="truncate">{{ $m->media_nome ?: 'documento' }}</span>
                                    @if ($m->tamanhoLegivel())
                                        <span class="shrink-0 text-[10px] opacity-70">{{ $m->tamanhoLegivel() }}</span>
                                    @endif
                                </a>
                            @endif

                            @if ($m->legenda)
                                <div class="mt-1 whitespace-pre-wrap">{{ $m->legenda }}</div>
                            @endif

                        @else
                            {{-- midia anunciada pelo webhook mas o download falhou --}}
                            <div class="italic opacity-80">
                                [{{ $m->tipo }}] nao foi possivel baixar o arquivo
                            </div>
                            @if ($m->legenda)
                                <div class="mt-1 whitespace-pre-wrap">{{ $m->legenda }}</div>
                            @endif
                        @endif

                        <div class="mt-1 text-[10px] opacity-70">
                            {{ $m->created_at?->format('H:i') }}
                            @unless ($entrada) &middot; {{ $m->status }} @endunless
                        </div>

                        @if ($m->erro)
                            <div class="mt-1 text-[10px] {{ $entrada ? 'text-red-600' : 'text-red-200' }}">{{ $m->erro }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="grid flex-1 place-items-center bg-slate-50 text-slate-400">Selecione uma conversa</div>
    @endif
</div>
