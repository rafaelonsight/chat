<x-filament-panels::page>
    <form wire:submit="salvar" class="max-w-2xl space-y-4">
        @php
            $campos = [
                ['nome', 'Nome da conta', 'text', 'Como o provedor e conhecido.'],
                ['razao_social', 'Razão social', 'text', 'Opcional.'],
                ['documento', 'CNPJ ou CPF', 'text', 'Opcional.'],
                ['email', 'E-mail de contato', 'email', 'Opcional.'],
                ['telefone', 'Telefone', 'text', 'Opcional.'],
            ];
        @endphp

        @foreach ($campos as [$campo, $rotulo, $tipo, $ajuda])
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ $rotulo }}</label>
                <input type="{{ $tipo }}" wire:model="{{ $campo }}"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $ajuda }}</p>
                @error($campo) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Fuso horário</label>
            <select wire:model="fuso_horario"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                @foreach ($this->fusos() as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
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
