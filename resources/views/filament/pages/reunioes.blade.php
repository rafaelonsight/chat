<x-filament-panels::page>
    <div x-data x-on:abrir-sala.window="window.open($event.detail.url, '_blank', 'noopener')">

        @if (! $disponivel)
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/20">
                <p class="text-base font-medium text-gray-700 dark:text-gray-200">{{ __('ui_05444ea123c4') }}</p>
                <p class="mx-auto mt-2 max-w-lg text-sm text-gray-500 dark:text-gray-400">
                    {{ __('ui_5b61ec7e32aa') }}
                </p>
            </div>
        @else
            @if ($recado)
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    {{ $recado }}
                </div>
            @endif

            {{-- --------------------------------------------------------- abrir --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('ui_c62e6ee23092') }}</x-slot>

                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_d3f0909a2ab6') }}</span>
                            <input type="text" wire:model="titulo" maxlength="120"
                                   placeholder="{{ __('ui_5dfc31c8e038') }}"
                                   class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                        </label>

                        {{-- Com contato, o link sai pelo WhatsApp dele. Sem contato, e um link
                             para mandar a mão. --}}
                        <div>
                            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('ui_a47914ee40f8') }}</span>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <input type="text" wire:model.live.debounce.300ms="buscaContato"
                                       placeholder="{{ __('ui_ed869fb33b44') }}"
                                       class="min-w-48 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/20 dark:bg-gray-800">
                                @if ($contact_id)
                                    <x-filament::button size="xs" color="gray" wire:click="tirarContato">{{ __('ui_f409bdf3a555') }}</x-filament::button>
                                @endif
                            </div>

                            @if ($candidatos->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($candidatos as $c)
                                        <button type="button" wire:key="ct-{{ $c->id }}"
                                                wire:click="escolherContato({{ $c->id }})"
                                                class="rounded-full border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-100 dark:border-white/20 dark:hover:bg-white/5">
                                            {{ $c->nomeExibicao() }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-filament::button wire:click="abrir" icon="heroicon-o-video-camera">
                            {{ __('ui_5a076c72ec61') }}
                        </x-filament::button>

                        <span class="text-xs text-gray-400">
                            @if ($contact_id)
                                O link vai pela conversa dele no WhatsApp.
                            @else
                                Abre numa aba nova. Copie o link para quem precisa entrar.
                            @endif
                        </span>
                    </div>
                </div>
            </x-filament::section>

            {{-- ------------------------------------------------------- abertas --}}
            @if ($abertas->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">
                        {{ __('ui_7647f38c35fa') }} <span class="text-xs opacity-60">({{ $abertas->count() }})</span>
                    </x-slot>

                    <div class="space-y-2">
                        @foreach ($abertas as $r)
                            <div wire:key="ab-{{ $r->id }}"
                                 class="flex flex-wrap items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-500/30 dark:bg-emerald-500/10">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ $r->titulo ?: 'Reunião' }}
                                    </p>

                                    <p class="mt-0.5 text-xs text-gray-500">
                                        começou {{ $r->comecou_em->format('d/m H:i') }}
                                        @if ($r->criador) · {{ $r->criador->name }} @endif
                                        @if ($r->contact) · {{ $r->contact->nomeExibicao() }} @endif
                                        · {{ $r->participants_count }}
                                        {{ $r->participants_count === 1 ? 'entrada' : 'entradas' }}
                                    </p>

                                    <div class="mt-2 flex items-center gap-1">
                                        <a href="{{ $r->url() }}" target="_blank" rel="noopener"
                                           class="truncate text-xs text-primary-600 underline dark:text-primary-400">{{ $r->url() }}</a>
                                        <x-inbox.copiar :valor="$r->url()" titulo="Copiar o link" />
                                    </div>
                                </div>

                                <div class="flex shrink-0 gap-1">
                                    <x-filament::button size="xs" tag="a" href="{{ $r->url() }}" target="_blank">
                                        {{ __('ui_7573ba85155c') }}
                                    </x-filament::button>
                                    <x-filament::button size="xs" color="danger" wire:click="encerrar({{ $r->id }})"
                                        wire:confirm="Encerrar para todos? Quem estiver dentro sai na hora e o link para de abrir.">
                                        {{ __('ui_8041ee4c59fa') }}
                                    </x-filament::button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            {{-- ------------------------------------------------------ passadas --}}
            @if ($passadas->isNotEmpty())
                <x-filament::section collapsible collapsed>
                    <x-slot name="heading">{{ __('ui_8bd3b20f6a25') }}</x-slot>

                    <div class="space-y-1">
                        @foreach ($passadas as $r)
                            <div wire:key="pa-{{ $r->id }}"
                                 class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-100 px-3 py-2 text-xs dark:border-white/5">
                                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $r->titulo ?: 'Reunião' }}</span>
                                <span class="text-gray-400">{{ $r->comecou_em->format('d/m H:i') }}</span>
                                @if ($r->contact)
                                    <span class="text-gray-400">· {{ $r->contact->nomeExibicao() }}</span>
                                @endif
                                <span class="text-gray-400">
                                    · {{ $r->participants_count }} {{ $r->participants_count === 1 ? 'entrada' : 'entradas' }}
                                </span>
                                @unless ($r->aberta())
                                    <span class="text-gray-400">{{ __('ui_723493065c04') }}</span>
                                @else
                                    {{-- Vencida sem ninguem encerrar: o link ja nao abre, e
                                         dizer "aberta" aqui seria mentira. --}}
                                    <span class="text-gray-400">{{ __('ui_a930498c0589') }}</span>
                                @endunless
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            @if ($abertas->isEmpty() && $passadas->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('ui_2062fba6bd8b') }}
                </p>
            @endif
        @endif
    </div>
</x-filament-panels::page>
