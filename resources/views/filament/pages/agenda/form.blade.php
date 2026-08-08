<x-filament::section>
    <x-slot name="heading">{{ $editando ? 'Editar' : 'Novo na agenda' }}</x-slot>

    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block sm:col-span-2">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">O quê</span>
                <input type="text" wire:model="titulo" maxlength="120"
                       placeholder="Visita técnica, retorno de orçamento, ligar para o cliente…"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                @error('titulo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Tipo</span>
                <select wire:model.live="tipo"
                        class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    @foreach (\App\Models\Appointment::TIPOS as $chave => $rotulo)
                        <option value="{{ $chave }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Quando</span>
                <input type="datetime-local" wire:model="quando"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                @error('quando') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>

            @if ($tipo === \App\Models\Appointment::COMPROMISSO)
                <label class="block">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Duração (min)</span>
                    <input type="number" wire:model="duracao_min" min="5" max="1440" step="5"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">De quem é</span>
                    <select wire:model="user_id"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @foreach ($pessoas as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </label>
            @else
                <div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    Lembrete é seu: <strong>ninguém mais vê</strong>, e ele não vai para a
                    agenda da equipe.
                </div>
            @endif
        </div>

        {{-- Contato é OPCIONAL: "ligar para o contador" não tem contato cadastrado, e exigir um
             faria a pessoa inventar cadastro para conseguir anotar. --}}
        <div>
            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Com quem (opcional)</span>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <input type="text" wire:model.live.debounce.300ms="buscaContato" placeholder="nome do contato"
                       class="min-w-52 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                @if ($contact_id)
                    <x-filament::button size="xs" color="gray" wire:click="tirarContato">Tirar</x-filament::button>
                @endif
            </div>

            @if ($candidatos->isNotEmpty())
                <div class="mt-2 flex flex-wrap gap-1">
                    @foreach ($candidatos as $c)
                        <button type="button" wire:key="ct-{{ $c->id }}" wire:click="escolherContato({{ $c->id }})"
                                class="rounded-full border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-100 dark:border-white/20 dark:hover:bg-white/5">
                            {{ $c->nomeExibicao() }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ------------------------------------------------------------- por vídeo --}}
        @if ($tipo === \App\Models\Appointment::COMPROMISSO)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model.live="por_video"
                           class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500 dark:border-white/20">
                    <span>
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-100">
                            Reunião por vídeo
                        </span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            Abre uma sala em {{ config('app.name') }} e manda o link no WhatsApp de
                            quem foi convidado. Não precisa instalar nada.
                        </span>
                    </span>
                </label>

                @if ($por_video && ! app(\App\Services\Video\Chamada::class)->disponivel())
                    <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                        Este servidor não tem a chamada de vídeo configurada.
                    </p>
                @elseif ($por_video && ! $contact_id)
                    {{-- Sem contato nao ha para quem mandar: a sala nasce e o link fica na tela. --}}
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Sem um contato escolhido, a sala é criada e o link fica aqui para você
                        mandar para quem vai participar.
                    </p>
                @endif

                {{-- O link do compromisso que já é por vídeo, para copiar e remandar. --}}
                @if ($editando)
                    @php($jaTem = \App\Models\Appointment::find($editando)?->meeting)

                    @if ($jaTem)
                        <div class="mt-3 flex items-center gap-1 border-t border-gray-100 pt-3 dark:border-white/5">
                            <a href="{{ $jaTem->url() }}" target="_blank" rel="noopener"
                               class="truncate text-xs text-primary-600 underline dark:text-primary-400">{{ $jaTem->url() }}</a>
                            <x-inbox.copiar :valor="$jaTem->url()" titulo="Copiar o link da reunião" />
                        </div>
                    @endif
                @endif
            </div>
        @endif

        {{-- --------------------------------------------------- outro em cima da hora --}}
        @php($choques = $this->conflitos())

        @if ($choques->isNotEmpty())
            {{-- Avisa e nao impede: marcar duas coisas na mesma hora as vezes e proposital, e o
                 horario ja fica travado onde importa — o link publico nao oferece vaga ocupada. --}}
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="font-medium">Já tem coisa nesse horário:</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($choques as $c)
                        <li>· {{ $c->comeca_em->format('H:i') }} — {{ $c->titulo }}</li>
                    @endforeach
                </ul>
                <p class="mt-1 opacity-80">Dá para salvar assim mesmo, se for de propósito.</p>
            </div>
        @endif

        <label class="block">
            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Observação (opcional)</span>
            <textarea wire:model="descricao" rows="2"
                      class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800"></textarea>
        </label>

        <div class="flex flex-wrap gap-2">
            <x-filament::button wire:click="salvar">Salvar</x-filament::button>
            <x-filament::button color="gray" wire:click="$set('formAberto', false)">Cancelar</x-filament::button>

            @if ($editando)
                <x-filament::button color="gray" wire:click="concluir({{ $editando }})">Marcar como feito</x-filament::button>
                <x-filament::button class="ms-auto" color="danger" wire:click="excluir({{ $editando }})"
                                    wire:confirm="Excluir da agenda?">Excluir</x-filament::button>
            @endif
        </div>
    </div>
</x-filament::section>
