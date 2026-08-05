<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Sessão bloqueada — OnChat</title>

    {{-- Estilo inline de proposito: esta tela precisa funcionar mesmo se o build do
         CSS estiver quebrado. Tela de bloqueio que nao renderiza deixa a pessoa
         trancada fora do proprio atendimento. --}}
    <style>
        *, *::before, *::after { box-sizing: border-box }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            background: #0f172a; color: #e2e8f0;
            font: 400 15px/1.5 system-ui, -apple-system, Segoe UI, sans-serif;
            padding: 1.5rem;
        }
        .cartao {
            width: 100%; max-width: 22rem; background: #1e293b;
            border: 1px solid rgba(255,255,255,.08); border-radius: 1rem;
            padding: 1.75rem; box-shadow: 0 20px 50px rgba(0,0,0,.4);
        }
        .marca { font-weight: 700; font-size: 1.1rem; letter-spacing: -.01em; margin: 0 0 1.25rem }
        .avatar {
            width: 3rem; height: 3rem; border-radius: 999px; background: #334155;
            display: grid; place-items: center; font-weight: 600; margin: 0 auto .75rem;
        }
        .nome { text-align: center; font-weight: 600; margin: 0 }
        .aviso { text-align: center; color: #94a3b8; font-size: .8rem; margin: .35rem 0 1.25rem }
        label { display: block; font-size: .8rem; color: #cbd5e1; margin-bottom: .35rem }
        input {
            width: 100%; padding: .6rem .75rem; border-radius: .5rem;
            border: 1px solid rgba(255,255,255,.15); background: #0f172a; color: #f1f5f9;
            font-size: .95rem;
        }
        input:focus { outline: 2px solid #10b981; outline-offset: 1px }
        button {
            width: 100%; margin-top: .85rem; padding: .6rem; border: 0; cursor: pointer;
            border-radius: .5rem; background: #059669; color: #fff; font-weight: 600; font-size: .9rem;
        }
        button:hover { background: #047857 }
        .erro { color: #fca5a5; font-size: .8rem; margin-top: .5rem }
        .sair {
            display: block; text-align: center; margin-top: 1.1rem;
            color: #94a3b8; font-size: .8rem; background: none; border: 0; cursor: pointer;
            text-decoration: underline; width: 100%;
        }
    </style>
</head>
<body>
    <div class="cartao">
        <p class="marca">OnChat</p>

        <div class="avatar">{{ $iniciais }}</div>
        <p class="nome">{{ $nome }}</p>
        <p class="aviso">Sessão bloqueada. Digite sua senha para voltar.</p>

        <form method="POST" action="{{ route('sessao.destravar') }}">
            @csrf
            <label for="senha">Senha</label>
            {{-- autofocus e autocomplete current-password: o gerenciador de senhas
                 preenche, e destravar volta a ser um gesto de dois segundos. --}}
            <input type="password" name="senha" id="senha" autofocus required
                   autocomplete="current-password">

            @error('senha') <p class="erro">{{ $message }}</p> @enderror

            <button type="submit">Destravar</button>
        </form>

        {{-- Sair de verdade continua possivel: bloqueio nao pode virar armadilha. --}}
        <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
            @csrf
            <button type="submit" class="sair">Sair da conta</button>
        </form>
    </div>

    <script>
        // Avisa as outras abas desta maquina: quem estiver com o atendimento aberto
        // recebe a cortina na hora, sem esperar recarregar. Sem isto, "bloquear" so
        // valeria para a proxima navegacao e a tela ao lado seguiria a mostrar tudo.
        try { localStorage.setItem('onchat.bloqueada', '1'); } catch (e) {}
    </script>
</body>
</html>
