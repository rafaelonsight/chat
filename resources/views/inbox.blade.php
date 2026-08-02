<x-layouts.inbox>
    <div class="flex h-full bg-white">
        <livewire:inbox.conversation-list />
        <div class="flex flex-1 flex-col overflow-hidden">
            <livewire:inbox.conversation-window />
            <livewire:inbox.message-composer />
        </div>
    </div>
</x-layouts.inbox>
