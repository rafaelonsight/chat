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
                    <strong>{{ __('ui_f2532030dd87') }}</strong> Mande o link abaixo pelo caminho que
                    tiver: ele funciona igual.
                @endif
            </p>

            <div class="mt-3 flex items-start gap-2" x-data="{ copiado: false }">
                <input type="text" readonly value="{{ $linkDoConvite }}"
                       x-ref="link" @click="$refs.link.select()"
                       class="{{ $classeInput }} font-mono text-xs">

                <x-filament::button type="button" color="gray"
                    x-on:click="navigator.clipboard.writeText($refs.link.value); copiado = true; setTimeout(() => copiado = false, 2000)">
                    <span x-show="! copiado">{{ __('ui_885410773340') }}</span>
                    <span x-show="copiado" x-cloak>{{ __('ui_9e417050324b') }}</span>
                </x-filament::button>
            </div>

            <p class="{{ $classeAjuda }}">
                Vale por {{ config('auth.passwords.users.expire') }} minutos. Depois disso, use
                <em>{{ __('ui_2fb81f909726') }}</em> {{ __('ui_f33e883f677d') }}
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
        <x-slot name="heading">{{ __('ui_a0cf533c2cba') }}</x-slot>
        <x-slot name="description">
            {{ __('ui_8f57c790a35f') }}
        </x-slot>

        <form wire:submit="criar" class="max-w-2xl space-y-6">
            <div class="flex gap-2" aria-label="{{ __('ui_350c4092fa04') }}">
                @foreach ([1=>'Identificação',2=>'Endereço',3=>'Administrador'] as $numero=>$titulo)
                    <span @class(['flex-1 rounded-lg px-3 py-2 text-sm','bg-primary-100 text-primary-700 font-semibold'=>$etapaCadastro===$numero,'bg-gray-100 text-gray-500'=>$etapaCadastro!==$numero])>{{ $numero }}. {{ $titulo }}</span>
                @endforeach
            </div>
            @if($etapaCadastro===1)

            <div>
                <label class="{{ $classeRotulo }}">{{ __('ui_b5f2e070f4b2') }}</label>

                <div class="flex items-start gap-2">
                    {{-- .blur e nao .live: consultar a Receita a cada tecla gastaria o
                         limite por IP em um cadastro so. --}}
                    <input type="text" wire:model.live.debounce.600ms="documento" inputmode="numeric"
                           placeholder="{{ __('ui_88c2b6666515') }}" class="{{ $classeInput }} flex-1">

                    <x-filament::button type="button" color="gray" wire:click="buscarCnpj"
                                        wire:loading.attr="disabled" wire:target="buscarCnpj,documento">
                        <span wire:loading.remove wire:target="buscarCnpj,documento">{{ __('ui_a62405e2cefb') }}</span>
                        <span wire:loading wire:target="buscarCnpj,documento">{{ __('ui_9870b9e56922') }}</span>
                    </x-filament::button>
                </div>

                <p class="{{ $classeAjuda }}">
                    {{ __('ui_4b281a24b232') }}
                </p>
                @error('documento') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @php
                $campos = [
                    ['nome', 'Nome do cliente', 'text', 'Nome da pessoa ou empresa.'],
                    ['email_resumos', 'E-mail para receber resumos', 'email', 'Opcional. Em branco, usa o e-mail do administrador.'],
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

            @endif
            @if($etapaCadastro===2)
            <fieldset class="space-y-3 rounded-xl border border-gray-200 p-4">
                <legend class="px-2 font-semibold">{{ __('ui_ef764c1db7f8') }}</legend>
                <label class="block">{{ __('ui_2cbe9f1f9067') }}<input wire:model.live.debounce.600ms="receita.cep" inputmode="numeric" maxlength="9" placeholder="00000-000" class="{{ $classeInput }}"></label>
                <p class="{{ $classeAjuda }}">{{ __('ui_449f1487df1f') }}</p>
                @foreach (['razao_social'=>'Razão social (empresa)', 'nome_fantasia'=>'Nome fantasia (empresa)', 'logradouro'=>'Rua / avenida', 'numero'=>'Número', 'complemento'=>'Complemento', 'bairro'=>'Bairro', 'cidade'=>'Cidade', 'uf'=>'UF'] as $campo => $rotulo)
                    <label class="block">{{ $rotulo }}<input wire:model="receita.{{ $campo }}" class="{{ $classeInput }}"></label>
                @endforeach
            </fieldset>
            <div>
                <label class="{{ $classeRotulo }}">{{ __('ui_e596aca45bd9') }}</label>
                <select wire:model="fuso_horario" class="{{ $classeInput }}">
                    @foreach ($this->fusos() as $valor => $rotulo)
                        <option value="{{ $valor }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
                @error('fuso_horario') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @endif
            @if($etapaCadastro===3)
            <div class="border-t border-gray-200 pt-6 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                    {{ __('ui_a83c5afc47e9') }}
                </h3>
                <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('ui_1be41ed486eb') }}
                </p>

                <div class="space-y-4">
                    <div>
                        <label class="{{ $classeRotulo }}">{{ __('ui_13030dd962f4') }}</label>
                        <input type="text" wire:model="responsavel_nome" class="{{ $classeInput }}">
                        @error('responsavel_nome') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="{{ $classeRotulo }}">{{ __('ui_0d01e2e86669') }}</label>
                        <input type="email" wire:model="responsavel_email" class="{{ $classeInput }}">
                        <p class="{{ $classeAjuda }}">{{ __('ui_4969e5003057') }}</p>
                        @error('responsavel_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            @endif
            <div class="flex items-center gap-3">
                @if($etapaCadastro>1)<x-filament::button color="gray" wire:click="voltarCadastro">{{ __('ui_6da1db5a662b') }}</x-filament::button>@endif
                @if($etapaCadastro<3)<x-filament::button wire:click="avancarCadastro">{{ __('ui_b8cb6f8bec0b') }}</x-filament::button>@endif
            </div>
            @if($etapaCadastro===3)
            <div class="pt-2">
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="criar">
                    <span wire:loading.remove wire:target="criar">{{ __('ui_f61b9fb8e26b') }}</span>
                    <span wire:loading wire:target="criar">{{ __('ui_0c8d469fbde6') }}</span>
                </x-filament::button>
            </div>
            @endif
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('ui_e84b64f4b7a2') }}</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="py-2 pr-3">{{ __('ui_c5c7d5ee3d61') }}</th>
                        <th class="py-2 pr-3">{{ __('ui_5b8520c93e44') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('ui_5891b7e007e7') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('ui_5f86be3deae7') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('ui_071c899b7393') }}</th>
                        <th class="py-2 pr-3">{{ __('ui_966c7c4cad9a') }}</th>
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
                                        {{ __('ui_9d619bc02ea2') }}
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
                                @if(auth()->user()?->super_admin)
                                    <x-filament::button size="xs" wire:click="abrirConta({{ $c['id'] }})">{{ __('ui_bea19da033a2') }}</x-filament::button>
                                @endif
                                <x-filament::button type="button" size="xs" color="gray"
                                                    wire:click="reenviar({{ $c['id'] }})"
                                                    wire:loading.attr="disabled">
                                    {{ __('ui_2fb81f909726') }}
                                </x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
