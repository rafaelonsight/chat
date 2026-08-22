<x-filament-panels::page>
    {{-- Mesmo aviso do formulario manual (ChannelForm::aviso_oficial), mesma redacao de
         proposito: o risco e o mesmo em qualquer um dos dois caminhos. --}}
    <div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
        <p class="font-semibold">Atenção: isto não tem volta fácil.</p>
        <p class="mt-1">
            Depois de vincular um número à API oficial da Meta, ele
            <strong>deixa de funcionar no aplicativo do WhatsApp</strong> — no celular, para
            sempre. Use um número que a empresa não usa no dia a dia.
        </p>
    </div>

    <x-filament::section>
        <x-slot name="heading">{{ $reconectando ? 'Reconectar pelo Facebook' : 'Conectar pelo Facebook' }}</x-slot>
        <x-slot name="description">
            @if ($reconectando)
                Abre o cadastro oficial da Meta numa janela para renovar a credencial deste
                canal — nome, número e histórico de conversas continuam os mesmos.
            @else
                Abre o cadastro oficial da Meta numa janela: você escolhe ou cria a conta do
                WhatsApp Business ali dentro, e o número, a conta e a credencial voltam prontos —
                sem copiar nada do painel da Meta.
            @endif
        </x-slot>

        <div class="flex flex-col items-start gap-3">
            <x-filament::button type="button" id="onsight-btn-meta" onclick="onsightIniciarCadastroIncorporado()">
                Continuar com o Facebook
            </x-filament::button>

            <p id="onsight-status-meta" class="hidden text-sm text-gray-500"></p>

            <div id="onsight-erro-meta" class="hidden w-full rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"></div>
        </div>
    </x-filament::section>

    @unless ($reconectando)
        <p class="text-xs text-gray-500">
            Prefere preencher os dados você mesmo (Phone Number ID, WABA ID e token)?
            <a href="{{ \App\Filament\Resources\Channels\ChannelResource::getUrl('create') }}?tipo={{ \App\Models\Channel::META_CLOUD }}"
               class="text-primary-600 underline hover:no-underline dark:text-primary-400">
                Preencher manualmente
            </a>
        </p>
    @endunless

    <script>
        (function () {
            const appId = @json(config('services.meta.app_id'));
            const configId = @json(config('services.meta.config_id'));
            const redirectUri = @json(route('canais.meta.callback'));
            const urlConectar = @json(route('canais.meta.conectar-incorporado'));
            const csrfToken = @json(csrf_token());
            // Constante DESTA aba, carregada uma vez — de proposito, nao vive em
            // sessao. Ver o comentario em ConectarMetaIncorporado::class sobre por
            // que sessao (compartilhada entre abas) misturaria duas reconexoes
            // concorrentes.
            const canalId = @json($canalId);

            let popup = null;
            let vigia = null;
            let concluido = false;

            window.addEventListener('message', function (event) {
                if (event.origin !== window.location.origin) {
                    return;
                }
                if (!event.data || event.data.type !== 'onsight-meta-signup-done') {
                    return;
                }

                concluido = true;
                clearInterval(vigia);
                finalizarConexao(event.data.signupToken);
            });

            window.onsightIniciarCadastroIncorporado = function () {
                if (!configId) {
                    mostrarErro('O Cadastro Incorporado ainda não foi configurado no Painel de Apps da Meta (falta o config_id). Use "Preencher manualmente" por enquanto.');
                    return;
                }

                concluido = false;

                // Este e o link "Cadastro incorporado hospedado pela Meta" (Painel de Apps >
                // WhatsApp > Torne-se um Provedor de Tecnologia): NAO e o FB.login() classico.
                // O FB.login() com este config_id ficava preso na tela de "continuar como
                // Fulano" e nunca chegava a mostrar a escolha da conta do WhatsApp — este link
                // hospedado e o mesmo caminho que a propria Meta gera, e mostra o assistente
                // completo.
                const url = new URL('https://business.facebook.com/messaging/whatsapp/onboard/');
                url.searchParams.set('app_id', appId);
                url.searchParams.set('config_id', configId);
                url.searchParams.set('extras', JSON.stringify({
                    version: 'v4',
                    sessionInfoVersion: '3',
                    featureType: 'whatsapp_business_app_onboarding',
                }));
                url.searchParams.set('redirect_uri', redirectUri);

                popup = window.open(url.toString(), 'onsight-cadastro-incorporado', 'width=620,height=780');

                if (!popup) {
                    mostrarErro('O navegador bloqueou a janela. Libere pop-ups para este site e tente de novo.');
                    return;
                }

                mostrarStatus('Complete o cadastro na janela que abriu…');

                // Este vigia so serve para o caso de a pessoa fechar a janela na mao,
                // sem terminar — sem ele o status "Complete o cadastro…" ficaria preso
                // na tela para sempre. Se a mensagem de sucesso ja chegou (concluido),
                // o popup fechar sozinho e o comportamento esperado, nao erro.
                clearInterval(vigia);
                vigia = setInterval(function () {
                    if (popup && popup.closed) {
                        clearInterval(vigia);
                        if (!concluido) {
                            mostrarErro('A janela foi fechada antes de terminar. Clique de novo para tentar.');
                        }
                    }
                }, 500);
            };

            function finalizarConexao(signupToken) {
                mostrarStatus('Conectando o número…');

                fetch(urlConectar, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        signup_token: signupToken,
                        canal_id: canalId,
                    }),
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
                const el = document.getElementById('onsight-status-meta');
                el.textContent = mensagem;
                el.classList.remove('hidden');
                document.getElementById('onsight-erro-meta').classList.add('hidden');
            }

            function mostrarErro(mensagem) {
                document.getElementById('onsight-status-meta').classList.add('hidden');
                const el = document.getElementById('onsight-erro-meta');
                el.textContent = mensagem;
                el.classList.remove('hidden');
            }
        })();
    </script>
</x-filament-panels::page>
