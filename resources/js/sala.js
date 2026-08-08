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

        return {
            // ---------------------------------------------------------- estado da tela
            entrando: false,
            dentro: false,
            saiu: false,
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

            /** So dado simples: o que a tela precisa desenhar, e nada de objeto de midia. */
            pessoas: [],

            async entrar(token, url) {
                if (this.entrando || this.dentro) return;

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
                    .on(RoomEvent.ParticipantConnected, () => this.recontar())
                    .on(RoomEvent.ParticipantDisconnected, () => this.recontar())
                    .on(RoomEvent.LocalTrackPublished, () => this.recontar())
                    .on(RoomEvent.LocalTrackUnpublished, () => this.recontar())
                    .on(RoomEvent.ActiveSpeakersChanged, () => this.recontar())
                    .on(RoomEvent.Disconnected, () => {
                        this.dentro = false;
                        this.saiu = true;
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
                    const tela = p.getTrackPublication(Track.Source.ScreenShare)?.track ?? null;
                    const audio = souEu
                        ? null
                        : p.getTrackPublication(Track.Source.Microphone)?.track ?? null;

                    faixas.set(p.identity, { video, tela, audio });

                    return {
                        id: p.identity,
                        nome: p.name || (souEu ? 'Você' : 'Convidado'),
                        souEu,
                        falando: p.isSpeaking,
                        semSom: ! p.isMicrophoneEnabled,
                        temImagem: Boolean(video || tela),
                        espelhar: souEu && ! tela && this.ehCameraFrontal(video),
                    };
                };

                this.pessoas = [
                    monta(sala.localParticipant, true),
                    ...Array.from(sala.remoteParticipants.values()).map((p) => monta(p, false)),
                ];
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
             * deixaria a tela congelada na ultima imagem. Tela compartilhada ganha a frente da
             * camera — quem compartilha esta mostrando alguma coisa, e o rosto dele nao e o
             * assunto.
             */
            plugarVideo(el, p) {
                const faixa = faixas.get(p.id);
                const imagem = faixa?.tela ?? faixa?.video;

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

            async alternarMicrofone() {
                this.microfone = ! this.microfone;
                await sala?.localParticipant.setMicrophoneEnabled(this.microfone);
                this.recontar();
            },

            async alternarCamera() {
                this.camera = ! this.camera;
                await sala?.localParticipant.setCameraEnabled(this.camera);
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

                        await Promise.all(novas.map((f) => sala.localParticipant.publishTrack(f)));

                        this.compartilhando = true;
                    }
                } catch (e) {
                    this.compartilhando = false;
                }

                this.recontar();
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
