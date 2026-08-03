<div class="border-b border-gray-200 p-3 dark:border-white/10">
    @if (! $aberto)
        <button type="button" wire:click="alternar"
                class="w-full rounded bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">
            + Nova conversa
        </button>
    @else
        <div class="space-y-2">
            <input type="text" wire:model.live.debounce.300ms="termo" autocomplete="off" autofocus
                   placeholder="Nome do contato ou telefone"
                   class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">

            {{-- contatos salvos --}}
            @if ($contatos->isNotEmpty())
                <ul class="max-h-56 overflow-y-auto rounded border border-gray-200 dark:border-white/10">
                    @foreach ($contatos as $contato)
                        <li wire:key="ct-{{ $contato->id }}">
                            <button type="button" wire:click="selecionarContato({{ $contato->id }})"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/5">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-100 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                                    {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($contato->nomeExibicao(), 0, 2)) }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-1.5">
                                        @if ($contato->eGrupo())
                                            <span class="shrink-0 rounded bg-gray-200 px-1 text-[10px] text-gray-600 dark:bg-white/10 dark:text-gray-300">grupo</span>
                                        @endif
                                        <span class="truncate text-sm text-gray-800 dark:text-gray-100">{{ $contato->nomeExibicao() }}</span>
                                    </span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                        @if ($contato->telefone_e164)
                                            {{ $contato->telefone_e164 }}
                                        @endif
                                        @if (isset($emAtendimento[$contato->id]))
                                            &middot; {{ $emAtendimento[$contato->id]['status'] }}
                                            @if ($emAtendimento[$contato->id]['atendente'])
                                                com {{ $emAtendimento[$contato->id]['atendente'] }}
                                            @endif
                                        @endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif (mb_strlen(trim($termo)) >= 2 && ! $telefoneDigitado)
                <p class="px-1 text-xs text-gray-500 dark:text-gray-400">Nenhum contato salvo com esse termo.</p>
            @endif

            {{-- numero novo --}}
            @if ($telefoneDigitado)
                <button type="button" wire:click="iniciar" wire:loading.attr="disabled" wire:target="iniciar"
                        class="w-full rounded border border-emerald-600 px-3 py-2 text-left text-sm text-emerald-700 hover:bg-emerald-50 disabled:opacity-60 dark:text-emerald-400 dark:hover:bg-emerald-500/10">
                    <span wire:loading.remove wire:target="iniciar">Iniciar conversa com {{ $telefoneDigitado }}</span>
                    <span wire:loading wire:target="iniciar">Verificando no WhatsApp&hellip;</span>
                </button>
            @endif

            <textarea wire:model="primeiraMensagem" rows="2"
                      placeholder="Primeira mensagem (opcional)"
                      class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>

            @error('termo') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

            <button type="button" wire:click="alternar"
                    class="w-full rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-600 dark:border-white/20 dark:text-gray-300">
                Cancelar
            </button>
        </div>
    @endif
</div>
