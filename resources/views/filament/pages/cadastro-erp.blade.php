<x-filament-panels::page>
    {{--
        Dois atalhos, e nao um texto dizendo "use o menu".

        Quem clica em Cadastro esta a caminho de outro lugar. A pagina que so explica onde clicar
        e um degrau a mais entre a pessoa e o que ela veio fazer.
    --}}
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ([
            [
                'titulo' => 'Produtos e serviços',
                'texto'  => 'O que você vende, com preço num lugar só. A proposta escolhe daqui em vez de digitar de novo.',
                'url'    => \App\Filament\Resources\Offerings\OfferingResource::getUrl(),
                'icone'  => 'heroicon-o-cube',
            ],
            [
                'titulo' => 'Pessoas',
                'texto'  => 'Clientes e contatos. É a mesma ficha que aparece no atendimento, com o histórico das conversas.',
                'url'    => \App\Filament\Resources\Contacts\ContactResource::getUrl(),
                'icone'  => 'heroicon-o-users',
            ],
        ] as $atalho)
            <a href="{{ $atalho['url'] }}"
               class="group flex gap-4 rounded-xl border border-gray-200 bg-white p-5 transition hover:border-amber-400 hover:shadow-sm dark:border-white/10 dark:bg-gray-900 dark:hover:border-amber-500/60">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <x-filament::icon :icon="$atalho['icone']" class="h-6 w-6" />
                </span>

                <span class="min-w-0">
                    <span class="block font-semibold text-gray-900 group-hover:text-amber-700 dark:text-gray-100 dark:group-hover:text-amber-300">
                        {{ $atalho['titulo'] }}
                    </span>
                    <span class="mt-1 block text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ $atalho['texto'] }}
                    </span>
                </span>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
