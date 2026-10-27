@if (auth()->user()?->operador && auth()->user()?->super_admin)
    <div class="flex items-center gap-2">
        <form method="POST" action="{{ route('suporte.selecionar') }}" class="flex items-center gap-2">
            @csrf
            <label for="conta-atual" class="text-xs text-gray-500">{{ __('ui_7caa0c002b78') }}</label>
            <select id="conta-atual" name="tenant_id" onchange="this.form.requestSubmit()" class="max-w-48 rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-sm dark:bg-gray-900" aria-label="{{ __('ui_7caa0c002b78') }}">
                <option value="">{{ __('ui_9a42b110f16e') }}</option>
                @foreach (\App\Models\Tenant::orderBy('nome')->get() as $conta)
                    <option value="{{ $conta->id }}" @selected(\App\Support\TenantContext::get()===$conta->id)>{{ $conta->nome }}</option>
                @endforeach
            </select>
        </form>
        <a href="/gestao/clientes" class="text-xs font-medium text-primary-600">{{ __('ui_92400d35255d') }}</a>
    </div>
@endif
@if (auth()->check())
    @php
        $tenantIdExibido = \App\Support\TenantContext::get() ?: auth()->user()->tenant_id;
        $tenantExibido = \App\Models\Tenant::find($tenantIdExibido);
    @endphp
    <div
        x-data="{ agora: new Date() }"
        x-init="setInterval(() => agora = new Date(), 1000)"
        class="hidden items-center gap-3 px-2 sm:flex"
    >
        <div class="flex flex-col items-end leading-tight">
            <span class="tabular-nums font-mono text-sm font-semibold text-gray-900 dark:text-white" x-text="agora.toLocaleTimeString('{{ str_replace('_', '-', app()->getLocale()) }}', { timeZone: '{{ $tenantExibido?->fuso_horario ?: 'America/Sao_Paulo' }}' })"></span>
            <span class="text-xs capitalize text-gray-500 dark:text-gray-400" x-text="agora.toLocaleDateString('{{ str_replace('_', '-', app()->getLocale()) }}', { weekday: 'long', day: '2-digit', month: 'long', timeZone: '{{ $tenantExibido?->fuso_horario ?: 'America/Sao_Paulo' }}' })"></span>
        </div>

        @if ($tenantExibido)
            <span class="h-8 w-px bg-gray-200 dark:bg-white/10"></span>
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $tenantExibido->nome }}</span>
        @endif
    </div>
@endif
