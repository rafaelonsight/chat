<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $titulo ?? 'Reunião' }} — {{ config('app.name') }}</title>

    {{-- sala.js e nao app.js: o cliente de video pesa, e o app.js traz o tempo real do
         atendimento, que nao tem uso nenhum aqui dentro. --}}
    @vite(['resources/css/app.css', 'resources/js/sala.js'])
    @livewireStyles
</head>
{{-- Escuro e o padrao de sala de video, e nao escolha de estilo: fundo claro atras de uma
     janela de camera cansa a vista e come o contraste da imagem de quem esta falando. --}}
<body class="h-full bg-gray-950 text-gray-100 antialiased">
    {{ $slot }}

    @livewireScripts
</body>
</html>
