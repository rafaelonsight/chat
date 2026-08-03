<div class="flex flex-1 flex-col overflow-hidden">
    @if ($conversa)
        <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-white/10">
            <div class="flex min-w-0 items-center gap-2">
                {{-- detalhes do contato: fica antes do nome, como identidade de quem se fala --}}
                <button type="button" wire:click="verDetalhes"
                        class="shrink-0 rounded-full border border-gray-300 p-1.5 text-gray-500 transition hover:bg-gray-50 hover:text-gray-800 dark:border-white/20 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-100"
                        title="Detalhes do contato">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                </button>

                <div class="min-w-0">
                <div class="truncate font-semibold text-gray-800 dark:text-gray-100">
                    {{ $conversa->contact->nomeExibicao() }}
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <span>{{ $conversa->contact->telefone_e164 }}</span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 dark:bg-white/10">
                        {{ $conversa->rotuloStatus() }}
                    </span>
                    @if ($conversa->atendente)
                        <span>&middot; {{ $conversa->atendente->name }}</span>
                    @endif
                </div>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($conversa->status === \App\Models\Conversation::NOVA)
                    <button type="button" wire:click="assumir"
                            class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">
                        Assumir
                    </button>
                @endif

                @if ($conversa->status === \App\Models\Conversation::ARQUIVADA)
                    @if ($conversa->podeReabrir())
                        <button type="button" wire:click="reabrir"
                                class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
                            Reabrir
                        </button>
                    @else
                        <span class="text-xs text-gray-400" title="Ja existe conversa aberta com este contato">
                            encerrada
                        </span>
                    @endif
                @else
                    <button type="button" wire:click="finalizar"
                            wire:confirm="Finalizar este atendimento?"
                            class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">
                        Finalizar
                    </button>
                @endif
            </div>
        </div>

        @error('reabrir')
            <div class="bg-red-50 px-4 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</div>
        @enderror

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
                        @if ($entrada && $m->remetente_nome)
                            {{-- em grupo, quem falou importa tanto quanto o que foi dito --}}
                            <div class="mb-0.5 text-xs font-semibold text-emerald-700">{{ $m->remetente_nome }}</div>
                        @endif

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
