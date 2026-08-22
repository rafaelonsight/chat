<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Conectar WhatsApp oficial</title>
</head>
<body style="font-family: ui-sans-serif, system-ui, sans-serif; padding: 24px; color: #1f2937;">
@if ($sucesso)
    <p>Conectado! Voltando para o canal…</p>
    <script>
        // So o ID do resultado atravessa para a aba principal — o token continua
        // so no servidor, dentro do cache, e nunca passa pelo navegador. E o
        // window.opener (nao a sessao do navegador, compartilhada entre abas) quem
        // sabe para qual canal esta reconexao era.
        if (window.opener && !window.opener.closed) {
            window.opener.postMessage(
                { type: "onsight-meta-signup-done", signupToken: @json($signupToken) },
                window.location.origin
            );
            window.close();
        } else {
            document.body.textContent = "Pode fechar esta janela e voltar para o OnChat.";
        }
    </script>
@else
    <p style="color:#991b1b;">Não foi possível conectar: {{ $mensagem }}</p>
    <p>
        <button type="button" onclick="window.close()">Fechar esta janela</button>
        e tente de novo na tela do OnChat.
    </p>
@endif
</body>
</html>
