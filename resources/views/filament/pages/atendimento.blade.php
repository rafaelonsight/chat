<x-filament-panels::page>
    <div class="flex h-[calc(100vh-14rem)] min-h-[28rem] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="flex w-80 shrink-0 flex-col border-r border-gray-200 dark:border-white/10">
            <livewire:inbox.new-conversation />
            <livewire:inbox.conversation-list />
        </div>

        <div class="flex flex-1 flex-col overflow-hidden">
            <livewire:inbox.conversation-window />
            <livewire:inbox.message-composer />
        </div>
    </div>
</x-filament-panels::page>
