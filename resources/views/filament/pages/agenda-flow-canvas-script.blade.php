    @script
    <script>
        window.agendaFlowCanvas = (desenho) => ({
            escala: 1,
            pan: { x: 40, y: 20 },
            linhas: {},
            quadroMorph: null,
            raiz: null,
            ligando: null,
            puxando: false,
            sobreCartao: null,
            fantasma: '',
            desenho,

            init() {
                // Alpine $el follows the invoking element; geometry always needs the canvas root.
                this.raiz = this.$el;
                this.$nextTick(() => this.recalcular());

                // Depois de cada atualizacao do Livewire os cartoes podem ter mudado
                // de tamanho (acao adicionada, saida nova). Sem redesenhar aqui, as
                // linhas ficam apontando para onde as saidas estavam antes.
                this.desligarHook = Livewire.hook('morph.updated', ({ el }) => {
                    if (el !== this.raiz && ! this.raiz.contains(el)) return;
                    if(this.quadroMorph !== null)return;
                    this.quadroMorph=requestAnimationFrame(() => {
                        this.quadroMorph=null;
                        this.desenho=JSON.parse(this.raiz.dataset.desenho || '[]');
                        this.recalcular();
                    });
                });

                this.aoRedimensionar = () => this.recalcular();
                window.addEventListener('resize', this.aoRedimensionar);
            },

            destroy() { if(this.quadroMorph!==null)cancelAnimationFrame(this.quadroMorph);this.cancelarArraste?.();this.desligarHook?.(); window.removeEventListener('resize', this.aoRedimensionar); },

            // -------------------------------------------------------------- geometria

            /**
             * As coordenadas saem do LAYOUT (offsetTop/offsetWidth), nao de constantes
             * de altura. Chutar a altura funciona ate alguem mexer num padding.
             */
            recalcular() {
                const linhas = {};

                this.raiz.querySelectorAll('[data-handle-row]').forEach((linha) => {
                    const deId = linha.dataset.from;
                    const handle = linha.dataset.handle;

                    const cartaoDe = this.raiz.querySelector(`[data-step="${deId}"]`);
                    if (! cartaoDe) return;

                    const destinoId = this.destinoDe(deId, handle);
                    if (! destinoId) return;

                    const cartaoPara = this.raiz.querySelector(`[data-step="${destinoId}"]`);
                    if (! cartaoPara) return;

                    const bola = linha.querySelector('button:last-child');
                    if (! bola) return;

                    const x1 = this.coordX(cartaoDe) + cartaoDe.offsetWidth;
                    const y1 = this.centroY(bola);

                    const ancora = cartaoPara.querySelector('[data-ancora-entrada]');
                    const x2 = this.coordX(cartaoPara);
                    const y2 = ancora ? this.centroY(ancora) : this.coordY(cartaoPara) + 20;

                    linhas[`${deId}:${handle}`] = this.curva(x1, y1, x2, y2);
                });

                this.linhas = linhas;
                this.raiz.querySelectorAll('path[data-edge]').forEach(path=>{
                    const d=linhas[path.dataset.edge];if(d)path.setAttribute('d',d);
                });
            },

            destinoDe(deId, handle) {
                const passo = this.desenho.find((p) => String(p.id) === String(deId));
                return passo ? (passo.saidas?.[handle] ?? null) : null;
            },

            centroY(el) { const r = el.getBoundingClientRect(); return this.paraMundo(r.left, r.top + r.height / 2).y; },

            coordX(el) { return parseFloat(el.dataset.x || 0); },
            coordY(el) { return parseFloat(el.dataset.y || 0); },

            curva(x1, y1, x2, y2) {
                // Alca proporcional a distancia: curva suave de perto e de longe.
                const alca = Math.max(40, Math.min(160, Math.abs(x2 - x1) * 0.5));
                return `M ${x1},${y1} C ${x1 + alca},${y1} ${x2 - alca},${y2} ${x2},${y2}`;
            },

            // ---------------------------------------------------------------- arraste

            iniciarArraste(e, id) {
                if (e.button !== 0 || this.ligando) return;

                const cartao = e.currentTarget.closest('[data-step]');
                const iniX = e.clientX, iniY = e.clientY;
                const origX = this.coordX(cartao), origY = this.coordY(cartao);
                let moveu=false, quadro=null, pendente=null;
                // Measure connections once before moving. Dragging changes position only,
                // so anchors keep their offsets inside each card without layout reads per event.
                const conexoes=[];
                this.raiz.querySelectorAll('[data-handle-row]').forEach((linha)=>{
                    const deId=linha.dataset.from, handle=linha.dataset.handle;
                    const paraId=this.destinoDe(deId,handle);
                    if(String(deId)!==String(id) && String(paraId)!==String(id))return;
                    const de=this.raiz.querySelector(`[data-step="${deId}"]`);
                    const para=this.raiz.querySelector(`[data-step="${paraId}"]`);
                    const bola=linha.querySelector('button:last-child');
                    if(!de || !para || !bola)return;
                    const ancora=para.querySelector('[data-ancora-entrada]');
                    conexoes.push({chave:`${deId}:${handle}`,de,para,largura:de.offsetWidth,
                        offsetDe:this.centroY(bola)-this.coordY(de),
                        offsetPara:ancora?this.centroY(ancora)-this.coordY(para):20,
                        paths:[...this.raiz.querySelectorAll('path[data-edge]')].filter(p=>p.dataset.edge===`${deId}:${handle}`)});
                });
                const desenhar=()=>{
                    quadro=null;if(!pendente)return;
                    const {nx,ny}=pendente;pendente=null;
                    cartao.dataset.x=nx;cartao.dataset.y=ny;
                    cartao.style.left=nx+'px';cartao.style.top=ny+'px';
                    const atualizadas={...this.linhas};
                    conexoes.forEach(c=>{
                        const d=this.curva(this.coordX(c.de)+c.largura,this.coordY(c.de)+c.offsetDe,
                            this.coordX(c.para),this.coordY(c.para)+c.offsetPara);
                        atualizadas[c.chave]=d;
                        c.paths.forEach(path=>path.setAttribute('d',d));
                    });
                    this.linhas=atualizadas;
                };
                const mover=(ev)=>{
                    const nx=Math.max(0,Math.round(origX+(ev.clientX-iniX)/this.escala));
                    const ny=Math.max(0,Math.round(origY+(ev.clientY-iniY)/this.escala));
                    if(Math.abs(nx-origX)>2 || Math.abs(ny-origY)>2)moveu=true;
                    pendente={nx,ny};if(quadro===null)quadro=requestAnimationFrame(desenhar);
                };
                const limpar=()=>{
                    if(quadro!==null)cancelAnimationFrame(quadro);
                    desenhar();window.removeEventListener('pointermove',mover,true);
                    window.removeEventListener('pointerup',soltar,true);
                    window.removeEventListener('pointercancel',soltar,true);
                    this.cancelarArraste=null;
                };
                const soltar=()=>{
                    limpar();this.recalcular();
                    if(moveu)$wire.moverPasso(id,parseInt(cartao.dataset.x),parseInt(cartao.dataset.y));
                };
                this.cancelarArraste=limpar;

                // Fase de captura (true): window recebe ANTES do alvo, entao nenhum
                // stopPropagation no caminho consegue engolir o fim do arraste.
                // pointercancel cobre o gesto abortado pelo navegador.
                window.addEventListener('pointermove', mover, true);
                window.addEventListener('pointerup', soltar, true);
                window.addEventListener('pointercancel', soltar, true);
            },

            // -------------------------------------------------------------------- pan

            iniciarPan(e) {
                // So no fundo: pointerdown no cartao ja foi parado com .stop
                if (e.target !== this.$refs.viewport && e.target !== this.$refs.mundo) return;
                if (e.button !== 0) return;

                const iniX = e.clientX, iniY = e.clientY;
                const origem = { ...this.pan };

                const mover = (ev) => {
                    this.pan = { x: origem.x + (ev.clientX - iniX), y: origem.y + (ev.clientY - iniY) };
                };
                const soltar = () => {
                    window.removeEventListener('pointermove', mover, true);
                    window.removeEventListener('pointerup', soltar, true);
                    window.removeEventListener('pointercancel', soltar, true);
                };

                window.addEventListener('pointermove', mover, true);
                window.addEventListener('pointerup', soltar, true);
                window.addEventListener('pointercancel', soltar, true);
            },

            rolar(e) {
                if (e.ctrlKey || e.metaKey) {
                    this.ajustarZoom(e.deltaY > 0 ? -0.08 : 0.08);
                    return;
                }

                this.pan = { x: this.pan.x - e.deltaX, y: this.pan.y - e.deltaY };
            },

            ajustarZoom(delta) {
                this.escala = Math.min(1.6, Math.max(0.15, Math.round((this.escala + delta) * 100) / 100));
            },

            enquadrar() {
                const cartoes = [...this.raiz.querySelectorAll('[data-step]')];
                if (! cartoes.length) return;

                const xs = cartoes.map((c) => this.coordX(c));
                const ys = cartoes.map((c) => this.coordY(c));
                const x2 = Math.max(...cartoes.map((c) => this.coordX(c) + c.offsetWidth));
                const y2 = Math.max(...cartoes.map((c) => this.coordY(c) + c.offsetHeight));

                const larg = this.$refs.viewport.clientWidth - 80;
                const alt = this.$refs.viewport.clientHeight - 80;

                this.escala = Math.min(1, Math.max(0.15,
                    Math.min(larg / (x2 - Math.min(...xs)), alt / (y2 - Math.min(...ys)))));

                this.pan = { x: 40 - Math.min(...xs) * this.escala, y: 40 - Math.min(...ys) * this.escala };
                this.$nextTick(() => this.recalcular());
            },

            // ---------------------------------------------------------------- ligacao

            /**
             * Puxa a linha com o mouse — o gesto que todo construtor de fluxo usa.
             *
             * Se soltar sem mover (um clique), a ligacao FICA armada e da para clicar
             * no destino. Arrastar e o caminho natural; clicar continua sendo o atalho
             * para tela de toque, mao tremida ou zoom muito reduzido.
             */
            puxarLigacao(e, de, handle) {
                this.ligando = { de, handle };
                this.puxando = true;

                // A origem sai do proprio botao apertado. Reconsultar por seletor
                // durante o gesto era fragil e devolvia nada — a linha nascia em 0,0.
                const bola = e.currentTarget;
                const cartaoOrigem = bola.closest('[data-step]');
                const origem = {
                    x: this.coordX(cartaoOrigem) + cartaoOrigem.offsetWidth,
                    y: this.centroY(bola),
                };

                let moveu = false;

                const mover = (ev) => {
                    moveu = true;
                    const q = this.paraMundo(ev.clientX, ev.clientY);
                    this.fantasma = this.curva(origem.x, origem.y, q.x, q.y);
                    this.sobreCartao = this.cartaoEm(ev.clientX, ev.clientY);
                };

                const soltar = (ev) => {
                    window.removeEventListener('pointermove', mover, true);
                    window.removeEventListener('pointerup', soltar, true);
                    window.removeEventListener('pointercancel', soltar, true);

                    this.puxando = false;
                    this.fantasma = '';
                    this.sobreCartao = null;

                    const alvo = this.cartaoEm(ev.clientX, ev.clientY);

                    if (alvo && alvo !== de) {
                        $wire.ligar(de, handle, alvo);
                        this.ligando = null;

                        return;
                    }

                    // Soltou no vazio: se nem chegou a mover, mantem armado para o
                    // modo clique. Se arrastou e errou, cancela — arrastar para fora
                    // e a forma natural de desistir.
                    if (moveu) {
                        this.ligando = null;
                    }
                };

                window.addEventListener('pointermove', mover, true);
                window.addEventListener('pointerup', soltar, true);
                window.addEventListener('pointercancel', soltar, true);
            },

            /** Centro da bolinha daquela saida, em coordenadas do canvas. */
            pontoDaSaida(de, handle) {
                const fila = this.raiz.querySelector(`[data-handle-row][data-from="${de}"][data-handle="${handle}"]`);
                const cartao = this.raiz.querySelector(`[data-step="${de}"]`);

                if (!fila || !cartao) return { x: 0, y: 0 };

                const bola = fila.querySelector('button:last-child');

                return {
                    x: this.coordX(cartao) + cartao.offsetWidth,
                    y: bola ? this.centroY(bola) : this.coordY(cartao),
                };
            },

            /** Tela -> canvas, descontando pan e zoom. */
            paraMundo(clientX, clientY) {
                const r = this.$refs.viewport.getBoundingClientRect();

                return {
                    x: (clientX - r.left - this.pan.x) / this.escala,
                    y: (clientY - r.top - this.pan.y) / this.escala,
                };
            },

            /** Qual cartao esta sob o cursor, se houver. */
            cartaoEm(clientX, clientY) {
                const el = document.elementFromPoint(clientX, clientY);
                const cartao = el ? el.closest('[data-step]') : null;

                return cartao ? cartao.dataset.step : null;
            },

            cancelarLigacao() {
                this.ligando = null;
                this.fantasma = '';
            },

            alvoClicado(id) {
                if (! this.ligando) return;

                if (this.ligando.de === id) {
                    // Ligar um passo a ele mesmo e laco infinito; o servico e o banco
                    // tambem recusam, mas avisar aqui evita a viagem.
                    this.cancelarLigacao();
                    return;
                }

                $wire.ligar(this.ligando.de, this.ligando.handle, id);
                this.cancelarLigacao();
            },

            novoPasso() {
                // Nasce no centro do que esta a vista, nao numa coordenada fixa: com
                // pan aplicado, coordenada fixa criaria o cartao fora da tela.
                let x = Math.round((this.$refs.viewport.clientWidth / 2 - this.pan.x) / this.escala) - 144;
                let y = Math.round((this.$refs.viewport.clientHeight / 2 - this.pan.y) / this.escala) - 60;

                // Desvia enquanto houver cartao no mesmo lugar: dois blocos exatamente
                // sobrepostos parecem UM, e o de baixo fica invisivel para sempre.
                const ocupado = (px, py) => [...this.raiz.querySelectorAll('[data-step]')]
                    .some((c) => Math.abs(this.coordX(c) - px) < 40 && Math.abs(this.coordY(c) - py) < 40);

                for (let i = 0; i < 12 && ocupado(x, y); i++) {
                    x += 48;
                    y += 48;
                }

                $wire.addAt(Math.max(0, x), Math.max(0, y));
            },
        });
    </script>
    @endscript