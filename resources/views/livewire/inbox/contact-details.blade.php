<div @class([
        'flex w-80 shrink-0 flex-col overflow-y-auto border-l border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => $aberto,
        'hidden' => ! $aberto,
    ])>
    @if ($aberto && $conversa)
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
            <span class="font-semibold text-gray-800 dark:text-gray-100">Detalhes do contato</span>
            <button type="button" wire:click="fechar"
                    class="rounded px-2 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                    title="Fechar">&times;</button>
        </div>

        <div class="space-y-5 p-4 text-sm">
            <div class="flex items-center gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                    {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($conversa->contact->nomeExibicao(), 0, 2)) }}
                </span>
                <div class="min-w-0">
                    <p class="truncate font-semibold text-gray-800 dark:text-gray-100">
                        {{ $conversa->contact->nomeExibicao() }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Contato desde {{ $conversa->contact->created_at?->format('d/m/Y') }}
                    </p>
                </div>
            </div>

            {{-- nome editavel: o que vem do WhatsApp e o apelido do cliente,
                 quase sempre inutil para identificar quem e --}}
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Nome</label>
                <div class="flex gap-2">
                    {{-- value explicito: sem ele o campo sai vazio do servidor
                         e so aparece depois que o Livewire hidrata --}}
                    <input type="text" wire:model="nome" value="{{ $nome }}"
                           wire:keydown.enter="salvarNome"
                           class="min-w-0 flex-1 rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                    <button type="button" wire:click="salvarNome" wire:loading.attr="disabled"
                            class="shrink-0 rounded bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                        Salvar
                    </button>
                </div>
                @error('nome') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <dl class="space-y-3">
                <div>
                    <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Telefone</dt>
                    <dd class="text-gray-800 dark:text-gray-100">{{ $conversa->contact->telefone_e164 }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Canal</dt>
                    <dd class="text-gray-800 dark:text-gray-100">
                        {{ $conversa->channel->nome }}
                        @if ($conversa->channel->telefone_e164)
                            <span class="text-xs text-gray-500">({{ $conversa->channel->telefone_e164 }})</span>
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Atendimento</dt>
                    <dd class="text-gray-800 dark:text-gray-100">
                        {{ $conversa->rotuloStatus() }}
                        @if ($conversa->atendente)
                            &middot; {{ $conversa->atendente->name }}
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="rounded border border-gray-200 dark:border-white/10">
                {{-- Etiquetas: aqui COM nome, ao contrario da lista, onde so a cor
                     aparece para nao roubar espaco do nome do cliente. --}}
                <div class="border-t border-gray-100 pt-3 dark:border-white/5">
                    <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">Etiquetas</p>

                    @if ($etiquetas->isEmpty())
                        <p class="text-xs text-gray-400">
                            Nenhuma cadastrada. Crie em <strong>Configurações &rarr; Etiquetas</strong>.
                        </p>
                    @else
                        <div class="flex flex-wrap gap-1">
                            @foreach ($etiquetas as $etiqueta)
                                @php $posta = in_array($etiqueta->id, $doContato, true); @endphp
                                <button type="button" wire:key="et-{{ $etiqueta->id }}"
                                        wire:click="alternarEtiqueta({{ $etiqueta->id }})"
                                        @class([
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 transition',
                                            $etiqueta->classes() => $posta,
                                            'bg-transparent text-gray-500 ring-gray-200 hover:bg-gray-50 dark:text-gray-400 dark:ring-white/10 dark:hover:bg-white/5' => ! $posta,
                                        ])
                                        title="{{ $posta ? 'Clique para remover' : 'Clique para aplicar' }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $etiqueta->pontinho() }}"></span>
                                    {{ $etiqueta->nome }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Notas internas: ficam no historico da conversa e NUNCA vao para o
                     cliente. Escreve-se pelo cadeado no compositor. --}}
                <div class="border-t border-gray-100 pt-3 dark:border-white/5">
                    <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">Notas internas</p>

                    @forelse ($notas as $nota)
                        <div wire:key="nota-{{ $nota->id }}" class="mb-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2 py-1.5 dark:border-amber-500/30 dark:bg-amber-500/10">
                            <p class="whitespace-pre-wrap text-xs text-amber-900 dark:text-amber-200">{{ $nota->descricao }}</p>
                            <p class="mt-0.5 text-[10px] text-amber-700/80 dark:text-amber-300/70">
                                {{ $nota->created_at?->format('d/m H:i') }}
                                @if ($nota->user) &middot; {{ $nota->user->name }} @endif
                            </p>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">Nenhuma nota. Use o cadeado no campo de mensagem.</p>
                    @endforelse
                </div>


                <div class="border-b border-gray-200 px-3 py-2 text-xs font-medium text-gray-500 dark:border-white/10 dark:text-gray-400">
                    Historico
                </div>
                <dl class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                    <div class="flex justify-between px-3 py-2">
                        <dt class="text-gray-500 dark:text-gray-400">Mensagens</dt>
                        <dd class="text-gray-800 dark:text-gray-100">{{ $resumo['total'] }}</dd>
                    </div>
                    <div class="flex justify-between px-3 py-2">
                        <dt class="text-gray-500 dark:text-gray-400">Recebidas</dt>
                        <dd class="text-gray-800 dark:text-gray-100">{{ $resumo['recebidas'] }}</dd>
                    </div>
                    <div class="flex justify-between px-3 py-2">
                        <dt class="text-gray-500 dark:text-gray-400">Enviadas</dt>
                        <dd class="text-gray-800 dark:text-gray-100">{{ $resumo['enviadas'] }}</dd>
                    </div>
                    @if ($resumo['primeira'])
                        <div class="flex justify-between px-3 py-2">
                            <dt class="text-gray-500 dark:text-gray-400">Primeiro contato</dt>
                            <dd class="text-gray-800 dark:text-gray-100">
                                {{ \Illuminate\Support\Carbon::parse($resumo['primeira'])->format('d/m/Y H:i') }}
                            </dd>
                        </div>
                    @endif
                    @if ($resumo['ultima'])
                        <div class="flex justify-between px-3 py-2">
                            <dt class="text-gray-500 dark:text-gray-400">Ultima mensagem</dt>
                            <dd class="text-gray-800 dark:text-gray-100">
                                {{ \Illuminate\Support\Carbon::parse($resumo['ultima'])->format('d/m/Y H:i') }}
                            </dd>
                        </div>
                    @endif
                    @if ($outrasConversas > 0)
                        <div class="flex justify-between px-3 py-2">
                            <dt class="text-gray-500 dark:text-gray-400">Outros canais</dt>
                            <dd class="text-gray-800 dark:text-gray-100">{{ $outrasConversas }} conversa(s)</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>
    @endif
</div>
