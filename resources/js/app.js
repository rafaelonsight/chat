//
// Tempo real do OnChat: um lugar so para receber a mensagem que chegou, avisar as
// telas e alertar a pessoa.
//
// POR QUE A PONTE E ESCRITA A MAO. O Livewire tem integracao propria com o Echo
// (ouvintes "echo-private:canal,.evento"). Medi em producao: o evento chega no
// navegador, o canal dispara, o Livewire.dispatch funciona e o ouvinte do servidor
// responde — mas a ponte dele nunca se registrava, e a caixa de entrada so mudava
// quando alguem clicava. Com a ponte explicita eu vejo o que acontece, e ela e o
// mesmo lugar onde o som e o aviso visual precisam entrar.
//
// UMA assinatura por conta, nao uma por conversa: toda mensagem da conta passa pelo
// canal do tenant, entao a conversa aberta e a lista se atualizam com a mesma fonte.

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

const meta = (nome) => document.querySelector(`meta[name="${nome}"]`)?.content ?? null;

// ============================================================== som do alerta ==

const SOM_LIGADO = 'onchat.som';

const somLigado = () => localStorage.getItem(SOM_LIGADO) !== 'nao';

window.onchatSomLigado = somLigado;

window.onchatAlternarSom = () => {
    const novo = ! somLigado();
    localStorage.setItem(SOM_LIGADO, novo ? 'sim' : 'nao');

    // Toca ao LIGAR, nunca ao desligar: e a unica forma de a pessoa saber que vai
    // ouvir, e o clique dela ja e o gesto que o navegador exige para liberar audio.
    if (novo) bipe();

    return novo;
};

let audio = null;

/**
 * Dois tons curtos, gerados na hora.
 *
 * Sem arquivo de audio de proposito: arquivo e mais um pedido que pode dar 404 num
 * deploy e falhar calado justo quando importa. O navegador so libera audio depois de
 * um gesto do usuario, entao a primeira tentativa pode ser recusada — a falha e
 * engolida, porque alerta que quebra a tela e pior que alerta que nao toca.
 */
function bipe() {
    if (! somLigado()) return;

    try {
        audio = audio ?? new (window.AudioContext ?? window.webkitAudioContext)();

        if (audio.state === 'suspended') audio.resume();

        const agora = audio.currentTime;

        [[880, 0], [1180, 0.12]].forEach(([hz, atraso]) => {
            const osc = audio.createOscillator();
            const vol = audio.createGain();

            osc.type = 'sine';
            osc.frequency.value = hz;

            // Sobe e desce o volume: onda cortada no zero estala.
            vol.gain.setValueAtTime(0, agora + atraso);
            vol.gain.linearRampToValueAtTime(0.18, agora + atraso + 0.01);
            vol.gain.linearRampToValueAtTime(0, agora + atraso + 0.11);

            osc.connect(vol).connect(audio.destination);
            osc.start(agora + atraso);
            osc.stop(agora + atraso + 0.12);
        });
    } catch (e) {
        // audio bloqueado ou indisponivel: o aviso visual continua valendo
    }
}

// ========================================================== aviso visual =======

const tituloOriginal = document.title;
let piscando = null;
let naoVistas = 0;

/**
 * Titulo piscando enquanto a aba nao esta a vista.
 *
 * O caso que importa e justamente o atendente em OUTRA aba: som pode estar
 * desligado, e a tela dele nao esta na frente. O titulo e o unico lugar que ele ve
 * sem estar olhando.
 */
function piscarTitulo() {
    naoVistas++;

    if (piscando) return;

    let alterna = false;

    piscando = setInterval(() => {
        alterna = ! alterna;
        const quantas = naoVistas === 1 ? '1 nova mensagem' : `${naoVistas} novas mensagens`;
        document.title = alterna ? `🔔 ${quantas}` : tituloOriginal;
    }, 1200);
}

function pararDePiscar() {
    if (piscando) clearInterval(piscando);
    piscando = null;
    naoVistas = 0;
    document.title = tituloOriginal;
}

document.addEventListener('visibilitychange', () => {
    if (! document.hidden) pararDePiscar();
});

window.addEventListener('focus', pararDePiscar);

/** Aviso curto no canto, para quem esta com a tela na frente. */
function torrada(texto) {
    let caixa = document.getElementById('onchat-avisos');

    if (! caixa) {
        caixa = document.createElement('div');
        caixa.id = 'onchat-avisos';
        caixa.style.cssText = 'position:fixed;z-index:9999;right:1rem;bottom:1rem;display:flex;flex-direction:column;gap:.5rem;pointer-events:none';
        document.body.appendChild(caixa);
    }

    const aviso = document.createElement('div');
    aviso.textContent = texto;
    aviso.style.cssText = 'background:#065f46;color:#fff;padding:.6rem .9rem;border-radius:.6rem;font:500 13px/1.3 system-ui,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25);opacity:0;transition:opacity .2s,transform .2s;transform:translateY(6px)';
    caixa.appendChild(aviso);

    requestAnimationFrame(() => { aviso.style.opacity = '1'; aviso.style.transform = 'none'; });

    setTimeout(() => {
        aviso.style.opacity = '0';
        setTimeout(() => aviso.remove(), 250);
    }, 4000);
}

// ================================================= a ponte: canal -> telas ======

const tenant = meta('onchat-tenant');

if (tenant) {
    const canal = window.Echo.private(`tenant.${tenant}.conversations`);

    canal.listen('.message.stored', (e) => {
        // Avisa as telas SEMPRE, inclusive na mensagem que nos mandamos: a lista
        // mostra a previa e a hora da ultima mensagem, e ficaria velha.
        if (window.Livewire) {
            window.Livewire.dispatch('mensagem-chegou', {
                conversationId: e.conversation_id ?? null,
            });
        }

        // Alerta SO para mensagem do cliente. Apitar na propria resposta treina a
        // pessoa a ignorar o som, e ai o alerta perde a razao de existir.
        if (e.direcao !== 'in') return;

        bipe();

        // hasFocus e nao document.hidden: o caso comum nao e a aba oculta, e a
        // pessoa com OUTRA janela na frente — planilha, sistema do cliente. Aba
        // visivel mas sem foco tambem precisa chamar atencao.
        if (document.hasFocus()) {
            torrada('Nova mensagem de cliente');
        } else {
            piscarTitulo();
        }
    });
}

// =================================================== estado da conexao =========

/**
 * Sem isto, conexao caida e indistinguivel de "ninguem escreveu": a tela fica com
 * cara de normal e o cliente espera. O aviso e discreto de proposito, e some sozinho
 * quando volta.
 */
const conexao = window.Echo.connector?.pusher?.connection;

if (conexao) {
    const aviso = document.createElement('div');
    aviso.textContent = 'Sem conexão ao vivo — as mensagens novas podem não aparecer. Tentando reconectar…';
    aviso.style.cssText = 'position:fixed;z-index:9998;left:50%;top:0;transform:translateX(-50%);background:#b45309;color:#fff;padding:.45rem 1rem;border-radius:0 0 .5rem .5rem;font:500 12px/1.3 system-ui,sans-serif;display:none';
    document.addEventListener('DOMContentLoaded', () => document.body.appendChild(aviso));

    const mostrar = (sim) => { aviso.style.display = sim ? 'block' : 'none'; };

    conexao.bind('state_change', ({ current }) => {
        mostrar(['unavailable', 'failed', 'disconnected', 'connecting'].includes(current) && current !== 'connecting');

        // "connecting" logo no inicio e normal; so avisa se demorar.
        if (current === 'connecting') {
            setTimeout(() => mostrar(conexao.state !== 'connected'), 4000);
        }
    });
}
