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
        // Mesma origem do OnChat (e para ca que o redirect_uri aponta), entao da para
        // mandar a aba PRINCIPAL para o canal recem-criado e fechar este popup sozinho —
        // sem isso, a pessoa ficaria olhando o resultado numa janela pequena que ela
        // precisaria fechar na mao para continuar.
        if (window.opener && !window.opener.closed) {
            window.opener.location.href = @json($destino);
            window.close();
        } else {
            window.location.href = @json($destino);
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
