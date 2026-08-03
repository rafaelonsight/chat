<div class="flex flex-1 flex-col overflow-hidden">
    {{-- abas --}}
    <div class="flex shrink-0 border-b border-gray-200 dark:border-white/10">
        @foreach ($rotulos as $estado => $rotulo)
            <button type="button" wire:key="aba-{{ $estado }}"
                    wire:click="selecionarAba('{{ $estado }}')"
                    class="flex-1 border-b-2 px-2 py-2 text-xs font-medium transition
                           {{ $aba === $estado
                                ? 'border-emerald-600 text-emerald-700 dark:text-emerald-400'
                                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400' }}">
                {{ $rotulo }}
                @if ($contadores[$estado] > 0)
                    <span class="ml-1 rounded-full px-1.5 py-0.5 text-[10px]
                                 {{ $estado === 'nova' ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300' }}">
                        {{ $contadores[$estado] }}
                    </span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="flex-1 overflow-y-auto">
        @forelse ($conversas as $conversa)
            <button type="button" wire:key="conv-{{ $conversa->id }}"
                    wire:click="selecionar({{ $conversa->id }})"
                    class="block w-full border-b border-gray-100 px-4 py-3 text-left hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5
                           {{ $selecionada === $conversa->id ? 'bg-emerald-50 dark:bg-emerald-500/10' : '' }}">
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate font-medium text-gray-800 dark:text-gray-100">
                        {{ $conversa->contact->nomeExibicao() }}
                    </span>
                    @if ($conversa->nao_lidas > 0)
                        <span class="shrink-0 rounded-full bg-emerald-600 px-2 py-0.5 text-xs text-white">
                            {{ $conversa->nao_lidas }}
                        </span>
                    @endif
                </div>

                @php
                    $ultima = $conversa->ultimaMensagem;
                    $previa = match (true) {
                        ! $ultima                   => null,
                        $ultima->tipo === 'text'    => $ultima->corpo,
                        $ultima->tipo === 'image'   => 'Foto',
                        $ultima->tipo === 'video'   => 'Video',
                        $ultima->tipo === 'audio'   => 'Audio',
                        $ultima->tipo === 'sticker' => 'Figurinha',
                        default                     => $ultima->media_nome ?: 'Documento',
                    };
                @endphp
                @if ($previa)
                    <div class="truncate text-xs text-gray-600 dark:text-gray-400">
                        @unless ($ultima->entrada()) <span class="opacity-60">voce:</span> @endunless
                        {{ \Illuminate\Support\Str::limit($previa, 44) }}
                    </div>
                @endif

                <div class="flex items-center justify-between gap-2 text-xs text-gray-400">
                    @if ($aba === \App\Models\Conversation::ARQUIVADA)
                        {{-- em Arquivadas o mesmo contato pode ter varios
                             atendimentos: sem o periodo as linhas ficam iguais --}}
                        <span title="atendimento de {{ $conversa->created_at?->format('d/m/Y H:i') }} a {{ $conversa->ultima_msg_em?->format('d/m/Y H:i') }}">
                            {{ $conversa->created_at?->format('d/m H:i') }}
                            &rarr;
                            {{ $conversa->ultima_msg_em?->format('d/m H:i') }}
                        </span>
                        <span class="shrink-0">{{ $conversa->messages_count }} msg</span>
                    @else
                        <span>{{ $conversa->ultima_msg_em?->diffForHumans() }}</span>
                        @if ($conversa->atendente)
                            <span class="truncate">{{ $conversa->atendente->name }}</span>
                        @endif
                    @endif
                </div>
            </button>
        @empty
            <p class="p-4 text-sm text-gray-500 dark:text-gray-400">
                @if ($aba === 'nova')
                    Nenhuma conversa aguardando resposta.
                @elseif ($aba === 'em_atendimento')
                    Nenhuma conversa em atendimento.
                @else
                    Nenhuma conversa arquivada.
                @endif
            </p>
        @endforelse
    </div>
</div>
