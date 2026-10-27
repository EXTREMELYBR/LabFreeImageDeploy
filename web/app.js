/* LabFree ImageDeploy simplificado — customização local (não faz parte do FOG). */
'use strict';

// ================================================================ utilidades

const API = 'api.php';
const sessao = { usuario: null, token: null };
const estadoGlobal = { resumo: null, tela: null };
let temporizadores = [];
let geracao = 0; // muda a cada troca de tela: respostas e repetições da tela anterior são descartadas
const modaisAbertos = new Set();

function el(tag, attrs, ...filhos) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') n.className = v;
    else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
    else if (v === true) n.setAttribute(k, '');
    else n.setAttribute(k, v);
  }
  for (const f of filhos.flat(Infinity)) {
    if (f === null || f === undefined || f === false) continue;
    n.append(f instanceof Node ? f : document.createTextNode(String(f)));
  }
  return n;
}

// append/prepend/replaceChildren do navegador escrevem null como o texto "null"; aqui, como no el(),
// filhos vazios (null, undefined, false) são ignorados — as telas montam partes opcionais com "cond ? nó : null"
for (const m of ['append', 'prepend', 'replaceChildren']) {
  const original = Element.prototype[m];
  Element.prototype[m] = function (...filhos) {
    return original.apply(this, filhos.flat(Infinity).filter((f) => f !== null && f !== undefined && f !== false));
  };
}

const SVG = 'http://www.w3.org/2000/svg';
const ICONES = {
  inicio: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  maquinas: 'M4 4h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zM8 20h8M12 16v4',
  imagens: 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zM4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6',
  capturar: 'M12 15V3M7 8l5-5 5 5M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4',
  restaurar: 'M12 3v12M7 10l5 5 5-5M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4',
  grupo: 'M12 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM16.2 7.8a6 6 0 0 1 0 8.4M7.8 16.2a6 6 0 0 1 0-8.4M19 5a10 10 0 0 1 0 14M5 19A10 10 0 0 1 5 5',
  tarefas: 'M9 6h11M9 12h11M9 18h11M3.5 6 5 7.5 7.5 5M3.5 12 5 13.5 7.5 11M3.5 18 5 19.5 7.5 17',
  ajuda: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17.5v.01',
  mais: 'M12 5v14M5 12h14',
  ok: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 12l3 3 5-6',
  check: 'M5 12.5 10 17 19 7',
  alerta: 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17v.01',
  info: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v5M12 8v.01',
  x: 'M6 6l12 12M18 6 6 18',
  buscar: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
  seta: 'M5 12h14M13 6l6 6-6 6',
  voltar: 'M19 12H5M11 18l-6-6 6-6',
  pontos: 'M12 6.5v.01M12 12v.01M12 17.5v.01',
  sair: 'M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 16l4-4-4-4M14 12H4',
  externo: 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
  sol: 'M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4',
  lua: 'M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z',
  auto: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 3v18',
  lixo: 'M4 7h16M10 11v6M14 11v6M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13M9 7V4h6v3',
  editar: 'M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4',
  rede: 'M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M2 9a14.5 14.5 0 0 1 20 0M12 19.5v.01',
  disco: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
  relogio: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
  escudo: 'M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z',
  menu: 'M4 6h16M4 12h16M4 18h16',
  config: 'M4 7h10M18 7h2M4 17h4M12 17h8M16 5v4M10 15v4',
  usuarios: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1M16 3.5a4 4 0 0 1 0 7M22 21v-1a5 5 0 0 0-3.5-4.8',
  logo: 'M9 9h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V10a1 1 0 0 1 1-1zM5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1',
};
function icone(nome, classe = 'i') {
  const s = document.createElementNS(SVG, 'svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('fill', 'none');
  s.setAttribute('stroke', 'currentColor');
  s.setAttribute('stroke-width', nome === 'pontos' ? '3.2' : nome === 'logo' ? '2.1' : '1.9');
  s.setAttribute('stroke-linecap', 'round');
  s.setAttribute('stroke-linejoin', 'round');
  s.setAttribute('aria-hidden', 'true');
  if (classe) s.setAttribute('class', classe);
  const p = document.createElementNS(SVG, 'path');
  p.setAttribute('d', ICONES[nome] || '');
  s.append(p);
  return s;
}
const ladrilho = (ic, cor, tam = '') => el('span', { class: `ladrilho ${tam}`, 'data-cor': cor || null }, icone(ic));

function gb(bytes) {
  if (bytes === null || bytes === undefined) return '—';
  const v = bytes / 1e9;
  if (v >= 1000) return (v / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' TB';
  return v.toLocaleString('pt-BR', { maximumFractionDigits: v < 10 ? 2 : 1 }) + ' GB';
}
function dataHora(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function haQuanto(iso) {
  if (!iso) return '—';
  const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return 'agora mesmo';
  if (s < 3600) return `há ${Math.round(s / 60)} min`;
  if (s < 86400) return `há ${Math.round(s / 3600)} h`;
  const d = Math.round(s / 86400);
  return d === 1 ? 'ontem' : `há ${d} dias`;
}
function rotuloDisco(dev) {
  if (!dev) return '—';
  if (dev.startsWith('nvme')) return `NVMe (${dev})`;
  if (dev.startsWith('sd')) return `SATA / SSD (${dev})`;
  if (dev.startsWith('mmcblk')) return `eMMC (${dev})`;
  return dev;
}
const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

// ações que reconfiguram o servidor podem levar alguns minutos (testam e desfazem se falhar)
const PRAZOS = { rede_aplicar: 300000, dhcp_salvar: 120000, excluir_imagem: 120000, rede_simular: 60000, restaurar: 600000, capturar: 120000, multicast: 120000, limpar_historico: 120000 };

async function api(acao, dados, repetida = false) {
  const op = { credentials: 'same-origin', headers: {} };
  if (dados !== undefined) {
    op.method = 'POST';
    op.headers['Content-Type'] = 'application/json';
    op.headers['X-Painel-Token'] = sessao.token || '';
    op.body = JSON.stringify(dados);
  }
  const ctl = new AbortController();
  const prazo = setTimeout(() => ctl.abort(), PRAZOS[acao] || 30000);
  op.signal = ctl.signal;
  let r;
  let corpo = {};
  try {
    const [nome, extra] = acao.split('&', 2);
    r = await fetch(`${API}?a=${encodeURIComponent(nome)}${extra ? `&${extra}` : ''}`, op);
    try { corpo = await r.json(); } catch (e) { /* vazio */ }
  } catch (e) {
    throw new Error(ctl.signal.aborted ? 'O servidor demorou demais para responder. Confira em instantes se a ação foi feita.' : 'Sem comunicação com o servidor.');
  } finally { clearTimeout(prazo); }
  if (r.status === 428 && corpo.trocar_senha) {
    if (estadoGlobal.tela !== 'senha') telaPrimeiraSenha();
    throw new Error(corpo.erro || 'Defina uma senha nova para continuar.');
  }
  if (r.status === 401 && acao !== 'entrar') {
    sessao.usuario = null;
    if (estadoGlobal.tela !== 'login') telaLogin(corpo.erro);
    throw new Error(corpo.erro || 'Sessão expirada.');
  }
  // token trocado (login em outra aba): busca o token novo e tenta uma vez
  if (r.status === 403 && dados !== undefined && !repetida && /seguran/i.test(corpo.erro || '')) {
    try {
      const s = await api('sessao');
      if (s.logado && s.usuario !== sessao.usuario) { location.reload(); throw new Error('Outra pessoa entrou neste navegador. Recarregando…'); }
      if (s.logado) { sessao.token = s.token; return api(acao, dados, true); }
    } catch (e) { if (/Recarregando/.test(e.message)) throw e; }
  }
  if (!r.ok) throw new Error(corpo.erro || `Erro ${r.status}`);
  return corpo;
}
function fecharModais() { [...modaisAbertos].forEach((f) => f(null)); }

function aviso(msg, erro = false) {
  const n = el('div', { class: 'aviso-f' + (erro ? ' erro' : ''), role: erro ? 'alert' : 'status' }, icone(erro ? 'alerta' : 'ok'), el('div', {}, msg));
  document.getElementById('avisos').append(n);
  setTimeout(() => n.remove(), erro ? 9000 : 4500);
}

function modal({ titulo, texto, icone: ic, cor, conteudo, botoes }) {
  return new Promise((resolver) => {
    const anterior = document.activeElement;
    let ocupado = false; // com uma ação em andamento, Esc e clique fora não fecham (o resultado se perderia)
    const fechar = (v) => { modaisAbertos.delete(fechar); fundo.remove(); if (!modaisAbertos.size) document.getElementById('app').inert = false; document.removeEventListener('keydown', tecla); if (anterior && anterior.focus && anterior.isConnected) anterior.focus(); resolver(v); };
    const tecla = (e) => {
      if (e.key === 'Escape' && !ocupado) fechar(null);
      if (e.key === 'Tab') { // o foco não sai da janela
        const f = [...caixa.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea, a[href]')];
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
      }
    };
    const caixa = el('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': titulo },
      el('div', { class: 'modal-cab' }, ic ? ladrilho(ic, cor) : null, el('div', {}, el('h2', {}, titulo), texto ? el('p', {}, texto) : null)),
      conteudo || null,
      el('div', { class: 'botoes' }, botoes.map((b) => el('button', {
        type: 'button', class: 'btn ' + (b.classe || ''),
        onclick: async (e) => {
          if (!b.acao) return fechar(b.valor ?? null);
          const bt = e.currentTarget;
          if (ocupado) return;
          ocupado = true; bt.disabled = true;
          try { const r = await b.acao(); if (r !== false) return fechar(r ?? true); } catch (err) { aviso(err.message, true); }
          ocupado = false; bt.disabled = false;
        },
      }, b.texto))));
    const fundo = el('div', { class: 'fundo-modal', onclick: (e) => { if (e.target === fundo && !ocupado) fechar(null); } }, caixa);
    modaisAbertos.add(fechar);
    document.getElementById('app').inert = true;
    document.body.append(fundo);
    document.addEventListener('keydown', tecla);
    (caixa.querySelector('input:not([type=radio]):not([type=checkbox]), select, textarea') || caixa.querySelector('.botoes .btn:last-child')).focus();
  });
}
function confirmar(titulo, texto, rotulo = 'Confirmar', perigo = false) {
  return modal({ titulo, texto, icone: perigo ? 'alerta' : 'info', cor: perigo ? 'maquinas' : 'inicio',
    botoes: [{ texto: 'Voltar', classe: 'fantasma', valor: false }, { texto: rotulo, classe: perigo ? 'perigo-cheio' : 'primario', valor: true }] });
}

const campo = (rotulo, entrada, ajuda) => el('label', { class: 'campo' }, el('span', { class: 'rot' }, rotulo), entrada, ajuda ? el('span', { class: 'ajuda' }, ajuda) : null);
const pilula = (texto, tipo = '', vivo = false) => el('span', { class: `pilula ${tipo}${vivo ? ' vivo' : ''}` }, texto);
function barra(pct, tipo = '', cor) {
  const p = Math.max(0, Math.min(100, pct || 0));
  return el('div', { class: `progresso ${tipo}`, 'data-cor': cor || null, role: 'progressbar', 'aria-valuenow': p, 'aria-valuemin': 0, 'aria-valuemax': 100 }, el('div', { style: `width:${p}%` }));
}
const vazio = (ic, titulo, texto, ...acoes) => el('div', { class: 'vazio' }, ladrilho(ic, '', 'g'), el('strong', {}, titulo), texto ? el('span', { class: 'small' }, texto) : null, acoes.length ? el('div', { class: 'botoes' }, acoes) : null);
function busca(placeholder) {
  const i = el('input', { type: 'search', placeholder, 'aria-label': placeholder });
  return { input: i, no: el('div', { class: 'busca' }, icone('buscar'), i) };
}
function esqueleto() {
  const b = (h, w = '100%') => el('div', { class: 'esqueleto', style: `height:${h}px;width:${w}` });
  return el('div', { 'aria-busy': 'true', 'aria-label': 'Carregando' }, b(34, '40%'), el('div', { style: 'height:10px' }), b(16, '60%'), el('div', { style: 'height:26px' }),
    el('div', { class: 'grade g3' }, b(120), b(120), b(120)), el('div', { style: 'height:16px' }), b(220));
}

function menuSuspenso(gatilho, itens) {
  const wrap = el('div', { class: 'menu-wrap' }, gatilho);
  let aberto = null;
  const fechar = () => { if (aberto) { aberto.remove(); aberto = null; gatilho.setAttribute('aria-expanded', 'false'); document.removeEventListener('click', fora, true); document.removeEventListener('keydown', tecla); } };
  const fora = (e) => { if (!wrap.contains(e.target) || !wrap.isConnected) fechar(); };
  const tecla = (e) => {
    if (e.key === 'Escape') { fechar(); gatilho.focus(); }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      const f = [...aberto.querySelectorAll('[role=menuitem]')];
      const i = f.indexOf(document.activeElement);
      f[(i + (e.key === 'ArrowDown' ? 1 : f.length - 1)) % f.length]?.focus();
      e.preventDefault();
    }
  };
  gatilho.setAttribute('aria-haspopup', 'menu');
  gatilho.setAttribute('aria-expanded', 'false');
  gatilho.addEventListener('click', () => {
    if (aberto) return fechar();
    aberto = el('div', { class: 'menu', role: 'menu' }, (typeof itens === 'function' ? itens() : itens).map((it) => {
      if (it === '-') return el('hr');
      if (it.rotulo) return el('div', { class: 'rotulo-menu' }, it.rotulo);
      return el(it.href ? 'a' : 'button', { role: 'menuitem', type: it.href ? null : 'button', href: it.href, target: it.alvo, rel: it.alvo ? 'noopener' : null, class: it.perigo ? 'perigo' : '',
        onclick: () => { fechar(); if (it.acao) it.acao(); } }, it.icone ? icone(it.icone) : null, it.texto, it.marcado ? el('span', { style: 'margin-left:auto;display:flex' }, icone('check')) : null);
    }));
    wrap.append(aberto);
    gatilho.setAttribute('aria-expanded', 'true');
    setTimeout(() => { document.addEventListener('click', fora, true); document.addEventListener('keydown', tecla); aberto?.querySelector('[role=menuitem]')?.focus(); });
  });
  return wrap;
}

/** A pessoa está interagindo com esta área (botão em foco ou clique há pouco)? Então não redesenha agora. */
function interagindo(caixa) {
  if (!caixa.dataset.vigiado) {
    caixa.dataset.vigiado = '1';
    caixa.addEventListener('pointerdown', () => { caixa.dataset.toque = String(Date.now()); }, true);
  }
  const a = document.activeElement;
  return (a && a !== document.body && caixa.contains(a) && a.tagName === 'BUTTON') || Date.now() - Number(caixa.dataset.toque || 0) < 1500;
}

/** Repete fn a cada ms enquanto a tela atual estiver aberta; nunca dois pedidos ao mesmo tempo. */
function repetir(fn, ms) {
  const g = geracao;
  const ciclo = { id: null };
  const passo = async () => {
    if (g !== geracao) return;
    if (!document.hidden) { try { await fn(); } catch (e) { /* a próxima tentativa resolve */ } }
    if (g === geracao) ciclo.id = setTimeout(passo, ms);
  };
  ciclo.id = setTimeout(passo, ms);
  temporizadores.push(ciclo);
}
function pararRepeticoes() { temporizadores.forEach((c) => clearTimeout(c.id)); temporizadores = []; }
/** Adia fn até a pessoa parar de digitar. */
function aoParar(fn, ms = 150) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

// tema: automático / claro / escuro (preferência só deste navegador)
function lerTema() { try { return localStorage.getItem('LabFreeImageDeploy-tema') || 'auto'; } catch (e) { return 'auto'; } }
function aplicarTema(t) {
  if (t === 'auto') delete document.documentElement.dataset.tema; else document.documentElement.dataset.tema = t;
  try { localStorage.setItem('LabFreeImageDeploy-tema', t); } catch (e) { /* sem armazenamento */ }
}
aplicarTema(lerTema());

const TECLAS = [['F12', 'Dell, Lenovo, Positivo, Acer'], ['F9', 'HP'], ['F8', 'Asus'], ['F11', 'MSI, ASRock']];
const teclasBoot = () => el('span', {}, TECLAS.map(([t, m], i) => [i ? ', ' : '', el('kbd', { class: 'tecla' }, t), ` ${m}`]));

// ================================================================ login

function pararTudo() {
  geracao++;
  pararRepeticoes();
  fecharModais();
  clearInterval(estadoGlobal.relogioStatus); estadoGlobal.relogioStatus = null;
}

function telaLogin(msg) {
  pararTudo();
  estadoGlobal.tela = 'login';
  const usuario = el('input', { type: 'text', name: 'usuario', autocomplete: 'username', required: true });
  const senha = el('input', { type: 'password', name: 'senha', autocomplete: 'current-password', required: true });
  const erro = el('div');
  const botao = el('button', { class: 'btn primario g', type: 'submit', style: 'width:100%' }, 'Entrar', icone('seta'));
  const mostrarErro = (t) => erro.replaceChildren(t ? el('div', { class: 'faixa ruim', role: 'alert', style: 'margin-bottom:16px' }, icone('alerta'), el('div', {}, t)) : '');
  mostrarErro(msg);
  const form = el('form', { onsubmit: async (e) => {
    e.preventDefault();
    botao.disabled = true; mostrarErro('');
    try {
      const r = await api('entrar', { usuario: usuario.value, senha: senha.value });
      sessao.usuario = r.usuario; sessao.token = r.token; estadoGlobal.versao = r.versao;
      if (r.trocar_senha) telaPrimeiraSenha(); else { montarCasca(); navegar(); }
    } catch (err) { mostrarErro(err.message); botao.disabled = false; senha.select(); }
  } }, campo('Usuário', usuario), campo('Senha', senha), erro, botao);
  const recurso = (ic, cor, t) => el('div', { class: 'recurso' }, ladrilho(ic, cor, 'p'), t);
  document.getElementById('app').replaceChildren(el('div', { class: 'tela-login' },
    el('div', { class: 'login-arte' },
      el('div', { class: 'marca', style: 'color:#fff;padding:0' }, el('span', { class: 'logo' }, icone('logo')), el('div', {}, el('strong', {}, 'LabFree ImageDeploy'), el('span', { style: 'color:rgba(255,255,255,.7)' }, 'Clonagem de máquinas pela rede'))),
      el('div', {}, el('h1', {}, 'Capture e restaure máquinas em poucos cliques.'),
        el('p', {}, 'Clonagem de máquinas pela rede sem complicação: assistentes passo a passo, acompanhamento ao vivo e avisos claros quando algo não está certo.'),
        el('div', { class: 'recursos' },
          recurso('capturar', 'capturar', 'Capturar a imagem de uma máquina modelo'),
          recurso('restaurar', 'restaurar', 'Restaurar em uma ou várias máquinas'),
          recurso('grupo', 'grupo', 'Restaurar uma sala inteira de uma vez'))),
      el('span', { class: 'small', style: 'color:rgba(255,255,255,.55)' }, 'Baseado no FOG Project · software livre (GPL v3)')),
    el('div', { class: 'login-form' }, el('div', { class: 'caixa' },
      el('div', { class: 'marca', style: 'padding:0 0 28px' }, el('span', { class: 'logo' }, icone('logo')), el('div', {}, el('strong', {}, 'LabFree ImageDeploy'), el('span', {}, 'Bem-vindo de volta'))),
      el('h2', { style: 'font-size:1.5rem;margin-bottom:6px' }, 'Entrar'),
      el('p', { class: 'muted', style: 'margin-bottom:22px' }, 'Entre com seu usuário e senha.'),
      form))));
  usuario.focus();
}

// ================================================================ primeiro acesso

function telaPrimeiraSenha() {
  pararTudo();
  estadoGlobal.tela = 'senha';
  const nova = el('input', { type: 'password', autocomplete: 'new-password', required: true });
  const repetir = el('input', { type: 'password', autocomplete: 'new-password', required: true });
  const erro = el('div');
  const botao = el('button', { class: 'btn primario g', type: 'submit', style: 'width:100%' }, 'Salvar e entrar', icone('seta'));
  const regra = (texto) => el('li', {}, el('span', { class: 'marca-regra' }, icone('check', 'i')), texto);
  const r8 = regra('Pelo menos 8 caracteres'), rDif = regra('Diferente de "admin" e do nome de usuário'), rIg = regra('As duas senhas iguais');
  const conferir = () => {
    const v = nova.value;
    r8.classList.toggle('ok', v.length >= 8);
    rDif.classList.toggle('ok', v.length > 0 && !['admin', 'password'].includes(v.toLowerCase()) && v.toLowerCase() !== (sessao.usuario || '').toLowerCase());
    rIg.classList.toggle('ok', v.length > 0 && v === repetir.value);
  };
  nova.addEventListener('input', conferir); repetir.addEventListener('input', conferir);
  const mostrarErro = (t) => erro.replaceChildren(t ? el('div', { class: 'faixa ruim', role: 'alert', style: 'margin-bottom:16px' }, icone('alerta'), el('div', {}, t)) : '');
  const form = el('form', { onsubmit: async (e) => {
    e.preventDefault();
    mostrarErro('');
    if (nova.value !== repetir.value) return mostrarErro('As duas senhas não são iguais.');
    botao.disabled = true;
    try {
      const r = await api('primeira_senha', { nova: nova.value });
      montarCasca(); navegar(); aviso(r.msg);
    } catch (err) { mostrarErro(err.message); botao.disabled = false; }
  } }, campo('Nova senha', nova), campo('Repita a nova senha', repetir),
  el('ul', { class: 'regras-senha' }, r8, rDif, rIg), erro, botao,
  el('button', { class: 'btn fantasma', type: 'button', style: 'width:100%;margin-top:8px', onclick: sair }, 'Sair'));
  const item = (ic, t) => el('div', { class: 'recurso' }, ladrilho(ic, 'inicio', 'p'), t);
  document.getElementById('app').replaceChildren(el('div', { class: 'tela-login' },
    el('div', { class: 'login-arte' },
      el('div', { class: 'marca', style: 'color:#fff;padding:0' }, el('span', { class: 'logo' }, icone('logo')), el('div', {}, el('strong', {}, 'LabFree ImageDeploy'), el('span', { style: 'color:rgba(255,255,255,.7)' }, 'Primeiro acesso'))),
      el('div', {}, el('h1', {}, 'Proteja o seu sistema de clonagem.'),
        el('p', {}, 'Você entrou com a senha de fábrica. Crie uma senha só sua antes de continuar: quem tem acesso ao painel pode apagar o disco de qualquer máquina da rede.'),
        el('div', { class: 'recursos' }, item('escudo', 'A senha de fábrica deixa de funcionar'), item('usuarios', 'Depois, crie um usuário para cada técnico em Usuários'))),
      el('span', { class: 'small', style: 'color:rgba(255,255,255,.55)' }, 'LabFree ImageDeploy')),
    el('div', { class: 'login-form' }, el('div', { class: 'caixa' },
      el('div', { class: 'marca', style: 'padding:0 0 28px' }, el('span', { class: 'logo' }, icone('logo')), el('div', {}, el('strong', {}, 'LabFree ImageDeploy'), el('span', {}, `Olá, ${sessao.usuario || ''}`))),
      el('h2', { style: 'font-size:1.5rem;margin-bottom:6px' }, 'Crie sua senha'),
      el('p', { class: 'muted', style: 'margin-bottom:22px' }, 'Este é o primeiro acesso com esta conta.'),
      form))));
  nova.focus();
}

// ================================================================ casca

const ROTAS = {
  inicio: { nome: 'Início', icone: 'inicio', cor: 'inicio', tela: telaInicio },
  capturar: { nome: 'Capturar imagem', icone: 'capturar', cor: 'capturar', tela: telaCapturar },
  restaurar: { nome: 'Restaurar imagem', icone: 'restaurar', cor: 'restaurar', tela: telaRestaurar },
  grupo: { nome: 'Restaurar em grupo', icone: 'grupo', cor: 'grupo', tela: telaGrupo },
  maquinas: { nome: 'Máquinas', icone: 'maquinas', cor: 'maquinas', tela: telaMaquinas },
  imagens: { nome: 'Imagens', icone: 'imagens', cor: 'imagens', tela: telaImagens },
  tarefas: { nome: 'Tarefas', icone: 'tarefas', cor: 'tarefas', tela: telaTarefas },
  configuracoes: { nome: 'Configurações', icone: 'config', cor: 'configuracoes', tela: telaConfiguracoes },
  usuarios: { nome: 'Usuários', icone: 'usuarios', cor: 'configuracoes', tela: telaUsuarios },
  ajuda: { nome: 'Ajuda', icone: 'ajuda', cor: 'ajuda', tela: telaAjuda },
};
const GRUPOS_NAV = [['Dia a dia', ['inicio', 'capturar', 'restaurar', 'grupo']], ['Gerenciar', ['maquinas', 'imagens', 'tarefas']], ['Servidor', ['configuracoes', 'usuarios', 'ajuda']]];

function itensUsuario() {
  const t = lerTema();
  return [
    { rotulo: `Conectado como ${sessao.usuario || ''}` },
    '-',
    { rotulo: 'Tema' },
    { texto: 'Automático', icone: 'auto', marcado: t === 'auto', acao: () => aplicarTema('auto') },
    { texto: 'Claro', icone: 'sol', marcado: t === 'claro', acao: () => aplicarTema('claro') },
    { texto: 'Escuro', icone: 'lua', marcado: t === 'escuro', acao: () => aplicarTema('escuro') },
    '-',
    { texto: 'Trocar minha senha', icone: 'escudo', acao: trocarMinhaSenha },
    { texto: 'Sair', icone: 'sair', perigo: true, acao: sair },
  ];
}
async function sair() {
  try { await api('sair', {}); } catch (e) { /* já saiu */ }
  sessao.usuario = null; sessao.token = null;
  telaLogin();
  aviso('Você saiu do painel.');
}

function montarCasca() {
  estadoGlobal.tela = 'painel';
  const nav = el('nav', { class: 'lateral', 'aria-label': 'Menu principal' },
    el('a', { class: 'marca', href: '#/inicio' }, el('span', { class: 'logo' }, icone('logo')), el('div', {}, el('strong', {}, 'LabFree ImageDeploy'), el('span', {}, 'Clonagem pela rede'))),
    GRUPOS_NAV.map(([titulo, ids]) => el('div', { class: 'grupo-nav' }, el('span', {}, titulo),
      ids.map((id) => el('a', { class: 'nav-item', href: `#/${id}`, 'data-rota': id, 'data-cor': ROTAS[id].cor },
        ladrilho(ROTAS[id].icone, ROTAS[id].cor), ROTAS[id].nome, el('span', { class: 'contador', id: `cont-${id}`, hidden: true }))))),
    el('div', { class: 'lateral-rodape' },
      el('button', { class: 'nav-item', type: 'button', 'data-cor': 'ajuda', style: 'border:0;background:none;font:inherit;cursor:pointer;width:100%;text-align:left', onclick: trocarMinhaSenha },
        ladrilho('escudo', 'ajuda'), 'Trocar minha senha'),
      el('button', { class: 'nav-item', type: 'button', 'data-cor': 'ajuda', style: 'border:0;background:none;font:inherit;cursor:pointer;width:100%;text-align:left', onclick: sair },
        ladrilho('sair', 'ajuda'), 'Sair')));

  const statusRede = el('a', { class: 'status-rede', href: '#/inicio', id: 'status-rede', title: 'Estado da rede de clonagem' }, el('span', { class: 'ponto' }), el('span', { class: 'txt' }, 'Verificando…'));
  const avatar = el('button', { class: 'avatar', type: 'button', 'aria-label': 'Menu do usuário' }, (sessao.usuario || '?').slice(0, 1).toUpperCase());
  const topo = el('header', { class: 'topo' },
    el('a', { class: 'marca', href: '#/inicio' }, el('span', { class: 'logo' }, icone('logo')), el('div', {}, el('strong', {}, 'LabFree ImageDeploy'))),
    el('div', { class: 'espaco' }), statusRede, menuSuspenso(avatar, itensUsuario));

  const maisBotao = el('button', { type: 'button', 'data-rota': 'mais', onclick: abrirMais }, el('span', { class: 'bolha' }, icone('menu')), 'Mais');
  const inferior = el('nav', { class: 'barra-inferior', 'aria-label': 'Navegação' },
    ['inicio', 'maquinas', 'imagens', 'tarefas'].map((id) => el('a', { href: `#/${id}`, 'data-rota': id, 'data-cor': ROTAS[id].cor },
      el('span', { class: 'bolha' }, icone(ROTAS[id].icone)), ROTAS[id].nome)), maisBotao);

  document.getElementById('app').replaceChildren(el('div', { class: 'casca' }, nav,
    el('div', { class: 'principal' }, topo, el('main', { class: 'conteudo', id: 'conteudo', tabindex: '-1' }))), inferior);
  atualizarStatus();
  clearInterval(estadoGlobal.relogioStatus);
  // o Início já atualiza o status a cada 5 s; nas outras telas, este relógio cuida dele
  estadoGlobal.relogioStatus = setInterval(() => { if (sessao.usuario && !document.hidden && rota().id !== 'inicio') atualizarStatus(); }, 20000);
}

function abrirMais() {
  const fecharFolha = () => document.querySelector('.fundo-modal')?.click();
  const item = (id) => el('a', { class: 'escolha', href: `#/${id}`, 'data-cor': ROTAS[id].cor, onclick: fecharFolha },
    ladrilho(ROTAS[id].icone, ROTAS[id].cor, 'p'), el('div', { class: 'corpo', style: 'align-self:center' }, el('strong', {}, ROTAS[id].nome)));
  const t = lerTema();
  const temaBt = (v, txt, ic) => el('button', { type: 'button', class: 'btn p ' + (t === v ? 'primario' : ''), onclick: () => { aplicarTema(v); fecharFolha(); } }, icone(ic), txt);
  modal({ titulo: 'Mais opções', conteudo: el('div', {},
    el('div', { class: 'escolhas uma-coluna' }, ['capturar', 'restaurar', 'grupo', 'usuarios', 'configuracoes', 'ajuda'].map(item)),
    el('div', { style: 'margin:18px 0 8px' }, el('span', { class: 'small muted forte' }, 'Tema')),
    el('div', { class: 'botoes' }, temaBt('auto', 'Automático', 'auto'), temaBt('claro', 'Claro', 'sol'), temaBt('escuro', 'Escuro', 'lua')),
    el('div', { class: 'botoes', style: 'margin-top:18px' },
      el('button', { class: 'btn', type: 'button', onclick: () => { fecharFolha(); trocarMinhaSenha(); } }, icone('escudo'), 'Trocar minha senha'),
      el('button', { class: 'btn perigo', type: 'button', onclick: () => { fecharFolha(); sair(); } }, icone('sair'), 'Sair'))),
  botoes: [{ texto: 'Fechar', classe: 'fantasma' }] });
}

function pintarStatus(r, erro) {
  const s = document.getElementById('status-rede');
  if (s) {
    const probs = r ? problemas(r) : [];
    s.classList.toggle('ruim', !!erro || probs.length > 0);
    s.querySelector('.txt').textContent = erro ? 'Sem comunicação' : probs.length ? plural(probs.length, 'problema', 'problemas') : `Rede online · ${r.rede.ip}`;
    s.title = erro ? `O painel não está conseguindo falar com o servidor: ${erro}` : 'Estado da rede de clonagem';
  }
  if (!r) return;
  const cont = (id, n) => { const c = document.getElementById(`cont-${id}`); if (c) { c.hidden = !n; c.textContent = n; } };
  cont('tarefas', r.tarefas.length + r.multicast.length);
  cont('maquinas', r.contagem.novas || 0);
}
async function atualizarStatus() {
  try {
    const r = await api('resumo');
    estadoGlobal.resumo = r;
    pintarStatus(r);
  } catch (e) { if (sessao.usuario) pintarStatus(null, e.message); }
}

function rota() {
  const [c, q] = location.hash.replace(/^#\/?/, '').split('?');
  const id = c === 'multicast' ? 'grupo' : c;
  return { id: ROTAS[id] ? id : 'inicio', p: new URLSearchParams(q || '') };
}

async function navegar() {
  if (!sessao.usuario) return;
  geracao++;
  pararRepeticoes();
  fecharModais();
  const { id, p } = rota();
  const def = ROTAS[id];
  document.querySelectorAll('[data-rota]').forEach((a) => {
    if (a.dataset.rota === id) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  });
  document.title = `${def.nome} · LabFree ImageDeploy`;
  // cada tela desenha num recipiente só dela: se a pessoa sair antes de a tela terminar de carregar,
  // o que chegar atrasado cai num recipiente que já saiu da página e não aparece por cima da tela nova
  const alvo = el('div', { class: 'tela' });
  alvo.append(esqueleto());
  document.getElementById('conteudo').replaceChildren(alvo);
  window.scrollTo(0, 0);
  try {
    await def.tela(alvo, p);
  } catch (err) {
    if (sessao.usuario) alvo.replaceChildren(el('div', { class: 'faixa ruim' }, icone('alerta'), el('div', {}, el('strong', {}, 'Não foi possível carregar esta tela'), err.message,
      el('div', { style: 'margin-top:10px' }, el('button', { class: 'btn p', type: 'button', onclick: navegar }, 'Tentar de novo')))));
  }
}

function cabPagina(id, subtitulo, ...acoes) {
  const d = ROTAS[id];
  return el('div', { class: 'cab-pagina' },
    el('div', { class: 'titulo' }, ladrilho(d.icone, d.cor, 'g'), el('div', {}, el('h1', {}, d.nome), subtitulo ? el('p', {}, subtitulo) : null)),
    acoes.length ? el('div', { class: 'botoes' }, acoes) : null);
}

// ================================================================ início

function problemas(r) {
  const l = [];
  const n = r.rede;
  if (!n.adaptador) l.push(`O adaptador USB da rede de clonagem (${n.interface}) não está conectado no servidor.`);
  else if (!n.cabo) l.push('O cabo de rede não está ligado no adaptador USB da rede de clonagem (ou o switch está desligado).');
  else if (!n.ip_ok) l.push(`O adaptador da rede de clonagem está sem o IP ${n.ip}. Desplugue e plugue o adaptador.`);
  if (n.adaptador && n.cabo && n.ip_ok && !n.dhcp_escutando) l.push('O DHCP não está respondendo na rede de clonagem: as máquinas não vão receber IP.');
  r.servicos.filter((s) => !s.ativo).forEach((s) => l.push(`Serviço parado: ${s.descricao}.`));
  if (r.disco.total && r.disco.livre / r.disco.total < 0.1) l.push(`Pouco espaço para imagens: só ${gb(r.disco.livre)} livres.`);
  return l;
}

function corTipo(tipo) { return tipo.startsWith('Captura') ? 'capturar' : tipo.startsWith('Restauração em grupo') ? 'grupo' : tipo.startsWith('Restauração') ? 'restaurar' : 'tarefas'; }
function iconeTipo(tipo) { return tipo.startsWith('Captura') ? 'capturar' : tipo.startsWith('Restauração em grupo') ? 'grupo' : tipo.startsWith('Restauração') ? 'restaurar' : 'tarefas'; }
function pilulaEstado(estado, texto) {
  const e = Number(estado);
  return pilula(texto, e === 4 ? 'ok' : e === 6 ? 'ruim' : e === 5 ? '' : e === 3 ? 'info' : 'aviso', e <= 3);
}

const duracao = (seg) => (seg < 90 ? `${Math.max(1, Math.round(seg))} s` : seg < 5400 ? `${Math.round(seg / 60)} min` : `${(seg / 3600).toFixed(1).replace('.', ',')} h`);
/** Chave para comparar respostas sem redesenhar a cada segundo de "sem notícia" (só quando muda o minuto). */
const chaveVivo = (dados) => JSON.stringify(dados, (k, v) => (k === 'sem_noticia' ? Math.floor(v / 60) : v));

// as mesmas 5 etapas que a tela da máquina mostra (marca/fos/bin/labfree-tela)
const NOMES_ETAPAS = {
  captura: ['Conectar', 'Verificar o disco', 'Partições', 'Copiar para o servidor', 'Finalizar'],
  restauracao: ['Conectar', 'Verificar o disco', 'Partições', 'Gravar no disco', 'Finalizar'],
};
/** Etapa atual: a informada pela máquina ou, sem ela (sistema de clonagem antigo), uma estimativa pela fase. */
function etapaDe(t) {
  if (t.etapa) return { atual: Number(t.etapa), total: Number(t.etapas) || 5 };
  const estimada = { preparando: 2, copiando: 4, finalizando: 5 }[t.fase];
  return estimada ? { atual: estimada, total: 5 } : null;
}
/** Trilha de etapas: o que já foi feito, o que está acontecendo e o que falta. */
function trilha(t) {
  const e = etapaDe(t);
  if (!e) return null;
  const nomes = t.tipo.startsWith('Captura') ? NOMES_ETAPAS.captura : NOMES_ETAPAS.restauracao;
  return el('ol', { class: 'trilha', 'aria-label': `Etapa ${e.atual} de ${e.total}` }, Array.from({ length: e.total }, (_, i) => {
    const n = i + 1;
    return el('li', { class: n < e.atual ? 'feita' : n === e.atual ? 'atual' : null },
      el('span', { class: 'bolinha' }, n < e.atual ? icone('check', '') : String(n)), el('span', { class: 'nome' }, nomes[i] || `Etapa ${n}`));
  }));
}

/** Cartão ao vivo de uma tarefa: etapas, barra, números e alerta se a máquina parou de dar notícia. */
function cartaoTarefa(t, detalhado, cor = corTipo(t.tipo)) {
  const copiando = t.fase === 'copiando' || t.fase === 'finalizando';
  const esquecida = t.fase === 'aguardando' && t.criada && Date.now() - new Date(t.criada).getTime() > 86400000;
  const direita = t.parada ? pilula('Sem notícia', 'aviso')
    : copiando ? el('span', { class: 'pct' }, `${t.pct}%`)
      : pilula(t.fase === 'aguardando' ? 'Aguardando ligar' : t.fase === 'fila' ? 'Na fila' : 'Preparando', t.fase === 'preparando' ? 'info' : 'aviso', true);
  return el('div', { class: 'tarefa-viva' + (t.parada ? ' parada' : ''), 'data-cor': cor },
    el('div', { class: 'topo-t' }, ladrilho(iconeTipo(t.tipo), cor, 'p'),
      el('div', { class: 'quem' }, el('strong', {}, t.maquina), el('div', { class: 'small muted' }, `${t.tipo} · ${t.imagem || '—'}`)),
      el('div', { class: 'acoes-t' }, direita,
        detalhado ? el('button', { class: 'btn p perigo', type: 'button', onclick: () => cancelarTarefa(t) }, 'Cancelar') : null)),
    trilha(t),
    copiando ? barra(t.pct, t.parada ? 'aviso' : '', cor) : barra(0, t.parada ? 'aviso' : 'indeterminado', cor),
    el('div', { class: 'fase' }, el('span', { class: 'ponto-vivo' }), el('span', {}, t.fase_texto, copiando && t.parada ? ` · ${t.pct}%` : null)),
    copiando ? el('div', { class: 'dados' },
      el('span', {}, el('b', {}, t.copiado || '—'), ' de ', el('b', {}, t.total || '—')),
      t.velocidade && !/^0+([.,]0+)?\s*[a-z]*\/min$/i.test(t.velocidade) ? el('span', {}, el('b', {}, t.velocidade)) : null,
      t.restante && t.fase === 'copiando' && !t.parada ? el('span', {}, 'faltam ', el('b', {}, t.restante)) : null,
      detalhado && t.decorrido ? el('span', {}, 'nesta parte há ', el('b', {}, t.decorrido)) : null) : null,
    t.fase === 'aguardando' ? el('div', { class: 'small ' + (esquecida ? 'texto-aviso' : 'muted'), style: 'margin-top:6px' },
      esquecida ? `Agendada ${haQuanto(t.criada)} e ainda esperando. Se não vai mais usar, cancele: um boot pela rede nesta máquina apaga o disco.`
        : [`Agendada ${haQuanto(t.criada)}. Ligue a máquina e dê boot pela rede: `, teclasBoot(), '.']) : null,
    t.parada ? el('div', { class: 'faixa aviso', style: 'margin-top:10px' }, icone('alerta'), el('div', {},
      el('strong', {}, `Sem notícia da máquina há ${duracao(t.sem_noticia)}`),
      'Ela pode ter travado, desligado ou perdido o cabo. Olhe a tela da máquina; se estiver parada, cancele e agende de novo.')) : null);
}

/** Sessão em grupo: quantas entraram e o andamento de cada máquina. */
function cartaoSessao(s, detalhado) {
  const maquinas = s.maquinas || [];
  const esperadas = Number(s.esperadas) || 0;
  const conectadas = Number(s.conectadas) || 0;
  const transmitindo = maquinas.some((m) => m.fase === 'copiando' || m.fase === 'finalizando');
  return el('div', { class: 'tarefa-viva', 'data-cor': 'grupo' },
    el('div', { class: 'topo-t' }, ladrilho('grupo', 'grupo', 'p'),
      el('div', { class: 'quem' }, el('strong', {}, `Sessão em grupo “${s.nome || s.id}”`),
        el('div', { class: 'small muted' }, `${s.imagem || '—'} · ${conectadas} de ${plural(esperadas, 'máquina conectada', 'máquinas conectadas')}`)),
      el('div', { class: 'acoes-t' },
        transmitindo ? el('span', { class: 'pct' }, `${s.pct}%`) : pilula(conectadas ? 'Aguardando as demais' : 'Aguardando máquinas', 'aviso', true),
        detalhado ? el('button', { class: 'btn p perigo', type: 'button', onclick: () => cancelarSessao(s) }, 'Cancelar') : null)),
    transmitindo ? barra(s.pct, '', 'grupo') : barra(esperadas ? (conectadas / esperadas) * 100 : 0, '', 'grupo'),
    el('div', { class: 'fase' }, el('span', { class: 'ponto-vivo' }), el('span', {}, transmitindo ? 'Transmitindo para todas as máquinas ao mesmo tempo'
      : `A transmissão começa quando as ${esperadas} máquinas entrarem. Em cada uma: boot pela rede → “Entrar em sessao de grupo” → usuário e senha do painel → ${s.nome}.`)),
    maquinas.length ? el('div', { class: 'sub-maquinas' }, maquinas.map((m) => {
      const copiandoM = m.fase === 'copiando' || m.fase === 'finalizando';
      return el('div', { class: 'sub-maquina' + (m.parada ? ' parada' : '') },
        el('strong', {}, m.maquina),
        el('span', { class: 'small ' + (m.parada ? 'texto-aviso' : 'muted') }, m.parada ? `Sem notícia há ${duracao(m.sem_noticia)}` : m.fase_texto),
        el('span', { class: 'pct-p' }, copiandoM ? `${m.pct}%` : ''),
        copiandoM ? barra(m.pct, m.parada ? 'aviso' : '', 'grupo') : barra(0, m.parada ? 'aviso' : 'indeterminado', 'grupo'));
    })) : null);
}

function blocoAtivas(tarefas, sessoes, detalhado) {
  if (!tarefas.length && !sessoes.length) {
    return vazio('tarefas', 'Nada em andamento', 'Quando você agendar uma captura ou restauração, o progresso aparece aqui ao vivo.');
  }
  return el('div', {}, tarefas.map((t) => cartaoTarefa(t, detalhado)), sessoes.map((x) => cartaoSessao(x, detalhado)));
}

async function cancelarTarefa(t) {
  const efeito = t.tipo.startsWith('Captura') ? 'Se a cópia já começou, a imagem fica incompleta e precisa ser capturada de novo.'
    : 'Se a gravação já começou, a máquina pode ficar sem sistema até você restaurar de novo.';
  if (!await confirmar('Cancelar esta tarefa?', `A ${t.tipo.toLowerCase()} de “${t.maquina}” será cancelada. ${efeito} Se ela já estava copiando, reinicie a máquina.`, 'Cancelar tarefa', true)) return;
  try { aviso((await api('cancelar_tarefa', { id: t.id })).msg); navegar(); } catch (e) { aviso(e.message, true); }
}
async function cancelarSessao(s) {
  if (!await confirmar('Cancelar a sessão em grupo?', `A sessão “${s.nome || s.id}” será encerrada para todas as máquinas.`, 'Cancelar sessão', true)) return;
  try { aviso((await api('cancelar_multicast', { id: s.id })).msg); navegar(); } catch (e) { aviso(e.message, true); }
}

function linhaHistorico(h) {
  const cor = corTipo(h.tipo);
  const falhou = Number(h.estado) === 6;
  const registro = h.tem_registro || falhou;
  return el('div', { class: 'linha quebra hist', 'data-cor': cor }, ladrilho(iconeTipo(h.tipo), cor, 'p'),
    el('div', { class: 'corpo' }, el('strong', {}, h.maquina, el('span', { class: 'tipo-h' }, ` · ${h.tipo}`)),
      el('div', { class: 'meta' }, el('span', {}, h.imagem || 'imagem excluída'), el('span', {}, dataHora(h.criada)), h.por ? el('span', {}, `por ${h.por}`) : null),
      h.motivo ? el('div', { class: 'motivo' + (falhou ? ' ruim' : '') }, icone(falhou ? 'alerta' : 'info'), el('span', {}, h.motivo)) : null),
    el('div', { class: 'lado' }, pilulaEstado(h.estado, h.estado_texto),
      // o espaço do botão fica reservado: as pílulas de estado alinham na mesma coluna em todas as linhas
      el('button', { class: 'btn p fantasma', type: 'button', disabled: !registro, 'aria-hidden': registro ? null : 'true', tabindex: registro ? null : '-1',
        style: registro ? null : 'visibility:hidden', onclick: () => verRegistro(h) }, icone('tarefas'), 'Ver registro')));
}

/** Rótulo do dia para agrupar listas: Hoje, Ontem ou a data. */
function rotuloDia(iso) {
  if (!iso) return 'Sem data';
  const d = new Date(iso), hoje = new Date();
  const dias = Math.round((new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate()) - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 86400000);
  if (dias === 0) return 'Hoje';
  if (dias === 1) return 'Ontem';
  return d.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' });
}

/** Botões de filtro (um ativo por vez), com contagem. opcoes: [[id, rótulo, quantidade]] */
function filtros(opcoes, atual, aoMudar) {
  return el('div', { class: 'filtros', role: 'group', 'aria-label': 'Filtrar' }, opcoes.map(([id, rot, n]) => el('button', {
    type: 'button', class: 'filtro' + (id === atual ? ' ativo' : ''), 'aria-pressed': String(id === atual), onclick: () => aoMudar(id),
  }, rot, n !== undefined ? el('span', { class: 'n' }, String(n)) : null)));
}

/** Registro completo que a máquina enviou no fim (e os erros que o FOG recebeu). */
async function verRegistro(h) {
  let r;
  try { r = await api(`registro&id=${encodeURIComponent(h.id)}`); } catch (e) { aviso(e.message, true); return; }
  const ok = r.resultado === 'sucesso';
  await modal({ titulo: `Registro · ${h.maquina}`, icone: ok ? 'ok' : 'alerta', cor: ok ? 'restaurar' : 'maquinas',
    texto: `${h.tipo} · ${h.imagem || '—'} · ${dataHora(r.quando || h.criada)}`,
    conteudo: el('div', {},
      r.mensagem ? el('div', { class: 'faixa ruim', style: 'margin-bottom:12px' }, icone('alerta'), el('div', {}, el('strong', {}, 'Motivo informado pela máquina'), r.mensagem)) : null,
      r.erros.length ? el('div', { class: 'faixa aviso', style: 'margin-bottom:12px' }, icone('alerta'), el('div', {}, el('strong', {}, 'Erros recebidos pelo servidor'),
        el('ul', { style: 'margin:4px 0 0' }, r.erros.map((x) => el('li', {}, x.motivo))))) : null,
      r.registro ? el('pre', { class: 'registro' }, r.registro) : el('p', { class: 'small muted' }, 'A máquina não enviou o registro completo desta tarefa.')),
    botoes: [{ texto: 'Fechar', classe: 'primario' }] });
}

function saudacao() {
  const h = new Date().getHours();
  return (h < 12 ? 'Bom dia' : h < 18 ? 'Boa tarde' : 'Boa noite') + (sessao.usuario ? `, ${sessao.usuario}` : '');
}

async function telaInicio(alvo) {
  let anterior = '';
  let servicosAbertos = null; // a pessoa abriu/fechou "Serviços": respeitar entre atualizações
  const montar = async () => {
    let r, t;
    try {
      [r, t] = await Promise.all([api('resumo'), api('tarefas')]);
    } catch (e) {
      pintarStatus(null, e.message);
      if (anterior && alvo.isConnected && !alvo.querySelector('.faixa-conexao')) {
        alvo.prepend(el('div', { class: 'faixa ruim faixa-conexao', style: 'margin-bottom:16px' }, icone('alerta'), el('div', {}, el('strong', {}, 'Sem comunicação com o servidor'), 'Os dados abaixo podem estar desatualizados. Tentando de novo…')));
      }
      if (!anterior) throw e;
      return;
    }
    estadoGlobal.resumo = r;
    pintarStatus(r);
    const igual = chaveVivo([r, t.historico.slice(0, 5), new Date().getMinutes()]);
    if (igual === anterior || interagindo(alvo)) return; // nada mudou (ou a pessoa está clicando): não redesenha
    anterior = igual;
    const probs = problemas(r);
    const usado = r.disco.total ? Math.round((1 - r.disco.livre / r.disco.total) * 100) : 0;
    const ativos = r.servicos.filter((s) => s.ativo).length;
    const emAndamento = r.tarefas.length + r.multicast.length;
    const acao = (id, desc) => el('a', { class: 'acao', href: `#/${id}`, 'data-cor': ROTAS[id].cor },
      ladrilho(ROTAS[id].icone, ROTAS[id].cor), el('div', {}, el('strong', {}, ROTAS[id].nome), el('span', { class: 'desc', style: 'display:block;margin-top:4px' }, desc)), el('span', { class: 'seta' }, icone('seta')));
    const numero = (ic, cor, valor, rot, href, extra) => el(href ? 'a' : 'div', { class: 'cartao numero', href, 'data-cor': cor, style: extra ? 'flex-wrap:wrap' : null },
      ladrilho(ic, cor), el('div', { style: 'flex:1;min-width:0' }, el('div', { class: 'valor' }, valor), el('div', { class: 'rot' }, rot)), extra || null);
    alvo.replaceChildren(
      el('div', { class: 'cab-pagina' }, el('div', {}, el('h1', {}, saudacao()), el('p', {}, 'O que você quer fazer hoje?'))),
      probs.length
        ? el('div', { class: 'heroi ruim' }, ladrilho('alerta', '', 'g'), el('div', { style: 'position:relative;z-index:1' }, el('h2', {}, 'Atenção: algo impede o funcionamento'), el('ul', {}, probs.map((x) => el('li', {}, x)))))
        : el('div', { class: 'heroi' }, ladrilho('ok', '', 'g'), el('div', { style: 'position:relative;z-index:1' }, el('h2', {}, 'Tudo pronto para clonar'),
          el('p', {}, 'A rede de clonagem está ativa e entregando IP. Pode ligar as máquinas e dar boot pela rede.'),
          el('div', { class: 'chips' }, el('span', { class: 'chip' }, `Servidor ${r.rede.ip}`), el('span', { class: 'chip' }, `${ativos}/${r.servicos.length} serviços ativos`), el('span', { class: 'chip' }, `${gb(r.disco.livre)} livres`)))),
      r.esquecidas ? el('div', { class: 'faixa aviso', style: 'margin-bottom:20px;align-items:center' }, icone('alerta'), el('div', { style: 'flex:1' },
        el('strong', {}, `${plural(r.esquecidas, 'tarefa agendada há mais de 1 dia', 'tarefas agendadas há mais de 1 dia')} ainda espera a máquina ligar`),
        'Se alguém der boot pela rede nessa máquina, o disco será apagado. Cancele o que não vai mais usar.'),
        el('a', { class: 'btn p', href: '#/tarefas' }, 'Ver tarefas')) : null,
      r.contagem.novas ? el('div', { class: 'faixa info', style: 'margin-bottom:20px;align-items:center' }, icone('info'), el('div', { style: 'flex:1' },
        el('strong', {}, `${plural(r.contagem.novas, 'máquina nova detectada', 'máquinas novas detectadas')} na rede`), 'Deram boot pela rede de clonagem mas ainda não estão cadastradas.'),
        el('a', { class: 'btn p', href: '#/maquinas' }, 'Ver e cadastrar')) : null,
      el('div', { class: 'grade g4', style: 'margin-bottom:20px' },
        acao('capturar', 'Copiar o disco de uma máquina modelo para o servidor.'),
        acao('restaurar', 'Gravar uma imagem em uma ou algumas máquinas.'),
        acao('grupo', 'A mesma imagem numa sala inteira, de uma vez.'),
        acao('maquinas', 'Cadastrar e organizar as máquinas.')),
      el('div', { class: 'grade g4', style: 'margin-bottom:20px' },
        numero('maquinas', 'maquinas', r.contagem.maquinas, r.contagem.maquinas === 1 ? 'máquina cadastrada' : 'máquinas cadastradas', '#/maquinas'),
        numero('imagens', 'imagens', r.contagem.imagens, r.contagem.imagens === 1 ? 'imagem' : 'imagens', '#/imagens'),
        numero('tarefas', 'tarefas', emAndamento, emAndamento === 1 ? 'tarefa em andamento' : 'tarefas em andamento', '#/tarefas'),
        numero('disco', 'inicio', gb(r.disco.livre), `livres de ${gb(r.disco.total)}`, null,
          el('div', { style: 'width:100%;margin-top:4px' }, barra(usado, usado > 90 ? 'ruim' : usado > 75 ? 'aviso' : '')))),
      el('div', { class: 'grade g2' },
        el('div', { class: 'cartao', 'data-cor': 'tarefas' },
          el('div', { class: 'cartao-cab' }, el('h2', {}, 'Em andamento'), el('a', { class: 'btn p fantasma', href: '#/tarefas' }, 'Ver tudo', icone('seta'))),
          blocoAtivas(r.tarefas, r.multicast, false)),
        el('div', { class: 'cartao' },
          el('div', { class: 'cartao-cab' }, el('h2', {}, 'Atividade recente'), el('a', { class: 'btn p fantasma', href: '#/tarefas' }, 'Histórico', icone('seta'))),
          t.historico.length ? el('div', { class: 'lista' }, t.historico.slice(0, 5).map(linhaHistorico)) : vazio('relogio', 'Sem atividade ainda', 'Suas capturas e restaurações aparecem aqui.'))),
      el('details', { class: 'cartao', style: 'margin-top:16px', open: servicosAbertos ?? (ativos < r.servicos.length),
        ontoggle: (e) => { servicosAbertos = e.currentTarget.open; } },
        el('summary', { style: 'cursor:pointer;font-weight:650' }, ativos < r.servicos.length ? 'Serviços do servidor' : `Serviços do servidor · todos os ${r.servicos.length} ativos`),
        el('div', { class: 'servicos', style: 'margin-top:12px' }, r.servicos.map((s) => el('div', {}, el('span', { class: 'ponto ' + (s.ativo ? 'ok' : 'ruim') }), s.descricao, s.ativo ? null : pilula('parado', 'ruim'))))));
  };
  await montar();
  repetir(montar, 5000);
}

// ================================================================ cadastros (máquina e imagem)

async function formMaquina(maquina, imagens, padrao = {}) {
  const nome = el('input', { type: 'text', maxlength: '15', value: maquina ? maquina.nome : (padrao.nome || ''), placeholder: 'ex.: LAB01-PC05', autocomplete: 'off' });
  const mac = el('input', { type: 'text', class: 'mono', value: maquina ? maquina.mac : (padrao.mac || ''), placeholder: '00:11:22:33:44:55', disabled: !!maquina, autocomplete: 'off' });
  const imagem = el('select', {}, el('option', { value: '0' }, 'Nenhuma por enquanto'), imagens.map((i) => el('option', { value: i.id, selected: maquina ? String(maquina.imagem_id) === String(i.id) : false }, i.nome)));
  const desc = el('textarea', { placeholder: 'Opcional: sala, patrimônio, observações' }, maquina && !maquina.descricao.startsWith('Cadastrada pelo') ? maquina.descricao : '');
  return modal({
    titulo: maquina ? `Editar ${maquina.nome}` : 'Cadastrar máquina', icone: 'maquinas', cor: 'maquinas',
    texto: maquina ? null : 'O MAC identifica a máquina na rede. Ele aparece na tela de boot pela rede ou na lista de máquinas detectadas.',
    conteudo: el('div', {},
      campo('Nome da máquina', nome, 'Até 15 caracteres: letras, números e hífen.'),
      campo('Endereço MAC', mac, maquina ? 'O MAC não pode ser alterado.' : null),
      campo('Imagem padrão', imagem, 'A imagem que esta máquina costuma receber.'),
      campo('Descrição', desc)),
    botoes: [{ texto: 'Cancelar', classe: 'fantasma' }, { texto: maquina ? 'Salvar alterações' : 'Cadastrar máquina', classe: 'primario', acao: async () => {
      const dados = { nome: nome.value.trim(), imagem_id: Number(imagem.value), descricao: desc.value };
      const r = maquina ? await api('editar_maquina', { id: maquina.id, ...dados }) : await api('cadastrar_maquina', { ...dados, mac: mac.value.trim() });
      aviso(r.msg);
      return r;
    } }],
  });
}

const TIPOS_IMAGEM = [
  [1, 'Redimensionável', 'Funciona em discos de tamanhos diferentes, inclusive menores, desde que os dados caibam.'],
  [2, 'Partições de tamanho fixo', 'Copia as partições exatamente como estão. Só restaura em discos do mesmo tamanho ou maiores.'],
  [3, 'Todos os discos (tamanho fixo)', 'Para máquinas com mais de um disco. Os discos de destino precisam ser iguais ou maiores.'],
  [4, 'Cópia setor a setor', 'Lenta: copia até o espaço vazio. Use só se os outros tipos falharem.'],
];

async function formImagem(sistemas) {
  const nome = el('input', { type: 'text', maxlength: '40', placeholder: 'ex.: Laboratório Windows 11', autocomplete: 'off' });
  const so = el('select', {}, sistemas.map((s) => el('option', { value: s.id, selected: s.id == 9 }, s.nome)));
  const desc = el('textarea', { placeholder: 'Opcional: o que tem nesta imagem, versão, data' });
  const tipos = el('div', { class: 'escolhas uma-coluna', role: 'radiogroup' }, TIPOS_IMAGEM.map(([v, t, d]) => el('label', { class: 'escolha', 'data-cor': 'imagens' },
    el('input', { type: 'radio', name: 'tipo-img', value: v, checked: v === 1 }),
    el('div', { class: 'corpo' }, el('strong', { style: 'white-space:normal' }, t, v === 1 ? ' ' : null, v === 1 ? pilula('recomendado', 'ok sem-ponto') : null), el('div', { class: 'meta' }, d)),
    el('span', { class: 'marca-sel' }, icone('check', '')))));
  return modal({
    titulo: 'Nova imagem', icone: 'imagens', cor: 'imagens', texto: 'A imagem começa vazia. Depois é só capturar uma máquina modelo para ela.',
    conteudo: el('div', {}, campo('Nome da imagem', nome), campo('Sistema operacional', so, 'Windows e Linux na mesma máquina? Escolha o Windows.'),
      el('div', { class: 'campo' }, el('span', { class: 'rot' }, 'Tipo de imagem'), tipos), campo('Descrição', desc)),
    botoes: [{ texto: 'Cancelar', classe: 'fantasma' }, { texto: 'Criar imagem', classe: 'primario', acao: async () => {
      const r = await api('criar_imagem', { nome: nome.value.trim(), os_id: Number(so.value), tipo: Number(tipos.querySelector('input:checked').value), descricao: desc.value });
      aviso(r.msg);
      return r;
    } }],
  });
}

function compat(i) {
  const d = i.disco;
  if (!d.existe || !d.bytes) return { texto: 'Vazia: ainda não tem captura.', curto: 'Vazia', tipo: 'aviso' };
  if (i.redimensionavel) return { texto: d.minimo_bytes ? `Cabe em discos a partir de ~${gb(d.minimo_bytes)}.` : 'Cabe em discos de tamanhos variados.', curto: 'Redimensionável', tipo: 'ok' };
  return { texto: d.minimo_bytes ? `Só restaura em discos com pelo menos ${gb(d.minimo_bytes)}.` : 'Só restaura em discos iguais ou maiores que o original.', curto: 'Tamanho fixo', tipo: 'aviso' };
}

// ================================================================ máquinas

async function telaMaquinas(alvo, p) {
  const [dados, img] = await Promise.all([api('maquinas'), api('imagens')]);
  const imagens = img.imagens;
  const { input: campoBusca, no: noBusca } = busca('Procurar por nome, MAC ou imagem');
  const lista = el('div', { class: 'lista' });
  const contagem = el('span', { class: 'small muted' });
  // depois de editar/excluir, volta à mesma busca e à mesma posição da lista
  const recarregar = async (r) => {
    if (!r) return;
    const termo = campoBusca.value, y = window.scrollY;
    await navegar();
    const novo = document.querySelector('#conteudo .busca input');
    if (novo && termo) { novo.value = termo; novo.dispatchEvent(new Event('input')); }
    setTimeout(() => window.scrollTo(0, y), 200);
  };
  const filtroBox = el('div');
  let filtro = 'todas';
  const FILTROS = [['todas', 'Todas', () => true], ['ocupadas', 'Em tarefa agora', (m) => m.tarefa], ['nunca', 'Nunca clonadas', (m) => !m.tarefa && !m.ultima],
    ['sem-imagem', 'Sem imagem padrão', (m) => !m.imagem_id]];
  const desenhar = () => {
    const termo = campoBusca.value.trim().toLowerCase();
    const regra = FILTROS.find((f) => f[0] === filtro)[2];
    filtroBox.replaceChildren(dados.maquinas.length ? filtros(FILTROS.map(([id, rot, f]) => [id, rot, dados.maquinas.filter(f).length]), filtro, (f) => { filtro = f; desenhar(); }) : null);
    const itens = dados.maquinas.filter((m) => regra(m) && (!termo || [m.nome, m.mac, m.imagem, m.descricao].join(' ').toLowerCase().includes(termo)));
    contagem.textContent = termo || filtro !== 'todas' ? `${itens.length} de ${dados.maquinas.length}` : plural(dados.maquinas.length, 'máquina', 'máquinas');
    lista.replaceChildren(...itens.map((m) => {
      const status = m.tarefa ? pilula(`${m.tarefa.tipo}${m.tarefa.estado == 3 ? ` · ${m.tarefa.pct}%` : ' agendada'}`, 'info', true)
        : m.ultima ? pilula(`Última: ${m.ultima.tipo.toLowerCase()} ${haQuanto(m.ultima.quando)}`, 'ok') : pilula('Nunca clonada', '');
      const mais = el('button', { class: 'btn p icone fantasma', type: 'button', 'aria-label': `Mais ações para ${m.nome}` }, icone('pontos'));
      return el('div', { class: 'linha quebra', 'data-cor': 'maquinas' }, ladrilho('maquinas', 'maquinas', 'p'),
        el('div', { class: 'corpo' }, el('strong', {}, m.nome),
          el('div', { class: 'meta' }, el('span', { class: 'mono' }, m.mac || '—'), el('span', {}, m.imagem ? `Imagem: ${m.imagem}` : 'Sem imagem padrão'),
            m.rede ? el('span', {}, `visto na rede ${haQuanto(m.rede.visto)}`) : null)),
        el('div', { class: 'lado' }, status,
          // máquina ocupada não pode receber outra tarefa: o botão leva ao acompanhamento
          m.tarefa ? el('a', { class: 'btn p', href: '#/tarefas' }, icone('tarefas'), 'Acompanhar')
            : el('a', { class: 'btn p', href: `#/restaurar?maquina=${m.id}${m.imagem_id ? `&imagem=${m.imagem_id}` : ''}` }, icone('restaurar'), 'Restaurar'),
          menuSuspenso(mais, [
            { texto: 'Capturar desta máquina', icone: 'capturar', href: `#/capturar?maquina=${m.id}` },
            { texto: 'Editar', icone: 'editar', acao: async () => recarregar(await formMaquina(m, imagens)) },
            '-',
            { texto: 'Excluir', icone: 'lixo', perigo: true, acao: async () => {
              if (!await confirmar(`Excluir ${m.nome}?`, 'A máquina sai da lista, junto com o histórico dela. As imagens não são afetadas e ela pode ser cadastrada de novo depois.', 'Excluir máquina', true)) return;
              try { aviso((await api('excluir_maquina', { id: m.id })).msg); recarregar(true); } catch (e) { aviso(e.message, true); }
            } },
          ])));
    }));
    if (!itens.length) lista.replaceChildren(termo || filtro !== 'todas' ? vazio('buscar', 'Nenhuma máquina encontrada', termo ? `Nada corresponde a “${campoBusca.value}”.` : 'Nenhuma máquina neste filtro.')
      : vazio('maquinas', 'Nenhuma máquina cadastrada', 'Cadastre manualmente ou dê boot pela rede numa máquina para ela aparecer em “Detectadas na rede”.'));
  };
  campoBusca.addEventListener('input', aoParar(desenhar));
  desenhar();

  const novas = dados.nao_cadastradas;
  alvo.replaceChildren(
    cabPagina('maquinas', 'Computadores cadastrados no sistema.',
      el('button', { class: 'btn primario', type: 'button', onclick: async () => recarregar(await formMaquina(null, imagens)) }, icone('mais'), 'Cadastrar máquina')),
    novas.length ? el('div', { class: 'cartao', 'data-cor': 'maquinas', style: 'border-color:color-mix(in srgb, var(--c) 35%, var(--border));margin-bottom:16px' },
      el('div', { class: 'cartao-cab' }, el('div', { class: 'titulo' }, ladrilho('rede', 'maquinas', 'p'), el('div', {}, el('h2', {}, 'Detectadas na rede'), el('div', { class: 'small muted' }, 'Deram boot pela rede de clonagem e ainda não estão cadastradas.'))),
        pilula(plural(novas.length, 'nova', 'novas'), 'info')),
      el('div', { class: 'lista' }, novas.map((n) => el('div', { class: 'linha quebra' }, ladrilho('rede', 'ajuda', 'p'),
        el('div', { class: 'corpo' }, el('strong', { class: 'mono' }, n.mac), el('div', { class: 'meta' }, n.nome_rede ? el('span', {}, `Nome: ${n.nome_rede}`) : null, el('span', {}, `IP ${n.ip}`), el('span', {}, `visto ${haQuanto(n.visto)}`))),
        el('div', { class: 'lado' }, el('button', { class: 'btn p cor', 'data-cor': 'maquinas', type: 'button', onclick: async () => {
          const sugestao = (n.nome_rede || '').replace(/[^A-Za-z0-9-]/g, '').slice(0, 15).toUpperCase();
          recarregar(await formMaquina(null, imagens, { mac: n.mac, nome: sugestao }));
        } }, icone('mais'), 'Cadastrar')))))) : null,
    el('div', { class: 'faixa info', style: 'margin-bottom:16px;align-items:center' }, icone('info'), el('div', { style: 'flex:1' },
      el('strong', {}, 'Máquina nova? Cadastre pelo boot em 2 passos'),
      'Dê boot pela rede, escolha "Cadastrar esta maquina", confirme o nome sugerido e a imagem.'),
      el('a', { class: 'btn p', href: '#/configuracoes?aba=cadastro' }, 'Como funciona')),
    el('div', { class: 'cartao' },
      el('div', { class: 'cartao-cab' }, el('div', { style: 'flex:1;min-width:220px' }, noBusca), contagem),
      filtroBox, lista));
  if (p.get('novo')) {
    history.replaceState(null, '', '#/maquinas');
    recarregar(await formMaquina(null, imagens));
  }
}

// ================================================================ imagens

async function telaImagens(alvo) {
  const { imagens, sistemas } = await api('imagens');
  const fato = (rot, val) => el('div', { class: 'fato' }, el('div', { class: 'rot' }, rot), el('div', { class: 'val' }, val));
  alvo.replaceChildren(
    cabPagina('imagens', 'Cópias de disco guardadas no servidor.',
      el('button', { class: 'btn primario', type: 'button', onclick: async () => { if (await formImagem(sistemas)) navegar(); } }, icone('mais'), 'Nova imagem')),
    imagens.length ? el('div', { class: 'grade g2' }, imagens.map((i) => {
      const c = compat(i);
      const mais = el('button', { class: 'btn icone fantasma', type: 'button', 'aria-label': `Mais ações para ${i.nome}` }, icone('pontos'));
      return el('div', { class: 'cartao img-cartao', 'data-cor': 'imagens' },
        el('div', { class: 'cartao-cab', style: 'margin:0;align-items:flex-start;flex-wrap:nowrap' },
          el('div', { class: 'titulo', style: 'min-width:0' }, ladrilho('imagens', 'imagens'), el('div', { style: 'min-width:0' }, el('h2', {}, i.nome), el('div', { class: 'small muted' }, i.descricao || i.os || ''))),
          menuSuspenso(mais, [
            { texto: 'Capturar para esta imagem', icone: 'capturar', href: `#/capturar?imagem=${i.id}` },
            { texto: 'Editar nome, descrição e tipo', icone: 'editar', acao: () => editarImagem(i, sistemas) },
            '-',
            { texto: 'Excluir imagem', icone: 'lixo', perigo: true, acao: () => excluirImagem(i) },
          ])),
        el('div', { class: 'botoes' }, pilula(c.curto, c.tipo), i.protegida == 1 ? pilula('Protegida', 'info') : null, pilula(i.os || 'Sistema não informado', 'sem-ponto')),
        el('div', { class: 'fatos' },
          fato('Tamanho', i.disco.bytes ? gb(i.disco.bytes) : '—'),
          fato('Última captura', i.disco.capturada ? haQuanto(i.disco.capturada) : 'nunca'),
          fato('Disco de origem', rotuloDisco(i.disco.disco_origem)),
          fato('Máquinas usando', String(i.maquinas))),
        el('div', { class: `faixa ${c.tipo}` }, icone(c.tipo === 'ok' ? 'ok' : 'alerta'), el('div', {}, c.texto)),
        el('div', { class: 'botoes' }, i.disco.bytes ? [
          el('a', { class: 'btn cor', 'data-cor': 'restaurar', href: `#/restaurar?imagem=${i.id}` }, icone('restaurar'), 'Restaurar'),
          el('a', { class: 'btn', href: `#/grupo?imagem=${i.id}` }, icone('grupo'), 'Em grupo'),
        ] : el('a', { class: 'btn cor', 'data-cor': 'capturar', href: `#/capturar?imagem=${i.id}` }, icone('capturar'), 'Capturar agora')));
    })) : el('div', { class: 'cartao' }, vazio('imagens', 'Nenhuma imagem ainda', 'Crie uma imagem e capture uma máquina modelo para ela.',
      el('a', { class: 'btn primario', href: '#/capturar' }, icone('capturar'), 'Capturar primeira imagem'))));
}

async function editarImagem(i, sistemas) {
  const nome = el('input', { type: 'text', maxlength: '40', value: i.nome });
  const desc = el('textarea', {}, i.descricao || '');
  const so = el('select', {}, (sistemas || []).map((x) => el('option', { value: x.id, selected: String(x.id) === String(i.os_id) }, x.nome)));
  const vazia = !i.disco.bytes;
  const tipo = el('select', { disabled: !vazia }, TIPOS_IMAGEM.map(([v, t]) => el('option', { value: v, selected: String(v) === String(i.tipo) }, t)));
  const prot = el('input', { type: 'checkbox', checked: i.protegida == 1 });
  const ok = await modal({ titulo: `Editar ${i.nome}`, icone: 'imagens', cor: 'imagens',
    conteudo: el('div', {}, campo('Nome', nome, `A pasta no servidor continua /images/${i.caminho}.`), campo('Descrição', desc),
      el('div', { class: 'grade', style: 'grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px' },
        campo('Sistema operacional', so), campo('Tipo de imagem', tipo, vazia ? null : 'Só dá para mudar com a imagem vazia.')),
      el('label', { class: 'alternar' }, prot, el('div', {}, el('strong', {}, 'Proteger contra sobrescrita e exclusão'), el('small', {}, 'Impede capturar por cima ou excluir esta imagem sem querer.')))),
    botoes: [{ texto: 'Cancelar', classe: 'fantasma' }, { texto: 'Salvar', classe: 'primario', acao: async () => {
      const dados = { id: i.id, nome: nome.value.trim(), descricao: desc.value, protegida: prot.checked };
      if (sistemas && sistemas.length) dados.os_id = Number(so.value);
      if (vazia) dados.tipo = Number(tipo.value);
      aviso((await api('editar_imagem', dados)).msg);
    } }] });
  if (ok) navegar();
}

async function excluirImagem(i) {
  const apagar = el('input', { type: 'checkbox', checked: true });
  const ok = await modal({ titulo: `Excluir ${i.nome}?`, icone: 'alerta', cor: 'maquinas',
    texto: i.maquinas > 0 ? `${plural(Number(i.maquinas), 'máquina usa', 'máquinas usam')} esta imagem como padrão; elas ficam sem imagem padrão.` : 'A imagem sai da lista.',
    conteudo: el('div', {},
      el('label', { class: 'alternar' }, apagar, el('div', {}, el('strong', {}, `Apagar também os arquivos (${i.disco.bytes ? gb(i.disco.bytes) : 'vazia'})`),
        el('small', {}, 'Libera espaço no servidor. Sem isso, a pasta continua em /images e pode ser recuperada manualmente.'))),
      el('div', { class: 'faixa ruim', style: 'margin-top:12px' }, icone('alerta'), el('div', {}, 'Isso não pode ser desfeito.'))),
    botoes: [{ texto: 'Voltar', classe: 'fantasma' }, { texto: 'Excluir imagem', classe: 'perigo-cheio', acao: async () => {
      aviso((await api('excluir_imagem', { id: i.id, apagar_arquivos: apagar.checked })).msg);
    } }] });
  if (ok) navegar();
}

async function trocarMinhaSenha() {
  const atual = el('input', { type: 'password', autocomplete: 'current-password' });
  const nova = el('input', { type: 'password', autocomplete: 'new-password' });
  const repetir = el('input', { type: 'password', autocomplete: 'new-password' });
  await modal({ titulo: 'Trocar minha senha', icone: 'escudo', cor: 'configuracoes',
    conteudo: el('div', {}, campo('Senha atual', atual), campo('Nova senha', nova, 'Pelo menos 8 caracteres.'), campo('Repita a nova senha', repetir)),
    botoes: [{ texto: 'Cancelar', classe: 'fantasma' }, { texto: 'Trocar senha', classe: 'primario', acao: async () => {
      if (nova.value !== repetir.value) throw new Error('As duas senhas novas não são iguais.');
      aviso((await api('minha_senha', { atual: atual.value, nova: nova.value })).msg);
    } }] });
}

// ================================================================ usuários

async function telaUsuarios(alvo) {
  const { usuarios } = await api('usuarios');
  const novo = async () => {
    const nome = el('input', { type: 'text', autocomplete: 'off', placeholder: 'ex.: tecnico1' });
    const senha = el('input', { type: 'password', autocomplete: 'new-password' });
    const minha = el('input', { type: 'password', autocomplete: 'current-password' });
    const ok = await modal({ titulo: 'Novo usuário', icone: 'usuarios', cor: 'configuracoes', texto: 'Todos os usuários podem usar todas as funções do sistema.',
      conteudo: el('div', {}, campo('Nome de usuário', nome, '3 a 40 caracteres, começando com letra (ex.: tecnico1, joao.silva).'), campo('Senha', senha, 'Pelo menos 8 caracteres.'),
        campo(`Sua senha (${sessao.usuario})`, minha, 'Para confirmar que é você.')),
      botoes: [{ texto: 'Cancelar', classe: 'fantasma' }, { texto: 'Criar usuário', classe: 'primario', acao: async () => aviso((await api('criar_usuario', { nome: nome.value.trim(), senha: senha.value, minha_senha: minha.value })).msg) }] });
    if (ok) navegar();
  };
  const minhaConfirmacao = () => el('input', { type: 'password', autocomplete: 'current-password' });
  const senhaDe = async (u) => {
    const senha = el('input', { type: 'password', autocomplete: 'new-password' });
    const minha = minhaConfirmacao();
    await modal({ titulo: `Nova senha para ${u.nome}`, icone: 'escudo', cor: 'configuracoes',
      conteudo: el('div', {}, campo('Nova senha', senha, 'Pelo menos 8 caracteres.'), campo(`Sua senha (${sessao.usuario})`, minha, 'Para confirmar que é você.')),
      botoes: [{ texto: 'Cancelar', classe: 'fantasma' }, { texto: 'Definir senha', classe: 'primario', acao: async () => aviso((await api('senha_usuario', { id: u.id, senha: senha.value, minha_senha: minha.value })).msg) }] });
  };
  alvo.replaceChildren(
    cabPagina('usuarios', 'Quem pode entrar neste painel.', el('button', { class: 'btn primario', type: 'button', onclick: novo }, icone('mais'), 'Novo usuário')),
    el('div', { class: 'cartao' }, el('div', { class: 'lista' }, usuarios.map((u) => {
      const mais = el('button', { class: 'btn p icone fantasma', type: 'button', 'aria-label': `Ações para ${u.nome}` }, icone('pontos'));
      return el('div', { class: 'linha quebra', 'data-cor': 'configuracoes' }, el('span', { class: 'avatar', style: 'cursor:default;flex:none' }, u.nome.slice(0, 1).toUpperCase()),
        el('div', { class: 'corpo' }, el('strong', {}, u.nome, u.eu ? ' ' : null, u.eu ? pilula('você', 'info sem-ponto') : null),
          el('div', { class: 'meta' }, u.criado ? el('span', {}, `criado em ${dataHora(u.criado)}`) : null, u.criado_por ? el('span', {}, `por ${u.criado_por}`) : null)),
        el('div', { class: 'lado' }, menuSuspenso(mais, [
          u.eu ? { texto: 'Trocar minha senha', icone: 'escudo', acao: trocarMinhaSenha } : { texto: 'Definir nova senha', icone: 'escudo', acao: () => senhaDe(u) },
          ...(u.eu ? [] : ['-', { texto: 'Excluir usuário', icone: 'lixo', perigo: true, acao: async () => {
            const minha = minhaConfirmacao();
            const ok = await modal({ titulo: `Excluir ${u.nome}?`, texto: 'Esta pessoa não vai mais conseguir entrar no painel.', icone: 'alerta', cor: 'maquinas',
              conteudo: campo(`Sua senha (${sessao.usuario})`, minha, 'Para confirmar que é você.'),
              botoes: [{ texto: 'Voltar', classe: 'fantasma' }, { texto: 'Excluir usuário', classe: 'perigo-cheio', acao: async () => aviso((await api('excluir_usuario', { id: u.id, minha_senha: minha.value })).msg) }] });
            if (ok) navegar();
          } }]),
        ])));
    }))));
}

// ================================================================ assistente genérico

/**
 * cfg: { id, sub, botaoFinal, etapas: [{ nome, titulo, sub, desenhar(est, redesenhar) -> Node, validar(est) -> string|null }],
 *        finalizar(est) -> Promise (false = cancelado), sucesso(est, resultado) -> Node }
 */
function assistente(alvo, cfg, est) {
  let atual = 0;
  const cor = ROTAS[cfg.id].cor;
  const corpo = el('div');
  const passos = el('ol', { class: 'etapas', 'aria-label': 'Etapas' });
  const voltar = el('button', { class: 'btn fantasma', type: 'button' }, icone('voltar'), 'Voltar');
  const seguir = el('button', { class: 'btn cor g', type: 'button' });
  const desenhar = () => {
    passos.replaceChildren(...cfg.etapas.map((e, i) => el('li', { class: i < atual ? 'feita' : i === atual ? 'atual' : null, 'aria-current': i === atual ? 'step' : null },
      el('span', { class: 'n' }, i < atual ? icone('check') : String(i + 1)), el('span', { class: 'txt' }, e.nome), i < cfg.etapas.length - 1 ? el('span', { class: 'traco' }) : null)));
    const e = cfg.etapas[atual];
    corpo.replaceChildren(el('h2', { class: 'passo-titulo' }, e.titulo), e.sub ? el('p', { class: 'passo-sub' }, e.sub) : null, e.desenhar(est, desenhar));
    voltar.style.visibility = atual === 0 ? 'hidden' : 'visible';
    const ultimo = atual === cfg.etapas.length - 1;
    seguir.replaceChildren(ultimo ? cfg.botaoFinal : 'Continuar', icone(ultimo ? ROTAS[cfg.id].icone : 'seta'));
  };
  const topo = () => window.scrollTo({ top: 0, behavior: 'smooth' });
  // um duplo clique em "Continuar" não pode pular a revisão e já executar a ação final
  let travadoAte = 0;
  let enviando = false;
  voltar.addEventListener('click', () => { if (atual > 0 && !enviando) { atual--; desenhar(); topo(); } });
  seguir.addEventListener('click', async () => {
    if (Date.now() < travadoAte || enviando) return;
    const e = cfg.etapas[atual];
    const erro = e.validar ? e.validar(est) : null;
    if (erro) return aviso(erro, true);
    if (atual < cfg.etapas.length - 1) { atual++; desenhar(); topo(); travadoAte = Date.now() + 800; return; }
    enviando = true;
    seguir.disabled = true;
    try {
      const r = await cfg.finalizar(est);
      if (r === false) { seguir.disabled = false; enviando = false; return; }
      if (!alvo.isConnected) return; // a pessoa já saiu desta tela
      alvo.replaceChildren(cabPagina(cfg.id, cfg.sub), el('div', { class: 'assistente', 'data-cor': cor }, cfg.sucesso(est, r)));
      window.scrollTo(0, 0);
    } catch (err) { aviso(err.message, true); seguir.disabled = false; enviando = false; }
  });
  alvo.replaceChildren(cabPagina(cfg.id, cfg.sub),
    el('div', { class: 'assistente', 'data-cor': cor }, passos,
      el('div', { class: 'cartao' }, corpo, el('div', { class: 'rodape-assistente' }, voltar, seguir))));
  desenhar();
}

function escolhas({ tipo = 'radio', nome, itens, selecionado, titulo, meta, icone: ic, cor, desabilitado, aoMudar, extra, umaColuna }) {
  const grupo = el('div', { class: 'escolhas' + (umaColuna ? ' uma-coluna' : ''), role: tipo === 'radio' ? 'radiogroup' : 'group' });
  itens.forEach((it) => {
    const marcado = tipo === 'radio' ? String(selecionado) === String(it.id) : selecionado.has(String(it.id));
    const inp = el('input', { type: tipo, name: nome, value: it.id, checked: marcado, disabled: desabilitado ? !!desabilitado(it) : false });
    // classe "sel" além do :has() do CSS: navegadores antigos (ex.: Firefox ESR 115) também mostram o que está marcado
    inp.addEventListener('change', () => {
      grupo.querySelectorAll('.escolha > input').forEach((x) => x.parentElement.classList.toggle('sel', x.checked));
      aoMudar(String(it.id), inp.checked);
    });
    grupo.append(el('label', { class: 'escolha' + (tipo === 'checkbox' ? ' caixa' : '') + (marcado ? ' sel' : '') + (inp.disabled ? ' desab' : ''), 'data-cor': cor }, inp,
      ic ? ladrilho(ic, cor, 'p') : null,
      el('div', { class: 'corpo' }, el('strong', {}, titulo(it)), meta ? el('div', { class: 'meta' }, meta(it)) : null),
      el('span', { class: 'marca-sel' }, icone('check', ''))));
  });
  if (extra) grupo.append(extra);
  return grupo;
}

const cartaoNovo = (texto, acao) => el('button', { type: 'button', class: 'escolha nova', onclick: acao, style: 'font:inherit' }, icone('mais'), el('span', { class: 'forte' }, texto));

function opcoesTarefa(est) {
  const wol = el('input', { type: 'checkbox', checked: est.wol });
  const desl = el('input', { type: 'checkbox', checked: est.desligar });
  wol.addEventListener('change', () => { est.wol = wol.checked; });
  desl.addEventListener('change', () => { est.desligar = desl.checked; });
  return el('div', {},
    el('label', { class: 'alternar' }, wol, el('div', {}, el('strong', {}, 'Tentar ligar pela rede (Wake-on-LAN)'), el('small', {}, 'Só funciona se estiver ativado na BIOS. Se não ligar sozinha, ligue manualmente.'))),
    el('label', { class: 'alternar' }, desl, el('div', {}, el('strong', {}, 'Desligar quando terminar'), el('small', {}, 'Desmarcado: a máquina reinicia sozinha no sistema.'))));
}

const resumo = (linhas) => el('div', { class: 'resumo' }, linhas.map(([ic, cor, rot, val]) => el('div', {}, ladrilho(ic, cor, 'p'), el('div', { style: 'min-width:0' }, el('div', { class: 'rot' }, rot), el('div', { class: 'val' }, val)))));

function passosBoot(ultimo) {
  return el('ol', { class: 'passos-boot' },
    el('li', {}, el('div', {}, 'Ligue a máquina conectada na rede de clonagem (ou reinicie, se já estiver ligada).')),
    el('li', {}, el('div', {}, 'Assim que aparecer o logotipo, aperte a tecla do menu de boot: ', teclasBoot(), '.')),
    el('li', {}, el('div', {}, 'Escolha a opção de rede: “Network”, “PXE”, “IPv4” ou “Onboard NIC”.')),
    el('li', {}, el('div', {}, ultimo)));
}

/** Acompanha ao vivo as tarefas recém-agendadas para as máquinas indicadas. */
function acompanhamento(nomes, cor, ids) {
  const caixa = el('div');
  let anterior = '';
  const desenhar = async () => {
    let itens;
    if (ids && ids.length) {
      // pelos ids das tarefas criadas: funciona com qualquer quantidade de máquinas
      itens = (await api(`acompanhar&ids=${ids.join(',')}`)).tarefas;
    } else {
      const t = await api('tarefas');
      itens = nomes.map((n) => t.ativas.find((x) => x.maquina === n) || { maquina: n, ...(t.historico.find((x) => x.maquina === n) || { estado: 1, estado_texto: 'Aguardando' }) });
    }
    const chave = chaveVivo(itens);
    if (chave === anterior || interagindo(caixa)) return;
    anterior = chave;
    caixa.replaceChildren(...itens.map((a) => {
      if (a.fase) return cartaoTarefa(a, false, cor);
      return el('div', { class: 'tarefa-viva', 'data-cor': cor },
        el('div', { class: 'topo-t', style: 'margin:0' }, ladrilho('maquinas', 'maquinas', 'p'), el('strong', { style: 'flex:1;min-width:0' }, a.maquina),
          pilulaEstado(a.estado, a.estado_texto),
          a.tem_registro || Number(a.estado) === 6 ? el('button', { class: 'btn p fantasma', type: 'button', onclick: () => verRegistro({ ...a, tipo: '', imagem: '' }) }, 'Ver registro') : null),
        a.motivo ? el('div', { class: 'motivo ruim' }, icone('alerta'), el('span', {}, a.motivo)) : null);
    }));
  };
  desenhar().catch(() => {});
  repetir(desenhar, 3000);
  return caixa;
}

function telaSucesso(titulo, texto, nomes, cor, ultimoPasso, ids) {
  return el('div', { class: 'pilha' },
    el('div', { class: 'heroi' }, ladrilho('ok', '', 'g'), el('div', { style: 'position:relative;z-index:1' }, el('h2', {}, titulo), el('p', {}, texto))),
    el('div', { class: 'cartao', 'data-cor': cor }, el('div', { class: 'cartao-cab' }, el('h2', {}, nomes.length === 1 ? 'Agora, na máquina' : 'Agora, em cada máquina')), passosBoot(ultimoPasso)),
    el('div', { class: 'cartao' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'Acompanhe aqui'), pilula('ao vivo', 'info', true)), acompanhamento(nomes, cor, ids),
      el('div', { class: 'botoes', style: 'margin-top:16px' }, el('a', { class: 'btn', href: '#/tarefas' }, 'Ver todas as tarefas'), el('a', { class: 'btn fantasma', href: '#/inicio' }, 'Voltar ao início'))));
}

function semImagens(alvo, id) {
  alvo.replaceChildren(cabPagina(id), el('div', { class: 'cartao' }, vazio('imagens', 'Nenhuma imagem capturada ainda', 'Antes de restaurar, capture uma máquina modelo.',
    el('a', { class: 'btn primario', href: '#/capturar' }, icone('capturar'), 'Capturar imagem'))));
}

// ================================================================ capturar

async function telaCapturar(alvo, p) {
  const [dm, di] = await Promise.all([api('maquinas'), api('imagens')]);
  const est = { maquinas: dm.maquinas, imagens: di.imagens, sistemas: di.sistemas, maquina: p.get('maquina') || '', imagem: p.get('imagem') || '', wol: true, desligar: false, filtro: '' };
  if (!est.imagens.some((x) => String(x.id) === est.imagem)) est.imagem = '';
  if (!est.maquinas.some((x) => String(x.id) === est.maquina)) est.maquina = '';
  if (!est.imagem && est.maquina) {
    const m = est.maquinas.find((x) => String(x.id) === est.maquina);
    if (m && m.imagem_id) est.imagem = String(m.imagem_id);
  }
  const maq = () => est.maquinas.find((x) => String(x.id) === est.maquina);
  const img = () => est.imagens.find((x) => String(x.id) === est.imagem);
  assistente(alvo, {
    id: 'capturar', sub: 'Copia o disco de uma máquina modelo para o servidor.', botaoFinal: 'Agendar captura',
    etapas: [
      { nome: 'Máquina', titulo: 'Qual máquina é o modelo?', sub: 'A máquina já configurada, com tudo instalado, que vai ser copiada.',
        desenhar: (e, redesenhar) => {
          const { input, no } = busca('Procurar máquina');
          input.value = e.filtro;
          const lista = el('div');
          const pintar = () => {
            const termo = e.filtro.toLowerCase();
            lista.replaceChildren(escolhas({ nome: 'maquina', cor: 'capturar', icone: 'maquinas', selecionado: e.maquina,
              itens: e.maquinas.filter((m) => !termo || `${m.nome} ${m.mac}`.toLowerCase().includes(termo)),
              titulo: (m) => m.nome, meta: (m) => (m.tarefa ? `Ocupada: ${m.tarefa.tipo.toLowerCase()}` : m.mac),
              desabilitado: (m) => m.tarefa,
              aoMudar: (id) => { e.maquina = id; const m = maq(); if (m && m.imagem_id && !e.imagem) e.imagem = String(m.imagem_id); },
              extra: cartaoNovo('Cadastrar outra máquina', async () => {
                const r = await formMaquina(null, e.imagens);
                if (r && r.id) { e.maquinas = (await api('maquinas')).maquinas; e.maquina = String(r.id); redesenhar(); }
              }) }));
          };
          input.addEventListener('input', aoParar(() => { e.filtro = input.value; pintar(); }));
          pintar();
          return el('div', {}, e.maquinas.length > 6 ? el('div', { style: 'margin-bottom:12px' }, no) : null, lista);
        },
        validar: (e) => (e.maquina ? null : 'Escolha a máquina modelo.') },
      { nome: 'Imagem', titulo: 'Onde guardar a cópia?', sub: 'Escolha uma imagem existente ou crie uma nova.',
        desenhar: (e, redesenhar) => {
          const alertaBox = el('div', { style: 'margin-top:14px' });
          const atualizar = () => {
            const i = img();
            alertaBox.replaceChildren(i && i.protegida == 1 ? el('div', { class: 'faixa ruim' }, icone('escudo'), el('div', {}, el('strong', {}, 'Esta imagem está protegida'), 'Desmarque a proteção em Imagens para poder substituí-la, ou crie uma imagem nova.'))
              : i && i.disco.bytes ? el('div', { class: 'faixa aviso' }, icone('alerta'), el('div', {}, el('strong', {}, `A versão atual de “${i.nome}” será substituída`), `Ela tem ${gb(i.disco.bytes)} e foi capturada ${haQuanto(i.disco.capturada)}.`)) : '');
          };
          const g = escolhas({ nome: 'imagem', cor: 'capturar', icone: 'imagens', selecionado: e.imagem, itens: e.imagens,
            titulo: (i) => i.nome, meta: (i) => (i.disco.bytes ? `${gb(i.disco.bytes)} · capturada ${haQuanto(i.disco.capturada)}` : 'Vazia, pronta para receber'),
            aoMudar: (id) => { e.imagem = id; atualizar(); },
            extra: cartaoNovo('Criar nova imagem', async () => {
              const r = await formImagem(e.sistemas);
              if (r && r.id) { e.imagens = (await api('imagens')).imagens; e.imagem = String(r.id); redesenhar(); }
            }) });
          atualizar();
          return el('div', {}, g, alertaBox);
        },
        validar: (e) => (!e.imagem ? 'Escolha ou crie uma imagem.' : img()?.protegida == 1 ? 'Esta imagem está protegida contra sobrescrita.' : null) },
      { nome: 'Revisar', titulo: 'Tudo certo?', sub: 'Confira antes de agendar.',
        desenhar: (e) => el('div', {},
          resumo([['maquinas', 'maquinas', 'Copiar o disco da máquina', maq()?.nome], ['imagens', 'imagens', img()?.disco.bytes ? 'Substituindo a imagem' : 'Para a imagem', img()?.nome]]),
          el('div', { style: 'margin-top:14px' }, opcoesTarefa(e)),
          el('div', { class: 'faixa info', style: 'margin-top:14px' }, icone('info'), el('div', {}, el('strong', {}, 'Capturando um Windows?'),
            'Desligue pelo menu Iniciar segurando Shift (desliga de verdade, sem “inicialização rápida”) e desative o BitLocker se estiver ligado.'))) },
    ],
    finalizar: async (e) => {
      if (img()?.disco.bytes && !await confirmar('Substituir a imagem?', `“${img().nome}” já tem uma captura. Ela será trocada pelo disco de “${maq().nome}”.`, 'Substituir', true)) return false;
      return api('capturar', { maquina_id: Number(e.maquina), imagem_id: Number(e.imagem), wol: e.wol, desligar: e.desligar });
    },
    sucesso: (e, r) => telaSucesso('Captura agendada!', `O disco de “${maq().nome}” vai ser copiado para “${img().nome}”. Leva de 10 a 40 minutos.`, [maq().nome], 'capturar',
      'A captura começa sozinha. Ao terminar, a máquina mostra o resultado na tela e espera você escolher reiniciar ou desligar.', r && r.tarefas),
  }, est);
}

// ================================================================ restaurar

async function telaRestaurar(alvo, p) {
  const [dm, di] = await Promise.all([api('maquinas'), api('imagens')]);
  const imagens = di.imagens.filter((i) => i.disco.bytes);
  if (!imagens.length) return semImagens(alvo, 'restaurar');
  const est = { maquinas: dm.maquinas, imagens, imagem: p.get('imagem') || (imagens.length === 1 ? String(imagens[0].id) : ''),
    selecionadas: new Set((p.get('maquina') || '').split(',').filter(Boolean)), wol: true, desligar: false, filtro: '' };
  const img = () => est.imagens.find((x) => String(x.id) === est.imagem);
  if (!img()) est.imagem = ''; // imagem do link vazia ou excluída: a pessoa escolhe
  const nomesSel = () => est.maquinas.filter((m) => est.selecionadas.has(String(m.id))).map((m) => m.nome);
  assistente(alvo, {
    id: 'restaurar', sub: 'Grava uma imagem em uma ou mais máquinas cadastradas.', botaoFinal: 'Apagar e restaurar',
    etapas: [
      { nome: 'Imagem', titulo: 'Qual imagem gravar?', sub: 'O conteúdo atual do disco das máquinas será substituído por ela.',
        desenhar: (e) => {
          const dica = el('div', { style: 'margin-top:14px' });
          const atualizar = () => {
            const i = img();
            dica.replaceChildren(i && !i.redimensionavel ? el('div', { class: 'faixa aviso' }, icone('alerta'), el('div', {}, el('strong', {}, compat(i).texto),
              'Em máquinas com disco menor (por exemplo, NVMe de 120 GB) a restauração falha logo no início.')) : '');
          };
          const g = escolhas({ nome: 'imagem', cor: 'restaurar', icone: 'imagens', selecionado: e.imagem, itens: e.imagens, umaColuna: e.imagens.length < 3,
            titulo: (i) => i.nome, meta: (i) => `${gb(i.disco.bytes)} · ${compat(i).curto} · capturada ${haQuanto(i.disco.capturada)}`,
            aoMudar: (id) => { e.imagem = id; atualizar(); } });
          atualizar();
          return el('div', {}, g, dica);
        },
        validar: (e) => (e.imagem ? null : 'Escolha a imagem.') },
      { nome: 'Máquinas', titulo: 'Em quais máquinas?', sub: 'Marque uma ou mais.',
        desenhar: (e) => {
          const { input, no } = busca('Filtrar máquinas');
          input.value = e.filtro;
          const cont = el('span', { class: 'pilula info sem-ponto' });
          const lista = el('div');
          const contar = () => { const n = e.selecionadas.size; cont.textContent = n ? plural(n, 'selecionada', 'selecionadas') : 'Nenhuma selecionada'; };
          const pintar = () => {
            const termo = e.filtro.toLowerCase();
            const visiveis = e.maquinas.filter((m) => !termo || `${m.nome} ${m.mac} ${m.imagem}`.toLowerCase().includes(termo));
            lista.replaceChildren(visiveis.length ? escolhas({ tipo: 'checkbox', nome: 'maquinas', cor: 'restaurar', icone: 'maquinas', selecionado: e.selecionadas, itens: visiveis,
              titulo: (m) => m.nome, meta: (m) => (m.tarefa ? `Ocupada: ${m.tarefa.tipo.toLowerCase()}` : `Imagem atual: ${m.imagem || 'nenhuma'}`),
              desabilitado: (m) => m.tarefa, aoMudar: (id, ok) => { if (ok) e.selecionadas.add(id); else e.selecionadas.delete(id); contar(); } })
              : vazio('maquinas', e.maquinas.length ? 'Nenhuma máquina encontrada' : 'Nenhuma máquina cadastrada', e.maquinas.length ? null : 'Use “Restaurar em grupo”, que não exige cadastro.'));
          };
          input.addEventListener('input', aoParar(() => { e.filtro = input.value; pintar(); }));
          pintar(); contar();
          return el('div', {},
            el('div', { class: 'cartao-cab' }, el('div', { style: 'flex:1;min-width:200px' }, no), cont),
            el('div', { class: 'botoes', style: 'margin-bottom:12px' },
              el('button', { class: 'btn p', type: 'button', onclick: () => { lista.querySelectorAll('input:not(:disabled)').forEach((c) => { c.checked = true; c.parentElement.classList.add('sel'); e.selecionadas.add(c.value); }); contar(); } }, 'Marcar todas'),
              el('button', { class: 'btn p fantasma', type: 'button', onclick: () => { e.selecionadas.clear(); pintar(); contar(); } }, 'Limpar')),
            lista,
            el('div', { class: 'faixa info', style: 'margin-top:14px' }, icone('grupo'), el('div', {}, 'Muitas máquinas iguais? ', el('a', { href: `#/grupo?imagem=${e.imagem}` }, 'Restaurar em grupo'), ' envia para todas ao mesmo tempo, e elas nem precisam estar cadastradas.')));
        },
        validar: (e) => (e.selecionadas.size ? null : 'Marque pelo menos uma máquina.') },
      { nome: 'Revisar', titulo: 'Confirme a restauração', sub: 'Atenção: o disco das máquinas será apagado.',
        desenhar: (e) => el('div', {},
          resumo([['imagens', 'imagens', 'Imagem', `${img().nome} (${gb(img().disco.bytes)})`], ['maquinas', 'maquinas', plural(e.selecionadas.size, 'máquina', 'máquinas'), nomesSel().join(', ')]]),
          !img().redimensionavel ? el('div', { class: 'faixa aviso', style: 'margin-top:14px' }, icone('alerta'), el('div', {}, compat(img()).texto)) : null,
          el('div', { style: 'margin-top:14px' }, opcoesTarefa(e)),
          el('div', { class: 'faixa ruim', style: 'margin-top:14px' }, icone('alerta'), el('div', {}, el('strong', {}, 'Isso não pode ser desfeito'), 'Tudo o que estiver no disco dessas máquinas será apagado.'))) },
    ],
    finalizar: async (e) => {
      const r = await api('restaurar', { imagem_id: Number(e.imagem), maquinas: [...e.selecionadas].map(Number), wol: e.wol, desligar: e.desligar });
      if (r.falhas.length) aviso('Algumas máquinas não foram agendadas:\n' + r.falhas.join('\n'), true);
      return r;
    },
    sucesso: (e, r) => telaSucesso('Restauração agendada!', `${plural(r.agendadas.length, 'máquina vai', 'máquinas vão')} receber “${img().nome}”.`, r.agendadas, 'restaurar',
      'A restauração começa sozinha. Ao terminar, a máquina mostra o resultado na tela e espera você escolher reiniciar ou desligar.', r.tarefas),
  }, est);
}

// ================================================================ restaurar em grupo

async function telaGrupo(alvo, p) {
  const [di, dt] = await Promise.all([api('imagens'), api('tarefas')]);
  const imagens = di.imagens.filter((i) => i.disco.bytes);
  if (!imagens.length) return semImagens(alvo, 'grupo');
  const agora = new Date();
  const est = { imagens, imagem: p.get('imagem') || (imagens.length === 1 ? String(imagens[0].id) : ''),
    nome: `sala${String(agora.getHours()).padStart(2, '0')}${String(agora.getMinutes()).padStart(2, '0')}`, quantidade: 10, espera: 30 };
  const img = () => est.imagens.find((x) => String(x.id) === est.imagem);
  if (!img()) est.imagem = '';
  assistente(alvo, {
    id: 'grupo', sub: 'Uma transmissão para várias máquinas ao mesmo tempo. As máquinas não precisam estar cadastradas.',
    botaoFinal: 'Criar sessão',
    etapas: [
      { nome: 'Imagem', titulo: 'Qual imagem enviar?', sub: 'Todas as máquinas que entrarem na sessão vão receber esta imagem.',
        desenhar: (e) => el('div', {},
          dt.multicast_ativas.length ? el('div', { class: 'faixa info', style: 'margin-bottom:14px;align-items:center' }, icone('grupo'), el('div', { style: 'flex:1' },
            el('strong', {}, plural(dt.multicast_ativas.length, 'sessão em andamento', 'sessões em andamento')), 'Acompanhe ou cancele em Tarefas.'), el('a', { class: 'btn p', href: '#/tarefas' }, 'Ver')) : null,
          escolhas({ nome: 'imagem', cor: 'grupo', icone: 'imagens', selecionado: e.imagem, itens: e.imagens, umaColuna: e.imagens.length < 3,
            titulo: (i) => i.nome, meta: (i) => `${gb(i.disco.bytes)} · ${compat(i).curto} · capturada ${haQuanto(i.disco.capturada)}`, aoMudar: (id) => { e.imagem = id; } })),
        validar: (e) => (e.imagem ? null : 'Escolha a imagem.') },
      { nome: 'Detalhes', titulo: 'Como vai ser a sessão?', sub: 'O nome da sessão vai ser digitado em cada máquina.',
        desenhar: (e) => {
          const nome = el('input', { type: 'text', maxlength: '20', value: e.nome, class: 'mono', autocomplete: 'off' });
          const qtd = el('input', { type: 'number', min: '1', max: '200', value: e.quantidade, inputmode: 'numeric' });
          const esp = el('input', { type: 'number', min: '1', max: '60', value: e.espera, inputmode: 'numeric' });
          nome.addEventListener('input', () => { e.nome = nome.value.trim(); });
          qtd.addEventListener('input', () => { e.quantidade = Number(qtd.value); });
          esp.addEventListener('input', () => { e.espera = Number(esp.value); });
          return el('div', {},
            campo('Nome da sessão', nome, 'Curto e sem espaços, por exemplo sala3 ou lab-a.'),
            el('div', { class: 'grade', style: 'grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px' },
              campo('Quantas máquinas?', qtd, 'A transmissão espera esse número de máquinas entrar.'),
              campo('Prazo para entrar (minutos)', esp, 'Conta a partir de agora. Depois dele, máquinas novas não entram mais; dê folga para ligar a sala toda.')),
            el('div', { class: 'faixa info' }, icone('info'), el('div', {}, 'Use um switch (não um roteador doméstico) e ligue todas as máquinas na rede de clonagem. Uma máquina bem mais lenta atrasa a sala inteira: na dúvida, teste com 2 ou 3 máquinas primeiro.')));
        },
        validar: (e) => (!/^[A-Za-z0-9-]{2,20}$/.test(e.nome) ? 'Nome da sessão: de 2 a 20 letras, números ou hífen, sem espaços.'
          : !(e.quantidade >= 1 && e.quantidade <= 200) ? 'Quantidade de máquinas: de 1 a 200.'
          : !(e.espera >= 1 && e.espera <= 60) ? 'Espera máxima: de 1 a 60 minutos.' : null) },
      { nome: 'Revisar', titulo: 'Confirme a sessão', sub: 'O disco de todas as máquinas que entrarem será apagado.',
        desenhar: (e) => el('div', {},
          resumo([['imagens', 'imagens', 'Imagem', `${img().nome} (${gb(img().disco.bytes)})`], ['grupo', 'grupo', 'Nome da sessão', e.nome],
            ['maquinas', 'maquinas', 'Máquinas', `${e.quantidade} · começa quando todas entrarem ou após ${e.espera} min`]]),
          !img().redimensionavel ? el('div', { class: 'faixa aviso', style: 'margin-top:14px' }, icone('alerta'), el('div', {}, compat(img()).texto)) : null,
          el('div', { class: 'faixa ruim', style: 'margin-top:14px' }, icone('alerta'), el('div', {}, el('strong', {}, 'Isso não pode ser desfeito'), 'Toda máquina que entrar na sessão terá o disco apagado.'))) },
    ],
    finalizar: (e) => api('multicast', { imagem_id: Number(e.imagem), nome: e.nome, quantidade: e.quantidade, espera_min: e.espera }),
    sucesso: (e) => {
      const vivo = el('div');
      let vista = false; // só diz "terminou" depois de ter visto a sessão ativa pelo menos uma vez
      let anterior = '';
      const pintar = async () => {
        const t = await api('tarefas');
        const s = t.multicast_ativas.find((x) => x.nome === e.nome);
        if (s) vista = true;
        const chave = s ? chaveVivo(s) : String(vista);
        if (chave === anterior || interagindo(vivo)) return;
        anterior = chave;
        vivo.replaceChildren(s ? blocoAtivas([], [s], true)
          : vista ? el('div', { class: 'faixa ok' }, icone('ok'), el('div', {}, 'A sessão terminou ou foi encerrada. Veja o resultado em Tarefas.'))
            : el('div', { class: 'faixa info' }, icone('relogio'), el('div', {}, 'Preparando a sessão no servidor…')));
      };
      pintar().catch(() => {});
      repetir(pintar, 4000);
      return el('div', { class: 'pilha' },
        el('div', { class: 'heroi' }, ladrilho('ok', '', 'g'), el('div', { style: 'position:relative;z-index:1' }, el('h2', {}, 'Sessão criada!'), el('p', {}, `Ligue as ${e.quantidade} máquinas e faça o passo a passo abaixo em cada uma.`))),
        el('div', { class: 'cartao', 'data-cor': 'grupo', style: 'text-align:center' }, el('div', { class: 'small muted forte', style: 'margin-bottom:10px' }, 'Nome da sessão'), el('span', { class: 'nome-sessao' }, e.nome)),
        el('div', { class: 'cartao', 'data-cor': 'grupo' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'Em cada máquina')),
          el('ol', { class: 'passos-boot' },
            el('li', {}, el('div', {}, 'Ligue a máquina e aperte a tecla do menu de boot: ', teclasBoot(), '. Escolha a rede (PXE).')),
            el('li', {}, el('div', {}, 'No menu do LabFree ImageDeploy, escolha ', el('strong', {}, 'Entrar em sessao de grupo (multicast)'), '. Será pedido um usuário e senha do painel (teclado em inglês: cuidado com símbolos).')),
            el('li', {}, el('div', {}, 'Digite ', el('strong', { class: 'mono' }, e.nome), ' e aperte Enter.')),
            el('li', {}, el('div', {}, `Espere: a transmissão começa quando as ${e.quantidade} máquinas entrarem. Máquinas que chegarem depois de ${e.espera} min não entram mais.`)))),
        el('div', { class: 'cartao' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'Acompanhe aqui'), pilula('ao vivo', 'info', true)), vivo));
    },
  }, est);
}

// ================================================================ tarefas

async function telaTarefas(alvo) {
  const numeros = el('div');
  const ativas = el('div');
  const hist = el('div');
  const filtroBox = el('div');
  let anterior = '';
  let ultimo = null;
  let filtro = 'todas';
  const pintarHistorico = () => {
    const t = ultimo;
    // tarefas e sessões em grupo numa lista só, da mais nova para a mais antiga
    const itens = [...t.historico.map((h) => ({ quando: h.criada, estado: Number(h.estado), no: () => linhaHistorico(h) })),
      ...t.multicast_historico.map((s) => ({ quando: s.inicio, estado: Number(s.estado), no: () => el('div', { class: 'linha quebra hist', 'data-cor': 'grupo' }, ladrilho('grupo', 'grupo', 'p'),
        el('div', { class: 'corpo' }, el('strong', {}, `Sessão “${s.nome || s.id}”`, el('span', { class: 'tipo-h' }, ' · Restauração em grupo')),
          el('div', { class: 'meta' }, el('span', {}, s.imagem || 'imagem excluída'), el('span', {}, dataHora(s.inicio)), el('span', {}, plural(Number(s.esperadas), 'máquina', 'máquinas')))),
        el('div', { class: 'lado' }, pilulaEstado(s.estado, s.estado_texto), el('span', { class: 'btn p fantasma', 'aria-hidden': 'true', style: 'visibility:hidden' }, icone('tarefas'), 'Ver registro'))) }))]
      .sort((a, b) => new Date(b.quando || 0) - new Date(a.quando || 0));
    const conta = (e) => itens.filter((x) => x.estado === e).length;
    filtroBox.replaceChildren(itens.length ? filtros([['todas', 'Todas', itens.length], ['ok', 'Concluídas', conta(4)], ['falhas', 'Com falha', conta(6)], ['canceladas', 'Canceladas', conta(5)]],
      filtro, (f) => { filtro = f; pintarHistorico(); }) : null);
    const visiveis = itens.filter((x) => filtro === 'todas' || x.estado === { ok: 4, falhas: 6, canceladas: 5 }[filtro]);
    if (!itens.length) return hist.replaceChildren(vazio('relogio', 'Nenhuma tarefa concluída ainda', 'O histórico de capturas e restaurações aparece aqui.'));
    if (!visiveis.length) return hist.replaceChildren(vazio('relogio', 'Nada neste filtro', null));
    const grupos = [];
    for (const x of visiveis) {
      const dia = rotuloDia(x.quando);
      if (!grupos.length || grupos[grupos.length - 1][0] !== dia) grupos.push([dia, []]);
      grupos[grupos.length - 1][1].push(x.no());
    }
    hist.replaceChildren(...grupos.map(([dia, nos]) => el('section', { class: 'dia' }, el('h3', { class: 'rotulo-dia' }, dia), el('div', { class: 'lista' }, nos))));
  };
  const montar = async () => {
    const t = await api('tarefas');
    const igual = chaveVivo(t);
    if (igual === anterior || interagindo(ativas) || interagindo(hist)) return; // sem mudança ou com a pessoa clicando: os botões ficam
    anterior = igual;
    ultimo = t;
    // resumo em números: o que está acontecendo agora, de relance
    const todas = [...t.ativas, ...t.multicast_ativas.flatMap((s) => s.maquinas || [])];
    const trabalhando = todas.filter((x) => ['preparando', 'copiando', 'finalizando'].includes(x.fase) && !x.parada).length;
    const esperando = t.ativas.filter((x) => x.fase === 'aguardando' || x.fase === 'fila').length + t.multicast_ativas.filter((s) => !(s.maquinas || []).length).length;
    const paradas = todas.filter((x) => x.parada).length;
    const falhasHoje = t.historico.filter((h) => Number(h.estado) === 6 && rotuloDia(h.criada) === 'Hoje').length;
    const numero = (ic, cor, valor, rot, tipo) => el('div', { class: 'cartao numero' + (tipo ? ` destaque-${tipo}` : ''), 'data-cor': cor },
      ladrilho(ic, cor), el('div', {}, el('div', { class: 'valor' }, String(valor)), el('div', { class: 'rot' }, rot)));
    numeros.replaceChildren(el('div', { class: 'grade g4', style: 'margin-bottom:16px' },
      numero('relogio', 'tarefas', trabalhando, 'clonando agora'),
      numero('maquinas', 'inicio', esperando, esperando === 1 ? 'esperando a máquina' : 'esperando as máquinas'),
      numero('alerta', 'maquinas', paradas, 'sem notícia há mais de 3 min', paradas ? 'aviso' : null),
      numero('x', 'imagens', falhasHoje, falhasHoje === 1 ? 'falha hoje' : 'falhas hoje', falhasHoje ? 'ruim' : null)));
    ativas.replaceChildren(blocoAtivas(t.ativas, t.multicast_ativas, true));
    pintarHistorico();
  };
  await montar();
  alvo.replaceChildren(
    cabPagina('tarefas', 'Capturas e restaurações. Esta tela se atualiza sozinha.',
      el('button', { class: 'btn', type: 'button', onclick: async () => { if (await limparHistorico()) { anterior = ''; await montar(); } } }, icone('lixo'), 'Limpar histórico')),
    numeros,
    el('div', { class: 'cartao', 'data-cor': 'tarefas' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'Em andamento'), pilula('ao vivo', 'info', true)), ativas),
    el('div', { class: 'cartao' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'Histórico'), filtroBox), hist));
  repetir(montar, 3000);
}

/** Janela de limpeza: histórico, fila de espera, registro de clonagens e registros internos. */
async function limparHistorico() {
  let c;
  try { c = await api('limpeza'); } catch (e) { aviso(e.message, true); return false; }
  const opcao = (chave, titulo, texto, marcado) => {
    const caixa = el('input', { type: 'checkbox', checked: marcado && c[chave] > 0, disabled: !c[chave] });
    return { chave, caixa, no: el('label', { class: 'alternar' }, caixa, el('div', {},
      el('strong', {}, `${titulo} `, pilula(String(c[chave]), c[chave] ? 'info sem-ponto' : 'sem-ponto')), el('small', {}, texto))) };
  };
  const opcoes = [
    opcao('historico', 'Histórico de tarefas', 'Capturas, restaurações e sessões em grupo já concluídas, canceladas ou que falharam.', true),
    opcao('fila', 'Fila de espera', 'Cancela as tarefas agendadas que ainda não começaram (máquinas que ainda não ligaram) e as sessões em grupo sem nenhuma máquina.', false),
    opcao('clonagens', 'Registro de clonagens', 'O "Captura há…" / "Restauração há…" que aparece em Máquinas e Imagens.', false),
    opcao('registros', 'Registros internos', 'Registro de atividades interno da base (entradas e alterações). Não aparece no painel.', false),
  ];
  const minha = el('input', { type: 'password', autocomplete: 'current-password' });
  const ok = await modal({ titulo: 'Limpar histórico', icone: 'lixo', cor: 'tarefas',
    texto: 'Escolha o que apagar. Máquinas, imagens e configurações não são afetadas.',
    conteudo: el('div', {}, opcoes.map((o) => o.no),
      c.em_andamento ? el('div', { class: 'faixa info', style: 'margin-top:12px' }, icone('info'),
        el('div', {}, `${plural(c.em_andamento, 'tarefa em andamento não é afetada', 'tarefas em andamento não são afetadas')}. Para parar, use “Cancelar” em cada uma.`)) : null,
      el('div', { class: 'faixa ruim', style: 'margin-top:12px' }, icone('alerta'), el('div', {}, 'Isso não pode ser desfeito.')),
      el('div', { style: 'margin-top:12px' }, campo(`Sua senha (${sessao.usuario})`, minha, 'Para confirmar que é você.'))),
    botoes: [{ texto: 'Voltar', classe: 'fantasma' }, { texto: 'Apagar', classe: 'perigo-cheio', acao: async () => {
      const dados = { minha_senha: minha.value };
      opcoes.forEach((o) => { dados[o.chave] = o.caixa.checked; });
      if (!opcoes.some((o) => o.caixa.checked)) throw new Error('Marque pelo menos uma opção.');
      aviso((await api('limpar_historico', dados)).msg);
    } }] });
  if (ok) atualizarStatus();
  return !!ok;
}

// ================================================================ ajuda

async function telaAjuda(alvo) {
  const pergunta = (titulo, ...corpo) => el('details', {}, el('summary', {}, titulo), el('div', {}, corpo));
  const palavra = (ic, cor, t, d) => el('div', { class: 'linha' }, ladrilho(ic, cor, 'p'), el('div', { class: 'corpo' }, el('strong', {}, t), el('div', { class: 'meta', style: 'display:block' }, d)));
  alvo.replaceChildren(
    cabPagina('ajuda', 'Como usar o LabFree ImageDeploy no dia a dia.'),
    el('div', { class: 'grade g2' },
      el('div', { class: 'cartao' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'O caminho mais comum')),
        el('ol', { class: 'passos-boot' },
          el('li', {}, el('div', {}, 'Prepare uma máquina modelo com tudo instalado.')),
          el('li', {}, el('div', {}, el('a', { href: '#/maquinas?novo=1' }, 'Cadastre'), ' a máquina modelo, ou dê boot pela rede uma vez e cadastre pela lista “Detectadas na rede”.')),
          el('li', {}, el('div', {}, el('a', { href: '#/capturar' }, 'Capture a imagem'), ' dela.')),
          el('li', {}, el('div', {}, 'Para uma sala inteira, ', el('a', { href: '#/grupo' }, 'restaure em grupo'), '. Para poucas máquinas, ', el('a', { href: '#/restaurar' }, 'restaure'), '.')))),
      el('div', { class: 'cartao' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'Palavras que aparecem aqui')),
        el('div', { class: 'lista' },
          palavra('imagens', 'imagens', 'Imagem', 'Cópia completa do disco de uma máquina, guardada no servidor.'),
          palavra('capturar', 'capturar', 'Capturar', 'Copiar o disco da máquina para o servidor (máquina → servidor).'),
          palavra('restaurar', 'restaurar', 'Restaurar', 'Gravar a imagem no disco da máquina, apagando o que havia (servidor → máquina).'),
          palavra('grupo', 'grupo', 'Em grupo (multicast)', 'Uma única transmissão recebida por várias máquinas ao mesmo tempo.'),
          palavra('rede', 'maquinas', 'Boot pela rede (PXE)', el('span', {}, 'Ligar a máquina pelo cabo de rede. Tecla do menu de boot: ', teclasBoot(), '.'))))),
    el('div', { style: 'margin-top:24px' }, el('h2', { style: 'margin-bottom:12px' }, 'Problemas comuns'),
      el('div', { class: 'perguntas' },
        pergunta('A máquina não pega IP ou não aparece o menu de boot',
          el('p', {}, 'Veja no Início se aparece “Tudo pronto para clonar”. Confira o cabo, o switch e se o adaptador USB está plugado no servidor.'),
          el('p', {}, 'Na BIOS da máquina, ative “Network Stack” / “PXE Boot” e, se possível, desative o “Secure Boot”.')),
        pergunta('Erro “Permission denied (ipxe.org/0216eb8f)”',
          el('p', {}, 'O relógio da BIOS da máquina está muito errado. O servidor já acerta a hora pela rede; se continuar, ajuste data e hora na BIOS e troque a bateria da placa-mãe se ela sempre atrasa.')),
        pergunta('A restauração falha logo no começo (por exemplo, em máquina com NVMe)',
          el('p', {}, 'Quase sempre a imagem é de “tamanho fixo” e o disco da máquina é menor que o da máquina modelo. A tela Imagens mostra o tamanho mínimo de cada uma.'),
          el('p', {}, 'Solução: crie uma imagem do tipo “Redimensionável” e capture de novo a máquina modelo.')),
        pergunta('A máquina não aparece em “Detectadas na rede”',
          el('p', {}, 'Ela só aparece depois de dar boot pela rede de clonagem pelo menos uma vez. Ou cadastre manualmente com o MAC, que aparece na tela de boot pela rede.')),
        pergunta('A captura terminou mas a imagem continua vazia',
          el('p', {}, 'Veja o resultado em Tarefas. Se falhou, confira o espaço livre no Início e tente de novo. No Windows, desligue de verdade (Shift + Desligar) e desative o BitLocker antes de capturar.')),
        pergunta('Esqueci a senha do painel',
          el('p', {}, 'Outro usuário pode definir uma nova senha em Usuários. Se ninguém consegue entrar, no servidor rode: ', el('code', {}, 'sudo LabFreeImageDeploy senha admin --redefinir'), ' (volta para admin/admin e pede uma senha nova no próximo acesso).')))),
    el('div', { class: 'cartao', style: 'margin-top:24px' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'Sobre o LabFree ImageDeploy')),
      el('p', { class: 'small' }, `Versão ${estadoGlobal.versao || ''}. Sistema de clonagem de máquinas pela rede.`),
      el('p', { class: 'small muted', style: 'margin-top:8px' }, 'Baseado no FOG Project (fogproject.org), distribuído sob a licença GNU GPL versão 3. ',
        'Também usa iPXE, Partclone, udpcast, Kea DHCP e outros softwares livres, cada um sob sua própria licença. ',
        'O código-fonte desta distribuição acompanha o pacote de instalação.')));
}

// ================================================================ configurações (rede e DHCP)

const MASCARAS = [[24, '255.255.255.0 (/24) — até 253 máquinas'], [23, '255.255.254.0 (/23) — até 509'], [22, '255.255.252.0 (/22) — até 1021'],
  [21, '255.255.248.0 (/21) — até 2045'], [16, '255.255.0.0 (/16) — até 65 mil'], [25, '255.255.255.128 (/25) — até 125'], [26, '255.255.255.192 (/26) — até 61']];

async function telaConfiguracoes(alvo, p) {
  const aba = ['rede', 'cadastro'].includes(p.get('aba')) ? p.get('aba') : 'dhcp';
  const abas = el('div', { class: 'abas', role: 'tablist' },
    [['dhcp', 'DHCP', 'rede'], ['rede', 'Placa de rede', 'maquinas'], ['cadastro', 'Cadastro pelo boot', 'mais']].map(([id, nome, ic]) => el('a', {
      role: 'tab', href: `#/configuracoes?aba=${id}`, 'aria-selected': String(aba === id), class: aba === id ? 'ativa' : null,
    }, icone(ic), nome)));
  const corpo = el('div');
  alvo.replaceChildren(cabPagina('configuracoes', 'Rede de clonagem e servidor DHCP. As mudanças são testadas e, se algo falhar, desfeitas sozinhas.'), abas, corpo);
  corpo.append(esqueleto());
  if (aba === 'rede') await abaRede(corpo); else if (aba === 'cadastro') await abaCadastro(corpo); else await abaDhcp(corpo);
}

function aviso503(err) {
  return el('div', { class: 'faixa aviso' }, icone('alerta'), el('div', {}, el('strong', {}, 'Configuração indisponível'), err.message));
}

async function abaCadastro(corpo) {
  const [c, img] = await Promise.all([api('cadastro'), api('imagens')]);
  const imagens = img.imagens;
  const prefixo = el('input', { type: 'text', maxlength: '12', value: c.prefixo, class: 'mono', autocomplete: 'off' });
  const sufixo = el('input', { type: 'text', maxlength: '8', value: c.sufixo, class: 'mono', autocomplete: 'off', placeholder: 'opcional' });
  const digitos = el('select', {}, [1, 2, 3, 4, 5].map((n) => el('option', { value: n, selected: String(n) === String(c.digitos) }, `${n} (${'0'.repeat(n - 1)}1)`)));
  const numero = el('input', { type: 'number', min: '1', max: '99999', value: c.numero, inputmode: 'numeric' });
  const imagem = el('select', {}, el('option', { value: '0' }, 'Nenhuma (escolher na hora)'), imagens.map((i) => el('option', { value: i.id, selected: String(i.id) === String(c.imagem_id) }, i.nome)));
  const restaurar = el('input', { type: 'checkbox', checked: c.restaurar });
  const previa = el('div', { class: 'previa-nomes' });
  const montarPrevia = () => {
    const pre = prefixo.value.trim().toUpperCase(); const suf = sufixo.value.trim().toUpperCase();
    const d = Number(digitos.value); const n = Math.max(1, Number(numero.value) || 1);
    const nomes = [0, 1, 2].map((k) => `${pre}${String(n + k).padStart(d, '0')}${suf}`);
    const longo = nomes[0].length > 15;
    previa.replaceChildren(el('span', { class: 'small muted' }, 'Próximas máquinas: '),
      ...nomes.map((x) => el('span', { class: 'pilula sem-ponto mono ' + (longo ? 'ruim' : 'info') }, x)), el('span', { class: 'small muted' }, '…'),
      longo ? el('div', { class: 'small', style: 'color:var(--bad);width:100%' }, 'Mais de 15 caracteres: encurte o começo ou o fim.') : null);
  };
  [prefixo, sufixo, digitos, numero].forEach((x) => x.addEventListener('input', montarPrevia));
  digitos.addEventListener('change', montarPrevia);
  montarPrevia();
  const salvar = el('button', { class: 'btn primario g', type: 'button' }, icone('check'), 'Salvar');
  salvar.addEventListener('click', async () => {
    salvar.disabled = true;
    try {
      const r = await api('cadastro_salvar', { prefixo: prefixo.value, sufixo: sufixo.value, digitos: Number(digitos.value), numero: Number(numero.value),
        imagem_id: Number(imagem.value), restaurar: restaurar.checked });
      aviso(r.msg);
      await abaCadastro(corpo);
    } catch (err) { aviso(err.message, true); salvar.disabled = false; }
  });
  const passo = (n, titulo, texto) => el('div', { class: 'passo-boot' }, el('span', { class: 'n' }, String(n)), el('div', {}, el('strong', {}, titulo), el('div', { class: 'small muted' }, texto)));
  corpo.replaceChildren(el('div', { class: 'pilha' },
    el('div', { class: 'cartao', 'data-cor': 'maquinas' },
      el('div', { class: 'cartao-cab' }, el('div', { class: 'titulo' }, ladrilho('maquinas', 'maquinas', 'p'), el('h2', {}, 'Como cadastrar uma máquina nova'))),
      el('div', { class: 'passos-cadastro' },
        passo(1, 'Dê boot pela rede', 'Ligue a máquina e aperte a tecla do menu de boot (F12 na maioria).'),
        passo(2, 'Escolha "Cadastrar esta maquina"', `Confirme o nome sugerido (${c.sugestao || 'ex.: LAB-001'}) com Enter.`),
        passo(3, 'Escolha a imagem e pronto', 'Em menos de 1 minuto a máquina aparece no painel.')),
      el('div', { class: 'faixa info', style: 'margin-top:14px' }, icone('info'), el('div', {}, el('strong', {}, 'Muitas máquinas de uma vez?'),
        'Use "Cadastro automatico (sem perguntas)" no menu de boot: a máquina recebe o próximo nome da sequência sozinha.'))),
    el('div', { class: 'cartao' }, el('h2', { style: 'margin-bottom:4px' }, 'Nomes das máquinas'),
      el('p', { class: 'small muted', style: 'margin-bottom:14px' }, 'O sistema sugere o próximo nome livre da sequência.'),
      el('div', { class: 'grade', style: 'grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0 16px' },
        campo('Começo do nome', prefixo, 'Ex.: LAB-, SALA2-'), campo('Dígitos', digitos), campo('Próximo número', numero), campo('Final (opcional)', sufixo)),
      previa),
    el('div', { class: 'cartao' }, el('h2', { style: 'margin-bottom:12px' }, 'Imagem padrão'),
      campo('Imagem já selecionada no cadastro', imagem, 'Aparece marcada na lista; dá para trocar na hora.'),
      el('label', { class: 'alternar' }, restaurar, el('div', {}, el('strong', {}, 'Cadastro automático já restaura a imagem padrão'),
        el('small', {}, 'Cuidado: a máquina cadastrada pelo modo automático tem o disco apagado e recebe a imagem logo em seguida.')))),
    el('div', { class: 'botoes', style: 'justify-content:flex-end' }, salvar)));
}

async function abaDhcp(corpo) {
  let d, maquinas = [];
  try {
    [d, maquinas] = await Promise.all([api('dhcp'), api('maquinas').then((m) => m.maquinas).catch(() => [])]);
  } catch (err) { corpo.replaceChildren(aviso503(err)); return; }
  const est = { ativo: d.ativo, reservas: d.reservas.map((r) => ({ ...r })) };
  const ativo = el('input', { type: 'checkbox', checked: d.ativo });
  const inicio = el('input', { type: 'text', value: d.inicio, class: 'mono', inputmode: 'decimal' });
  const fim = el('input', { type: 'text', value: d.fim, class: 'mono', inputmode: 'decimal' });
  const gateway = el('input', { type: 'text', value: d.gateway, class: 'mono', placeholder: 'vazio = sem gateway', inputmode: 'decimal' });
  const dns1 = el('input', { type: 'text', value: d.dns[0] || '', class: 'mono', placeholder: 'opcional', inputmode: 'decimal' });
  const dns2 = el('input', { type: 'text', value: d.dns[1] || '', class: 'mono', placeholder: 'opcional', inputmode: 'decimal' });
  const dominio = el('input', { type: 'text', value: d.dominio, placeholder: 'opcional, ex.: lab.local' });
  const opcoesConc = [[1, '1 hora'], [4, '4 horas'], [6, '6 horas (padrão)'], [12, '12 horas'], [24, '1 dia'], [168, '7 dias']];
  if (!opcoesConc.some(([h]) => h === d.concessao_horas)) opcoesConc.push([d.concessao_horas, `${d.concessao_horas} horas (atual)`]);
  const conc = el('select', {}, opcoesConc.map(([h, t]) => el('option', { value: h, selected: h === d.concessao_horas }, t)));
  const campos = el('div');
  const reservasBox = el('div');
  const contReservas = pilula('', 'sem-ponto');

  const desenharReservas = () => {
    const linhas = est.reservas.map((r, i) => {
      const mac = el('input', { type: 'text', value: r.mac, class: 'mono', placeholder: '00:11:22:33:44:55', 'aria-label': 'MAC' });
      const ip = el('input', { type: 'text', value: r.ip, class: 'mono', placeholder: 'IP fixo', 'aria-label': 'IP', inputmode: 'decimal' });
      const nome = el('input', { type: 'text', value: r.nome || '', placeholder: 'nome (opcional)', 'aria-label': 'Nome' });
      mac.addEventListener('input', () => { r.mac = mac.value.trim(); });
      ip.addEventListener('input', () => { r.ip = ip.value.trim(); });
      nome.addEventListener('input', () => { r.nome = nome.value.trim(); });
      return el('div', { class: 'reserva' }, mac, ip, nome,
        el('button', { class: 'btn p icone fantasma', type: 'button', 'aria-label': 'Remover reserva', onclick: () => { est.reservas.splice(i, 1); desenharReservas(); } }, icone('lixo')));
    });
    const escolher = el('select', { 'aria-label': 'Reservar IP para uma máquina cadastrada' }, el('option', { value: '' }, 'Reservar para uma máquina cadastrada…'),
      maquinas.filter((m) => m.mac && !est.reservas.some((r) => r.mac.toLowerCase() === m.mac)).map((m) => el('option', { value: m.mac }, `${m.nome} (${m.mac})`)));
    escolher.addEventListener('change', () => {
      const m = maquinas.find((x) => x.mac === escolher.value);
      if (m) { est.reservas.push({ mac: m.mac, ip: '', nome: m.nome.toLowerCase() }); desenharReservas(); }
    });
    contReservas.textContent = plural(est.reservas.length, 'reserva', 'reservas');
    reservasBox.replaceChildren(
      linhas.length ? el('div', { class: 'reservas' }, el('div', { class: 'reserva cab' }, el('span', {}, 'MAC'), el('span', {}, 'IP fixo'), el('span', {}, 'Nome'), el('span')), linhas)
        : el('p', { class: 'small muted', style: 'margin-bottom:10px' }, 'Nenhuma reserva. Reservas fazem uma máquina receber sempre o mesmo IP.'),
      el('div', { class: 'botoes', style: 'margin-top:10px' },
        el('button', { class: 'btn p', type: 'button', onclick: () => { est.reservas.push({ mac: '', ip: '', nome: '' }); desenharReservas(); } }, icone('mais'), 'Adicionar reserva'),
        maquinas.length ? el('div', { style: 'min-width:240px;flex:1;max-width:360px' }, escolher) : null));
  };

  const desenharCampos = () => {
    campos.hidden = !ativo.checked;
  };
  ativo.addEventListener('change', desenharCampos);

  const estado = d.ativo
    ? (d.escutando ? el('div', { class: 'faixa ok' }, icone('ok'), el('div', {}, el('strong', {}, 'DHCP ligado e respondendo'),
      `Entregando IPs de ${d.inicio} a ${d.fim} na placa ${d.interface}. ${plural(d.leases_ativos, 'máquina com IP agora', 'máquinas com IP agora')}.`))
      : el('div', { class: 'faixa aviso' }, icone('alerta'), el('div', {}, el('strong', {}, 'DHCP ligado, mas sem responder'), `A placa ${d.interface} está sem cabo ou sem IP. Veja a aba “Placa de rede”.`)))
    : el('div', { class: 'faixa info' }, icone('info'), el('div', {}, el('strong', {}, 'DHCP do servidor desligado'), 'Outro servidor DHCP precisa entregar os IPs e apontar o boot para este servidor (veja abaixo).'));

  const salvar = el('button', { class: 'btn primario g', type: 'button' }, icone('check'), 'Salvar e aplicar');
  salvar.addEventListener('click', async () => {
    const dados = { ativo: ativo.checked, inicio: inicio.value.trim(), fim: fim.value.trim(), gateway: gateway.value.trim(),
      dns: [dns1.value.trim(), dns2.value.trim()].filter(Boolean), dominio: dominio.value.trim(), concessao_horas: Number(conc.value),
      reservas: est.reservas.filter((r) => r.mac || r.ip) };
    const texto = !dados.ativo ? 'O servidor vai PARAR de entregar IPs. As máquinas só vão dar boot pela rede se outro servidor DHCP estiver configurado para isso.'
      : 'A configuração nova é testada antes de entrar no ar. Máquinas que já têm IP continuam funcionando; as próximas recebem as regras novas.';
    if (!await confirmar(dados.ativo ? 'Aplicar a configuração do DHCP?' : 'Desligar o DHCP?', texto, dados.ativo ? 'Aplicar' : 'Desligar DHCP', !dados.ativo)) return;
    salvar.disabled = true;
    try {
      const r = await api('dhcp_salvar', dados);
      aviso(r.msg + (r.aviso ? `\n${r.aviso}` : ''));
      await abaDhcp(corpo);
    } catch (err) { aviso(err.message, true); salvar.disabled = false; }
  });

  campos.append(
    el('div', { class: 'cartao' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'Faixa de IPs'), pilula(`Rede ${d.rede}`, 'sem-ponto')),
      el('div', { class: 'grade', style: 'grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px' },
        campo('Primeiro IP entregue', inicio), campo('Último IP entregue', fim)),
      el('p', { class: 'small muted' }, `O servidor usa ${d.servidor_ip}; ele não pode estar dentro da faixa. Máscara: ${d.mascara}. Para mudar a rede em si, use a aba “Placa de rede”.`)),
    el('div', { class: 'cartao' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'Opções entregues às máquinas')),
      el('div', { class: 'grade', style: 'grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px' },
        campo('Gateway (roteador)', gateway, 'Numa rede isolada só para clonagem pode ficar vazio.'),
        campo('Tempo de concessão', conc, 'Quanto tempo cada IP fica reservado para a máquina.'),
        campo('DNS primário', dns1), campo('DNS secundário', dns2), campo('Domínio', dominio))),
    el('div', { class: 'cartao' }, el('div', { class: 'cartao-cab' }, el('h2', {}, 'Reservas de IP'), contReservas), reservasBox),
    el('details', { class: 'cartao' }, el('summary', { style: 'cursor:pointer;font-weight:650' }, 'Arquivos de boot por tipo de máquina (automático)'),
      el('p', { class: 'small muted', style: 'margin:10px 0' }, `O DHCP manda cada tipo de máquina buscar o arquivo certo no servidor TFTP ${d.tftp}. Isso é configurado pelo FOG e não precisa ser alterado.`),
      el('div', { class: 'lista' }, d.arquivos_boot.map((b) => el('div', { class: 'linha' }, el('div', { class: 'corpo' }, el('strong', {}, b.classe.replace(/^FOG-/, '').replace(/-/g, ' ')), el('div', { class: 'meta mono' }, b.arquivo)))))));
  const semDhcp = el('div', { class: 'cartao', hidden: ativo.checked },
    el('h2', { style: 'margin-bottom:10px' }, 'Usando outro servidor DHCP'),
    el('p', { class: 'small' }, 'Configure no outro servidor DHCP (roteador, Windows Server…) as opções de boot pela rede:'),
    el('div', { class: 'resumo', style: 'margin-top:10px' },
      el('div', {}, el('div', {}, el('div', { class: 'rot' }, 'Opção 66 — servidor de boot (next-server)'), el('div', { class: 'val mono' }, d.servidor_ip))),
      el('div', {}, el('div', {}, el('div', { class: 'rot' }, 'Opção 67 — arquivo de boot (UEFI)'), el('div', { class: 'val mono' }, 'snponly.efi'))),
      el('div', {}, el('div', {}, el('div', { class: 'rot' }, 'Opção 67 — arquivo de boot (BIOS antiga)'), el('div', { class: 'val mono' }, 'undionly.kkpxe')))));
  ativo.addEventListener('change', () => { semDhcp.hidden = ativo.checked; });
  desenharReservas();
  desenharCampos();
  corpo.replaceChildren(el('div', { class: 'pilha' }, estado,
    el('div', { class: 'cartao' }, el('label', { class: 'alternar', style: 'padding:0' }, ativo,
      el('div', {}, el('strong', {}, 'Este servidor entrega IPs na rede de clonagem (DHCP)'), el('small', {}, 'Desligue apenas se outro servidor DHCP já atende a rede de clonagem.')))),
    campos, semDhcp,
    el('div', { class: 'botoes', style: 'justify-content:flex-end' }, el('button', { class: 'btn fantasma', type: 'button', onclick: () => abaDhcp(corpo) }, 'Descartar mudanças'), salvar)));
}

async function abaRede(corpo) {
  let r;
  try { r = await api('rede'); } catch (err) { corpo.replaceChildren(aviso503(err)); return; }
  const atual = r.atual;
  const est = { interface: atual.interface, ip: atual.ip, prefixo: atual.prefixo };
  const placaAtual = r.placas.find((x) => x.nome === atual.interface);
  const plano = el('div');
  const ip = el('input', { type: 'text', value: est.ip, class: 'mono', inputmode: 'decimal' });
  const mascara = el('select', {}, MASCARAS.map(([v, t]) => el('option', { value: v, selected: v === est.prefixo }, t)));
  const invalidar = () => plano.replaceChildren();
  ip.addEventListener('input', () => { est.ip = ip.value.trim(); invalidar(); });
  mascara.addEventListener('change', () => { est.prefixo = Number(mascara.value); invalidar(); });
  const placas = escolhas({ nome: 'placa', cor: 'configuracoes', selecionado: est.interface, itens: r.placas.map((x) => ({ ...x, id: x.nome })), umaColuna: true,
    icone: 'rede', titulo: (x) => `${x.usb ? 'Adaptador USB' : x.tipo === 'wifi' ? 'Wi-Fi' : 'Placa de rede'} · ${x.nome}`,
    meta: (x) => [x.modelo, x.mac, x.cabo ? (x.velocidade ? `cabo conectado (${x.velocidade} Mb/s)` : 'conectada') : 'sem cabo', x.ips.join(', ') || 'sem IP',
      x.internet ? 'leva a INTERNET deste servidor — não pode ser usada' : null, x.tipo === 'wifi' ? 'Wi-Fi não serve para boot pela rede' : null, x.fog ? 'atual da rede de clonagem' : null].filter(Boolean).join(' · '),
    desabilitado: (x) => !x.pode_usar, aoMudar: (id) => { est.interface = id; invalidar(); } });

  const verPlano = el('button', { class: 'btn', type: 'button' }, icone('buscar'), 'Ver o que vai mudar');
  verPlano.addEventListener('click', async () => {
    verPlano.disabled = true;
    try {
      const s = await api('rede_simular', est);
      const aplicar = el('button', { class: 'btn perigo-cheio g', type: 'button' }, icone('check'), 'Aplicar');
      aplicar.addEventListener('click', async () => {
        if (!await confirmar('Aplicar a nova configuração de rede?', 'Durante alguns segundos a rede de clonagem fica fora. Não faça isso com capturas ou restaurações em andamento. Se algo der errado, tudo volta sozinho ao estado anterior.', 'Aplicar agora', true)) return;
        aplicar.disabled = true;
        plano.replaceChildren(el('div', { class: 'faixa info' }, icone('relogio'), el('div', {}, el('strong', {}, 'Aplicando…'), 'Isso leva de 1 a 3 minutos. Não feche esta página.')));
        try {
          const res = await api('rede_aplicar', est);
          plano.replaceChildren(el('div', { class: 'faixa ok' }, icone('ok'), el('div', {}, el('strong', {}, res.msg),
            res.avisos && res.avisos.length ? el('ul', {}, res.avisos.map((a) => el('li', {}, a))) : null,
            res.url && /^https?:\/\//.test(res.url) && !res.url.includes(location.hostname) && est.ip !== atual.ip ? el('p', { style: 'margin-top:6px' }, 'Novo endereço do painel: ', el('a', { href: res.url }, res.url)) : null)));
          aviso(res.msg);
        } catch (err) {
          // com troca de IP, a página pode perder a conexão no meio: o servidor termina sozinho (e desfaz se falhar)
          const semConexao = /comunica|demorou/i.test(err.message) && est.ip !== atual.ip;
          plano.replaceChildren(semConexao
            ? el('div', { class: 'faixa aviso' }, icone('alerta'), el('div', {}, el('strong', {}, 'A página perdeu a conexão durante a troca'),
              'Isso é esperado quando o IP muda. Aguarde 2 minutos e abra ', el('a', { href: `https://${est.ip}/` }, `https://${est.ip}/`),
              '. Se não abrir, a troca foi desfeita: recarregue esta página.'))
            : el('div', { class: 'faixa ruim' }, icone('alerta'), el('div', {}, el('strong', {}, 'A configuração não foi aplicada'), err.message)));
        }
      });
      plano.replaceChildren(el('div', { class: 'cartao', 'data-cor': 'configuracoes', style: 'border-color:color-mix(in srgb, var(--c) 40%, var(--border))' },
        el('h2', { style: 'margin-bottom:12px' }, 'O que vai mudar'),
        el('ul', { class: 'mudancas' }, s.mudancas.map((m) => el('li', {}, m))),
        s.avisos.length ? el('div', { class: 'faixa aviso', style: 'margin-top:12px' }, icone('alerta'), el('div', {}, el('ul', { style: 'margin:0' }, s.avisos.map((a) => el('li', {}, a))))) : null,
        el('div', { class: 'botoes', style: 'justify-content:flex-end;margin-top:16px' }, el('button', { class: 'btn fantasma', type: 'button', onclick: invalidar }, 'Cancelar'), aplicar)));
      plano.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (err) { aviso(err.message, true); }
    verPlano.disabled = false;
  });

  corpo.replaceChildren(el('div', { class: 'pilha' },
    placaAtual ? el('div', { class: 'faixa ' + (placaAtual.cabo && placaAtual.ips.length ? 'ok' : 'aviso') }, icone(placaAtual.cabo ? 'ok' : 'alerta'),
      el('div', {}, el('strong', {}, `Rede de clonagem: ${atual.ip}/${atual.prefixo} na placa ${atual.interface}`),
        placaAtual.cabo ? `${placaAtual.modelo} · cabo conectado${placaAtual.velocidade ? ` a ${placaAtual.velocidade} Mb/s` : ''}.` : 'A placa está sem cabo conectado: ligue o cabo no switch da rede de clonagem.'))
      : el('div', { class: 'faixa ruim' }, icone('alerta'), el('div', {}, el('strong', {}, 'A placa da rede de clonagem não está conectada'), `Placa configurada: ${atual.interface || 'nenhuma'}. Plugue o adaptador ou escolha outra placa abaixo.`)),
    el('div', { class: 'cartao' }, el('h2', { style: 'margin-bottom:4px' }, '1. Placa que vai atender a rede de clonagem'),
      el('p', { class: 'small muted', style: 'margin-bottom:14px' }, 'É por esta placa que o servidor entrega IPs (DHCP) e envia as imagens. Use uma placa com cabo dedicada à rede de clonagem; a placa que leva o servidor à internet fica bloqueada.'),
      placas),
    el('div', { class: 'cartao' }, el('h2', { style: 'margin-bottom:4px' }, '2. Endereço do servidor nessa rede'),
      el('p', { class: 'small muted', style: 'margin-bottom:14px' }, 'IP fixo do servidor. Sem gateway e sem DNS nesta placa, para não atrapalhar a internet do servidor.'),
      el('div', { class: 'grade', style: 'grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 16px' }, campo('IP do servidor', ip, 'Ex.: 192.168.31.1 ou 10.10.10.1'), campo('Máscara', mascara)),
      el('div', { class: 'botoes' }, verPlano)),
    plano));
}

// ================================================================ partida

window.addEventListener('hashchange', navegar);
document.addEventListener('DOMContentLoaded', async () => {
  try {
    const s = await api('sessao');
    estadoGlobal.versao = s.versao;
    if (!s.logado) telaLogin();
    else { sessao.usuario = s.usuario; sessao.token = s.token; if (s.trocar_senha) telaPrimeiraSenha(); else { montarCasca(); navegar(); } }
  } catch (e) {
    document.getElementById('app').replaceChildren(el('div', { class: 'tela-login' }, el('div', { class: 'login-form' }, el('div', { class: 'faixa ruim' }, icone('alerta'),
      el('div', {}, el('strong', {}, 'Não foi possível abrir o painel'), e.message,
        el('div', { style: 'margin-top:10px' }, el('button', { class: 'btn p', type: 'button', onclick: () => location.reload() }, 'Tentar de novo')))))));
  }
});
