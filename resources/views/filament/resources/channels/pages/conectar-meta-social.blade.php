<x-filament-panels::page>
    <div id="fb-root"></div>

    @if ($erroInstagram)
        <div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
            {{ $erroInstagram }}
        </div>
    @endif

    {{-- Messenger: Pagina do Facebook, FB.login() classico. --}}
    @if (! $reconectando || $tipoCanal === \App\Models\Channel::MESSENGER)
        <x-filament::section>
            <x-slot name="heading">{{ $reconectando ? 'Reconectar Messenger' : 'Facebook Messenger' }}</x-slot>
            <x-slot name="description">
                @if ($reconectando)
                    Escolha de novo a Página do Facebook — nome e histórico de conversas
                    deste canal continuam os mesmos.
                @else
                    Você escolhe qual Página do Facebook administra.
                @endif
            </x-slot>

            {{-- Estado 1: so o botao de login. --}}
            <div id="onsight-social-login" class="flex flex-col items-start gap-3">
                <x-filament::button type="button" id="onsight-btn-social" onclick="onsightIniciarLoginSocial()">
                    Continuar com o Facebook
                </x-filament::button>

                <p id="onsight-social-status" class="hidden text-sm text-gray-500"></p>
                <div id="onsight-social-erro" class="hidden w-full rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"></div>
            </div>

            {{-- Estado 2: escolher a Pagina, some ate a lista chegar. --}}
            <div id="onsight-social-paginas" class="hidden flex flex-col gap-3"></div>
        </x-filament::section>
    @endif

    {{-- Instagram: login direto no instagram.com, sem Pagina no meio. Redirect
         comum, nao JS — o link ja sai pronto do servidor, com o 'state' que o
         callback vai conferir. --}}
    @if (! $reconectando || $tipoCanal === \App\Models\Channel::INSTAGRAM)
        <x-filament::section>
            <x-slot name="heading">{{ $reconectando ? 'Reconectar Instagram' : 'Instagram' }}</x-slot>
            <x-slot name="description">
                @if ($reconectando)
                    Faça login de novo com a mesma conta do Instagram — nome e histórico
                    de conversas deste canal continuam os mesmos.
                @else
                    Faça login direto com a conta profissional do Instagram — sem precisar
                    de Página do Facebook.
                @endif
            </x-slot>

            <x-filament::button tag="a" href="{{ $urlInstagram }}">
                Continuar com o Instagram
            </x-filament::button>
        </x-filament::section>
    @endif

    <script>
        (function () {
            const appId = @json(config('services.meta.app_id'));
            const versao = @json(config('services.meta.versao'));
            const urlListarPaginas = @json(route('canais.meta.social.listar-paginas'));
            const urlConectar = @json(route('canais.meta.social.conectar'));
            const csrfToken = @json(csrf_token());
            // Constante DESTA aba — mesmo motivo do Cadastro Incorporado do WhatsApp:
            // sessao e compartilhada entre abas e misturaria duas reconexoes concorrentes.
            const canalId = @json($canalId);

            window.fbAsyncInit = function () {
                FB.init({ appId: appId, xfbml: false, version: versao });
            };

            (function (d, s, id) {
                if (d.getElementById(id)) { return; }
                var js, ref = d.getElementsByTagName(s)[0];
                js = d.createElement(s);
                js.id = id;
                js.src = 'https://connect.facebook.net/pt_BR/sdk.js';
                ref.parentNode.insertBefore(js, ref);
            }(document, 'script', 'facebook-jssdk'));

            window.onsightIniciarLoginSocial = function () {
                if (typeof FB === 'undefined') {
                    mostrarErro('O SDK do Facebook ainda não carregou. Espere um instante e tente de novo.');
                    return;
                }

                mostrarStatus('Abrindo o login do Facebook…');

                FB.login(function (resposta) {
                    if (!resposta || resposta.status !== 'connected' || !resposta.authResponse) {
                        mostrarErro('O login não foi concluído. Tente de novo.');
                        return;
                    }

                    listarPaginas(resposta.authResponse.accessToken);
                }, {
                    scope: 'pages_show_list,pages_manage_metadata,pages_messaging,business_management',
                    return_scopes: true,
                });
            };

            function listarPaginas(token) {
                mostrarStatus('Buscando suas Páginas…');

                fetch(urlListarPaginas, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ token: token }),
                })
                    .then(function (r) { return r.json().then(function (body) { return { status: r.status, body: body }; }); })
                    .then(function (resultado) {
                        if (resultado.status === 200 && resultado.body.ok) {
                            mostrarPaginas(resultado.body.uuid, resultado.body.paginas);
                        } else {
                            mostrarErro(resultado.body.erro || 'Não foi possível listar suas Páginas.');
                        }
                    })
                    .catch(function () {
                        mostrarErro('Falha de rede ao falar com o servidor. Tente de novo.');
                    });
            }

            function mostrarPaginas(uuid, paginas) {
                document.getElementById('onsight-social-login').classList.add('hidden');

                const lista = document.getElementById('onsight-social-paginas');
                lista.innerHTML = '';
                lista.classList.remove('hidden');

                const titulo = document.createElement('p');
                titulo.className = 'text-sm text-gray-700 dark:text-gray-300';
                titulo.textContent = 'Qual Página conectar?';
                lista.appendChild(titulo);

                paginas.forEach(function (pagina) {
                    const linha = document.createElement('div');
                    linha.className = 'flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10';

                    const info = document.createElement('p');
                    info.className = 'text-sm font-medium';
                    info.textContent = pagina.nome;

                    const botao = document.createElement('button');
                    botao.type = 'button';
                    botao.className = 'fi-btn fi-btn-size-sm inline-flex items-center justify-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500';
                    botao.textContent = 'Conectar';
                    botao.onclick = function () { conectar(uuid, pagina.id); };

                    linha.appendChild(info);
                    linha.appendChild(botao);
                    lista.appendChild(linha);
                });
            }

            function conectar(uuid, pageId) {
                mostrarStatus('Conectando…');

                fetch(urlConectar, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ uuid: uuid, page_id: pageId, canal_id: canalId }),
                })
                    .then(function (r) { return r.json().then(function (body) { return { status: r.status, body: body }; }); })
                    .then(function (resultado) {
                        if (resultado.status === 200 && resultado.body.ok) {
                            window.location.href = resultado.body.redirect;
                        } else {
                            mostrarErro(resultado.body.erro || 'Não foi possível conectar. Tente de novo.');
                        }
                    })
                    .catch(function () {
                        mostrarErro('Falha de rede ao falar com o servidor. Tente de novo.');
                    });
            }

            function mostrarStatus(mensagem) {
                const el = document.getElementById('onsight-social-status');
                if (!el) { return; }
                el.textContent = mensagem;
                el.classList.remove('hidden');
                document.getElementById('onsight-social-erro').classList.add('hidden');
            }

            function mostrarErro(mensagem) {
                const status = document.getElementById('onsight-social-status');
                if (!status) { return; }
                status.classList.add('hidden');
                const el = document.getElementById('onsight-social-erro');
                el.textContent = mensagem;
                el.classList.remove('hidden');
            }
        })();
    </script>
</x-filament-panels::page>
