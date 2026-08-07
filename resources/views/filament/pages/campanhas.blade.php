<x-filament-panels::page>
    @error('campanha')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</div>
    @enderror

    @if (! $formAberto)
        <div class="flex justify-end">
            <x-filament::button wire:click="novaCampanha" icon="heroicon-o-plus">Nova campanha</x-filament::button>
        </div>
    @endif

    {{-- ------------------------------------------------------------ o formulario --}}
    @if ($formAberto)
        <x-filament::section>
            <x-slot name="heading">{{ $editando ? 'Editar campanha' : 'Nova campanha' }}</x-slot>

            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Nome (só você vê)</span>
                        <input type="text" wire:model="nome" placeholder="Promoção de agosto"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @error('nome') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Por qual canal</span>
                        <select wire:model.live="channel_id"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            @foreach ($canais as $c)
                                <option value="{{ $c->id }}">{{ $c->nome }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                {{-- O aviso mais importante desta tela. Disparo em massa pelo canal por QR e o
                     gatilho mais rapido de banimento, e um numero banido leva junto o
                     atendimento inteiro — nao so a campanha. --}}
                @if ($canalPorQr)
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        <p class="font-semibold">Este é um canal conectado por QR code.</p>
                        <p class="mt-1">
                            Disparo em massa é o motivo número um de banimento de número no WhatsApp.
                            O ritmo abaixo existe para reduzir esse risco — <strong>não para eliminá-lo</strong>.
                            Se este número também é o do seu atendimento, um banimento leva as duas coisas.
                        </p>
                    </div>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Para quem</span>
                        <select wire:model.live="publico"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            <option value="etiqueta">Contatos com uma etiqueta</option>
                            <option value="todos">Todos os contatos</option>
                        </select>
                    </label>

                    @if ($publico === 'etiqueta')
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Qual etiqueta</span>
                            <select wire:model.live="tag_id"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                                <option value="">escolha</option>
                                @foreach ($etiquetas as $e)
                                    <option value="{{ $e->id }}">{{ $e->nome }}</option>
                                @endforeach
                            </select>
                            @error('tag_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    @endif
                </div>

                {{-- OS DOIS NUMEROS. "482 contatos, 41 fora" faz perguntar por que; "441"
                     sozinho não faz perguntar nada. --}}
                @if ($previa)
                    <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">
                        <strong>{{ $previa['final'] }}</strong> {{ $previa['final'] === 1 ? 'pessoa recebe' : 'pessoas recebem' }}
                        @if ($previa['fora'] > 0)
                            · <strong>{{ $previa['fora'] }}</strong> {{ $previa['fora'] === 1 ? 'fica' : 'ficam' }} de fora
                            (pediram para sair, estão bloqueados, arquivados, ou são grupos)
                        @endif
                    </div>
                @endif

                @if ($exigeTemplate)
                    <div>
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Template aprovado</span>
                        <p class="mb-1 text-[11px] text-gray-500">
                            No canal oficial, campanha só sai por template aprovado pela Meta —
                            fora da janela de 24 horas ela recusa texto livre, e numa campanha a
                            janela está fechada para quase todo mundo.
                        </p>
                        <select wire:model.live="meta_template_id"
                                class="w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                            <option value="">escolha</option>
                            @foreach ($templates as $t)
                                <option value="{{ $t->id }}">{{ $t->nome }}</option>
                            @endforeach
                        </select>
                        @error('meta_template_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                @else
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">A mensagem</span>
                        <textarea wire:model="corpo" rows="4"
                                  class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800"></textarea>
                        <span class="text-[11px] text-gray-500">
                            Quem responder <strong>PARAR</strong> sai da lista automaticamente e não
                            recebe mais campanha nenhuma. Vale a pena dizer isso na mensagem.
                        </span>
                        @error('corpo') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endif

                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Por minuto</span>
                        <input type="number" wire:model="por_minuto" min="1" max="30"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        <span class="text-[11px] text-gray-500">6 é conservador. 30 é o teto.</span>
                        @error('por_minuto') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Só a partir das</span>
                        <input type="number" wire:model="hora_inicio" min="0" max="23"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">E até as</span>
                        <input type="number" wire:model="hora_fim" min="1" max="23"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        @error('hora_fim') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>

                <p class="text-[11px] text-gray-500">
                    O que não couber na janela de hoje continua amanhã no mesmo horário. Disparo
                    de madrugada não é só falta de educação — é assédio de consumo no CDC.
                </p>

                <div class="flex gap-2">
                    <x-filament::button wire:click="salvar">Salvar</x-filament::button>
                    <x-filament::button color="gray" wire:click="$set('formAberto', false)">Cancelar</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- ------------------------------------------------------------- a lista --}}
    <x-filament::section>
        <x-slot name="heading">Suas campanhas</x-slot>

        @if ($campanhas->isEmpty())
            <p class="py-6 text-center text-sm text-gray-500">
                Nenhuma campanha ainda. A primeira coisa a fazer é etiquetar os contatos que
                devem receber.
            </p>
        @else
            <div class="space-y-3">
                @foreach ($campanhas as $c)
                    @php
                        $total = $c->recipients_count;
                        $feitas = $c->enviadas_count + $c->puladas_count + $c->falharam_count;
                        $pct = $total > 0 ? (int) round($feitas * 100 / $total) : 0;
                    @endphp

                    <div wire:key="camp-{{ $c->id }}"
                         class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $c->nome }}</span>
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                        'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => in_array($c->status, ['rascunho','cancelada']),
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300' => in_array($c->status, ['enviando','agendada']),
                                        'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' => $c->status === 'pausada',
                                        'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-300' => $c->status === 'concluida',
                                    ])>{{ \App\Models\Campaign::ROTULOS[$c->status] }}</span>
                                </div>

                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ $c->channel?->nome }}
                                    @if ($c->tag) · etiqueta {{ $c->tag->nome }} @endif
                                    · {{ $c->por_minuto }}/min · das {{ $c->hora_inicio }}h às {{ $c->hora_fim }}h
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-wrap gap-1">
                                @if ($c->editavel())
                                    <x-filament::button size="xs" color="gray" wire:click="editar({{ $c->id }})">Editar</x-filament::button>
                                    <x-filament::button size="xs" wire:click="iniciar({{ $c->id }})"
                                        wire:confirm="Começar a disparar agora?">Disparar</x-filament::button>
                                @endif

                                @if ($c->status === 'enviando')
                                    <x-filament::button size="xs" color="warning" wire:click="pausar({{ $c->id }})">Pausar</x-filament::button>
                                @endif

                                @if ($c->status === 'pausada')
                                    <x-filament::button size="xs" wire:click="retomar({{ $c->id }})">Retomar</x-filament::button>
                                @endif

                                @if ($c->rodando() || $c->status === 'pausada')
                                    <x-filament::button size="xs" color="danger" wire:click="cancelar({{ $c->id }})"
                                        wire:confirm="Cancelar? O que já saiu não volta.">Cancelar</x-filament::button>
                                @endif

                                @if ($c->enviadas_count === 0)
                                    <x-filament::button size="xs" color="gray" wire:click="excluir({{ $c->id }})">Excluir</x-filament::button>
                                @endif
                            </div>
                        </div>

                        @if ($total > 0)
                            <div class="mt-3">
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                    <div class="h-full rounded-full bg-emerald-500" style="width: {{ $pct }}%"></div>
                                </div>
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                    <strong>{{ $c->enviadas_count }}</strong> de {{ $total }} enviadas
                                    @if ($c->puladas_count > 0) · {{ $c->puladas_count }} puladas @endif
                                    @if ($c->falharam_count > 0)
                                        · <span class="text-red-600 dark:text-red-400">{{ $c->falharam_count }} falharam</span>
                                    @endif
                                </p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
