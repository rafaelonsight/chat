//
// A sala de video.
//
// SEPARADO DO app.js DE PROPOSITO. O cliente do LiveKit sao centenas de kilobytes, e ele so
// serve numa tela do sistema inteiro. Pendurado no pacote principal, ele seria baixado por
// todo atendente em toda pagina do atendimento — que e justamente a tela que precisa abrir
// rapido.
//
// A CAMERA E O MICROFONE SAO PEDIDOS UMA VEZ, no clique de entrar. Navegador so libera
// dispositivo depois de um gesto, e permissao negada nao se pede de novo: por isso a tela
// pergunta o nome antes, e o botao de entrar e o gesto.

import { Room, RoomEvent, Track, createLocalScreenTracks } from 'livekit-client';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('salaDeVideo', (config) => ({
        sala: null,

        // estado da tela
        entrando: false,
        dentro: false,
        erro: '',
        saiu: false,

        // controles
        microfone: true,
        camera: true,
        compartilhando: false,
        telaCompartilhada: null,

        // quem esta aqui
        pessoas: [],

        async entrar(token, url) {
            if (this.entrando || this.dentro) return;

            this.entrando = true;
            this.erro = '';

            try {
                this.sala = new Room({
                    adaptiveStream: true,
                    // Sobe e desce a qualidade sozinho conforme a banda de cada um. Sem isto,
                    // um participante em 4G derruba a chamada para todos os outros.
                    dynacast: true,
                });

                this.ouvir();

                await this.sala.connect(url, token);

                // Camera e microfone juntos, num pedido so: dois pedidos seguidos viram duas
                // caixas de permissao e a segunda quase sempre e negada por reflexo.
                await this.sala.localParticipant.enableCameraAndMicrophone();

                this.dentro = true;
                this.recontar();
            } catch (e) {
                this.erro = this.explicar(e);
                this.desmontar();
            } finally {
                this.entrando = false;
            }
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

            if (nome === 'NotAllowedError') {
                return 'Você precisa liberar a câmera e o microfone para o navegador. Toque no cadeado ao lado do endereço e permita.';
            }

            if (nome === 'NotFoundError' || nome === 'DevicesNotFoundError') {
                return 'Não encontramos câmera nem microfone neste aparelho.';
            }

            if (nome === 'NotReadableError') {
                return 'A câmera parece estar em uso por outro programa. Feche os outros aplicativos e tente de novo.';
            }

            return 'Não foi possível entrar na sala. Verifique sua conexão e tente de novo.';
        },

        ouvir() {
            const s = this.sala;

            s.on(RoomEvent.TrackSubscribed, () => this.recontar())
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
         */
        recontar() {
            const s = this.sala;
            if (! s) return;

            const eu = s.localParticipant;

            const monta = (p, souEu) => ({
                id: p.identity,
                nome: p.name || (souEu ? 'Você' : 'Convidado'),
                souEu,
                falando: p.isSpeaking,
                semSom: ! p.isMicrophoneEnabled,
                video: p.getTrackPublication(Track.Source.Camera)?.track ?? null,
                tela: p.getTrackPublication(Track.Source.ScreenShare)?.track ?? null,
                audio: souEu ? null : p.getTrackPublication(Track.Source.Microphone)?.track ?? null,
            });

            this.pessoas = [
                monta(eu, true),
                ...Array.from(s.remoteParticipants.values()).map((p) => monta(p, false)),
            ];
        },

        /**
         * Liga a imagem ao elemento dela.
         *
         * Chamado por x-effect, e nao por x-init: o quadro da pessoa continua o MESMO elemento
         * quando ela liga e desliga a camera, entao algo que roda so no nascimento deixaria a
         * tela congelada na ultima imagem. Tela compartilhada ganha a frente da camera —
         * quem compartilha esta mostrando alguma coisa, e o rosto dele nao e o assunto.
         */
        plugarVideo(el, p) {
            const faixa = p.tela ?? p.video;

            if (faixa) {
                faixa.attach(el);
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
            if (! p.souEu && p.audio) p.audio.attach(el);
        },

        async alternarMicrofone() {
            this.microfone = ! this.microfone;
            await this.sala?.localParticipant.setMicrophoneEnabled(this.microfone);
            this.recontar();
        },

        async alternarCamera() {
            this.camera = ! this.camera;
            await this.sala?.localParticipant.setCameraEnabled(this.camera);
            this.recontar();
        },

        async alternarTela() {
            if (! this.sala) return;

            try {
                if (this.compartilhando) {
                    await this.sala.localParticipant.setScreenShareEnabled(false);
                    this.compartilhando = false;
                } else {
                    // createLocalScreenTracks antes de publicar: assim o cancelamento na caixa
                    // do navegador — que e o caminho mais comum — nao deixa o botao aceso
                    // mentindo que esta compartilhando.
                    const faixas = await createLocalScreenTracks({ audio: false });
                    await Promise.all(faixas.map((f) => this.sala.localParticipant.publishTrack(f)));
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
                this.sala?.disconnect();
            } catch (e) {
                // ja estava fora
            }

            this.sala = null;
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
    }));
});
