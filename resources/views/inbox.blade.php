<x-layouts.inbox>
    <div class="flex h-full flex-col bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-2">
            <span class="font-semibold text-slate-800">OnChat</span>
            <nav class="flex items-center gap-4 text-sm">
                <a href="/admin" class="text-slate-600 hover:text-slate-900">Painel</a>
                <a href="/admin/channels" class="text-slate-600 hover:text-slate-900">Canais</a>
            </nav>
        </header>

        <div class="flex flex-1 overflow-hidden">
            <div class="flex w-80 shrink-0 flex-col border-r border-slate-200">
                <livewire:inbox.new-conversation />
                <livewire:inbox.conversation-list />
            </div>
            <div class="flex flex-1 flex-col overflow-hidden">
                <livewire:inbox.conversation-window />
                <livewire:inbox.message-composer />
            </div>
        </div>
    </div>
</x-layouts.inbox>
