{{-- Rastro interno. NUNCA vai para o cliente. --}}
@if ($ev->ehNota())
    <div class="flex justify-center">
        <div class="vc-note max-w-lg rounded-lg border px-3 py-2 text-sm">
            <div class="mb-0.5 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                <span>&#128274;</span> nota interna
            </div>
            <div class="whitespace-pre-wrap">{{ $ev->descricao }}</div>
            <div class="mt-1 text-[10px] text-amber-700/80 dark:text-amber-300/70">
                {{ $ev->created_at?->format('d/m H:i') }}
                @if ($ev->user) &middot; {{ $ev->user->name }} @endif
            </div>
        </div>
    </div>
@else
    <div class="flex justify-center">
        <p class="text-[11px] text-slate-500 dark:text-gray-400">
            {{ $ev->created_at?->format('d/m H:i') }} &middot; {{ $ev->descricao }}
            @if ($ev->user) &middot; {{ $ev->user->name }} @endif
        </p>
    </div>
@endif
