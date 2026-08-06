<x-filament-panels::page>
    @php
        $classeInput = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100';
        $classeRotulo = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
        $classeAjuda = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    @endphp

    {{-- O resultado vem ANTES do formulario: o link do convite e a unica coisa que o
         operador precisa levar dali para fora, e rolar a tela para procura-lo seria o
         momento em que ele se perde. --}}
    @if ($clienteCriado && $linkDoConvite)
        <div @class([
            'rounded-xl border p-4',
            'border-green-300 bg-green-50 dark:border-green-500/30 dark:bg-green-500/10' => $emailEnviado,
            'border-amber-300 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' => ! $emailEnviado,
        ])>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $clienteCriado }} — link de acesso
            </h3>

            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                @if ($emailEnviado)
                    O convite foi enviado por e-mail. O link abaixo é o mesmo — mande também
                    por WhatsApp se quiser garantir.
                @else
                    <strong>O e-mail não saiu.</strong> Mande o link abaixo pelo caminho que
                    tiver: ele funciona igual.
                @endif
            </p>

            <div class="mt-3 flex items-start gap-2" x-data="{ copiado: false }">
                <input type="text" readonly value="{{ $linkDoConvite }}"
                       x-ref="link" @click="$refs.link.select()"
                       class="{{ $classeInput }} font-mono text-xs">

                <x-filament::button type="button" color="gray"
                    x-on:click="navigator.clipboard.writeText($refs.link.value); copiado = true; setTimeout(() => copiado = false, 2000)">
                    <span x-show="! copiado">Copiar</span>
                    <span x-show="copiado" x-cloak>Copiado</span>
                </x-filament::button>
            </div>

            <p class="{{ $classeAjuda }}">
                Vale por {{ config('auth.passwords.users.expire') }} minutos. Depois disso, use
                <em>Reenviar convite</em> na lista abaixo.
            </p>

            @if ($falhaDeEmail)
                {{-- O motivo tecnico fica visivel: sem ele o operador so sabe que "nao foi",
                     e "nao foi" nao se conserta. --}}
                <p class="mt-2 font-mono text-[11px] text-amber-800 dark:text-amber-300">
                    {{ \Illuminate\Support\Str::limit($falhaDeEmail, 240) }}
                </p>
            @endif
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">Novo cliente</x-slot>
        <x-slot name="description">
            Cria a conta e o primeiro usuário dela, que entra como administrador e configura o
            resto sozinho.
        </x-slot>

        <form wire:submit="criar" class="max-w-2xl space-y-6">
            <div>
                <label class="{{ $classeRotulo }}">CNPJ</label>

                <div class="flex items-start gap-2">
                    {{-- .blur e nao .live: consultar a Receita a cada tecla gastaria o
                         limite por IP em um cadastro so. --}}
                    <input type="text" wire:model.blur="documento" inputmode="numeric"
                           placeholder="00.000.000/0000-00" class="{{ $classeInput }} flex-1">

                    <x-filament::button type="button" color="gray" wire:click="buscarCnpj"
                                        wire:loading.attr="disabled" wire:target="buscarCnpj,documento">
                        <span wire:loading.remove wire:target="buscarCnpj,documento">Buscar</span>
                        <span wire:loading wire:target="buscarCnpj,documento">Buscando…</span>
                    </x-filament::button>
                </div>

                <p class="{{ $classeAjuda }}">
                    Opcional. Se preencher, razão social e endereço vêm da Receita e o cliente
                    já encontra o cadastro dele pronto.
                </p>
                @error('documento') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @php
                $campos = [
                    ['nome', 'Nome do cliente', 'text', 'Como você chama essa empresa.'],
                    ['email', 'E-mail de contato', 'email', 'Opcional. Da empresa, não do usuário.'],
                    ['telefone', 'Telefone', 'text', 'Opcional.'],
                ];
            @endphp

            @foreach ($campos as [$campo, $rotulo, $tipo, $ajuda])
                <div>
                    <label class="{{ $classeRotulo }}">{{ $rotulo }}</label>
                    <input type="{{ $tipo }}" wire:model="{{ $campo }}" class="{{ $classeInput }}">
                    <p class="{{ $classeAjuda }}">{{ $ajuda }}</p>
                    @error($campo) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endforeach

            <div>
                <label class="{{ $classeRotulo }}">Fuso horário</label>
                <select wire:model="fuso_horario" class="{{ $classeInput }}">
                    @foreach ($this->fusos() as $valor => $rotulo)
                        <option value="{{ $valor }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
                @error('fuso_horario') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="border-t border-gray-200 pt-6 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                    Quem vai administrar a conta
                </h3>
                <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                    Recebe o convite, escolhe a própria senha e depois cadastra o resto da
                    equipe. Você não define senha para ninguém.
                </p>

                <div class="space-y-4">
                    <div>
                        <label class="{{ $classeRotulo }}">Nome</label>
                        <input type="text" wire:model="responsavel_nome" class="{{ $classeInput }}">
                        @error('responsavel_nome') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="{{ $classeRotulo }}">E-mail</label>
                        <input type="email" wire:model="responsavel_email" class="{{ $classeInput }}">
                        <p class="{{ $classeAjuda }}">É com ele que a pessoa entra no sistema.</p>
                        @error('responsavel_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="pt-2">
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="criar">
                    <span wire:loading.remove wire:target="criar">Cadastrar cliente</span>
                    <span wire:loading wire:target="criar">Cadastrando…</span>
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Clientes</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="py-2 pr-3">Cliente</th>
                        <th class="py-2 pr-3">CNPJ</th>
                        <th class="py-2 pr-3 text-right">Usuários</th>
                        <th class="py-2 pr-3 text-right">Canais</th>
                        <th class="py-2 pr-3 text-right">Contatos</th>
                        <th class="py-2 pr-3">Desde</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($this->clientes() as $c)
                        <tr>
                            <td class="py-2 pr-3">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $c['nome'] }}</span>
                                @if ($c['eu'])
                                    <span class="ml-2 rounded bg-gray-200 px-1.5 py-0.5 text-[11px] text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                        sua conta
                                    </span>
                                @endif
                                @if ($c['email'])
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $c['email'] }}</div>
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-gray-600 dark:text-gray-300">{{ $c['documento'] ?: '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $c['usuarios'] }}</td>
                            {{-- Cliente sem canal nao recebe mensagem nenhuma: e o unico
                                 numero desta tabela que significa "parado". --}}
                            <td @class(['py-2 pr-3 text-right tabular-nums', 'text-amber-600 dark:text-amber-400 font-medium' => $c['canais'] === 0])>
                                {{ $c['canais'] }}
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $c['contatos'] }}</td>
                            <td class="py-2 pr-3 text-gray-600 dark:text-gray-300">{{ $c['criado'] }}</td>
                            <td class="py-2 text-right">
                                <x-filament::button type="button" size="xs" color="gray"
                                                    wire:click="reenviar({{ $c['id'] }})"
                                                    wire:loading.attr="disabled">
                                    Reenviar convite
                                </x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
