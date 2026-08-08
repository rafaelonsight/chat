<div class="mx-auto max-w-3xl px-4 py-8 sm:py-14">
    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">

        {{-- ---------------------------------------------------------- cabeçalho --}}
        <div class="border-b border-gray-100 px-6 py-5">
            <h1 class="text-xl font-semibold text-gray-900">{{ $pagina->titulo }}</h1>

            <p class="mt-1 text-sm text-gray-500">
                com {{ $pagina->user?->name }}
                · {{ $pagina->duracao_min }} min
                @if ($pagina->local) · {{ $pagina->local }} @endif
            </p>

            @if ($pagina->descricao)
                <p class="mt-3 whitespace-pre-line text-sm text-gray-600">{{ $pagina->descricao }}</p>
            @endif
        </div>

        {{-- ------------------------------------------------------------ marcado --}}
        @if ($confirmado)
            @php($q = \Illuminate\Support\Carbon::parse($confirmado))

            <div class="px-6 py-10 text-center">
                <div class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-emerald-100 text-2xl text-emerald-700">&check;</div>

                <h2 class="mt-4 text-lg font-semibold text-gray-900">Horário confirmado</h2>

                <p class="mt-1 text-base text-gray-700">
                    {{ \App\Support\DataPtBr::porExtenso($q) }} às <strong>{{ $q->format('H:i') }}</strong>
                </p>

                @if ($pagina->channel_id)
                    <p class="mt-3 text-sm text-gray-500">
                        Mandamos a confirmação no seu WhatsApp. Se precisar remarcar, é só
                        responder por lá.
                    </p>
                @else
                    <p class="mt-3 text-sm text-gray-500">Anote aí: {{ $q->format('d/m/Y \à\s H:i') }}.</p>
                @endif

                <button type="button" wire:click="$set('confirmado', null)"
                        class="mt-6 text-sm text-gray-500 underline">marcar outro horário</button>
            </div>

        {{-- ------------------------------------------------------------ fechado --}}
        @elseif (! $pagina->ativa)
            <div class="px-6 py-14 text-center">
                <h2 class="text-base font-medium text-gray-800">Esta agenda está fechada no momento</h2>
                <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500">
                    O link continua valendo — assim que voltar a receber horários, ele mostra as
                    opções aqui.
                </p>
            </div>

        @elseif (empty($vagas))
            <div class="px-6 py-14 text-center">
                <h2 class="text-base font-medium text-gray-800">Nenhum horário livre por enquanto</h2>
                <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500">
                    Tente de novo mais tarde, ou fale direto com {{ $pagina->user?->name }}.
                </p>
            </div>

        {{-- ---------------------------------------------------------- escolher --}}
        @else
            @if ($recado)
                <div class="border-b border-amber-200 bg-amber-50 px-6 py-3 text-sm text-amber-800">
                    {{ $recado }}
                </div>
            @endif

            <div class="px-6 py-5">
                {{-- Os dias em fita: so os que TEM vaga entram. Mostrar um mes inteiro e deixar
                     a pessoa descobrir clicando qual dia esta livre e fazer ela trabalhar. --}}
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Escolha o dia</p>

                <div class="mt-2 flex gap-2 overflow-x-auto pb-1">
                    @foreach ($vagas as $chave => $horarios)
                        @php($d = \Illuminate\Support\Carbon::parse($chave))

                        <button type="button" wire:key="dia-{{ $chave }}" wire:click="escolherDia('{{ $chave }}')"
                                @class([
                                    'shrink-0 rounded-xl border px-3 py-2 text-center',
                                    'border-gray-900 bg-gray-900 text-white' => $dia === $chave,
                                    'border-gray-200 text-gray-700 hover:border-gray-400' => $dia !== $chave,
                                ])>
                            <span class="block text-xs uppercase opacity-70">{{ \App\Support\DataPtBr::curto($d) }}</span>
                            <span class="block text-sm font-medium">{{ count($horarios) }} horário{{ count($horarios) === 1 ? '' : 's' }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($quando === '')
                <div class="border-t border-gray-100 px-6 py-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">
                        {{ $dia ? \App\Support\DataPtBr::porExtenso(\Illuminate\Support\Carbon::parse($dia)) : 'Escolha a hora' }}
                    </p>

                    <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @foreach ($doDia as $vaga)
                            <button type="button" wire:key="h-{{ $vaga->format('Hi') }}"
                                    wire:click="escolherHora('{{ $vaga->format('Y-m-d H:i:s') }}')"
                                    class="rounded-lg border border-gray-200 py-2 text-sm font-medium text-gray-800 hover:border-gray-900 hover:bg-gray-50">
                                {{ $vaga->format('H:i') }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @else
                {{-- ------------------------------------------------------- os dados --}}
                @php($q = \Illuminate\Support\Carbon::parse($quando))

                <div class="border-t border-gray-100 px-6 py-5">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm text-gray-700">
                            <strong>{{ \App\Support\DataPtBr::porExtenso($q) }}</strong> às
                            <strong>{{ $q->format('H:i') }}</strong>
                        </p>

                        <button type="button" wire:click="voltar" class="text-xs text-gray-500 underline">trocar</button>
                    </div>

                    <div class="mt-4 space-y-3">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Seu nome</span>
                            <input type="text" wire:model="nome" maxlength="80" autocomplete="name"
                                   class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            @error('nome') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">WhatsApp (com DDD)</span>
                            <input type="tel" wire:model="telefone" maxlength="25" autocomplete="tel"
                                   placeholder="(41) 99999-0000"
                                   class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            @error('telefone') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">Quer adiantar alguma coisa? (opcional)</span>
                            <textarea wire:model="observacao" rows="2" maxlength="500"
                                      class="mt-1 w-full rounded-lg border-gray-300 text-sm"></textarea>
                            @error('observacao') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <button type="button" wire:click="confirmar" wire:loading.attr="disabled"
                                class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-60">
                            <span wire:loading.remove wire:target="confirmar">Confirmar horário</span>
                            <span wire:loading wire:target="confirmar">Confirmando…</span>
                        </button>
                    </div>
                </div>
            @endif
        @endif
    </div>

    <p class="mt-4 text-center text-xs text-gray-400">
        Horários no fuso de Brasília.
    </p>
</div>
