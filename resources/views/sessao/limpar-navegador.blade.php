<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>Limpando dados locais — {{ config('app.name') }}</title>
    <style>
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            font: 400 15px/1.5 system-ui, sans-serif; color: #334155; background: #f8fafc;
        }
    </style>
</head>
<body>
    <p>Limpando os dados deste navegador…</p>

    <script>
        // O que isto limpa: preferencias guardadas NESTE navegador — tema, barra
        // recolhida, som do alerta. Nao toca em nada do servidor, e nao apaga
        // conversa, contato nem mensagem: aquilo vive no banco.
        //
        // Serve para o caso "a tela esta esquisita": estado local estranho e uma das
        // poucas coisas que um recarregar comum nao resolve.
        try {
            const guardar = @json($manterSessao);

            // A sessao NAO e limpa: apagar o cookie derrubaria a pessoa do painel, e
            // ela clicou em "limpar dados", nao em "sair".
            for (const chave of Object.keys(localStorage)) {
                if (! guardar.includes(chave)) localStorage.removeItem(chave);
            }

            sessionStorage.clear();
        } catch (e) {}

        // Recarrega o painel do zero, sem cache de memoria do navegador.
        location.replace(@json($voltarPara));
    </script>

    <noscript>
        <p>Este navegador está com JavaScript desligado, então não há dados locais para limpar.
            <a href="{{ $voltarPara }}">Voltar ao painel</a>.</p>
    </noscript>
</body>
</html>
