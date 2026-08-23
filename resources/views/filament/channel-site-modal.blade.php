<div class="space-y-4 py-2">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Cole esta linha no HTML do site, de preferência perto do fim, antes do
        <code>&lt;/body&gt;</code>. O balão aparece sozinho, em qualquer página onde o
        script estiver presente.
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
            <span x-show="! copiado">Copiar</span>
            <span x-show="copiado" x-cloak>Copiado!</span>
        </button>
    </div>

    <p class="text-xs text-gray-400 dark:text-gray-500">
        Chave deste canal: <code>{{ $channel->site_key }}</code> — não compartilhe fora do
        código do site; é ela que identifica a sua conta para quem conversa pelo balão.
    </p>
</div>
