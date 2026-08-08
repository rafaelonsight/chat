(function () {
  'use strict';

  /*
    O CHAT DO SITE.

    Um arquivo, sem dependencia nenhuma. Ele vai rodar dentro do site de outra pessoa, e site
    de outra pessoa ja tem jQuery de 2011, um framework qualquer e CSS que renomeia .btn — nada
    aqui pode depender do que existe la, nem atrapalhar o que existe la.

    POR ISSO SHADOW DOM. O CSS do site nao entra e o nosso nao vaza. Sem isso, a primeira
    pagina com "* { box-sizing: content-box }" quebra o widget, e o dono do site culpa o chat.
  */

  var script = document.currentScript;
  if (! script) return;

  var chave = script.getAttribute('data-chave');
  if (! chave) return;

  var base = new URL(script.src).origin;
  var CHAVE_LOCAL = 'virtus.site.' + chave;

  // A identidade do visitante mora no navegador dele. Quem volta amanha cai na mesma conversa.
  var token = null;
  try { token = localStorage.getItem(CHAVE_LOCAL); } catch (e) {}

  var ultimoId = 0;
  var aberto = false;
  var sondagem = null;
  var naoLidas = 0;

  // ------------------------------------------------------------------ a casca

  var raiz = document.createElement('div');
  raiz.setAttribute('data-virtus-chat', '');
  raiz.style.cssText = 'position:fixed;z-index:2147483000;right:0;bottom:0;';
  var sombra = raiz.attachShadow ? raiz.attachShadow({ mode: 'open' }) : raiz;

  var css = document.createElement('style');
  css.textContent = [
    ':host,*{box-sizing:border-box}',
    '.bolha{position:fixed;right:20px;bottom:20px;width:60px;height:60px;border-radius:999px;border:0;cursor:pointer;',
      'background:#f5b301;color:#1a1a17;display:grid;place-items:center;box-shadow:0 10px 30px rgba(0,0,0,.28);',
      'transition:transform .2s cubic-bezier(.16,1,.3,1)}',
    '.bolha:hover{transform:translateY(-2px)}',
    '.bolha:focus-visible{outline:3px solid #f5b301;outline-offset:3px}',
    '.selo{position:absolute;top:-2px;right:-2px;min-width:22px;height:22px;padding:0 6px;border-radius:999px;',
      'background:#dc2626;color:#fff;font:700 11px/22px system-ui,sans-serif;text-align:center}',
    '.painel{position:fixed;right:20px;bottom:92px;width:360px;max-width:calc(100vw - 32px);height:520px;',
      'max-height:calc(100dvh - 120px);display:flex;flex-direction:column;border-radius:16px;overflow:hidden;',
      'background:#12151c;color:#f5f5f4;box-shadow:0 24px 60px rgba(0,0,0,.4);',
      'font:400 14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}',
    '@media (max-width:480px){.painel{right:8px;left:8px;bottom:88px;width:auto;height:calc(100dvh - 110px)}}',
    '.topo{display:flex;align-items:center;gap:10px;padding:12px 14px;background:#1a1e27;border-bottom:1px solid rgba(255,255,255,.08)}',
    '.topo b{font-size:14px;font-weight:600}',
    '.topo small{display:block;color:#a1a1aa;font-size:11px}',
    '.fechar{margin-left:auto;background:none;border:0;color:#a1a1aa;font-size:20px;cursor:pointer;line-height:1;padding:4px 6px}',
    '.corpo{flex:1;min-height:0;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px}',
    '.b{max-width:84%;padding:8px 11px;border-radius:13px;font-size:13.5px;word-wrap:break-word;overflow-wrap:anywhere}',
    '.b.eu{align-self:flex-end;background:#f5b301;color:#1a1a17;border-bottom-right-radius:4px}',
    '.b.eles{align-self:flex-start;background:#252a35;border-bottom-left-radius:4px}',
    '.b time{display:block;margin-top:3px;font-size:10px;opacity:.6}',
    '.vazio{color:#a1a1aa;font-size:13px;text-align:center;margin:auto;padding:0 12px}',
    '.pe{padding:10px;border-top:1px solid rgba(255,255,255,.08);display:flex;gap:8px;align-items:flex-end}',
    'textarea{flex:1;min-width:0;resize:none;border-radius:10px;border:1px solid rgba(255,255,255,.14);',
      'background:#1a1e27;color:#f5f5f4;padding:10px;font:inherit;font-size:14px;max-height:96px}',
    'textarea:focus{outline:2px solid #f5b301;outline-offset:-1px}',
    'input{width:100%;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#1a1e27;color:#f5f5f4;',
      'padding:10px;font:inherit;font-size:14px}',
    '.enviar{border:0;border-radius:10px;background:#f5b301;color:#1a1a17;font:600 13px/1 system-ui,sans-serif;',
      'padding:0 14px;height:40px;cursor:pointer;flex:none}',
    '.enviar:disabled{opacity:.5;cursor:default}',
    '.aviso{margin:0;padding:8px 14px;background:rgba(245,179,1,.12);color:#fbbf24;font-size:12px}',
    '.creditos{padding:6px 14px 10px;font-size:10.5px;color:#71717a;text-align:center}',
    '.creditos a{color:#a1a1aa;text-decoration:none}',
  ].join('');
  sombra.appendChild(css);

  function el(tag, attrs, texto) {
    var n = document.createElement(tag);
    for (var k in attrs) { if (k === 'class') n.className = attrs[k]; else n.setAttribute(k, attrs[k]); }
    if (texto != null) n.textContent = texto;
    return n;
  }

  // ------------------------------------------------------------------ a bolha

  var bolha = el('button', { class: 'bolha', type: 'button', 'aria-label': 'Abrir o chat' });
  bolha.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM21 12c0 4.556-4.03 8.25-9 8.25a9.76 9.76 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>';
  var selo = el('span', { class: 'selo' }, '');
  selo.style.display = 'none';
  bolha.appendChild(selo);
  sombra.appendChild(bolha);

  // ------------------------------------------------------------------ o painel

  var painel = el('div', { class: 'painel', role: 'dialog', 'aria-label': 'Conversa' });
  painel.style.display = 'none';

  var topo = el('div', { class: 'topo' });
  var quem = el('div');
  quem.appendChild(el('b', {}, '{{ config('app.name') }}'));
  quem.appendChild(el('small', {}, 'Costumamos responder rápido'));
  topo.appendChild(quem);
  var fechar = el('button', { class: 'fechar', type: 'button', 'aria-label': 'Fechar' }, '×');
  topo.appendChild(fechar);
  painel.appendChild(topo);

  var aviso = el('p', { class: 'aviso' }, '');
  aviso.style.display = 'none';
  painel.appendChild(aviso);

  var corpo = el('div', { class: 'corpo' });
  painel.appendChild(corpo);

  var pe = el('div', { class: 'pe' });
  var campoNome = el('input', { type: 'text', placeholder: 'Seu nome', maxlength: '60', 'aria-label': 'Seu nome' });
  var caixa = el('textarea', { rows: '1', placeholder: 'Escreva sua mensagem…', maxlength: '1500', 'aria-label': 'Mensagem' });
  var enviar = el('button', { class: 'enviar', type: 'button' }, 'Enviar');
  pe.appendChild(caixa);
  pe.appendChild(enviar);

  // O nome so e pedido enquanto ninguem falou: perguntar de novo a quem ja se apresentou e
  // fazer a pessoa repetir o que ela acabou de dizer.
  var linhaNome = el('div', { class: 'pe' });
  linhaNome.style.borderTop = '0';
  linhaNome.style.paddingBottom = '0';
  linhaNome.appendChild(campoNome);
  painel.appendChild(linhaNome);
  painel.appendChild(pe);

  var creditos = el('p', { class: 'creditos' });
  creditos.innerHTML = 'Conversa por <a href="{{ url('/') }}" target="_blank" rel="noopener">{{ config('app.name') }}</a>';
  painel.appendChild(creditos);

  sombra.appendChild(painel);

  // ------------------------------------------------------------------- a rede

  function pedir(caminho, dados, metodo) {
    var url = base + '/chat-do-site/' + encodeURIComponent(chave) + caminho;

    return fetch(url, {
      method: metodo || 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: metodo === 'GET' ? undefined : JSON.stringify(dados || {}),
    }).then(function (r) { return r.json().catch(function () { return {}; }); });
  }

  function guardarToken(t) {
    if (! t || t === token) return;
    token = t;
    try { localStorage.setItem(CHAVE_LOCAL, t); } catch (e) {}
  }

  function desenhar(mensagens) {
    if (! mensagens || ! mensagens.length) return;

    var vazio = sombra.querySelector('.vazio');
    if (vazio) vazio.remove();

    mensagens.forEach(function (m) {
      if (m.id <= ultimoId) return;
      ultimoId = m.id;

      var b = el('div', { class: 'b ' + (m.de === 'visitante' ? 'eu' : 'eles') }, m.corpo);
      b.appendChild(el('time', {}, m.hora));
      corpo.appendChild(b);

      if (m.de !== 'visitante' && ! aberto) {
        naoLidas++;
        selo.textContent = naoLidas > 9 ? '9+' : String(naoLidas);
        selo.style.display = '';
      }
    });

    corpo.scrollTop = corpo.scrollHeight;
  }

  function olhar() {
    if (! token) return;

    pedir('/mensagens?token=' + encodeURIComponent(token) + '&desde=' + ultimoId, null, 'GET')
      .then(function (r) { desenhar(r.mensagens); })
      .catch(function () {});
  }

  /*
    A SONDAGEM ACOMPANHA A ATENCAO DA PESSOA.

    Aberto, de tres em tres segundos: ela esta olhando e esperando resposta. Fechado, de vinte
    em vinte: so para o numerinho aparecer. Aba escondida, nada — sondar em segundo plano gasta
    bateria de celular para atualizar uma tela que ninguem esta vendo.
  */
  function ritmo() {
    if (sondagem) clearInterval(sondagem);
    if (! token) return;

    sondagem = setInterval(function () {
      if (document.hidden) return;
      olhar();
    }, aberto ? 3000 : 20000);
  }

  function abrir() {
    aberto = true;
    painel.style.display = 'flex';
    bolha.setAttribute('aria-label', 'Fechar o chat');
    naoLidas = 0;
    selo.style.display = 'none';

    pedir('/abrir', { token: token, nome: campoNome.value })
      .then(function (r) {
        guardarToken(r.token);

        if (! r.mensagens || ! r.mensagens.length) {
          if (! sombra.querySelector('.vazio')) {
            corpo.appendChild(el('div', { class: 'vazio' },
              r.saudacao || 'Manda sua dúvida por aqui. Alguém responde já já.'));
          }
        }

        desenhar(r.mensagens);
        linhaNome.style.display = ultimoId > 0 ? 'none' : '';
        ritmo();
      })
      .catch(function () {
        aviso.textContent = 'Não consegui abrir a conversa agora. Tente de novo em instantes.';
        aviso.style.display = '';
      });

    setTimeout(function () { caixa.focus(); }, 60);
  }

  function esconder() {
    aberto = false;
    painel.style.display = 'none';
    bolha.setAttribute('aria-label', 'Abrir o chat');
    ritmo();
  }

  function mandar() {
    var texto = caixa.value.trim();
    if (! texto) return;

    enviar.disabled = true;
    caixa.value = '';
    aviso.style.display = 'none';

    pedir('/mandar', { token: token, corpo: texto, nome: campoNome.value, desde: ultimoId })
      .then(function (r) {
        enviar.disabled = false;

        if (r.erro) {
          aviso.textContent = r.erro;
          aviso.style.display = '';
          caixa.value = texto;   // devolve o que a pessoa escreveu: perder texto e imperdoavel
          return;
        }

        guardarToken(r.token);
        desenhar(r.mensagens);
        linhaNome.style.display = 'none';
        ritmo();
      })
      .catch(function () {
        enviar.disabled = false;
        caixa.value = texto;
        aviso.textContent = 'Sua mensagem não saiu. Verifique a conexão e tente de novo.';
        aviso.style.display = '';
      });
  }

  bolha.addEventListener('click', function () { aberto ? esconder() : abrir(); });
  fechar.addEventListener('click', esconder);
  enviar.addEventListener('click', mandar);

  // Enter manda, Shift+Enter pula linha: e o que o dedo de quem usa WhatsApp ja espera.
  caixa.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && ! e.shiftKey) { e.preventDefault(); mandar(); }
  });

  document.addEventListener('visibilitychange', function () { if (! document.hidden) olhar(); });

  (document.body || document.documentElement).appendChild(raiz);

  // Quem ja conversou antes comeca a sondar sem abrir nada: se o atendente respondeu depois que
  // a pessoa fechou a aba, o numerinho tem de estar la quando ela voltar.
  if (token) { ritmo(); olhar(); }
})();
