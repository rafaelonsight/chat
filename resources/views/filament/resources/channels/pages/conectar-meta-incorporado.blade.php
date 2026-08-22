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
        <x-slot name="heading">Conectar pelo Facebook</x-slot>
        <x-slot name="description">
            Abre o cadastro oficial da Meta: você escolhe ou cria a conta do WhatsApp Business
            ali dentro, e o número, a conta e a credencial voltam prontos — sem copiar nada do
            painel da Meta.
        </x-slot>

        <div class="flex flex-col items-start gap-3">
            <x-filament::button type="button" id="onsight-btn-meta" onclick="onsightIniciarCadastroIncorporado()">
                Continuar com o Facebook
            </x-filament::button>

            <p id="onsight-status-meta" class="hidden text-sm text-gray-500"></p>

            <div id="onsight-erro-meta" class="hidden w-full rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"></div>
        </div>
    </x-filament::section>

    <p class="text-xs text-gray-500">
        Prefere preencher os dados você mesmo (Phone Number ID, WABA ID e token)?
        <a href="{{ \App\Filament\Resources\Channels\ChannelResource::getUrl('create') }}?tipo={{ \App\Models\Channel::META_CLOUD }}"
           class="text-primary-600 underline hover:no-underline dark:text-primary-400">
            Preencher manualmente
        </a>
    </p>

    {{-- SDK do Facebook. async/defer: nao pode atrasar o resto da tela carregando. --}}
    <script>
        window.fbAsyncInit = function () {
            FB.init({
                appId: @json(config('services.meta.app_id')),
                autoLogAppEvents: true,
                xfbml: false,
                version: 'v23.0',
            });
        };
    </script>
    <script async defer crossorigin="anonymous" src="https://connect.facebook.net/pt_BR/sdk.js"></script>

    <script>
        (function () {
            const csrfToken = @json(csrf_token());
            const urlConectar = @json(route('canais.meta.conectar-incorporado'));
            const configId = @json(config('services.meta.config_id'));

            // A v3 do Cadastro Incorporado NAO devolve waba_id/phone_number_id no "code" do
            // FB.login: eles chegam por um evento de sessao, mandado pelo proprio popup via
            // postMessage. Os dois lados (code + evento) tem de se encontrar aqui.
            let sessaoRecebida = null;

            window.addEventListener('message', function (event) {
                if (event.origin !== 'https://www.facebook.com' && event.origin !== 'https://web.facebook.com') {
                    return;
                }

                let dados;
                try {
                    dados = JSON.parse(event.data);
                } catch (e) {
                    return; // mensagem de outra origem que nao e do Cadastro Incorporado
                }

                if (dados.type === 'WA_EMBEDDED_SIGNUP' && dados.event === 'FINALIZE') {
                    sessaoRecebida = dados.data || {};
                }
            });

            window.onsightIniciarCadastroIncorporado = function () {
                if (!configId) {
                    mostrarErro('O Cadastro Incorporado ainda não foi configurado no Painel de Apps da Meta (falta o config_id). Use "Preencher manualmente" por enquanto.');
                    return;
                }

                mostrarStatus('Abrindo o Facebook…');

                FB.login(function (response) {
                    if (!response.authResponse || !response.authResponse.code) {
                        mostrarErro('O Facebook fechou sem concluir a autorização. Tente de novo.');
                        return;
                    }

                    enviarParaServidor(response.authResponse.code);
                }, {
                    config_id: configId,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: { setup: {}, sessionInfoVersion: '3' },
                });
            };

            function enviarParaServidor(code, tentativa) {
                tentativa = tentativa || 0;

                // O evento de sessao as vezes chega DEPOIS do callback do FB.login. Espera
                // até 2s (20 x 100ms) antes de desistir e mandar sem os ids — nesse caso o
                // servidor recusa com uma mensagem clara, em vez de travar aqui para sempre.
                if (!sessaoRecebida && tentativa < 20) {
                    mostrarStatus('Confirmando com o Facebook…');
                    setTimeout(function () { enviarParaServidor(code, tentativa + 1); }, 100);
                    return;
                }

                mostrarStatus('Conectando o número…');

                fetch(urlConectar, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        code: code,
                        waba_id: (sessaoRecebida && sessaoRecebida.waba_id) || '',
                        phone_number_id: (sessaoRecebida && sessaoRecebida.phone_number_id) || '',
                        business_id: (sessaoRecebida && sessaoRecebida.business_id) || null,
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
