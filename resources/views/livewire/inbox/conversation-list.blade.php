<div class="flex flex-1 flex-col overflow-hidden">
    {{-- escopo: o que eu olho agora --}}
    <div class="flex shrink-0 flex-wrap gap-1 border-b border-gray-200 px-2 py-2 dark:border-white/10">
        @foreach ($rotulosEscopo as $chave => $rotulo)
            <button type="button" wire:key="esc-{{ $chave }}"
                    wire:click="selecionarEscopo('{{ $chave }}')"
                    class="rounded-full px-2.5 py-1 text-xs font-medium transition
                           {{ $escopo === $chave
                                ? 'bg-gray-800 text-white dark:bg-white/20'
                                : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' }}">
                {{ $rotulo }}
                @if ($escopos[$chave] > 0)
                    <span class="ml-0.5 opacity-70">{{ $escopos[$chave] }}</span>
                @endif
            </button>
        @endforeach
    </div>

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
                    <span class="flex min-w-0 items-center gap-1.5">
                        @if ($conversa->contact->eGrupo())
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                 stroke="currentColor" class="h-3.5 w-3.5 shrink-0 text-gray-400" title="Grupo">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72M18 18.72m0 0a5.971 5.971 0 0 1-.941 3.197m0 0A5.995 5.995 0 0 1 12 21.75c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                            </svg>
                        @endif
                        <span class="truncate font-medium text-gray-800 dark:text-gray-100">
                            {{ $conversa->contact->nomeExibicao() }}
                        </span>
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
