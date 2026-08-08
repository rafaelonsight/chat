<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Fora do indice: e a agenda de um cliente, e nao conteudo para buscador nenhum. --}}
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $titulo ?? 'Agendar' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-gray-100 text-gray-900 antialiased">
    {{ $slot }}

    @livewireScripts
</body>
</html>
