<x-filament-panels::page>
    @php
        $classeInput = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100';
        $classeRotulo = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
        $classeAjuda = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    @endphp

    <form wire:submit="salvar" class="max-w-2xl space-y-6">
        {{-- O CNPJ vem primeiro porque e ele que preenche o resto. --}}
        <div>
            <label class="{{ $classeRotulo }}">CNPJ ou CPF</label>

            <div class="flex items-start gap-2">
                <div class="flex-1">
                    {{-- .blur e nao .live: consultar a Receita a cada tecla digitada
                         gastaria o limite por IP em um cadastro so. --}}
                    <input type="text" wire:model.blur="documento" inputmode="numeric"
                           placeholder="00.000.000/0000-00"
                           class="{{ $classeInput }}">
                </div>

                <x-filament::button type="button" color="gray" wire:click="buscarCnpj"
                                    wire:loading.attr="disabled" wire:target="buscarCnpj,documento">
                    <span wire:loading.remove wire:target="buscarCnpj,documento">Buscar dados</span>
                    <span wire:loading wire:target="buscarCnpj,documento">Buscando…</span>
                </x-filament::button>
            </div>

            <p class="{{ $classeAjuda }}">
                Digite o CNPJ e saia do campo: razão social, nome fantasia e endereço
                vêm da Receita Federal sozinhos. Para CPF não existe consulta pública.
            </p>
            @error('documento') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        @php
            $identidade = [
                ['nome', 'Nome da conta', 'text', 'Como a sua empresa é conhecida.'],
                ['razao_social', 'Razão social', 'text', 'Vem da Receita.'],
                ['nome_fantasia', 'Nome fantasia', 'text', 'Vem da Receita, quando houver.'],
                ['email', 'E-mail de contato', 'email', 'Opcional.'],
                ['telefone', 'Telefone', 'text', 'Opcional.'],
            ];
        @endphp

        @foreach ($identidade as [$campo, $rotulo, $tipo, $ajuda])
            <div>
                <label class="{{ $classeRotulo }}">{{ $rotulo }}</label>
                <input type="{{ $tipo }}" wire:model="{{ $campo }}" class="{{ $classeInput }}">
                <p class="{{ $classeAjuda }}">{{ $ajuda }}</p>
                @error($campo) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <div class="border-t border-gray-200 pt-6 dark:border-white/10">
            <h3 class="mb-4 text-sm font-semibold text-gray-800 dark:text-gray-100">Endereço</h3>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                @php
                    $endereco = [
                        ['cep', 'CEP', 'sm:col-span-2'],
                        ['logradouro', 'Logradouro', 'sm:col-span-4'],
                        ['numero', 'Número', 'sm:col-span-2'],
                        ['complemento', 'Complemento', 'sm:col-span-4'],
                        ['bairro', 'Bairro', 'sm:col-span-3'],
                        ['cidade', 'Cidade', 'sm:col-span-2'],
                        ['uf', 'UF', 'sm:col-span-1'],
                    ];
                @endphp

                @foreach ($endereco as [$campo, $rotulo, $colunas])
                    <div class="{{ $colunas }}">
                        <label class="{{ $classeRotulo }}">{{ $rotulo }}</label>
                        <input type="text" wire:model="{{ $campo }}" class="{{ $classeInput }}">
                        @error($campo) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Somente leitura: sao dados da Receita, corrigir aqui nao muda nada la. --}}
        @if (filled($this->dadosReceita()))
            <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                <h3 class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">
                    Dados da Receita Federal
                </h3>

                <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                    @foreach ($this->dadosReceita() as $rotulo => $valor)
                        <div class="flex justify-between gap-3 text-xs sm:block">
                            <dt class="text-gray-500 dark:text-gray-400">{{ $rotulo }}</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-100">{{ $valor }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif

        <div>
            <label class="{{ $classeRotulo }}">Fuso horário</label>
            <select wire:model="fuso_horario" class="{{ $classeInput }}">
                @foreach ($this->fusos() as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            <p class="{{ $classeAjuda }}">
                Usado nos horários mostrados e, adiante, no horário de atendimento.
            </p>
            @error('fuso_horario') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-3 pt-2">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="salvar">Salvar</span>
                <span wire:loading wire:target="salvar">Salvando…</span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
