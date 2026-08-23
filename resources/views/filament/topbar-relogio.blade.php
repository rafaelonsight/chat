@if (auth()->check())
    {{--
        Relogio ao vivo + nome da conta, sempre visiveis — sem precisar abrir o
        menu do usuario para saber de qual conta e a sessao aberta. Alpine (ja
        embutido no Filament) cuida do tique a tique; sem isso a hora ficaria
        parada no momento em que a pagina carregou.
    --}}
    <div
        x-data="{ agora: new Date() }"
        x-init="setInterval(() => agora = new Date(), 1000)"
        class="hidden items-center gap-3 px-2 sm:flex"
    >
        <div class="flex flex-col items-end leading-tight">
            <span
                class="tabular-nums font-mono text-sm font-semibold text-gray-900 dark:text-white"
                x-text="agora.toLocaleTimeString('pt-BR')"
            ></span>
            <span
                class="text-xs capitalize text-gray-500 dark:text-gray-400"
                x-text="agora.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' })"
            ></span>
        </div>

        @if ($tenant = auth()->user()?->tenant)
            <span class="h-8 w-px bg-gray-200 dark:bg-white/10"></span>

            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ $tenant->nome }}
            </span>
        @endif
    </div>
@endif
