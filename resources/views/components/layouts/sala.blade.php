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

    {{--
        A subida da reacao.

        Escrita aqui e nao no Tailwind porque e uma animacao de UMA tela: por na configuracao
        do tema faria toda pagina do sistema carregar a definicao dela, e ninguem mais usa.

        Ela sobe e desbota ao mesmo tempo. Reacao que so desbota parece falha de renderizacao;
        reacao que so sobe some de repente na borda. As duas juntas leem como "passou".
    --}}
    <style>
        @keyframes reacaoSobe {
            0%   { opacity: 0; transform: translateY(28px) scale(.6); }
            15%  { opacity: 1; transform: translateY(0) scale(1.1); }
            30%  { transform: translateY(-6px) scale(1); }
            100% { opacity: 0; transform: translateY(-120px) scale(.9); }
        }

        .reacao-sobe {
            animation: reacaoSobe 2.6s ease-out forwards;
        }

        /* Quem pediu para o sistema nao animar nada tem motivo — enjoo, vertigem. A reacao
           ainda aparece e ainda some; ela so nao voa pela tela. */
        @media (prefers-reduced-motion: reduce) {
            .reacao-sobe { animation: reacaoSobe 2.6s steps(2, end) forwards; }
        }
    </style>
</head>
{{-- Escuro e o padrao de sala de video, e nao escolha de estilo: fundo claro atras de uma
     janela de camera cansa a vista e come o contraste da imagem de quem esta falando. --}}
<body class="h-full bg-gray-950 text-gray-100 antialiased">
    {{ $slot }}

    @livewireScripts
</body>
</html>
