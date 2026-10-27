@php
    $usuario = auth()->user();
    $tenantId = \App\Support\TenantContext::get();
    $modoSuporte = $usuario?->operador && $usuario->super_admin && $tenantId && $tenantId !== $usuario->tenant_id;
    $conta = $modoSuporte ? \App\Models\Tenant::find($tenantId) : null;
@endphp

@if ($conta)
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-400/40 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:bg-amber-400/10 dark:text-amber-100">
        <span><strong>{{ __('ui_8c2d43a5b183') }}</strong> você está atuando na conta {{ $conta->nome }}.</span>
        <form method="POST" action="{{ route('suporte.sair') }}">
            @csrf
            <x-filament::button type="submit" size="sm" color="gray">{{ __('ui_7bb1b9641403') }}</x-filament::button>
        </form>
    </div>
@endif
