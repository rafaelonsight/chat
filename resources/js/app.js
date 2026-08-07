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

// ================================================ aviso do sistema operacional ==

const AVISO_LIGADO = 'onchat.aviso';

const avisoLigado = () => localStorage.getItem(AVISO_LIGADO) !== 'nao';

window.onchatAvisoLigado = avisoLigado;

/**
 * Notificacao do proprio sistema, que aparece por cima de qualquer janela.
 *
 * Por que existe, se ja ha som e titulo piscando: os dois so servem para quem tem o navegador
 * aberto em algum lugar. Atendente com o navegador minimizado — porque foi ao sistema do
 * cliente, ao ERP, a planilha — nao ve titulo nenhum, e o som pode estar desligado no
 * computador inteiro.
 *
 * A PERMISSAO SO E PEDIDA NUM CLIQUE. Navegador ignora (e o Firefox pune) pedido feito ao
 * carregar a pagina, e um aviso pedindo permissao do nada e o tipo de coisa que a pessoa nega
 * por reflexo — e negado nao se pede de novo. Entao ela e pedida na primeira vez que o
 * atendente clica em qualquer lugar, quando ja esta claramente usando o sistema.
 */
window.onchatAlternarAviso = async () => {
    const novo = ! avisoLigado();
    localStorage.setItem(AVISO_LIGADO, novo ? 'sim' : 'nao');

    if (novo) await pedirPermissao();

    return novo;
};

async function pedirPermissao() {
    if (! ('Notification' in window)) return false;
    if (Notification.permission === 'granted') return true;
    if (Notification.permission === 'denied') return false;

    try {
        return (await Notification.requestPermission()) === 'granted';
    } catch (e) {
        return false;
    }
}

// Um clique em qualquer lugar serve de gesto: e o mesmo que libera o audio.
document.addEventListener('click', function primeiro() {
    document.removeEventListener('click', primeiro);
    if (avisoLigado()) pedirPermissao();
}, { once: false });

let avisoAberto = null;

function avisoDoSistema(texto) {
    if (! avisoLigado()) return;
    if (! ('Notification' in window) || Notification.permission !== 'granted') return;

    try {
        // Fecha o anterior antes de abrir outro: dez mensagens em um minuto empilhariam dez
        // avisos, e a pessoa gastaria mais tempo fechando aviso do que respondendo.
        avisoAberto?.close();

        avisoAberto = new Notification('Nova mensagem', {
            body: texto,
            tag: 'onchat-mensagem',
            renotify: false,
        });

        avisoAberto.onclick = () => {
            window.focus();
            avisoAberto?.close();
        };
    } catch (e) {
        // Sem notificacao: o titulo piscando e o som continuam valendo.
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

            // Corpo cortado: o aviso do sistema fica visivel na tela de quem passa perto, e
            // conversa inteira ali seria vazamento por descuido.
            avisoDoSistema((e.corpo ?? '').slice(0, 80) || 'Mensagem recebida');
        }
    });
}

// ============================================ quem mais esta nesta conversa ====

/**
 * Dois atendentes na mesma conversa, e nada avisando.
 *
 * E o erro mais constrangedor de equipe: o cliente recebe duas respostas diferentes para a
 * mesma pergunta, com minutos de diferenca. Nao da para impedir — as duas pessoas tem direito
 * de abrir — mas da para AVISAR, e na pratica isso resolve: quem chega depois ve e sai, ou
 * fala antes de responder.
 *
 * Canal de presenca do Reverb: nada e guardado no banco. Quem fecha a aba some da lista
 * sozinho, e um aviso que fica preso depois de a pessoa sair seria pior que nenhum aviso.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('presencaDaConversa', (conversaId, meuId) => ({
        outros: [],

        init() {
            if (! conversaId || ! window.Echo) return;

            try {
                window.Echo.join(`conversation.${conversaId}`)
                    .here((todos) => { this.outros = todos.filter((u) => u.id !== meuId); })
                    .joining((u) => {
                        if (u.id !== meuId && ! this.outros.some((o) => o.id === u.id)) {
                            this.outros.push(u);
                        }
                    })
                    .leaving((u) => { this.outros = this.outros.filter((o) => o.id !== u.id); })
                    .error(() => { this.outros = []; });
            } catch (e) {
                // Sem presenca a tela continua inteira. Isto e aviso, nao funcionalidade.
            }
        },

        destroy() {
            // Sair do canal ao trocar de conversa. Sem isto a pessoa apareceria "vendo" cinco
            // conversas ao mesmo tempo, e o aviso viraria mentira em todas.
            if (conversaId && window.Echo) window.Echo.leave(`conversation.${conversaId}`);
        },

        get aviso() {
            if (! this.outros.length) return '';

            const nomes = this.outros.map((o) => o.nome).filter(Boolean);

            return nomes.length === 1
                ? `${nomes[0]} também está com esta conversa aberta`
                : `${nomes.join(', ')} também estão com esta conversa aberta`;
        },
    }));
});

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

// ============================================ cortina do bloqueio entre abas ==

/**
 * Bloquear a sessao numa aba tem de escurecer as OUTRAS abas na hora.
 *
 * O middleware do servidor segura a proxima navegacao, mas nao apaga o que ja esta
 * desenhado: a aba do lado continuaria mostrando o atendimento inteiro para quem
 * passasse na frente do balcao. localStorage e o canal certo aqui — o evento
 * 'storage' chega em todas as abas da mesma origem, sem servidor no meio.
 */
const TRAVA = 'onchat.bloqueada';

function cortina(mostrar) {
    let capa = document.getElementById('onchat-cortina');

    if (! mostrar) {
        capa?.remove();

        return;
    }

    if (capa) return;

    capa = document.createElement('div');
    capa.id = 'onchat-cortina';
    capa.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#0f172af2;backdrop-filter:blur(6px);display:grid;place-items:center;color:#e2e8f0;font:500 15px/1.5 system-ui,sans-serif;text-align:center;padding:2rem';
    capa.innerHTML = `
        <div>
            <p style="font-weight:700;font-size:1.1rem;margin:0 0 .4rem">Sessão bloqueada</p>
            <p style="color:#94a3b8;margin:0 0 1.2rem;font-size:.9rem">Esta tela foi bloqueada em outra aba.</p>
            <a href="/sessao/travada" style="display:inline-block;background:#059669;color:#fff;padding:.55rem 1.1rem;border-radius:.5rem;text-decoration:none;font-weight:600;font-size:.9rem">Destravar</a>
        </div>`;

    document.body.appendChild(capa);
}

// Esta pagina carregou, logo a sessao NAO esta travada: limpa a marca para as outras
// abas saberem que voltou. E o unico sinal confiavel de destravamento — quem destrava
// e redirecionado para o painel.
try {
    localStorage.removeItem(TRAVA);
} catch (e) {}

window.addEventListener('storage', (e) => {
    if (e.key !== TRAVA) return;

    cortina(e.newValue === '1');
});
