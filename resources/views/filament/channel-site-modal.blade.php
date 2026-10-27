<div class="space-y-4 py-2">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('ui_83705f131659') }}
        <code>&lt;/body&gt;</code>{{ __('ui_5412f2987982') }}
    </p>

    <div
        x-data="{ copiado: false }"
        class="relative rounded-lg border border-gray-200 bg-gray-50 p-3 pr-12 dark:border-white/10 dark:bg-white/5"
    >
        <code
            id="trecho-do-site-{{ $channel->id }}"
            class="block overflow-x-auto whitespace-pre text-xs text-gray-700 dark:text-gray-300"
        >{{ $channel->trechoDoSite() }}</code>

        <button
            type="button"
            x-on:click="
                navigator.clipboard.writeText(document.getElementById('trecho-do-site-{{ $channel->id }}').textContent);
                copiado = true;
                setTimeout(() => copiado = false, 2000);
            "
            class="absolute right-2 top-2 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
        >
            <span x-show="! copiado">{{ __('ui_885410773340') }}</span>
            <span x-show="copiado" x-cloak>{{ __('ui_094a8b9530ed') }}</span>
        </button>
    </div>

    <p class="text-xs text-gray-400 dark:text-gray-500">
        {{ __('ui_28b4fbc3cf56') }} <code>{{ $channel->site_key }}</code> {{ __('ui_024f49b309ce') }}
    </p>
</div>
