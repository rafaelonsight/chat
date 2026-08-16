<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Acesso indisponível — {{ config('app.name') }}</title>

    {{-- Estilo inline de proposito, igual a tela de sessao bloqueada: precisa
         funcionar mesmo se o build do CSS estiver quebrado. --}}
    <style>
        *, *::before, *::after { box-sizing: border-box }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            background: #0f172a; color: #e2e8f0;
            font: 400 15px/1.5 system-ui, -apple-system, Segoe UI, sans-serif;
            padding: 1.5rem;
        }
        .cartao {
            width: 100%; max-width: 24rem; background: #1e293b;
            border: 1px solid rgba(255,255,255,.08); border-radius: 1rem;
            padding: 1.75rem; box-shadow: 0 20px 50px rgba(0,0,0,.4);
        }
        .marca { font-weight: 700; font-size: 1.1rem; letter-spacing: -.01em; margin: 0 0 1.25rem }
        .status {
            display: inline-block; padding: .2rem .6rem; border-radius: 999px;
            background: #7f1d1d; color: #fecaca; font-size: .75rem; font-weight: 600;
            margin-bottom: .85rem;
        }
        .titulo { font-weight: 600; font-size: 1.05rem; margin: 0 0 .5rem }
        .texto { color: #94a3b8; font-size: .85rem; margin: 0 0 .35rem }
        .motivo {
            margin-top: .85rem; padding: .65rem .75rem; border-radius: .5rem;
            background: #0f172a; border: 1px solid rgba(255,255,255,.08);
            color: #cbd5e1; font-size: .8rem;
        }
        .sair {
            display: block; text-align: center; margin-top: 1.25rem;
            color: #94a3b8; font-size: .8rem; background: none; border: 0; cursor: pointer;
            text-decoration: underline; width: 100%;
        }
    </style>
</head>
<body>
    <div class="cartao">
        <p class="marca">{{ config('app.name') }}</p>

        <span class="status">{{ $status }}</span>
        <p class="titulo">Este acesso não está disponível agora</p>
        <p class="texto">Fale com quem administra sua conta para regularizar a situação.</p>

        @if ($motivo)
            <div class="motivo">{{ $motivo }}</div>
        @endif

        <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
            @csrf
            <button type="submit" class="sair">Sair da conta</button>
        </form>
    </div>
</body>
</html>
