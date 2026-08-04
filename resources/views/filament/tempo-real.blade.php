{{-- O JS precisa saber de qual conta assinar o canal. Meta e nao variavel global:
     e o jeito que sobrevive a wire:navigate sem depender de ordem de script. --}}
@auth
    <meta name="onchat-tenant" content="{{ auth()->user()->tenant_id }}">
    <meta name="onchat-usuario" content="{{ auth()->id() }}">
@endauth

@vite('resources/js/app.js')
