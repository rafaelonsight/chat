<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('ui_1c3584407999') }}</title>
</head>
<body style="font-family: ui-sans-serif, system-ui, sans-serif; padding: 24px; color: #1f2937;">
@if ($sucesso)
    <p>{{ __('ui_fbbbea686147') }}</p>
    <p><a href="{{ \App\Filament\Resources\Channels\ChannelResource::getUrl('conectar-meta') }}">{{ __('ui_9507a8a98f7d') }}</a></p>
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
            document.body.textContent = "Autorização recebida. Volte ao Virtus para concluir a conexão.";
        }
    </script>
@else
    <p style="color:#991b1b;">Não foi possível conectar: {{ $mensagem }}</p>
    <p>
        <button type="button" onclick="window.close()">{{ __('ui_9fea0b7815e1') }}</button>
        {{ __('ui_d583b3a61a14') }}
    </p>
@endif
</body>
</html>
