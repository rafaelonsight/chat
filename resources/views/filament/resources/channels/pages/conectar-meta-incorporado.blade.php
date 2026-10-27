<x-filament-panels::page>
    <div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
        <p class="font-semibold">{{ __('ui_cc738d973363') }}</p>
        <p class="mt-1">{{ __('ui_6f80eed6f565') }}</p>
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
                {{ __('ui_8ac414f9c077') }}
            </x-filament::button>

            <button type="button" onclick="virtusReiniciarMeta()">{{ __('ui_7baf853b49a0') }}</button>
            <p id="onsight-status-meta" class="hidden text-sm text-gray-500"></p>

            <div id="onsight-erro-meta" class="hidden w-full rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"></div>
        </div>
    </x-filament::section>

    @unless ($reconectando)
        <p class="text-xs text-gray-500">
            {{ __('ui_67ef377e4082') }}
            <a href="{{ \App\Filament\Resources\Channels\ChannelResource::getUrl('create') }}?tipo={{ \App\Models\Channel::META_CLOUD }}"
               class="text-primary-600 underline hover:no-underline dark:text-primary-400">
                {{ __('ui_df2666e7cf7f') }}
            </a>
        </p>
    @endunless

    <script>
        (function () {
            const appId = @json(config('services.meta.app_id'));
            const configId = @json(config('services.meta.config_id'));
            const apiVersion = @json(config('services.meta.versao'));
            const urlIniciar = @json(route('canais.meta.iniciar'));
            const urlResultado = @json(route('canais.meta.resultado'));
            const tenantId = @json(\App\Support\TenantContext::get());
            const urlReceber = @json(route('canais.meta.receber'));
            const urlConectar = @json(route('canais.meta.conectar-incorporado'));
            const csrfToken = @json(csrf_token());
            const canalId = @json($canalId);
            const button = document.getElementById('onsight-btn-meta');
            let state = null, code = null, assets = {}, active = false, submitted = false, timer = null;
            let sdkReady = false, attemptReady = false, polling = null, finalizing = false;
            const storageKey = 'virtus:meta:' + tenantId + ':' + (canalId || 'novo');
            function saveAttempt() { try { sessionStorage.setItem(storageKey, JSON.stringify({state, active})); } catch (_) {} }
            function forgetAttempt() { try { sessionStorage.removeItem(storageKey); } catch (_) {} }
            button.disabled = true;

            async function post(url, body) {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                    body: JSON.stringify(body),
                });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.erro || 'Não foi possível concluir. Atualize a página e tente novamente.');
                return result;
            }
            function ready() { button.disabled = !(sdkReady && attemptReady); }
            function status(message) {
                document.getElementById('onsight-status-meta').textContent = message;
                document.getElementById('onsight-status-meta').classList.remove('hidden');
                document.getElementById('onsight-erro-meta').classList.add('hidden');
            }
            function error(message) {
                document.getElementById('onsight-status-meta').classList.add('hidden');
                document.getElementById('onsight-erro-meta').textContent = message;
                document.getElementById('onsight-erro-meta').classList.remove('hidden');
            }
            async function persist(signupToken) {
                if (finalizing) return;
                finalizing = true;
                try {
                    status('Salvando o canal na conta…');
                    const result = await post(urlConectar, {signup_token: signupToken, canal_id: canalId});
                    forgetAttempt(); clearInterval(polling);
                    window.location.href = result.redirect;
                } catch (err) {
                    // Mantem a tentativa: perda de rede nao pode exigir outra autorizacao da Meta.
                    error(err.message); finalizing = false;
                }
            }
            async function recover() {
                if (!state || finalizing) return;
                try {
                    const response = await fetch(urlResultado + '?state=' + encodeURIComponent(state), {headers: {'Accept': 'application/json'}});
                    const result = await response.json();
                    if (!response.ok) {
                        if ([403,410].includes(response.status)) {
                            clearInterval(polling); forgetAttempt(); error(result.erro || 'Esta tentativa não está disponível nesta conta.'); prepare();
                        }
                        return;
                    }
                    if (result.status === 'ready') await persist(result.signup_token);
                    else if (result.status === 'completed') {
                        forgetAttempt(); clearInterval(polling); window.location.href = result.redirect;
                    } else if (result.status === 'failed') {
                        clearInterval(polling); forgetAttempt(); error(result.erro); prepare();
                    } else if (result.status === 'processing') status('A Meta foi autorizada. Validando a conexão…');
                } catch (_) { /* Uma falha transitoria nao descarta o retorno salvo no servidor. */ }
            }
            window.virtusReiniciarMeta = function () { forgetAttempt(); prepare(); status("Pronto para iniciar uma nova tentativa."); };
            function startPolling() {
                clearInterval(polling); polling = setInterval(recover, 2500); recover();
            }
            function prepare() {
                state = null; code = null; assets = {}; submitted = false; active = false; attemptReady = false;
                clearTimeout(timer); clearInterval(polling); finalizing = false; ready();
                if (!configId || !appId) { error('O aplicativo da Meta ainda não está configurado.'); return; }
                post(urlIniciar, {canal_id: canalId}).then(result => {
                    state = result.state; attemptReady = true; saveAttempt(); ready();
                }).catch(err => error(err.message));
            }
            function finish() {
                if (!active || !code || submitted) return;
                submitted = true; clearTimeout(timer);
                status('Validando a autorização e identificando o número…');
                post(urlReceber, {state, code, waba_id: assets.waba_id || null,
                    phone_number_id: assets.phone_number_id || null, business_id: assets.business_id || null})
                    .then(result => persist(result.signup_token))
                    .catch(err => { error(err.message); recover(); });
            }
            // O code e os identificadores chegam por eventos independentes e em qualquer ordem.
            window.addEventListener('message', event => {
                if (!active || submitted || !['https://www.facebook.com', 'https://web.facebook.com', 'https://business.facebook.com'].includes(event.origin)) return;
                let data;
                try { data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data; } catch (_) { return; }
                if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') return;
                if (['FINISH', 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', 'FINISH_ONLY_WABA'].includes(data.event)) {
                    assets = data.data || {};
                    if (code) finish();
                } else if (data.event === 'CANCEL' || data.event === 'ERROR') {
                    if (code) return;
                    error('O cadastro na Meta foi interrompido. Você pode iniciar novamente.'); prepare();
                }
            });
            window.onsightIniciarCadastroIncorporado = function () {
                if (!sdkReady || !attemptReady) return;
                active = true; submitted = false; button.disabled = true; saveAttempt(); startPolling();
                status('Complete a autorização e selecione a conta e o número da empresa na Meta…');
                FB.login(response => {
                    code = response.authResponse && response.authResponse.code;
                    if (!code) { error('A Meta não devolveu a autorização. Inicie novamente.'); prepare(); return; }
                    if (assets.waba_id) finish();
                    // Se nao houver evento com IDs, o servidor descobre apenas ativos inequivocos do token.
                    else timer = setTimeout(finish, 2000);
                }, {
                    config_id: configId, response_type: 'code', override_default_response_type: true,
                    extras: {version: 'v3', sessionInfoVersion: '3', featureType: 'whatsapp_business_app_onboarding'},
                });
            };
            function initSdk() {
                FB.init({appId, autoLogAppEvents: false, xfbml: false, version: apiVersion});
                sdkReady = true; ready();
            }
            if (window.FB) initSdk();
            else {
                window.fbAsyncInit = initSdk;
                if (!document.getElementById('virtus-facebook-sdk')) {
                    const script = document.createElement('script');
                    script.id = 'virtus-facebook-sdk'; script.async = true; script.defer = true;
                    script.src = 'https://connect.facebook.net/pt_BR/sdk.js';
                    script.onerror = () => error('Não foi possível carregar a Meta. Confira bloqueadores do navegador e atualize a página.');
                    document.head.appendChild(script);
                }
            }
            let previous;
            try { previous = JSON.parse(sessionStorage.getItem(storageKey) || 'null'); } catch (_) {}
            if (previous && previous.state && previous.active) {
                state = previous.state; active = true; submitted = true;
                status('Recuperando o resultado da conexão anterior…'); startPolling();
            } else prepare();
            window.addEventListener('beforeunload', () => clearInterval(polling), {once: true});
        })();
    </script>
</x-filament-panels::page>
