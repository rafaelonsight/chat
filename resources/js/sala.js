//
// A sala de video.
//
// SEPARADO DO app.js DE PROPOSITO. O cliente do LiveKit sao quase 500 kB, e ele so serve numa
// tela do sistema inteiro. Pendurado no pacote principal, ele seria baixado por todo atendente
// em toda pagina do atendimento — que e justamente a tela que precisa abrir rapido.
//
// A CAMERA E O MICROFONE SAO PEDIDOS NO CLIQUE DE ENTRAR, na tela, antes da ida ao servidor.
// Navegador so abre a caixa de permissao durante um gesto, e o gesto nao sobrevive a uma
// viagem de ida e volta: no iPhone o pedido feito depois e recusado sem nem perguntar.

import { Room, RoomEvent, Track, createLocalScreenTracks } from 'livekit-client';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('salaDeVideo', () => {
        /*
         * ================== O QUE NAO PODE ENCOSTAR NO ESTADO DO ALPINE ==================
         *
         * A sala e as faixas de midia vivem AQUI, em variaveis de fechamento, e nao como
         * propriedades do componente.
         *
         * O Alpine torna reativo tudo que esta no estado, e faz isso embrulhando cada objeto
         * num Proxy. O LiveKit, por dentro, precisa clonar objetos para conversar com os
         * trabalhadores de midia do navegador — e PROXY NAO PODE SER CLONADO. O resultado era
         * `DataCloneError: The object can not be cloned` na hora de ligar a camera: ninguem
         * publicava audio nem video, os dois lados se viam como iniciais numa bola, e o erro
         * nao dizia nada sobre permissao nem sobre rede.
         *
         * Fica valendo a regra: no estado do Alpine so entra dado simples — texto, numero,
         * booleano, e listas deles. Objeto de biblioteca, nunca.
         */
        let sala = null;

        /** identidade -> { video, tela, audio } com as faixas de verdade, fora do Proxy. */
        const faixas = new Map();

        /*
         * O AudioContext tambem mora aqui fora, pela MESMA razao das faixas: objeto de
         * navegador dentro do estado do Alpine vira Proxy, e Proxy nao pode ser clonado. Ja
         * custou uma tarde com o DataCloneError; nao custa duas.
         *
         * Um so para a sala inteira: cada `new AudioContext()` e um recurso de audio do
         * sistema, e o navegador limita quantos existem ao mesmo tempo.
         */
        let audio = null;

        /** Numera as reacoes que estao subindo na tela. Contador, e nao sorteio. */
        let proximaReacao = 0;

        return {
            // ---------------------------------------------------------- estado da tela
            entrando: false,
            dentro: false,
            saiu: false,
            motivoDaSaida: '',
            erro: '',

            /*
             * O nome tecnico da falha, em letra miuda. "NotAllowedError" nao ajuda quem esta na
             * reuniao, mas e a primeira coisa que se pergunta quando chega um print dizendo que
             * nao funcionou — e foi exatamente ele que apontou o DataCloneError.
             */
            detalhe: '',

            // ------------------------------------------------------------- os controles
            microfone: true,
            camera: true,
            compartilhando: false,

            /**
             * Quem esta com a tela compartilhada agora: { id, nome, souEu } ou null.
             *
             * Existe porque quadro e PESSOA eram a mesma coisa aqui, e nao sao. Quem
             * compartilhava perdia o rosto: a tela entrava no lugar da camera dentro do mesmo
             * quadro, e o Rafael viu a propria imagem desaparecer no meio da reuniao.
             *
             * Uma pessoa pode produzir DUAS imagens ao mesmo tempo. Com isto, a tela vira
             * palco e as cameras — a dela inclusive — ficam na fita do lado.
             */
            apresentador: null,

            /** So dado simples: o que a tela precisa desenhar, e nada de objeto de midia. */
            pessoas: [],

            /**
             * Mao levantada e reacao, por participante.
             *
             * Dado simples de novo — booleano e texto — entao pode viver no estado reativo sem
             * repetir o DataCloneError. { identidade: { mao: bool, reacao: '👍' } }
             */
            sinais: {},

            /**
             * As reacoes SUBINDO na tela, e nao grudadas em ninguem.
             *
             * Reacao presa ao quadro de uma pessoa por quatro segundos deixa de ser reacao e
             * vira adesivo — e num quadro pequeno de celular ela ainda tapa o rosto de quem
             * esta falando. Subindo no meio da tela, ela e vista por todo mundo e some sozinha.
             *
             * @type {Array<{id: number, emoji: string, nome: string, desvio: number}>}
             */
            reacoes: [],

            maoLevantada: false,

            // ---------------------------------------------------------- o bate-papo
            /** @type {Array<{nome: string, corpo: string, hora: string, daEquipe: boolean}>} */
            recados: [],

            /**
             * Qual painel esta aberto: '', 'chat' ou 'gente'.
             *
             * Um de cada vez de proposito. Em celular os dois ocupam a tela inteira, e mesmo no
             * computador dois paineis abertos deixariam o video numa tira no meio.
             */
            painel: '',

            /**
             * Quantos chegaram com o painel fechado.
             *
             * Existe porque numa chamada ninguem fica olhando para o icone do chat: sem o
             * numero, o recado escrito com a resposta que a pessoa esperava passa despercebido
             * ate a reuniao acabar.
             */
            naoLidos: 0,

            rascunho: '',

            // ------------------------------------------------------- dispositivos
            microfones: [],
            cameras: [],
            microfoneAtual: '',
            cameraAtual: '',

            async entrar(token, url, historico = []) {
                if (this.entrando || this.dentro) return;

                // O que ja foi dito, para quem chega depois: entrou dez minutos atrasado e le
                // em vez de parar a reuniao perguntando "o que eu perdi?".
                this.recados = Array.isArray(historico) ? [...historico] : [];

                this.entrando = true;
                this.erro = '';
                this.detalhe = '';

                try {
                    sala = new Room({
                        adaptiveStream: true,
                        // Sobe e desce a qualidade sozinho conforme a banda de cada um. Sem
                        // isto, um participante em 4G derruba a chamada para todos os outros.
                        dynacast: true,
                    });

                    this.ouvir();

                    await sala.connect(url, token);

                    /*
                     * JA ESTA NA SALA. Marcado ANTES de mexer em camera, e essa ordem e o
                     * conserto de um defeito que derrubava toda entrada: a falha ao abrir a
                     * camera caia no catch de baixo, que desconectava a sala inteira. O
                     * servidor registrava "participante ativo" e, 50 ms depois,
                     * CLIENT_REQUEST_LEAVE — parecia problema de rede e era a nossa propria
                     * mao desligando.
                     */
                    this.dentro = true;

                    await this.ligarMidia();
                    await this.listarDispositivos();

                    // O som e preparado agora, logo depois do clique que trouxe a pessoa para
                    // ca. Deixar para criar o AudioContext no primeiro apito e criar ele longe
                    // de qualquer gesto — e navegador nao libera audio assim.
                    this.prepararAudio();

                    this.recontar();
                } catch (e) {
                    // Aqui e falha de CONEXAO, e ai nao ha sala para ficar.
                    this.erro = this.explicar(e);
                    this.detalhe = this.tecnico(e);
                    this.desmontar();
                } finally {
                    this.entrando = false;
                }
            },

            /**
             * Liga camera e microfone.
             *
             * SEM CAMERA AINDA SE PARTICIPA. Quem negou a permissao, esta num aparelho sem
             * camera, ou com ela ocupada por outro programa, continua na reuniao vendo e
             * ouvindo os outros. Derrubar a pessoa por causa disso e trocar uma limitacao por
             * uma porta fechada.
             */
            async ligarMidia() {
                try {
                    // Os dois num pedido so: dois pedidos seguidos viram duas caixas de
                    // permissao, e a segunda quase sempre e negada por reflexo.
                    await sala.localParticipant.enableCameraAndMicrophone();

                    this.camera = true;
                    this.microfone = true;
                    this.erro = '';
                    this.detalhe = '';
                } catch (e) {
                    this.erro = this.explicar(e);
                    this.detalhe = this.tecnico(e);
                    this.camera = false;
                    this.microfone = false;
                }
            },

            /**
             * Quais microfones e cameras existem neste aparelho.
             *
             * SO DEPOIS DA PERMISSAO. Antes dela o navegador devolve a lista sem NOME — por
             * privacidade, para uma pagina qualquer nao conseguir catalogar o hardware de quem
             * visita. Uma lista de "dispositivo 1, dispositivo 2" nao ajuda ninguem a escolher
             * o headset, entao ela e carregada depois de a camera ser liberada.
             *
             * E o motivo de existir e concreto: microfone errado e a causa numero um de "nao
             * estao me ouvindo". O aparelho escolhe o embutido, a pessoa fala no headset, e a
             * reuniao inteira vira uma conversa sobre audio.
             */
            async listarDispositivos() {
                try {
                    const todos = await navigator.mediaDevices.enumerateDevices();

                    this.microfones = todos
                        .filter((d) => d.kind === 'audioinput' && d.deviceId)
                        .map((d) => ({ id: d.deviceId, nome: d.label || 'Microfone' }));

                    this.cameras = todos
                        .filter((d) => d.kind === 'videoinput' && d.deviceId)
                        .map((d) => ({ id: d.deviceId, nome: d.label || 'Câmera' }));

                    this.microfoneAtual = sala?.getActiveDevice('audioinput') ?? '';
                    this.cameraAtual = sala?.getActiveDevice('videoinput') ?? '';
                } catch (e) {
                    // Sem lista a sala continua inteira; so nao da para trocar de aparelho.
                    this.microfones = [];
                    this.cameras = [];
                }
            },

            async trocarDispositivo(tipo, id) {
                if (! sala) return;

                try {
                    await sala.switchActiveDevice(tipo, id);

                    if (tipo === 'audioinput') this.microfoneAtual = id;
                    if (tipo === 'videoinput') this.cameraAtual = id;
                } catch (e) {
                    this.erro = 'Não consegui trocar de aparelho.';
                    this.detalhe = this.tecnico(e);
                }

                this.recontar();
            },

            /**
             * Virar entre a camera da frente e a de tras.
             *
             * O BOTAO MAIS IMPORTANTE DESTE PRODUTO, e nao um enfeite: o atendimento por video
             * nasceu de "me mostra o aparelho". Quem esta com o problema precisa virar o
             * celular para o equipamento — e sem este botao teria de abrir a lista, ler nomes
             * de dispositivo que ninguem entende, e adivinhar qual e a de tras.
             *
             * So aparece quando ha mais de uma camera, que na pratica quer dizer celular.
             */
            async virarCamera() {
                if (this.cameras.length < 2) return;

                const atual = this.cameras.findIndex((c) => c.id === this.cameraAtual);
                const proxima = this.cameras[(atual + 1) % this.cameras.length];

                await this.trocarDispositivo('videoinput', proxima.id);
            },

            // ------------------------------------------------------------- sinais

            /**
             * Mao levantada e reacoes viajam pelo canal de DADOS da propria sala.
             *
             * Sem servidor no meio, sem banco, sem nada guardado: e recado de momento, e
             * recado de momento que sobrevive ao fim da reuniao vira lixo que alguem precisa
             * limpar depois.
             */
            enviarSinal(dado) {
                try {
                    sala?.localParticipant.publishData(
                        new TextEncoder().encode(JSON.stringify(dado)),
                        { reliable: true },
                    );
                } catch (e) {
                    // sinal e cortesia: se nao for, ninguem fica sem reuniao por causa disso
                }
            },

            marcarSinal(identidade, dado) {
                const atual = this.sinais[identidade] ?? {};

                if (dado.tipo === 'chat') {
                    this.guardarRecado({
                        nome: dado.nome || 'Convidado',
                        corpo: dado.corpo,
                        hora: dado.hora,
                        daEquipe: Boolean(dado.daEquipe),
                    });

                    return;
                }

                if (dado.tipo === 'mao') {
                    this.sinais[identidade] = { ...atual, mao: Boolean(dado.valor) };

                    /*
                     * O APITO E SO PARA MAO DOS OUTROS, e so ao LEVANTAR.
                     *
                     * Levantar a mao e um pedido de vez, e pedido de vez que ninguem escuta nao
                     * e pedido — quem esta falando esta olhando para a propria tela, nao para o
                     * quadro do colega. Apitar na propria mao seria treinar a pessoa a ignorar
                     * o som, e apitar ao baixar seria barulho por uma coisa que ja acabou.
                     */
                    if (dado.valor && identidade !== sala?.localParticipant?.identity) {
                        this.apitar();
                    }
                }

                if (dado.tipo === 'reacao') {
                    this.soltarReacao(dado.emoji, dado.nome || 'Alguém');
                }

                this.recontar();
            },

            guardarRecado(recado) {
                this.recados.push(recado);

                if (this.painel !== 'chat') this.naoLidos++;

                this.$nextTick(() => this.rolarRecados());
            },

            rolarRecados() {
                const caixa = this.$refs.recados;

                if (caixa) caixa.scrollTop = caixa.scrollHeight;
            },

            abrirPainel(qual) {
                // Clicar de novo no mesmo botao fecha: e o que o dedo espera de um botao que
                // fica aceso enquanto o painel esta aberto.
                this.painel = this.painel === qual ? '' : qual;

                if (this.painel === 'chat') {
                    this.naoLidos = 0;
                    this.$nextTick(() => this.rolarRecados());
                }
            },

            /**
             * Manda um recado.
             *
             * DOIS CAMINHOS, E CADA UM FAZ UMA COISA. O canal de dados da sala entrega na tela
             * dos outros no mesmo instante — e o que faz o chat parecer chat. O servidor guarda,
             * e e o que sobra para quem chegar depois e para quem for ler o atendimento amanha.
             *
             * Num sistema de atendimento isso nao e luxo: o que se digita durante a chamada e
             * justamente o que nao pode sumir — o numero de serie que o cliente leu do aparelho,
             * o endereco que ele corrigiu.
             *
             * Se a gravacao falhar, o recado JA apareceu para todo mundo. Perder o registro e
             * ruim; perder o recado no meio da conversa e pior.
             */
            mandarRecado() {
                const corpo = this.rascunho.trim();

                if (! corpo || ! sala) return;

                const meu = {
                    nome: sala.localParticipant.name || 'Você',
                    corpo,
                    hora: new Date().toTimeString().slice(0, 5),
                    daEquipe: false,
                    souEu: true,
                };

                this.guardarRecado(meu);
                this.rascunho = '';

                this.enviarSinal({
                    tipo: 'chat',
                    corpo,
                    nome: meu.nome,
                    hora: meu.hora,
                });

                this.$wire?.gravarMensagem(corpo);
            },

            /**
             * Solta uma reacao para subir no meio da tela.
             *
             * O desvio horizontal existe para duas reacoes no mesmo instante nao subirem
             * exatamente uma em cima da outra e parecerem uma so.
             */
            soltarReacao(emoji, nome) {
                const id = ++proximaReacao;

                this.reacoes.push({ id, emoji, nome, desvio: (id % 5) * 14 - 28 });

                // Some sozinha, no mesmo tempo da animacao. Guardar o que ja saiu de vista so
                // faria a lista crescer para sempre numa reuniao longa.
                setTimeout(() => {
                    this.reacoes = this.reacoes.filter((r) => r.id !== id);
                }, 2600);
            },

            /**
             * Dois tons curtos, gerados na hora.
             *
             * Sem arquivo de audio de proposito: arquivo e mais um pedido que pode dar 404 num
             * deploy e falhar calado justo quando importa. O navegador ja liberou audio nesta
             * pagina — a pessoa clicou em entrar e esta ouvindo os outros —, entao aqui nao ha
             * o problema do gesto.
             */
            /**
             * Deixa o audio pronto enquanto o gesto da pessoa ainda vale.
             *
             * Navegador nasce com o audio suspenso e so libera perto de um clique. Chamado do
             * entrar(), que roda logo depois do botao, ele pega essa janela.
             */
            prepararAudio() {
                try {
                    audio = audio ?? new (window.AudioContext ?? window.webkitAudioContext)();

                    if (audio.state === 'suspended') audio.resume();
                } catch (e) {
                    // sem audio o aviso visual continua valendo
                }
            },

            async apitar() {
                try {
                    audio = audio ?? new (window.AudioContext ?? window.webkitAudioContext)();

                    /*
                     * ESPERAR O RESUME, e nao so pedir.
                     *
                     * resume() e assincrono: sem o await, o relogio do audio ainda esta parado
                     * no zero quando os tons sao agendados, e eles sao marcados para um
                     * instante que ja passou. O apito nao toca e nao da erro nenhum -- o pior
                     * tipo de falha, porque nao ha o que investigar.
                     */
                    if (audio.state === 'suspended') await audio.resume();

                    const agora = audio.currentTime;

                    [[880, 0], [1320, 0.14]].forEach(([hz, atraso]) => {
                        const osc = audio.createOscillator();
                        const vol = audio.createGain();

                        osc.type = 'sine';
                        osc.frequency.value = hz;

                        // Sobe e desce o volume: onda cortada no zero estala.
                        vol.gain.setValueAtTime(0, agora + atraso);
                        vol.gain.linearRampToValueAtTime(0.16, agora + atraso + 0.01);
                        vol.gain.linearRampToValueAtTime(0, agora + atraso + 0.13);

                        osc.connect(vol).connect(audio.destination);
                        osc.start(agora + atraso);
                        osc.stop(agora + atraso + 0.14);
                    });
                } catch (e) {
                    // audio bloqueado: a mao levantada continua aparecendo na tela
                }
            },

            alternarMao() {
                this.maoLevantada = ! this.maoLevantada;

                const eu = sala?.localParticipant?.identity;

                if (eu) this.marcarSinal(eu, { tipo: 'mao', valor: this.maoLevantada });

                this.enviarSinal({ tipo: 'mao', valor: this.maoLevantada });
            },

            reagir(emoji) {
                const nome = sala?.localParticipant?.name || 'Você';

                this.soltarReacao(emoji, nome);

                this.enviarSinal({ tipo: 'reacao', emoji, nome });
            },

            /**
             * Tentar de novo, sem sair da sala.
             *
             * Roda no clique, que e o unico momento em que o navegador aceita abrir a caixa de
             * permissao. Quem negou por reflexo — e negar por reflexo e o caso comum — volta
             * atras sem recarregar a pagina e perder a reuniao.
             */
            async tentarMidia() {
                if (! sala) return;

                await this.ligarMidia();
                this.recontar();
            },

            // ----------------------------------------------------------------- os avisos

            tecnico(e) {
                return [e?.name, e?.message].filter(Boolean).join(': ');
            },

            /**
             * Traduz o erro do navegador para o que a pessoa pode fazer a respeito.
             *
             * "NotAllowedError" no meio da tela nao ajuda ninguem: quem esta do outro lado e o
             * cliente do nosso cliente, no celular, e ele precisa saber que tem de liberar a
             * camera — nao o nome da excecao.
             */
            explicar(e) {
                const nome = e?.name ?? '';

                /*
                 * O NAVEGADOR DE DENTRO DO WHATSAPP NAO ENTREGA CAMERA.
                 *
                 * No iPhone, link aberto pelo WhatsApp abre numa janela embutida que a Apple
                 * nao autoriza a usar camera nem microfone. Nao ha o que a pagina faca a
                 * respeito — a unica saida e abrir no Safari, e a pessoa precisa ser ensinada
                 * a fazer isso.
                 *
                 * E o caso mais provavel deste produto: o link de reuniao viaja pelo WhatsApp
                 * por projeto, entao quase todo convidado chega por esse caminho.
                 */
                if (this.dentroDeAplicativo() && nome !== 'DataCloneError') {
                    return 'Este navegador não libera a câmera. Toque em ••• (ou no ícone de compartilhar) e escolha "Abrir no Safari" — o link é o mesmo.';
                }

                if (nome === 'NotAllowedError') {
                    return 'Você precisa liberar a câmera e o microfone. Toque no cadeado ao lado do endereço e permita.';
                }

                if (nome === 'NotFoundError' || nome === 'DevicesNotFoundError') {
                    return 'Não encontramos câmera nem microfone neste aparelho.';
                }

                if (nome === 'NotReadableError') {
                    return 'A câmera parece estar em uso por outro programa. Feche os outros aplicativos e tente de novo.';
                }

                return 'Não foi possível ligar a câmera. Tente de novo — você continua na reunião.';
            },

            /**
             * Estamos numa janela embutida de aplicativo?
             *
             * Nao da para perguntar isso ao navegador, entao se olha o que da: no iPhone, a
             * janela do WhatsApp se apresenta como WebKit mas sem a marca "Version/" que o
             * Safari de verdade carrega. E heuristica, e por isso ela so decide o TEXTO do
             * aviso — nunca se a pessoa entra ou nao.
             */
            dentroDeAplicativo() {
                const ua = navigator.userAgent || '';

                if (/FBAN|FBAV|Instagram|Line\//i.test(ua)) return true;

                return /iPhone|iPad|iPod/i.test(ua)
                    && /AppleWebKit/i.test(ua)
                    && ! /Version\//i.test(ua);
            },

            // ------------------------------------------------------------------ eventos

            ouvir() {
                sala.on(RoomEvent.TrackSubscribed, () => this.recontar())
                    .on(RoomEvent.TrackUnsubscribed, () => this.recontar())
                    /*
                     * PUBLICADA E ASSINADA SAO DOIS MOMENTOS, e faltava ouvir o primeiro.
                     *
                     * Com adaptiveStream, a assinatura so acontece quando ha um elemento
                     * visivel pedindo a imagem. Entao para a tela compartilhada de outra pessoa
                     * a ordem e ao contrario do que parece: primeiro precisamos SABER que ela
                     * existe (publicada), desenhar o palco, e a assinatura vem depois, por
                     * causa do elemento que o palco criou.
                     *
                     * Sem este ouvinte, o TrackSubscribed nunca chegava — ele dependia do
                     * elemento que dependia dele. O palco aparecia so quando outro evento
                     * qualquer (alguem falando, um microfone mudando) redesenhava a lista por
                     * acaso: nos testes de duas janelas apareceu numa rodada e nao apareceu na
                     * seguinte, com o mesmo codigo. Intermitente e pior que quebrado, porque
                     * ninguem acredita em quem reclama.
                     */
                    .on(RoomEvent.TrackPublished, () => this.recontar())
                    .on(RoomEvent.TrackUnpublished, () => this.recontar())
                    .on(RoomEvent.ParticipantConnected, () => this.recontar())
                    .on(RoomEvent.ParticipantDisconnected, () => this.recontar())
                    // Calado pelo anfitriao: sem ouvir isto, o proprio botao continuaria
                    // aceso dizendo que o microfone esta ligado.
                    .on(RoomEvent.TrackMuted, () => this.recontar())
                    .on(RoomEvent.TrackUnmuted, () => this.recontar())
                    .on(RoomEvent.LocalTrackPublished, () => this.recontar())
                    .on(RoomEvent.LocalTrackUnpublished, () => this.recontar())
                    .on(RoomEvent.ActiveSpeakersChanged, () => this.recontar())
                    .on(RoomEvent.DataReceived, (carga, quem) => {
                        try {
                            const dado = JSON.parse(new TextDecoder().decode(carga));

                            if (quem?.identity) this.marcarSinal(quem.identity, dado);
                        } catch (e) {
                            // dado estranho no canal: ignora, nao e motivo para quebrar a sala
                        }
                    })
                    .on(RoomEvent.Disconnected, (motivo) => {
                        this.dentro = false;
                        this.saiu = true;

                        /*
                         * POR QUE A PESSOA SAIU.
                         *
                         * "Você saiu da chamada" para quem foi removido e quase uma mentira: ela
                         * nao saiu, foi tirada. E quem cai porque o anfitriao encerrou fica
                         * tentando voltar para uma sala que nao existe mais. O numero vem do
                         * servidor de midia; comparo por valor para nao depender de o nome da
                         * constante continuar igual na proxima versao do cliente.
                         */
                        if (motivo === 5) this.motivoDaSaida = 'Você foi retirado da chamada.';
                        else if (motivo === 6) this.motivoDaSaida = 'A chamada foi encerrada por quem organizou.';
                        else this.motivoDaSaida = '';
                    });
            },

            /**
             * Redesenha a lista de quem esta na sala.
             *
             * Um metodo so para todo evento: tentar aplicar cada mudanca em cima do estado
             * anterior (entrou fulano, saiu a camera do beltrano) e como o desenho da tela
             * desanda em silencio. Recontar do zero e barato — sao no maximo oito pessoas.
             *
             * As faixas vao para o Map de fora; para a tela vai so o que ela desenha.
             */
            recontar() {
                if (! sala) return;

                faixas.clear();

                const monta = (p, souEu) => {
                    const video = p.getTrackPublication(Track.Source.Camera)?.track ?? null;
                    /*
                     * A PUBLICACAO E A FAIXA SAO COISAS DIFERENTES, e aqui essa diferenca era
                     * uma TRAVA CIRCULAR.
                     *
                     * A sala roda com adaptiveStream, que so assina uma faixa de video quando
                     * ela esta pendurada num elemento visivel — e economiza banda de quem esta
                     * no 4G. Mas o palco da apresentacao so nascia se a FAIXA existisse:
                     *
                     *     sem elemento -> sem assinatura -> sem faixa -> sem palco -> sem elemento
                     *
                     * Quem compartilhava via o proprio palco (faixa local nao precisa de
                     * assinatura) e jurava que estava tudo certo. Os outros nunca viam nada, e
                     * nao havia erro em log nenhum para acusar.
                     *
                     * A PUBLICACAO aparece assim que o outro lado publica, independente de
                     * assinatura. Ela e a resposta certa para "alguem esta apresentando?", e a
                     * faixa e so o que se pendura quando chega. O palco nasce com a publicacao,
                     * o elemento nasce com o palco, a assinatura vem por causa do elemento, e o
                     * x-effect pendura a imagem quando o TrackSubscribed redesenhar.
                     */
                    const publicacaoDaTela = p.getTrackPublication(Track.Source.ScreenShare);
                    const tela = publicacaoDaTela?.track ?? null;
                    const audio = souEu
                        ? null
                        : p.getTrackPublication(Track.Source.Microphone)?.track ?? null;

                    faixas.set(p.identity, { video, tela, audio });

                    const sinal = this.sinais[p.identity] ?? {};

                    return {
                        id: p.identity,
                        nome: p.name || (souEu ? 'Você' : 'Convidado'),
                        souEu,
                        falando: p.isSpeaking,
                        semSom: ! p.isMicrophoneEnabled,
                        // SO a camera. A tela nao entra aqui: ela tem palco proprio agora, e
                        // quem compartilha continua com o rosto na fita como todo mundo.
                        temImagem: Boolean(video),
                        // Sem o "! tela": o espelho e da camera, e a camera continua sendo a
                        // camera enquanto a pessoa compartilha.
                        espelhar: souEu && this.ehCameraFrontal(video),
                        // A publicacao, e nao a faixa: ver o comentario da trava circular
                        // acima. Trocar isto de volta apaga o palco de todo mundo menos de
                        // quem compartilha — e sem sintoma nenhum do lado de dentro.
                        apresentando: Boolean(publicacaoDaTela),
                        mao: Boolean(sinal.mao),
                    };
                };

                this.pessoas = [
                    monta(sala.localParticipant, true),
                    ...Array.from(sala.remoteParticipants.values()).map((p) => monta(p, false)),
                ];

                /*
                 * QUEM OCUPA O PALCO.
                 *
                 * Com dois compartilhando ao mesmo tempo — raro, mas acontece — ganha o
                 * OUTRO, e nao eu: a minha tela eu ja estou vendo no meu monitor; a dele e a
                 * unica que eu nao teria como ver de outro jeito.
                 */
                const apresentando = this.pessoas.filter((p) => p.apresentando);
                const dono = apresentando.find((p) => ! p.souEu) ?? apresentando[0] ?? null;

                this.apresentador = dono
                    ? { id: dono.id, nome: dono.nome, souEu: dono.souEu }
                    : null;

                // A verdade sobre os proprios botoes mora no servidor de midia, nao aqui.
                this.microfone = sala.localParticipant.isMicrophoneEnabled;
                this.camera = sala.localParticipant.isCameraEnabled;
            },

            /**
             * A propria imagem vai ESPELHADA, como num espelho de banheiro.
             *
             * Nao e enfeite: e o que todo mundo espera. A pessoa passa a vida se vendo
             * espelhada, entao a imagem crua da camera frontal — que e a que o outro lado ve —
             * parece torta para ela, e ela passa a reuniao tentando entender o que esta
             * errado. Levantar a mao direita e ver a da esquerda subir e desconcertante.
             *
             * SO NA PROPRIA, E SO NA CAMERA. Espelhar o outro lado seria mentir sobre uma
             * pessoa de verdade, e espelhar tela compartilhada deixaria todo texto de tras
             * para frente.
             *
             * E CAMERA TRASEIRA NAO ESPELHA: ela mostra o mundo, e nao o rosto de quem segura.
             * Quem aponta o celular para o equipamento com defeito precisa ver o numero de
             * serie legivel.
             *
             * O espelho e so na tela de quem esta olhando. O que sai na chamada nunca foi
             * espelhado.
             */
            ehCameraFrontal(video) {
                if (! video) return false;

                const config = video.mediaStreamTrack?.getSettings?.() ?? {};

                // Webcam de computador nao informa o lado, e e sempre frontal.
                return config.facingMode !== 'environment';
            },

            /**
             * Liga a imagem ao elemento dela.
             *
             * Chamado por x-effect, e nao por x-init: o quadro da pessoa continua o MESMO
             * elemento quando ela liga e desliga a camera, entao algo que roda so no nascimento
             * deixaria a tela congelada na ultima imagem.
             *
             * SO A CAMERA. Antes a tela compartilhada era plugada aqui, na frente da camera, e
             * o efeito colateral era o dono da tela desaparecer da reuniao. A tela tem palco
             * proprio agora — plugarTela abaixo.
             */
            plugarVideo(el, p) {
                const imagem = faixas.get(p.id)?.video;

                if (imagem) {
                    imagem.attach(el);
                } else {
                    el.srcObject = null;
                }
            },

            /**
             * A tela compartilhada, no palco.
             *
             * object-contain no elemento, e nao object-cover como nos rostos: recortar rosto
             * pelas beiradas nao custa nada, recortar tela come justamente a barra de menu e a
             * primeira coluna da planilha que a pessoa esta tentando mostrar.
             */
            plugarTela(el) {
                const imagem = this.apresentador
                    ? faixas.get(this.apresentador.id)?.tela
                    : null;

                if (imagem) {
                    imagem.attach(el);
                } else {
                    el.srcObject = null;
                }
            },

            /**
             * O som dos outros.
             *
             * O proprio nunca: audio da propria camera voltando pela caixa vira microfonia na
             * hora, e quem descobre isso e a sala inteira.
             */
            plugarAudio(el, p) {
                if (p.souEu) return;

                faixas.get(p.id)?.audio?.attach(el);
            },

            // ---------------------------------------------------------------- controles

            /*
             * O ESTADO DOS BOTOES VEM DO SERVIDOR DE MIDIA, e nao de uma variavel nossa.
             *
             * Desde que o anfitriao pode calar o microfone de alguem, a nossa variavel deixou
             * de ser a verdade: a pessoa seria calada e o botao continuaria dizendo "Microfone
             * ligado". Botao que mente sobre microfone e a origem do "achei que voces estavam
             * me ouvindo".
             */
            async alternarMicrofone() {
                if (! sala) return;

                await sala.localParticipant.setMicrophoneEnabled(! sala.localParticipant.isMicrophoneEnabled);
                this.recontar();
            },

            async alternarCamera() {
                if (! sala) return;

                await sala.localParticipant.setCameraEnabled(! sala.localParticipant.isCameraEnabled);
                this.recontar();
            },

            async alternarTela() {
                if (! sala) return;

                try {
                    if (this.compartilhando) {
                        await sala.localParticipant.setScreenShareEnabled(false);
                        this.compartilhando = false;
                    } else {
                        // Criar as faixas antes de publicar: assim o cancelamento na caixa do
                        // navegador — que e o caminho mais comum — nao deixa o botao aceso
                        // mentindo que esta compartilhando.
                        const novas = await createLocalScreenTracks({ audio: false });

                        /*
                         * A fonte declarada de proposito. E o jeito documentado de publicar, em
                         * vez de deixar o SDK inferir do objeto da faixa.
                         *
                         * REGISTRO HONESTO: eu troquei isto achando que era a causa do palco
                         * nao aparecer para os outros, e nao era — a fonte atravessava certo.
                         * A causa era a trava circular do adaptiveStream, explicada no
                         * recontar(). Ficou porque declarar e melhor que inferir, nao porque
                         * consertou algo.
                         */
                        await Promise.all(novas.map((f) => sala.localParticipant.publishTrack(f, {
                            source: Track.Source.ScreenShare,
                        })));

                        this.compartilhando = true;
                    }
                } catch (e) {
                    this.compartilhando = false;
                }

                this.recontar();
            },

            /**
             * Fotografia das publicacoes de midia. NAO e usada pela tela.
             *
             * Existe porque esta sala e a parte mais difícil do sistema de depurar: o objeto do
             * LiveKit mora fora do Alpine de proposito (Proxy nao pode ser clonado), e sem isto
             * a unica forma de saber o que foi publicado e o que foi assinado e adivinhar pelo
             * que aparece na tela — que e exatamente o que fez eu perseguir tres causas erradas
             * para o compartilhamento de tela.
             *
             * Do console do navegador, dentro da sala:
             *   $el._x_dataStack[0].diagnostico()
             */
            diagnostico() {
                if (! sala) return null;

                const listar = (p) => Array.from(p.trackPublications.values()).map((t) => ({
                    fonte: t.source,
                    tipo: t.kind,
                    sid: t.trackSid,
                    temFaixa: Boolean(t.track),
                    assinada: t.isSubscribed ?? null,
                    silenciada: t.isMuted,
                }));

                return {
                    eu: listar(sala.localParticipant),
                    outros: Array.from(sala.remoteParticipants.values()).map((p) => ({
                        nome: p.name,
                        faixas: listar(p),
                    })),
                };
            },

            sair() {
                this.desmontar();
                this.dentro = false;
                this.saiu = true;
            },

            desmontar() {
                try {
                    sala?.disconnect();
                } catch (e) {
                    // ja estava fora
                }

                sala = null;
                faixas.clear();
                this.pessoas = [];
            },

            /** Aba fechada tem de sair da sala, senao a pessoa fica de fantasma no lugar dela. */
            init() {
                window.addEventListener('beforeunload', () => this.desmontar());

                // Headset plugado no meio da reuniao precisa aparecer na lista sem recarregar:
                // e exatamente quando a pessoa vai procurar por ele.
                navigator.mediaDevices?.addEventListener?.('devicechange', () => this.listarDispositivos());
            },

            get colunas() {
                const n = this.pessoas.length;

                if (n <= 1) return 'grid-cols-1';
                if (n <= 4) return 'grid-cols-1 sm:grid-cols-2';
                if (n <= 9) return 'grid-cols-2 sm:grid-cols-3';

                return 'grid-cols-2 sm:grid-cols-4';
            },
        };
    });
});
