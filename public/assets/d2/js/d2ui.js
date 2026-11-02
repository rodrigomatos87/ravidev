// D2UI: componentes do design system d2 para todas as telas do Ravi Developer.
// Módulo ES sem dependências. Expõe D2UI (exportado, em window.D2UI) e window.showNotification.
// Texto visível só por chave de idioma: D2UI.configurar({ t }) liga ao dicionário do sistema
// (fonte única: idiomas/{pt,en,es}/d2.json). Não há texto embutido: chave ausente aparece crua, à vista.
// Dado de usuário entra no DOM só por textContent; nada de innerHTML com dado.

// Configuração (D2UI.configurar)
const conf = { t: null, idioma: null, sprite: '/icones/sprite.svg', moeda: 'BRL', paisTelefone: 'BR', fuso: null, hoje: null };

function interpolar(texto, params) {
    if (!params) return texto;
    return texto.replace(/\{(\w+)\}/g, (m, k) => (params[k] !== undefined && params[k] !== null ? String(params[k]) : m));
}
// Traduz uma chave pelo dicionário configurado; sem tradução, devolve a própria chave (nunca outro idioma).
function t(chave, params) {
    if (typeof conf.t === 'function') {
        try {
            const r = conf.t(chave, params);
            if (typeof r === 'string' && r !== '') return r;
        } catch (e) { /* dicionário indisponível: devolve a chave */ }
    }
    return chave;
}
const RE_CHAVE = /^[a-z0-9_]+(\.[a-z0-9_-]+)+$/;
// Aceita chave de idioma ou texto já traduzido.
function txt(v, params) {
    if (v === null || v === undefined || v === false) return '';
    if (typeof v !== 'string') return String(v);
    return RE_CHAVE.test(v) ? t(v, params) : v;
}
function idioma() {
    const i = conf.idioma || (document.documentElement.getAttribute('lang') || 'pt');
    return String(i).slice(0, 2).toLowerCase();
}
// Só a primeira letra em maiúscula ("outubro de 2026" vira "Outubro de 2026").
function inicialMaiuscula(s) {
    return s ? s.charAt(0).toLocaleUpperCase(idioma()) + s.slice(1) : s;
}
function localidade() {
    return { pt: 'pt-BR', en: 'en-US', es: 'es-ES' }[idioma()] || 'pt-BR';
}

// Criação de elementos sem HTML: atributos, texto e filhos.
function h(etiqueta, atributos, ...filhos) {
    const e = document.createElement(etiqueta);
    if (atributos) {
        for (const [k, v] of Object.entries(atributos)) {
            if (v === null || v === undefined || v === false) continue;
            if (k === 'class') e.className = v;
            else if (k === 'texto') e.textContent = v;
            else if (k === 'dados') Object.assign(e.dataset, v);
            else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v);
            else if (v === true) e.setAttribute(k, '');
            else e.setAttribute(k, String(v));
        }
    }
    for (const f of filhos.flat(Infinity)) {
        if (f === null || f === undefined || f === false) continue;
        e.append(f instanceof Node ? f : String(f));
    }
    return e;
}
const SVG_NS = 'http://www.w3.org/2000/svg';
function icone(nome, classe) {
    const s = document.createElementNS(SVG_NS, 'svg');
    s.setAttribute('class', 'd2-icone' + (classe ? ' ' + classe : ''));
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('focusable', 'false');
    const u = document.createElementNS(SVG_NS, 'use');
    u.setAttribute('href', conf.sprite + '#' + nome);
    s.append(u);
    return s;
}
function corpoDe(conteudo) {
    if (conteudo instanceof Node) return conteudo;
    const d = document.createElement('div');
    d.textContent = conteudo === undefined || conteudo === null ? '' : String(conteudo);
    return d;
}
let contadorId = 0;
const novoId = (p = 'd2') => `${p}-${Date.now().toString(36)}-${(++contadorId).toString(36)}`;
const reduzido = () => window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const celular = () => window.matchMedia && window.matchMedia('(max-width: 759.98px)').matches;
// Espera o fim da transição (com limite), para remover o elemento só depois de sair.
function aposTransicao(el, ms, fn) {
    let feito = false;
    const fim = () => { if (feito) return; feito = true; el.removeEventListener('transitionend', fim); fn(); };
    el.addEventListener('transitionend', fim);
    setTimeout(fim, reduzido() ? 140 : ms + 60);
}
const SELETOR_FOCAVEL = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
function focaveis(raiz) {
    return [...raiz.querySelectorAll(SELETOR_FOCAVEL)].filter((e) => !e.closest('[hidden]') && e.getClientRects().length > 0);
}
// Mantém o Tab dentro do contêiner (modal, paleta).
function prenderFoco(raiz, evento) {
    if (evento.key !== 'Tab') return;
    const lista = focaveis(raiz);
    if (!lista.length) { evento.preventDefault(); return; }
    const primeiro = lista[0];
    const ultimo = lista[lista.length - 1];
    if (evento.shiftKey && (document.activeElement === primeiro || !raiz.contains(document.activeElement))) { evento.preventDefault(); ultimo.focus(); }
    else if (!evento.shiftKey && document.activeElement === ultimo) { evento.preventDefault(); primeiro.focus(); }
}

// Camadas que respondem ao Esc (modal, popover, menu): só a de cima fecha.
// Camada de janela só responde quando o foco está dentro da própria janela.
const camadas = [];
function empilhar(c) { camadas.push(c); }
function desempilhar(c) { const i = camadas.indexOf(c); if (i >= 0) camadas.splice(i, 1); }
document.addEventListener('keydown', (evento) => {
    if (evento.key !== 'Escape' || !camadas.length) return;
    for (let i = camadas.length - 1; i >= 0; i--) {
        const c = camadas[i];
        if (c.escopo && !c.escopo.contains(document.activeElement)) continue;
        if (c.esc === false) return;
        evento.preventDefault();
        evento.stopPropagation();
        c.fechar('esc');
        return;
    }
}, true);

// Posiciona um elemento flutuante junto do alvo (ou num ponto), sempre dentro da tela.
// lado: 'baixo' (padrão, vira para cima sem espaço), 'direita' ou 'esquerda' (ao lado, alinhado ao topo do alvo;
// sem espaço daquele lado, vai para o outro; sem espaço em nenhum, como no celular, abre embaixo).
function posicionar(flutuante, alvo, { alinhar = 'inicio', x, y, lado = 'baixo' } = {}) {
    const m = 8;
    const vw = document.documentElement.clientWidth;
    const vh = window.innerHeight;
    flutuante.style.left = '0px';
    flutuante.style.top = '0px';
    const pr = flutuante.getBoundingClientRect();
    let posEsquerda;
    let posTopo;
    flutuante.classList.remove('is-acima', 'is-lado-esquerdo', 'is-lado-direito');
    if (typeof x === 'number') {
        posEsquerda = x;
        posTopo = y;
        if (posTopo + pr.height > vh - m) posTopo = y - pr.height;
    } else if ((lado === 'direita' || lado === 'esquerda') && alvo && (
        alvo.getBoundingClientRect().right + 4 + pr.width <= vw - m || alvo.getBoundingClientRect().left - 4 - pr.width >= m)) {
        const r = alvo.getBoundingClientRect();
        const cabeDireita = r.right + 4 + pr.width <= vw - m;
        const direita = lado === 'direita' ? cabeDireita : r.left - 4 - pr.width < m;
        posEsquerda = direita ? r.right + 4 : r.left - 4 - pr.width;
        posTopo = r.top - 4;
        flutuante.classList.toggle('is-lado-esquerdo', !direita);
        flutuante.classList.toggle('is-lado-direito', direita);
    } else {
        const r = alvo.getBoundingClientRect();
        posEsquerda = alinhar === 'fim' ? r.right - pr.width : r.left;
        posTopo = r.bottom + 4;
        if (posTopo + pr.height > vh - m && r.top - pr.height - 4 >= m) { posTopo = r.top - pr.height - 4; flutuante.classList.add('is-acima'); }
    }
    posEsquerda = Math.max(m, Math.min(posEsquerda, vw - pr.width - m));
    posTopo = Math.max(m, Math.min(posTopo, vh - pr.height - m));
    flutuante.style.left = Math.round(posEsquerda) + 'px';
    flutuante.style.top = Math.round(posTopo) + 'px';
}

function alinhamentoAuto(botao) {
    if (!botao) return 'inicio';
    if (botao.classList.contains('d2-iconbtn') || !botao.textContent.trim()) return 'fim';
    const r = botao.getBoundingClientRect();
    return r.left + r.width / 2 > document.documentElement.clientWidth / 2 ? 'fim' : 'inicio';
}

// Flutuante genérico (menu, lista de opções, calendário): fecha no clique fora, no Esc
// e quando o alvo sai da tela; acompanha a rolagem.
const abertos = new Set();
function abrirFlutuante(conteudo, alvo, { classe = 'd2-popover', alinhar, lado, x, y, larguraMin, aoFechar, papel } = {}) {
    const flutuante = h('div', { class: classe, role: papel || null });
    flutuante.append(conteudo);
    if (larguraMin) flutuante.style.minWidth = Math.round(larguraMin) + 'px';
    document.body.append(flutuante);
    const reposicionar = () => {
        if (alvo && typeof x !== 'number') {
            const r = alvo.getBoundingClientRect();
            if (r.bottom < 0 || r.top > window.innerHeight || !alvo.isConnected) { fechar('fora'); return; }
        }
        posicionar(flutuante, alvo, { alinhar, lado, x, y });
    };
    let quadro = 0;
    const aoRolar = (evento) => {
        if (flutuante.contains(evento.target)) return;
        cancelAnimationFrame(quadro);
        quadro = requestAnimationFrame(reposicionar);
    };
    const aoApontar = (evento) => {
        if (flutuante.contains(evento.target) || (alvo && alvo.contains && alvo.contains(evento.target))) return;
        fechar('fora');
    };
    const aoRedimensionar = () => { cancelAnimationFrame(quadro); quadro = requestAnimationFrame(reposicionar); };
    let fechado = false;
    const camada = { fechar: (motivo) => fechar(motivo) };
    function fechar(motivo) {
        if (fechado) return;
        fechado = true;
        abertos.delete(reg);
        desempilhar(camada);
        document.removeEventListener('pointerdown', aoApontar, true);
        window.removeEventListener('scroll', aoRolar, true);
        window.removeEventListener('resize', aoRedimensionar);
        flutuante.remove();
        if (aoFechar) aoFechar(motivo);
    }
    const reg = { flutuante, alvo, fechar };
    abertos.add(reg);
    empilhar(camada);
    reposicionar();
    setTimeout(() => {
        if (fechado) return;
        document.addEventListener('pointerdown', aoApontar, true);
        window.addEventListener('scroll', aoRolar, true);
        window.addEventListener('resize', aoRedimensionar);
    }, 0);
    return { el: flutuante, fechar, reposicionar };
}
function flutuanteDe(alvo) {
    for (const r of abertos) if (alvo && r.alvo === alvo) return r;
    return null;
}

// Navegação por setas numa lista de itens focáveis.
function navegarSetas(evento, itens, atual) {
    const lista = itens.filter((i) => !i.disabled);
    if (!lista.length) return null;
    let i = lista.indexOf(atual);
    if (evento.key === 'ArrowDown') i = i < 0 ? 0 : (i + 1) % lista.length;
    else if (evento.key === 'ArrowUp') i = i < 0 ? lista.length - 1 : (i - 1 + lista.length) % lista.length;
    else if (evento.key === 'Home') i = 0;
    else if (evento.key === 'End') i = lista.length - 1;
    else return null;
    evento.preventDefault();
    return lista[i];
}

// Menu de contexto: alvo é o botão que abre (ou um evento de clique direito).
// itens: [{ icone, rotulo, fn, perigo, desativado, atalho, marcado?, manterAberto? }] ou 'separador' ou { grupo }.
// marcado (booleano) vira item de marcar (menuitemcheckbox, com a marca de seleção); com manterAberto o menu não fecha ao alternar.
// opcoes: { alinhar: 'inicio'|'fim', lado: 'baixo' (padrão)|'direita'|'esquerda' }.
// Sem alinhamento pedido: botão só de ícone (reticências no fim de linhas e cabeçalhos) alinha
// pelo fim; botão com texto cresce para dentro da tela, pelo início na metade esquerda.
function menuContexto(alvo, itens = [], { alinhar, lado } = {}) {
    const ehEvento = alvo && typeof alvo.clientX === 'number' && typeof alvo.preventDefault === 'function';
    if (ehEvento) alvo.preventDefault();
    const botao = ehEvento ? null : alvo;
    const existente = botao ? flutuanteDe(botao) : null;
    if (existente) { existente.fechar('alternar'); return null; }
    for (const r of [...abertos]) r.fechar('outro');
    const lista = h('div', { class: 'd2-menu-lista' });
    const botoes = [];
    let fecharMenu = () => {};
    // Com algum item marcável, todos reservam a coluna da marca para os textos ficarem alinhados.
    const comMarcas = itens.some((i) => i && typeof i.marcado === 'boolean');
    for (const item of itens) {
        if (!item) continue;
        if (item === 'separador' || item.separador) { lista.append(h('div', { class: 'd2-menu-sep', role: 'separator' })); continue; }
        if (item.grupo) { lista.append(h('div', { class: 'd2-menu-grupo', texto: txt(item.grupo) })); continue; }
        const marcavel = typeof item.marcado === 'boolean';
        const b = h('button', {
            type: 'button', role: marcavel ? 'menuitemcheckbox' : 'menuitem', tabindex: '-1',
            'aria-checked': marcavel ? String(item.marcado) : null,
            class: 'd2-menu-item' + (item.perigo ? ' is-perigo' : '') + (marcavel ? ' is-marcavel' : ''), disabled: Boolean(item.desativado),
        }, marcavel || comMarcas ? h('span', { class: 'd2-menu-item-marca', 'aria-hidden': 'true' }, marcavel ? icone('check') : null) : null,
        item.icone ? icone(item.icone) : null, h('span', { class: 'd2-menu-item-texto', texto: txt(item.rotulo) }),
        item.selo ? selo(txt(item.selo.texto), item.selo.tipo) : null,
        item.atalho ? h('kbd', { class: 'd2-kbd', 'aria-label': `${t('d2.atalho_teclado')}: ${item.atalho}`, texto: item.atalho }) : null);
        b.addEventListener('click', () => {
            // Item marcável com manterAberto alterna no lugar (ex.: escolher colunas); fn devolvendo false desfaz.
            if (marcavel && item.manterAberto) {
                const novo = b.getAttribute('aria-checked') !== 'true';
                const r = typeof item.fn === 'function' ? item.fn(item, novo) : undefined;
                if (r !== false) { item.marcado = novo; b.setAttribute('aria-checked', String(novo)); }
                if (typeof item.aoAlternar === 'function') item.aoAlternar(botoes);
                return;
            }
            fecharMenu('item');
            if (typeof item.fn === 'function') item.fn(item, marcavel ? !item.marcado : undefined);
        });
        botoes.push(b);
        lista.append(b);
    }
    lista.addEventListener('keydown', (evento) => {
        const proximo = navegarSetas(evento, botoes, document.activeElement);
        if (proximo) proximo.focus();
        else if (evento.key === 'Tab') fecharMenu('tab');
    });
    lista.addEventListener('pointermove', (evento) => {
        const b = evento.target.closest('.d2-menu-item');
        if (b && !b.disabled && document.activeElement !== b) b.focus({ preventScroll: true });
    });
    const f = abrirFlutuante(lista, botao, {
        classe: 'd2-menu-contexto', papel: 'menu', alinhar: alinhar || alinhamentoAuto(botao),
        lado: ['direita', 'esquerda'].includes(lado) ? lado : 'baixo',
        x: ehEvento ? alvo.clientX : undefined, y: ehEvento ? alvo.clientY : undefined,
        aoFechar: (motivo) => {
            if (botao) botao.setAttribute('aria-expanded', 'false');
            if (botao && motivo !== 'fora' && botao.isConnected) botao.focus({ preventScroll: true });
        },
    });
    fecharMenu = f.fechar;
    f.el.setAttribute('aria-label', t('d2.menu'));
    if (botao) { botao.setAttribute('aria-haspopup', 'menu'); botao.setAttribute('aria-expanded', 'true'); }
    const primeiro = botoes.find((b) => !b.disabled);
    if (primeiro) primeiro.focus({ preventScroll: true });
    return { el: f.el, fechar: f.fechar };
}

// Dica flutuante por data-tooltip: mouse, foco por teclado e toque longo.
let dicaEl = null;
let dicaAlvo = null;
let dicaTimer = 0;
let dicaUltima = 0;
let dicaModo = '';
let dicaVigia = 0;
// Vigia enquanto a dica está visível: some se o alvo saiu da tela, perdeu o mouse ou o foco.
function alvoDicaAtivo(alvo) {
    if (!alvo.isConnected || document.hidden) return false;
    if (dicaModo === 'toque') return true;
    try {
        if (alvo.matches(':hover')) return true;
        const foco = document.activeElement;
        return !!(foco && alvo.contains(foco) && foco.matches(':focus-visible'));
    } catch (e) { return true; }
}
function vigiarDica() {
    clearInterval(dicaVigia);
    dicaVigia = setInterval(() => {
        if (!dicaAlvo) { clearInterval(dicaVigia); dicaVigia = 0; return; }
        if (!alvoDicaAtivo(dicaAlvo)) esconderDica();
    }, 250);
}
function mostrarDica(alvo, modo) {
    const texto = txt(alvo.getAttribute('data-tooltip'));
    if (!texto) return;
    if (!dicaEl) {
        dicaEl = h('div', { class: 'd2-floattip', role: 'tooltip', id: 'd2-dica' });
        document.body.append(dicaEl);
    }
    dicaAlvo = alvo;
    dicaModo = modo || 'mouse';
    dicaEl.textContent = texto;
    dicaEl.classList.remove('abaixo', 'show', 'is-visivel');
    dicaEl.style.left = '0px';
    dicaEl.style.top = '0px';
    const r = alvo.getBoundingClientRect();
    const d = dicaEl.getBoundingClientRect();
    const vw = document.documentElement.clientWidth;
    const centro = r.left + r.width / 2;
    const meio = d.width / 2;
    const posEsquerda = Math.max(8 + meio, Math.min(centro, vw - 8 - meio));
    const abaixo = r.top < d.height + 14;
    dicaEl.classList.toggle('abaixo', abaixo);
    dicaEl.style.left = Math.round(posEsquerda) + 'px';
    dicaEl.style.top = Math.round(abaixo ? r.bottom : r.top) + 'px';
    dicaEl.style.setProperty('--d2-tip-seta', Math.round(Math.max(10, Math.min(d.width - 10, centro - (posEsquerda - meio)))) + 'px');
    alvo.setAttribute('aria-describedby', 'd2-dica');
    requestAnimationFrame(() => { if (dicaAlvo === alvo) dicaEl.classList.add('show'); });
    vigiarDica();
}
function esconderDica() {
    clearTimeout(dicaTimer);
    if (dicaAlvo) {
        if (dicaAlvo.getAttribute('aria-describedby') === 'd2-dica') dicaAlvo.removeAttribute('aria-describedby');
        dicaUltima = Date.now();
    }
    dicaAlvo = null;
    dicaModo = '';
    clearInterval(dicaVigia);
    dicaVigia = 0;
    if (dicaEl) dicaEl.classList.remove('show', 'is-visivel');
}
function agendarDica(alvo, ms, modo) {
    clearTimeout(dicaTimer);
    if (dicaAlvo === alvo) return;
    const espera = Date.now() - dicaUltima < 400 ? 0 : ms;
    dicaTimer = setTimeout(() => { if (alvo.isConnected) mostrarDica(alvo, modo); }, espera);
}
document.addEventListener('pointerover', (evento) => {
    if (evento.pointerType === 'touch') return;
    const alvo = evento.target.closest && evento.target.closest('[data-tooltip]');
    if (alvo) agendarDica(alvo, 380, 'mouse');
    else if (dicaAlvo || dicaTimer) esconderDica();
});
document.addEventListener('pointerout', (evento) => {
    if (evento.pointerType === 'touch') return;
    const alvo = evento.target.closest && evento.target.closest('[data-tooltip]');
    if (alvo && !(evento.relatedTarget && alvo.contains(evento.relatedTarget))) esconderDica();
});
document.addEventListener('focusin', (evento) => {
    const alvo = evento.target.closest && evento.target.closest('[data-tooltip]');
    if (!alvo) return;
    let teclado = false;
    try { teclado = evento.target.matches(':focus-visible'); } catch (e) { teclado = false; }
    if (teclado) agendarDica(alvo, 120, 'teclado');
});
document.addEventListener('focusout', () => esconderDica());
let toqueTimer = 0;
document.addEventListener('pointerdown', (evento) => {
    esconderDica();
    if (evento.pointerType !== 'touch') return;
    const alvo = evento.target.closest && evento.target.closest('[data-tooltip]');
    if (!alvo) return;
    clearTimeout(toqueTimer);
    toqueTimer = setTimeout(() => { mostrarDica(alvo, 'toque'); setTimeout(() => { if (dicaAlvo === alvo) esconderDica(); }, 1800); }, 450);
}, true);
const cancelarToque = () => clearTimeout(toqueTimer);
document.addEventListener('pointerup', cancelarToque, true);
document.addEventListener('pointercancel', cancelarToque, true);
document.addEventListener('keydown', (evento) => { if (evento.key === 'Escape' && dicaAlvo) esconderDica(); });
window.addEventListener('scroll', () => { if (dicaAlvo) esconderDica(); }, true);
window.addEventListener('blur', () => esconderDica());
document.documentElement.addEventListener('pointerleave', (evento) => { if (evento.pointerType !== 'touch') esconderDica(); });

// Atalhos de teclado: D2UI.atalhos.registrar('ctrl+k', fn) devolve a função que remove.
const atalhosLista = [];
const MODIFICADORES = ['ctrl', 'mod', 'cmd', 'meta', 'shift', 'alt'];
const APELIDOS_TECLA = { esc: 'escape', espaco: ' ', space: ' ', cima: 'arrowup', baixo: 'arrowdown', esquerda: 'arrowleft', direita: 'arrowright', del: 'delete', mais: '+', menos: '-' };
function lerCombo(combo) {
    const partes = String(combo).toLowerCase().split('+').map((s) => s.trim()).filter(Boolean);
    let tecla = partes.filter((p) => !MODIFICADORES.includes(p)).pop() || '';
    if (String(combo).trim().endsWith('++')) tecla = '+';
    tecla = APELIDOS_TECLA[tecla] || tecla;
    return {
        ctrl: partes.some((p) => p === 'ctrl' || p === 'mod' || p === 'cmd' || p === 'meta'),
        shift: partes.includes('shift'),
        alt: partes.includes('alt'),
        tecla,
    };
}
function emCampoEditavel(el) {
    if (!el) return false;
    if (el.isContentEditable) return true;
    const etiqueta = el.tagName;
    if (etiqueta === 'TEXTAREA' || etiqueta === 'SELECT') return true;
    if (etiqueta === 'INPUT') return !['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color', 'file'].includes(el.type);
    return false;
}
function comboBate(c, evento) {
    const tecla = (evento.key || '').toLowerCase();
    const simbolo = c.tecla.length === 1 && !/[a-z0-9]/.test(c.tecla);
    if ((evento.ctrlKey || evento.metaKey) !== c.ctrl || evento.altKey !== c.alt) return false;
    if (!simbolo && evento.shiftKey !== c.shift) return false;
    if (tecla === c.tecla) return true;
    return c.tecla.length === 1 && /[a-z0-9]/.test(c.tecla) && (evento.code === 'Key' + c.tecla.toUpperCase() || evento.code === 'Digit' + c.tecla);
}
document.addEventListener('keydown', (evento) => {
    if (evento.defaultPrevented || evento.isComposing || !atalhosLista.length) return;
    for (let i = atalhosLista.length - 1; i >= 0; i--) {
        const a = atalhosLista[i];
        if (!comboBate(a.combo, evento)) continue;
        if (emCampoEditavel(evento.target) && !a.combo.ctrl && !a.combo.alt && !a.emCampo) continue;
        evento.preventDefault();
        a.fn(evento);
        return;
    }
});
const atalhos = {
    registrar(combo, fn, opcoes = {}) {
        const reg = { texto: combo, combo: lerCombo(combo), fn, emCampo: Boolean(opcoes.emCampo), descricao: opcoes.descricao || null };
        atalhosLista.push(reg);
        return () => { const i = atalhosLista.indexOf(reg); if (i >= 0) atalhosLista.splice(i, 1); };
    },
    remover(combo) {
        for (let i = atalhosLista.length - 1; i >= 0; i--) if (atalhosLista[i].texto === combo) atalhosLista.splice(i, 1);
    },
    listar() { return atalhosLista.map((a) => ({ combo: a.texto, descricao: a.descricao })); },
};

// Notificação passageira
let pilhaAvisos = null;
const ICONE_TIPO = { ok: 'check', erro: 'erro', aviso: 'alerta', info: 'info' };
function normalizarTipo(tipo) {
    const mapa = { success: 'ok', sucesso: 'ok', error: 'erro', danger: 'erro', perigo: 'erro', warning: 'aviso', warn: 'aviso', alerta: 'aviso' };
    const v = mapa[tipo] || tipo;
    return ['ok', 'erro', 'aviso', 'info'].includes(v) ? v : 'info';
}
function toast(mensagem, opcoes = {}) {
    if (typeof opcoes === 'string') opcoes = { tipo: opcoes };
    const tipo = normalizarTipo(opcoes.tipo);
    if (!pilhaAvisos || !pilhaAvisos.isConnected) {
        pilhaAvisos = h('section', { class: 'd2-toasts', 'aria-label': t('d2.notificacoes') });
        document.body.append(pilhaAvisos);
    }
    const texto = h('div', { class: 'd2-toast-texto' },
        opcoes.titulo ? h('strong', { class: 'd2-toast-titulo', texto: txt(opcoes.titulo) }) : null,
        h('span', { class: 'd2-toast-msg', texto: txt(mensagem) }));
    const el = h('div', {
        class: `d2-toast d2-toast-${tipo}`, role: tipo === 'erro' ? 'alert' : 'status',
        'aria-live': tipo === 'erro' ? 'assertive' : 'polite', 'aria-atomic': 'true',
    }, h('span', { class: 'd2-toast-icone' }, icone(ICONE_TIPO[tipo])), texto);
    let restante = typeof opcoes.duracao === 'number' ? opcoes.duracao : (tipo === 'erro' ? 7000 : (opcoes.acao ? 8000 : 4500));
    let temporizador = 0;
    let inicio = 0;
    let fechado = false;
    function fechar() {
        if (fechado) return;
        fechado = true;
        clearTimeout(temporizador);
        el.classList.add('is-saindo');
        el.classList.remove('is-visivel');
        aposTransicao(el, 150, () => el.remove());
    }
    if (opcoes.acao && opcoes.acao.rotulo) {
        texto.append(h('button', {
            type: 'button', class: 'd2-link', texto: txt(opcoes.acao.rotulo),
            onclick: () => { fechar(); if (typeof opcoes.acao.fn === 'function') opcoes.acao.fn(); },
        }));
    }
    el.append(h('button', {
        type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma d2-toast-x', 'aria-label': t('d2.fechar_notificacao'),
        onclick: fechar,
    }, icone('fechar')));
    const correr = () => { if (restante > 0 && restante < Infinity) { inicio = Date.now(); temporizador = setTimeout(fechar, restante); } };
    const pausar = () => { clearTimeout(temporizador); restante -= Date.now() - inicio; };
    el.addEventListener('pointerenter', pausar);
    el.addEventListener('pointerleave', correr);
    el.addEventListener('focusin', pausar);
    el.addEventListener('focusout', correr);
    const vivos = pilhaAvisos.querySelectorAll('.d2-toast:not(.is-saindo)');
    if (vivos.length >= 4) vivos[0].querySelector('.d2-toast-x').click();
    pilhaAvisos.append(el);
    el.getBoundingClientRect();
    el.classList.add('is-visivel');
    correr();
    return { el, fechar };
}

// Modal. Com { janela } bloqueia só aquela janela (véu montado dentro da raiz dela);
// sem janela, bloqueia a tela inteira. enterConfirma: Enter num campo dispara o botão
// primário (padrão true quando o corpo tem um único campo).
function raizJanela(janela) {
    if (!janela) return null;
    if (janela instanceof Element) return janela;
    const r = janela.el || janela.raiz || janela.elemento;
    if (r instanceof Element) return r;
    const c = janela.corpo;
    if (c instanceof Element) return c.closest('.janela, [data-janela]') || c.parentElement || c;
    return null;
}
const CLASSE_BOTAO = { primario: 'd2-btn-primary', primary: 'd2-btn-primary', perigo: 'd2-btn-danger', danger: 'd2-btn-danger', secundario: 'd2-btn-secundario', fantasma: 'd2-btn-fantasma' };
let modaisTela = 0;
function modal(opcoes = {}) {
    const { titulo, corpo, botoes = [], largura, aoFechar, janela, perigo, classe } = opcoes;
    const raiz = raizJanela(janela);
    const anterior = document.activeElement;
    const idTitulo = novoId('d2-modal-titulo');
    let fechavel = opcoes.fechavel !== false;
    let fechado = false;
    const caixa = h('div', { class: 'd2-modal' + (classe ? ' ' + classe : ''), role: perigo ? 'alertdialog' : 'dialog', 'aria-modal': 'true', 'aria-labelledby': idTitulo, tabindex: '-1' });
    if (largura) caixa.style.setProperty('--d2-modal-largura', typeof largura === 'number' ? largura + 'px' : String(largura));
    const botaoX = h('button', { type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma d2-modal-x', 'aria-label': t('d2.fechar'), onclick: () => fechar('x') }, icone('fechar'));
    botaoX.hidden = !fechavel;
    caixa.append(h('div', { class: 'd2-modal-cab' },
        opcoes.icone ? h('span', { class: 'd2-modal-icone' + (perigo ? ' is-perigo' : '') }, icone(opcoes.icone)) : null,
        h('h2', { class: 'd2-modal-titulo', id: idTitulo, texto: txt(titulo) }), botaoX));
    const corpoEl = h('div', { class: 'd2-modal-corpo' }, typeof corpo === 'string' ? h('p', { texto: txt(corpo) }) : corpo);
    const rodape = h('div', { class: 'd2-modal-rodape' });
    caixa.append(corpoEl, rodape);
    const controleModal = { el: caixa, veu: null, corpo: corpoEl, rodape, botoes: [], fechar: (motivo) => fechar(motivo || 'codigo'), definirFechavel };
    function adicionarBotao(b) {
        const botaoEl = h('button', { type: 'button', class: ('d2-btn ' + (CLASSE_BOTAO[b.tipo || 'secundario'] || '')).trim() },
            b.icone ? icone(b.icone) : null, h('span', { texto: txt(b.rotulo) }));
        if (b.desativado) botaoEl.disabled = true;
        botaoEl.dataset.d2Tipo = b.tipo || 'secundario';
        botaoEl.addEventListener('click', async (evento) => {
            if (botaoEl.disabled || fechado) return;
            let r;
            if (typeof b.fn === 'function') {
                r = b.fn(controleModal, evento);
                if (r && typeof r.then === 'function') {
                    botaoEl.disabled = true;
                    botaoEl.classList.add('d2-btn-carregando');
                    botaoEl.setAttribute('aria-busy', 'true');
                    try { r = await r; } finally {
                        botaoEl.disabled = false;
                        botaoEl.classList.remove('d2-btn-carregando');
                        botaoEl.removeAttribute('aria-busy');
                    }
                }
            }
            if (b.fecha !== false && r !== false) fechar('botao');
        });
        controleModal.botoes.push(botaoEl);
        rodape.append(botaoEl);
        rodape.hidden = false;
        return botaoEl;
    }
    controleModal.adicionarBotao = adicionarBotao;
    rodape.hidden = true;
    botoes.forEach(adicionarBotao);
    const veu = h('div', { class: 'd2-modal-veu' + (raiz ? ' is-janela' : '') }, caixa);
    controleModal.veu = veu;
    let hospedou = false;
    const inertes = [];
    const pai = raiz || document.body;
    for (const filho of pai.children) {
        if (filho.inert || filho.classList.contains('d2-toasts') || filho.classList.contains('d2-floattip')) continue;
        if (!raiz && filho.matches('script, style, link')) continue;
        filho.inert = true;
        inertes.push(filho);
    }
    if (raiz) {
        if (getComputedStyle(raiz).position === 'static') { raiz.classList.add('d2-modal-hospedeiro'); hospedou = true; }
        raiz.append(veu);
    } else {
        document.body.append(veu);
        if (++modaisTela === 1) document.documentElement.style.overflow = 'hidden';
    }
    const camada = { escopo: raiz, esc: fechavel, fechar: () => fechar('esc') };
    empilhar(camada);
    caixa.addEventListener('keydown', (evento) => prenderFoco(caixa, evento));
    // Enter num campo dispara o botão primário. Padrão: ligado quando o corpo tem um único campo.
    caixa.addEventListener('keydown', (evento) => {
        if (evento.key !== 'Enter' || evento.defaultPrevented || evento.isComposing || evento.shiftKey || evento.altKey) return;
        const campo = evento.target;
        if (!(campo instanceof HTMLInputElement) || !corpoEl.contains(campo)) return;
        if (['checkbox', 'radio', 'file', 'button', 'submit'].includes(campo.type) || campo.getAttribute('aria-expanded') === 'true') return;
        const campos = corpoEl.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
        const ligado = typeof opcoes.enterConfirma === 'boolean' ? opcoes.enterConfirma : campos.length === 1;
        if (!ligado) return;
        const tipoDe = (b) => b.dataset.d2Tipo;
        const primario = controleModal.botoes.find((b) => ['primario', 'primary'].includes(tipoDe(b)))
            || controleModal.botoes.find((b) => ['perigo', 'danger'].includes(tipoDe(b)));
        if (!primario || primario.disabled) return;
        evento.preventDefault();
        primario.click();
    });
    let apertouFora = false;
    veu.addEventListener('pointerdown', (evento) => { apertouFora = evento.target === veu; });
    veu.addEventListener('click', (evento) => {
        if (evento.target !== veu || !apertouFora || !fechavel) return;
        const temCampo = corpoEl.querySelector('input, select, textarea');
        if (opcoes.fecharFora === true || (opcoes.fecharFora !== false && !temCampo)) fechar('fora');
    });
    if (janela && typeof janela.aoFechar === 'function') { try { janela.aoFechar(() => fechar('janela')); } catch (e) { /* janela sem suporte */ } }
    veu.getBoundingClientRect();
    veu.classList.add('is-aberto');
    const alvoFoco = opcoes.foco instanceof Element ? opcoes.foco
        : (corpoEl.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea')
            || (!perigo && controleModal.botoes.length ? controleModal.botoes[controleModal.botoes.length - 1] : caixa));
    requestAnimationFrame(() => { if (!fechado) alvoFoco.focus({ preventScroll: true }); });
    function definirFechavel(v) { fechavel = Boolean(v); botaoX.hidden = !fechavel; camada.esc = fechavel; }
    function fechar(motivo) {
        if (fechado) return;
        fechado = true;
        desempilhar(camada);
        for (const e of inertes) e.inert = false;
        if (!raiz && --modaisTela === 0) document.documentElement.style.overflow = '';
        veu.classList.remove('is-aberto');
        veu.classList.add('is-saindo');
        aposTransicao(veu, 150, () => {
            veu.remove();
            if (hospedou && !raiz.querySelector(':scope > .d2-modal-veu')) raiz.classList.remove('d2-modal-hospedeiro');
        });
        const foco = document.activeElement;
        if (anterior && anterior.isConnected && typeof anterior.focus === 'function' && (!foco || foco === document.body || veu.contains(foco))) {
            anterior.focus({ preventScroll: true });
        }
        if (typeof aoFechar === 'function') aoFechar(motivo);
    }
    return controleModal;
}

// Confirmação: Promise<boolean>. Com palavra, o botão só libera quando a palavra confere.
function confirmar(opcoes = {}) {
    if (typeof opcoes === 'string') opcoes = { texto: opcoes };
    const { titulo, texto, perigo = false, palavra, rotuloOk, rotuloCancelar, janela } = opcoes;
    return new Promise((resolver) => {
        let resposta = false;
        let m = null;
        const corpo = h('div', { class: 'd2-modal-confirmar' });
        if (texto instanceof Node) corpo.append(texto);
        else if (texto) for (const par of txt(texto).split(/\n{2,}/)) corpo.append(h('p', { texto: par }));
        let entrada = null;
        let erroEl = null;
        const confere = () => !palavra || (entrada && entrada.value.trim() === String(palavra));
        if (palavra) {
            const id = novoId('d2-palavra');
            const partes = t('d2.palavra_rotulo', { palavra: '\u0001' }).split('\u0001');
            entrada = h('input', {
                class: 'd2-control', id, type: 'text', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false',
                'aria-describedby': id + '-erro',
            });
            erroEl = h('div', { class: 'd2-field-erro', id: id + '-erro', 'aria-live': 'polite' });
            corpo.append(h('div', { class: 'd2-modal-palavra' },
                h('label', { class: 'd2-label', for: id }, partes[0], h('code', { texto: String(palavra) }), partes[1] || ''),
                entrada, erroEl));
        }
        const ok = () => {
            if (!confere()) {
                erroEl.textContent = t('d2.palavra_erro');
                entrada.classList.add('is-invalid');
                entrada.setAttribute('aria-invalid', 'true');
                entrada.focus();
                return false;
            }
            resposta = true;
            return true;
        };
        m = modal({
            titulo: titulo || 'd2.confirmar_titulo', corpo, janela, perigo, largura: opcoes.largura || 440,
            icone: opcoes.icone || (perigo ? 'alerta' : null),
            botoes: [
                { rotulo: rotuloCancelar || 'd2.cancelar', tipo: 'secundario' },
                { rotulo: rotuloOk || 'd2.confirmar', tipo: perigo ? 'perigo' : 'primario', fn: ok },
            ],
            foco: entrada || undefined,
            aoFechar: () => resolver(resposta),
        });
        const botaoOk = m.botoes[1];
        if (palavra) {
            botaoOk.disabled = true;
            entrada.addEventListener('input', () => {
                botaoOk.disabled = !confere();
                if (confere() || !entrada.value) { erroEl.textContent = ''; entrada.classList.remove('is-invalid'); entrada.removeAttribute('aria-invalid'); }
            });
            entrada.addEventListener('keydown', (evento) => {
                if (evento.key !== 'Enter') return;
                evento.preventDefault();
                if (ok()) m.fechar('botao');
            });
        } else if (perigo) {
            requestAnimationFrame(() => m.botoes[0].focus({ preventScroll: true }));
        }
    });
}

// Progresso de tarefa longa: { atualizar(pct, texto), concluir(texto), falhar(texto) }.
// pct nulo deixa a barra indeterminada. aoCancelar opcional mostra o botão Cancelar.
function progresso(opcoes = {}) {
    if (typeof opcoes === 'string') opcoes = { titulo: opcoes };
    const textoEl = h('span', { class: 'd2-progresso-texto', texto: txt(opcoes.texto) || t('d2.processando') });
    const pctEl = h('span', { class: 'd2-progresso-pct' });
    const barra = h('div', { class: 'd2-progresso-barra' });
    const trilho = h('div', {
        class: 'd2-progresso-trilho', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100',
        'aria-label': txt(opcoes.titulo) || t('d2.processando'),
    }, barra);
    const el = h('div', { class: 'd2-progresso is-indeterminado', 'aria-live': 'polite' },
        h('div', { class: 'd2-progresso-info' }, textoEl, pctEl), trilho);
    const m = modal({ titulo: opcoes.titulo || 'd2.processando', corpo: el, janela: opcoes.janela, largura: opcoes.largura || 420, fechavel: false, aoFechar: opcoes.aoFechar });
    let terminou = false;
    // Com aoCancelar, oferece "Cancelar" enquanto roda (some ao concluir ou falhar).
    const botaoCancelar = typeof opcoes.aoCancelar === 'function'
        ? m.adicionarBotao({ rotulo: 'd2.cancelar', tipo: 'secundario', fn: () => { terminou = true; opcoes.aoCancelar(); } })
        : null;
    const tirarCancelar = () => { if (botaoCancelar) botaoCancelar.remove(); };
    const fmtPct = (p) => new Intl.NumberFormat(localidade(), { style: 'percent', maximumFractionDigits: 0 }).format(p / 100);
    function atualizar(pct, texto) {
        if (terminou) return;
        if (texto !== undefined && texto !== null) textoEl.textContent = txt(texto);
        if (typeof pct !== 'number' || !isFinite(pct)) {
            el.classList.add('is-indeterminado');
            pctEl.textContent = '';
            barra.style.transform = '';
            trilho.removeAttribute('aria-valuenow');
            return;
        }
        const p = Math.max(0, Math.min(100, pct));
        el.classList.remove('is-indeterminado');
        barra.style.transform = `scaleX(${p / 100})`;
        pctEl.textContent = fmtPct(p);
        trilho.setAttribute('aria-valuenow', String(Math.round(p)));
    }
    function concluir(texto) {
        if (terminou) return;
        atualizar(100, texto || 'd2.concluido');
        terminou = true;
        tirarCancelar();
        el.classList.add('is-ok');
        setTimeout(() => m.fechar('concluido'), reduzido() ? 400 : 900);
    }
    function falhar(texto) {
        if (terminou) return;
        terminou = true;
        tirarCancelar();
        el.classList.remove('is-indeterminado');
        el.classList.add('is-erro');
        if (!trilho.hasAttribute('aria-valuenow')) barra.style.transform = 'scaleX(1)';
        textoEl.textContent = txt(texto) || t('d2.falhou');
        el.setAttribute('role', 'alert');
        m.definirFechavel(true);
        m.adicionarBotao({ rotulo: 'd2.fechar', tipo: 'secundario' }).focus();
    }
    return { el, atualizar, concluir, falhar, fechar: () => m.fechar('codigo') };
}

// Esqueleto de carregamento com N linhas (anuncia "carregando" ao leitor de tela).
function esqueleto(linhas = 3, opcoes = {}) {
    const el = h('div', { class: 'd2-esqueleto' + (opcoes.classe ? ' ' + opcoes.classe : ''), role: 'status', 'aria-busy': 'true' },
        h('span', { class: 'd2-oculto-leitor', texto: t('d2.carregando') }));
    if (opcoes.bloco) el.append(h('div', { class: 'd2-esqueleto-bloco', 'aria-hidden': 'true' }));
    for (let i = 0; i < Math.max(1, Number(linhas) || 1); i++) el.append(h('div', { class: 'd2-esqueleto-linha', 'aria-hidden': 'true' }));
    return el;
}

// Estado vazio: título pela chave, texto opcional (chave + '.texto' quando existir) e uma ação.
function vazio(opcoes = {}) {
    if (typeof opcoes === 'string') opcoes = { chave: opcoes };
    const chave = opcoes.chave || 'd2.vazio_titulo';
    let texto = opcoes.texto ? txt(opcoes.texto) : '';
    if (!opcoes.texto && RE_CHAVE.test(chave)) {
        const k = chave + '.texto';
        const v = t(k);
        if (v && v !== k && !v.includes(k)) texto = v;
    }
    const erro = opcoes.tipo === 'erro' || opcoes.icone === 'erro';
    const el = h('div', { class: 'd2-vazio' + (erro ? ' is-erro' : ''), role: erro ? 'alert' : null },
        h('span', { class: 'd2-vazio-icone', 'aria-hidden': 'true' }, icone(opcoes.icone || 'lista')),
        h('p', { class: 'd2-vazio-titulo', texto: txt(chave) }),
        texto ? h('p', { class: 'd2-vazio-texto', texto }) : null);
    const acao = opcoes.acao;
    if (acao && acao.rotulo) {
        el.append(h('button', {
            type: 'button', class: ('d2-btn ' + (CLASSE_BOTAO[acao.tipo || 'secundario'] || '')).trim(),
            onclick: (evento) => { if (typeof acao.fn === 'function') acao.fn(evento); },
        }, acao.icone ? icone(acao.icone) : null, h('span', { texto: txt(acao.rotulo) })));
    }
    return el;
}

// Áreas do sistema e tons de estado (guia em docs/design/cores.md). A área só troca o acento
// (pastilha, filete, cabeçalho, indicador); o tom diz o estado e vence a área no mesmo elemento.
const AREAS = ['painel', 'inicio', 'projetos', 'ide', 'agendamentos', 'usuarios', 'licencas', 'gateway', 'monitoramento', 'configuracoes', 'conta'];
const TONS = ['ok', 'aviso', 'erro', 'info', 'neutro'];
const areaValida = (a) => (AREAS.includes(a) ? a : null);
const tomValido = (t) => (TONS.includes(t) ? t : null);

// Selo de estado com ponto: tipo ok, aviso, erro, info ou neutro. opcoes.area só vale para
// selo neutro (categoria de uma parte do sistema); selo de estado nunca muda de cor pela área.
const TIPOS_SELO = TONS;
function selo(texto, tipo = 'neutro', opcoes = {}) {
    const tp = TIPOS_SELO.includes(tipo) ? tipo : ({ success: 'ok', sucesso: 'ok', error: 'erro', danger: 'erro', perigo: 'erro', warning: 'aviso', alerta: 'aviso' }[tipo] || 'neutro');
    const el = h('span', {
        class: `d2-selo d2-selo-${tp}` + (opcoes.classe ? ' ' + opcoes.classe : ''),
        'data-tom': tp, 'data-area': tp === 'neutro' ? areaValida(opcoes.area) : null,
    });
    if (el.dataset.area) el.removeAttribute('data-tom');
    if (opcoes.icone) el.append(icone(opcoes.icone));
    else if (opcoes.ponto !== false) el.append(h('span', { class: 'd2-ponto' + (opcoes.pulsando ? ' is-pulsando' : ''), 'aria-hidden': 'true' }));
    el.append(h('span', { class: 'd2-selo-texto', texto: txt(texto) }));
    if (opcoes.dica) el.setAttribute('data-tooltip', txt(opcoes.dica));
    return el;
}

// Avatar com iniciais; a cor sai do nome (sempre a mesma para o mesmo nome).
const PARTICULAS = ['da', 'de', 'do', 'das', 'dos', 'e', 'del', 'la', 'las', 'los', 'el', 'y', 'van', 'von', 'di', 'du', 'des', 'a', 'o', 'as', 'os', 'the', 'and', '&'];
// Sufixos societários não identificam ninguém: "Academia Albaterra LTDA" vira AA, não AL.
// Só saem do fim do nome e sempre sobra uma palavra; os curtos que também são sobrenome (Sa, Me) só contam em maiúsculas.
const SUFIXOS_SOCIETARIOS = new Set(['ltda', 'eireli', 'epp', 'mei', 'slu', 's/a', 's.a', 'cia']);
const SUFIXOS_MAIUSCULOS = new Set(['me', 'sa', 'ss']);
function ehSufixo(pedaco) {
    const limpo = pedaco.replace(/^[^\p{L}\p{N}/]+|[^\p{L}\p{N}/]+$/gu, '');
    if (!limpo) return false;
    const chave = limpo.toLowerCase();
    return SUFIXOS_SOCIETARIOS.has(chave) || (SUFIXOS_MAIUSCULOS.has(chave) && limpo === limpo.toUpperCase());
}
function nomeSemSufixo(nome) {
    const partes = String(nome || '').trim().split(/\s+/).filter(Boolean);
    while (partes.length > 1) {
        const ultima = partes[partes.length - 1];
        if (/^[^\p{L}\p{N}]+$/u.test(ultima) || ultima.split('-').filter(Boolean).every(ehSufixo)) partes.pop();
        else break;
    }
    return partes.join(' ');
}
function iniciais(nome) {
    const limpa = (p) => p.replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '');
    const todas = nomeSemSufixo(nome).split(/\s+/).map(limpa).filter(Boolean);
    const partes = todas.filter((p) => !PARTICULAS.includes(p.toLowerCase()));
    const uteis = partes.length ? partes : todas;
    if (!uteis.length) return '?';
    const letra = (p) => (Array.from(p)[0] || '').toLocaleUpperCase(localidade());
    return (letra(uteis[0]) + (uteis.length > 1 ? letra(uteis[uteis.length - 1]) : '')) || '?';
}
// A cor sai do nome (uma das 8 matizes, sempre a mesma para o mesmo nome); opcoes.cor (1 a 8)
// fixa a matiz e opcoes.area veste a matiz da parte do sistema a que a entidade pertence.
function corDoNome(nome) {
    let soma = 0;
    for (const c of nomeSemSufixo(nome)) soma = (soma * 31 + c.codePointAt(0)) >>> 0;
    return (soma % 8) + 1;
}
function avatar(nome, opcoes = {}) {
    const fixa = Number(opcoes.cor);
    const cor = Number.isInteger(fixa) && fixa >= 1 && fixa <= 8 ? fixa : corDoNome(nome);
    const area = areaValida(opcoes.area);
    const tam = opcoes.tamanho === 'sm' || opcoes.tamanho === 'lg' ? ' d2-avatar-' + opcoes.tamanho : '';
    const el = h('span', {
        class: 'd2-avatar' + tam + (!area && cor > 1 ? ' d2-avatar-cor-' + cor : ''),
        'data-area': area, 'aria-hidden': opcoes.rotulo ? null : 'true',
    });
    if (opcoes.rotulo) { el.setAttribute('role', 'img'); el.setAttribute('aria-label', String(nome || '')); }
    el.textContent = iniciais(nome);
    if (opcoes.imagem) {
        const imagem = h('img', { src: opcoes.imagem, alt: '', loading: 'lazy', decoding: 'async' });
        imagem.addEventListener('load', () => { el.textContent = ''; el.append(imagem); });
    }
    return el;
}

// Indicador (número de destaque). opcoes: { rotulo, valor, variacao, tipoVariacao (positivo, negativo,
// aviso, neutro), seta, icone, area, tom, dica, onclick, carregando, classe, id }.
// Cor por significado: tom para número que é estado ("Em atraso" = erro, "OS atrasadas" = aviso);
// área para total sem juízo de valor. O número só ganha cor com tom erro.
// Devolve { el, definir({ valor, variacao, tipoVariacao, seta, carregando, tom }) }.
function indicador(opcoes = {}) {
    const clicavel = typeof opcoes.onclick === 'function';
    const el = h(clicavel ? 'button' : 'div', {
        type: clicavel ? 'button' : null, id: opcoes.id || null,
        class: 'd2-indicador' + (opcoes.classe ? ' ' + opcoes.classe : ''),
        'data-area': areaValida(opcoes.area), 'data-tom': tomValido(opcoes.tom),
        'data-tooltip': opcoes.dica ? txt(opcoes.dica) : null, onclick: clicavel ? opcoes.onclick : null,
    });
    const rotulo = h('span', { class: 'd2-indicador-rotulo' }, opcoes.icone ? icone(opcoes.icone) : null, h('span', { texto: txt(opcoes.rotulo || '') }));
    const valor = h('span', { class: 'd2-indicador-valor d2-num' });
    const variacao = h('span', { class: 'd2-indicador-variacao' });
    el.append(rotulo, valor, variacao);
    function definir(d = {}) {
        if ('tom' in d) { const tom = tomValido(d.tom); if (tom) el.dataset.tom = tom; else delete el.dataset.tom; }
        if ('valor' in d) valor.textContent = d.valor === null || d.valor === undefined ? '' : String(d.valor);
        if ('variacao' in d || 'tipoVariacao' in d || 'seta' in d) {
            const tipo = d.tipoVariacao || opcoes.tipoVariacao;
            variacao.className = 'd2-indicador-variacao' + (tipo ? ' is-' + tipo : '');
            variacao.replaceChildren();
            const seta = 'seta' in d ? d.seta : opcoes.seta;
            if (seta) variacao.append(icone(seta));
            const v = 'variacao' in d ? d.variacao : opcoes.variacao;
            if (v !== null && v !== undefined && v !== '') variacao.append(txt(v));
        }
        if ('carregando' in d) {
            el.classList.toggle('is-carregando', !!d.carregando);
            el.setAttribute('aria-busy', d.carregando ? 'true' : 'false');
        }
    }
    definir({ valor: opcoes.valor, variacao: opcoes.variacao, tipoVariacao: opcoes.tipoVariacao, seta: opcoes.seta, carregando: !!opcoes.carregando });
    return { el, rotulo, valor, variacao, definir };
}

// Painel do mosaico. opcoes: { titulo, sub, icone, area, tom, acoes, rodape, id, classe, largo, justo }.
// Sem area o painel herda a área da janela; o ícone do cabeçalho vira pastilha do acento.
// Devolve { el, cab, tituloEl, acoes, corpo, rodape }.
function painel(opcoes = {}, ...conteudo) {
    const el = h('section', {
        id: opcoes.id || null,
        class: 'd2-painel' + (opcoes.largo ? ' d2-painel-largo' : '') + (opcoes.classe ? ' ' + opcoes.classe : ''),
        'data-area': areaValida(opcoes.area), 'data-tom': tomValido(opcoes.tom),
    });
    const idTitulo = opcoes.titulo ? novoId('d2-painel-titulo') : null;
    if (idTitulo) el.setAttribute('aria-labelledby', idTitulo);
    const tituloEl = opcoes.titulo ? h('h3', { class: 'd2-painel-titulo', id: idTitulo, texto: txt(opcoes.titulo) }) : null;
    const acoes = h('div', { class: 'd2-painel-acoes' }, opcoes.acoes || null);
    const cab = h('header', { class: 'd2-painel-cab' },
        opcoes.icone ? h('span', { class: 'd2-painel-icone', 'aria-hidden': 'true' }, icone(opcoes.icone)) : null,
        tituloEl, opcoes.sub ? h('span', { class: 'd2-painel-sub', texto: txt(opcoes.sub) }) : null, acoes);
    const corpo = h('div', { class: 'd2-painel-corpo' + (opcoes.justo ? ' is-justo' : '') }, ...conteudo);
    const rodape = opcoes.rodape ? h('footer', { class: 'd2-painel-rodape' }, typeof opcoes.rodape === 'string' ? txt(opcoes.rodape) : opcoes.rodape) : null;
    el.append(cab, corpo);
    if (rodape) el.append(rodape);
    return { el, cab, tituloEl, acoes, corpo, rodape };
}

// Abas: [{ id, rotulo, conteudo, icone?, contador? }]. conteudo pode ser elemento, texto
// ou função (chamada na primeira vez que a aba abre; pode devolver Promise).
function abas(lista = [], opcoes = {}) {
    const base = novoId('d2-abas');
    const el = h('div', { class: 'd2-abas' + (opcoes.classe ? ' ' + opcoes.classe : '') });
    const cab = h('div', { class: 'd2-tabs-head', role: 'tablist', 'aria-label': opcoes.rotulo ? txt(opcoes.rotulo) : null });
    el.append(cab);
    const itens = lista.filter(Boolean).map((a, i) => {
        const idAba = `${base}-aba-${i}`;
        const idPainel = `${base}-painel-${i}`;
        const botao = h('button', {
            type: 'button', class: 'd2-tab', role: 'tab', id: idAba, 'aria-controls': idPainel, 'aria-selected': 'false', tabindex: '-1',
            dados: { aba: String(a.id) },
        }, a.icone ? icone(a.icone) : null, h('span', { texto: txt(a.rotulo) }),
        a.contador !== undefined && a.contador !== null ? h('span', { class: 'd2-tag', texto: String(a.contador) }) : null);
        const painel = h('div', { class: 'd2-tabpane', role: 'tabpanel', id: idPainel, 'aria-labelledby': idAba, tabindex: '0' });
        painel.hidden = true;
        botao.addEventListener('click', () => ativar(a.id, true));
        cab.append(botao);
        el.append(painel);
        return { def: a, botao, painel, montado: false };
    });
    let atual = null;
    async function montar(item) {
        if (item.montado) return;
        item.montado = true;
        let c = item.def.conteudo;
        if (typeof c === 'function') {
            item.painel.append(esqueleto(3));
            try { c = await c(item.painel); } catch (e) { c = vazio({ chave: 'd2.erro_carregar', icone: 'erro' }); item.montado = false; }
            item.painel.textContent = '';
        }
        if (c instanceof Node) item.painel.append(c);
        else if (c !== undefined && c !== null) item.painel.append(h('p', { texto: txt(c) }));
    }
    function ativar(id, avisar) {
        const item = itens.find((x) => String(x.def.id) === String(id)) || itens[0];
        if (!item) return;
        for (const x of itens) {
            const sim = x === item;
            x.botao.classList.toggle('is-ativo', sim);
            x.botao.setAttribute('aria-selected', String(sim));
            x.botao.tabIndex = sim ? 0 : -1;
            x.painel.hidden = !sim;
            x.painel.classList.toggle('is-ativo', sim);
        }
        montar(item);
        const mudou = atual !== item.def.id;
        atual = item.def.id;
        if (avisar && mudou && typeof opcoes.aoTrocar === 'function') opcoes.aoTrocar(atual);
    }
    cab.addEventListener('keydown', (evento) => {
        const botoes = itens.map((x) => x.botao);
        const i = botoes.indexOf(document.activeElement);
        if (i < 0) return;
        let j = null;
        if (evento.key === 'ArrowRight') j = (i + 1) % botoes.length;
        else if (evento.key === 'ArrowLeft') j = (i - 1 + botoes.length) % botoes.length;
        else if (evento.key === 'Home') j = 0;
        else if (evento.key === 'End') j = botoes.length - 1;
        if (j === null) return;
        evento.preventDefault();
        botoes[j].focus();
        ativar(itens[j].def.id, true);
    });
    ativar(opcoes.ativa !== undefined ? opcoes.ativa : (itens[0] && itens[0].def.id), false);
    el.ativar = (id) => ativar(id, true);
    el.ativa = () => atual;
    el.el = el;
    return el;
}

// Tabela com cursor: carregar({cursor, ordem, filtros, busca}) => {itens, cursor_proximo}.
// Carrega mais ao rolar, cabeçalho fixo (abaixo da barra fixa da janela), ordenação, seleção,
// ações por linha, esqueleto, modo compacto em cartões, colunas escolhíveis (c.fixa nunca some,
// c.oculta começa escondida), exportação para planilha e virtualização das listas longas
// (só as linhas perto da área visível ficam no DOM; o resto vira espaço reservado).
const ALINHAR = { direita: 'is-direita', right: 'is-direita', centro: 'is-centro', center: 'is-centro' };
const VIRTUAL_A_PARTIR = 120;
const VIRTUAL_BLOCO = 16;
const EXPORTAR_LIMITE = 20000;
function rolador(el) {
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
        const oy = getComputedStyle(p).overflowY;
        if (oy === 'auto' || oy === 'scroll') return p;
    }
    return null;
}
function interativo(alvo, limite) {
    const e = alvo.closest('button, a[href], input, select, textarea, label, [role="button"], [contenteditable="true"]');
    return Boolean(e && limite.contains(e) && e !== limite);
}
function lerMemoria(chave) {
    try { return JSON.parse(window.localStorage.getItem(chave) || 'null'); } catch (e) { return null; }
}
function gravarMemoria(chave, valor) {
    try {
        if (valor === null) window.localStorage.removeItem(chave);
        else window.localStorage.setItem(chave, JSON.stringify(valor));
    } catch (e) { /* armazenamento indisponível: a escolha vale só nesta tela */ }
}
// Célula de CSV segura para abrir em planilha (Excel/LibreOffice): protegida contra injeção de
// fórmula (apóstrofo na frente de texto que comece com = + - @ TAB CR), exceto quando o próprio
// texto é um número negativo válido ("-12,50" ou "-12.50"), que fica número. Mesma regra do
// backend em backend/nucleo/tarefa.php (tarefaCelulaCsv); único lugar de verdade no frontend,
// usado por D2UI.tabela (exportar) e por quem exporta direto (ex.: clientes/lista.js).
function celulaCsv(valor, sep) {
    let s = String(valor ?? '').replace(/\s+/g, ' ').trim();
    if (/^[=+\-@\t\r]/.test(s) && !/^-?[\d.,\s]+$/.test(s)) s = "'" + s;
    return /["\r\n]/.test(s) || s.includes(sep) ? '"' + s.replace(/"/g, '""') + '"' : s;
}
// Ordem da tabela: aceita sentido ou direcao ('asc' | 'desc') e devolve os dois nomes.
function ordemDe(o) {
    if (!o || typeof o !== 'object' || !o.chave) return null;
    const sentido = (o.sentido || o.direcao) === 'desc' ? 'desc' : 'asc';
    return { chave: String(o.chave), sentido, direcao: sentido };
}

function tabela(opcoes = {}) {
    const { colunas = [], carregar, aoClicarLinha, cartao } = opcoes;
    const acoesLinha = (opcoes.acoesLinha || []).filter(Boolean);
    const selecao = Boolean(opcoes.selecao);
    const idDe = typeof opcoes.chave === 'function' ? opcoes.chave : (l) => l[opcoes.chave || 'id'];
    let filtros = opcoes.filtros || {};
    let busca = opcoes.busca || '';
    // Ordem padrão: o terceiro clique volta a ela em vez de ficar sem ordem.
    const ordemPadrao = ordemDe(opcoes.ordemPadrao);
    let ordem = ordemDe(opcoes.ordem) || ordemPadrao;
    let cursor = null;
    let temMais = false;
    let carregando = false;
    let erro = null;
    let pedido = 0;
    let paginas = 0;
    let linhas = [];
    const marcadas = new Map();
    // Automático: cartões abaixo de 640 px; escolha explícita por definirCompacta(true|false) vence até definirCompacta(null)
    let auto = opcoes.compacta === undefined && typeof cartao === 'function';
    let larguraVista = 0;
    let compacta = opcoes.compacta === true;

    // Colunas escolhíveis (padrão ligado). A escolha fica neste navegador, por opcoes.id ou pelas chaves das colunas.
    const escolhivel = opcoes.escolherColunas !== false && colunas.filter((c) => !c.fixa).length > 1;
    const memoria = 'd2.tabela.' + (opcoes.id || colunas.map((c) => c.chave).join(',')) + '.colunas';
    const padraoVisiveis = () => colunas.filter((c) => !c.oculta || c.fixa).map((c) => c.chave);
    function visiveisIniciais() {
        let lista = Array.isArray(opcoes.colunasVisiveis) ? opcoes.colunasVisiveis : null;
        if (!lista && escolhivel) { const v = lerMemoria(memoria); if (Array.isArray(v)) lista = v; }
        const validas = (lista || padraoVisiveis()).filter((k) => colunas.some((c) => c.chave === k));
        for (const c of colunas) if (c.fixa && !validas.includes(c.chave)) validas.push(c.chave);
        return validas.length ? validas : padraoVisiveis();
    }
    let visiveis = new Set(visiveisIniciais());
    const colunaVisivel = (c) => visiveis.has(c.chave);
    // Exportação: opt-in (o módulo decide se o dado pode sair da tela). true usa a própria carga; { fn } delega (ex.: tarefa no servidor).
    const confExportar = opcoes.exportar && typeof opcoes.exportar === 'object' ? opcoes.exportar : {};
    const exportavel = Boolean(opcoes.exportar) && (typeof carregar === 'function' || typeof confExportar.fn === 'function');
    const temCauda = acoesLinha.length > 0 || escolhivel || exportavel;
    // Virtualização: padrão ligada; desliga com virtualizar: false.
    const virtualizavel = opcoes.virtualizar !== false;

    const el = h('div', { class: 'd2-tabela-caixa' + (opcoes.densa ? ' d2-tabela-compacta' : '') + (opcoes.classe ? ' ' + opcoes.classe : '') });
    if (opcoes.altura) {
        el.classList.add('is-rolavel');
        el.style.setProperty('--d2-tabela-altura', typeof opcoes.altura === 'number' ? opcoes.altura + 'px' : String(opcoes.altura));
    }
    const barraTexto = h('span', { class: 'd2-tabela-barra-texto', 'aria-live': 'polite' });
    const barra = h('div', { class: 'd2-tabela-barra' }, barraTexto);
    barra.hidden = true;
    for (const a of opcoes.acoesSelecao || []) {
        barra.append(h('button', {
            type: 'button', class: 'd2-btn d2-btn-sm' + (a.perigo ? ' d2-btn-danger' : ' d2-btn-secundario'),
            onclick: () => a.fn([...marcadas.values()]),
        }, a.icone ? icone(a.icone) : null, h('span', { texto: txt(a.rotulo) })));
    }
    barra.append(h('button', { type: 'button', class: 'd2-btn d2-btn-sm d2-btn-fantasma', onclick: () => limparSelecao() },
        icone('fechar'), h('span', { texto: t('d2.limpar_selecao') })));
    const aba = h('table', { class: 'd2-tabela', 'aria-busy': 'true', 'aria-label': opcoes.rotulo ? txt(opcoes.rotulo) : null });
    const cabLinha = h('tr', { 'aria-rowindex': '1' });
    const cabTabela = h('thead', { class: 'd2-tabela-cab' }, cabLinha);
    const corpoTabela = h('tbody');
    aba.append(cabTabela, corpoTabela);
    const cartoes = h('div', { class: 'd2-tabela-cartoes', role: 'list', 'aria-busy': 'true' });
    const sentinela = h('div', { class: 'd2-tabela-sentinela', 'aria-hidden': 'true' });
    // Ferramentas (colunas e exportar): no canto do cabeçalho na tabela; numa faixa acima no modo cartões.
    function botoesFerramentas() {
        const lista = [];
        if (escolhivel) {
            const b = h('button', { type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma d2-tabela-ferramenta', 'aria-label': t('d2.colunas_escolher'), 'data-tooltip': t('d2.colunas_escolher') }, icone('colunas'));
            b.addEventListener('click', (evento) => { evento.stopPropagation(); escolherColunas(b); });
            lista.push(b);
        }
        if (exportavel) {
            const b = h('button', { type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma d2-tabela-ferramenta', 'aria-label': t('d2.exportar_planilha'), 'data-tooltip': t('d2.exportar_planilha') }, icone('exportar'));
            b.addEventListener('click', (evento) => { evento.stopPropagation(); exportar(); });
            lista.push(b);
        }
        return lista;
    }
    const faixaFerramentas = h('div', { class: 'd2-tabela-ferramentas', role: 'toolbar', 'aria-label': t('d2.ferramentas_tabela') }, botoesFerramentas());
    faixaFerramentas.hidden = true;
    el.append(barra, faixaFerramentas, aba, cartoes, sentinela);
    const totalColunas = () => colunas.filter(colunaVisivel).length + (selecao ? 1 : 0) + (temCauda ? 1 : 0);

    // Cabeçalho (colunas escondidas ficam no DOM com hidden: seletores por posição dos módulos não mudam)
    let todos = null;
    if (selecao) {
        todos = h('input', { type: 'checkbox', 'aria-label': t('d2.selecionar_todos') });
        todos.addEventListener('change', () => {
            if (todos.checked) for (const l of linhas) marcadas.set(idDe(l), l);
            else marcadas.clear();
            sincronizarSelecao();
        });
        cabLinha.append(h('th', { class: 'd2-tabela-sel', scope: 'col' }, todos));
    }
    const celsCab = colunas.map((c) => {
        const celCab = h('th', { scope: 'col', class: ALINHAR[c.alinhar] || null, dados: { d2Col: c.chave } });
        if (c.largura) celCab.style.width = typeof c.largura === 'number' ? c.largura + 'px' : String(c.largura);
        const titulo = txt(c.titulo);
        if (c.ordenavel) {
            const b = h('button', { type: 'button', class: 'd2-tabela-ordenar', 'aria-label': t('d2.ordenar_por', { coluna: titulo }) },
                h('span', { texto: titulo }), icone('seta-baixo'));
            b.addEventListener('click', () => {
                let prox;
                if (!ordem || ordem.chave !== c.chave) prox = ordemDe({ chave: c.chave, sentido: 'asc' });
                else if (ordem.sentido === 'asc') prox = ordemDe({ chave: c.chave, sentido: 'desc' });
                else prox = ordemPadrao;
                // Voltar ao padrão sem mudar nada (a coluna já é a padrão): inverte o sentido.
                if (prox && ordem && prox.chave === ordem.chave && prox.sentido === ordem.sentido) prox = ordemDe({ chave: c.chave, sentido: ordem.sentido === 'asc' ? 'desc' : 'asc' });
                ordem = prox;
                marcarOrdem();
                recarregar();
            });
            celCab.append(b);
        } else celCab.textContent = titulo;
        celCab.hidden = !colunaVisivel(c);
        cabLinha.append(celCab);
        return celCab;
    });
    const classeCauda = acoesLinha.length ? 'd2-tabela-acoes' : 'd2-tabela-acoes d2-tabela-cauda';
    if (temCauda) {
        cabLinha.append(h('th', { class: classeCauda, scope: 'col' },
            h('span', { class: 'd2-oculto-leitor', texto: t(acoesLinha.length ? 'd2.acoes' : 'd2.ferramentas_tabela') }), botoesFerramentas()));
    }
    function marcarOrdem() {
        colunas.forEach((c, i) => {
            if (!c.ordenavel) return;
            const sim = ordem && ordem.chave === c.chave;
            if (sim) celsCab[i].setAttribute('aria-sort', ordem.sentido === 'desc' ? 'descending' : 'ascending');
            else celsCab[i].removeAttribute('aria-sort');
        });
    }
    marcarOrdem();

    // Seleção
    function sincronizarSelecao() {
        const n = marcadas.size;
        barra.hidden = n === 0;
        barraTexto.textContent = t('d2.selecionados', { n });
        for (const item of el.querySelectorAll('[data-d2-indice]')) {
            const l = linhas[Number(item.dataset.d2Indice)];
            const sim = l !== undefined && marcadas.has(idDe(l));
            item.classList.toggle('is-selecionada', sim);
            const cx = item.querySelector('.d2-tabela-sel-caixa');
            if (cx) cx.checked = sim;
        }
        if (todos) {
            todos.checked = n > 0 && n >= linhas.length;
            todos.indeterminate = n > 0 && n < linhas.length;
        }
        if (typeof opcoes.aoSelecionar === 'function') opcoes.aoSelecionar([...marcadas.values()]);
    }
    function limparSelecao() { marcadas.clear(); sincronizarSelecao(); }
    function caixaSelecao(l) {
        const cx = h('input', { type: 'checkbox', class: 'd2-tabela-sel-caixa', 'aria-label': t('d2.selecionar_linha') });
        cx.checked = marcadas.has(idDe(l));
        cx.addEventListener('change', () => {
            if (cx.checked) marcadas.set(idDe(l), l); else marcadas.delete(idDe(l));
            sincronizarSelecao();
        });
        return cx;
    }

    // Ações por linha: até duas viram botões; acima disso, a primeira fica e o resto vai para o menu.
    function botoesAcao(l) {
        const diretas = acoesLinha.length > 2 ? acoesLinha.slice(0, 1) : acoesLinha;
        const resto = acoesLinha.length > 2 ? acoesLinha.slice(1) : [];
        // bloqueio(l) devolve o motivo (texto) de a ação não valer nesta linha: o botão fica desabilitado
        // com o motivo na dica; null libera.
        const lista = diretas.map((a) => {
            const motivo = typeof a.bloqueio === 'function' ? a.bloqueio(l) : null;
            return h('button', {
                type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma' + (a.perigo ? ' d2-iconbtn-perigo' : ''),
                'aria-label': txt(a.rotulo) + (motivo ? '. ' + txt(motivo) : ''), 'data-tooltip': motivo ? txt(motivo) : txt(a.rotulo),
                'aria-disabled': motivo ? 'true' : null,
                onclick: (evento) => { evento.stopPropagation(); if (!motivo) a.fn(l, evento); },
            }, icone(a.icone || 'editar'));
        });
        if (resto.length) {
            const mais = h('button', { type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma', 'aria-label': t('d2.mais_acoes'), 'data-tooltip': t('d2.mais_acoes') }, icone('reticencias'));
            mais.addEventListener('click', (evento) => {
                evento.stopPropagation();
                menuContexto(mais, resto.map((a) => ({ icone: a.icone, rotulo: a.rotulo, perigo: a.perigo, fn: () => a.fn(l) })));
            });
            lista.push(mais);
        }
        return lista;
    }
    function celula(c, l) {
        if (typeof c.render === 'function') return c.render(l);
        const v = l[c.chave];
        return v === null || v === undefined ? '' : String(v);
    }
    function ligarClique(item, l) {
        if (typeof aoClicarLinha !== 'function') return;
        item.classList.add('is-clicavel');
        item.tabIndex = 0;
        item.addEventListener('click', (evento) => {
            if (interativo(evento.target, item)) return;
            const sel = window.getSelection && String(window.getSelection());
            if (sel && sel.length > 2) return;
            aoClicarLinha(l, evento);
        });
        item.addEventListener('keydown', (evento) => {
            if (evento.target !== item) return;
            if (evento.key === 'Enter') { evento.preventDefault(); aoClicarLinha(l, evento); return; }
            if (evento.key === ' ' && selecao) { evento.preventDefault(); const cx = item.querySelector('.d2-tabela-sel-caixa'); if (cx) cx.click(); return; }
            if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
                // Por índice: com a lista virtualizada, a vizinha pode ainda não estar no DOM.
                const alvoI = Number(item.dataset.d2Indice) + (evento.key === 'ArrowDown' ? 1 : -1);
                if (alvoI >= 0 && alvoI < linhas.length) { evento.preventDefault(); focarIndice(alvoI); }
            }
        });
    }
    function linhaTabela(l, i) {
        const linhaEl = h('tr', { class: 'd2-tabela-linha', 'aria-rowindex': String(i + 2), dados: { d2Indice: String(i) } });
        if (marcadas.has(idDe(l))) linhaEl.classList.add('is-selecionada');
        if (selecao) linhaEl.append(h('td', { class: 'd2-tabela-cel d2-tabela-sel' }, caixaSelecao(l)));
        for (const c of colunas) {
            const celEl = h('td', { class: 'd2-tabela-cel' + (ALINHAR[c.alinhar] ? ' ' + ALINHAR[c.alinhar] : '') + (c.classe ? ' ' + c.classe : ''), dados: { d2Col: c.chave } }, celula(c, l));
            celEl.hidden = !colunaVisivel(c);
            linhaEl.append(celEl);
        }
        if (temCauda) linhaEl.append(h('td', { class: 'd2-tabela-cel ' + classeCauda }, acoesLinha.length ? botoesAcao(l) : null));
        ligarClique(linhaEl, l);
        return linhaEl;
    }
    function cartaoLinha(l, i) {
        let cartaoEl = typeof cartao === 'function' ? cartao(l) : null;
        if (!(cartaoEl instanceof Element)) {
            const [primeira, ...outras] = colunas.filter(colunaVisivel);
            cartaoEl = h('article', { class: 'd2-cartao' },
                primeira ? h('div', { class: 'd2-tabela-cartao-cab' }, celula(primeira, l)) : null,
                outras.length ? h('dl', { class: 'd2-tabela-cartao-campos' }, outras.map((c) => [h('dt', { texto: txt(c.titulo) }), h('dd', {}, celula(c, l))])) : null);
        }
        cartaoEl.setAttribute('role', 'listitem');
        cartaoEl.setAttribute('aria-posinset', String(i + 1));
        cartaoEl.dataset.d2Indice = String(i);
        if (marcadas.has(idDe(l))) cartaoEl.classList.add('is-selecionada');
        if (selecao || acoesLinha.length) {
            cartaoEl.append(h('div', { class: 'd2-tabela-cartao-acoes' },
                selecao ? h('label', { class: 'd2-check d2-tabela-cartao-sel' }, caixaSelecao(l), h('span', { texto: t('d2.selecionar_linha') })) : null,
                acoesLinha.length ? botoesAcao(l) : null));
        }
        ligarClique(cartaoEl, l);
        return cartaoEl;
    }
    const criarItem = (i) => (compacta ? cartaoLinha(linhas[i], i) : linhaTabela(linhas[i], i));

    // Corpo: [espaço do topo] linhas da janela [espaço da base] [estado]. Sem virtualização a janela é a lista inteira.
    // Com virtualização só a faixa perto da área visível fica no DOM; os espaços guardam a altura do resto.
    let espTopo = null;
    let espBase = null;
    let nos = [];
    let ri = 0;
    let rf = 0;
    let passo = 0;
    let porFila = 1;
    let congeladas = null;
    function criarEspaco() {
        const esp = compacta ? h('div', { class: 'd2-tabela-espaco', 'aria-hidden': 'true' })
            : h('tr', { class: 'd2-tabela-espaco', 'aria-hidden': 'true' }, h('td', { colspan: String(totalColunas()) }));
        esp.hidden = true;
        return esp;
    }
    function montarBase() {
        const alvo = alvoAtual();
        alvo.textContent = '';
        estadoEl = null;
        espTopo = criarEspaco();
        espBase = criarEspaco();
        alvo.append(espTopo, espBase);
        nos = [];
        ri = 0;
        rf = 0;
    }
    function vaoCartoes() { return compacta ? (parseFloat(getComputedStyle(cartoes).rowGap) || 0) : 0; }
    function alturaEspaco(esp, filas) {
        const px = filas > 0 ? Math.round(filas * passo - vaoCartoes()) : 0;
        esp.hidden = px <= 0;
        (compacta ? esp : esp.firstChild).style.height = px > 0 ? px + 'px' : '';
    }
    function rolavel() { return el.classList.contains('is-rolavel') ? el : rolador(el); }
    // Altura de uma fila (linha da tabela ou fileira de cartões) medida no que está desenhado.
    function medir() {
        if (nos.length < 2) return;
        const r0 = nos[0].getBoundingClientRect();
        if (!r0.height) return;
        if (compacta) {
            porFila = 1;
            while (porFila < nos.length && Math.abs(nos[porFila].getBoundingClientRect().top - r0.top) < 1) porFila++;
            passo = porFila < nos.length ? nos[porFila].getBoundingClientRect().top - r0.top : r0.height + vaoCartoes();
        } else {
            porFila = 1;
            passo = (nos[nos.length - 1].getBoundingClientRect().bottom - r0.top) / nos.length;
        }
        if (!(passo > 0)) passo = 0;
    }
    const elegivel = () => virtualizavel && linhas.length >= VIRTUAL_A_PARTIR && el.isConnected;
    function janelaDesejada() {
        const n = linhas.length;
        if (!elegivel() || !passo) return [0, n];
        const alvo = alvoAtual();
        const y0 = alvo.getBoundingClientRect().top + (parseFloat(getComputedStyle(alvo).paddingTop) || 0);
        const sc = rolavel();
        const topo = sc ? sc.getBoundingClientRect().top : 0;
        const base = sc ? sc.getBoundingClientRect().bottom : window.innerHeight;
        const filas = Math.ceil(n / porFila);
        let fi = Math.floor((Math.floor((topo - y0) / passo) - VIRTUAL_BLOCO / 2) / VIRTUAL_BLOCO) * VIRTUAL_BLOCO;
        let ff = Math.ceil((Math.ceil((base - y0) / passo) + VIRTUAL_BLOCO / 2) / VIRTUAL_BLOCO) * VIRTUAL_BLOCO;
        fi = Math.max(0, Math.min(fi, filas - VIRTUAL_BLOCO));
        ff = Math.min(filas, Math.max(ff, fi + VIRTUAL_BLOCO));
        return [fi * porFila, Math.min(n, ff * porFila)];
    }
    // Com a lista virtualizada as larguras das colunas não podem pular conforme as linhas entram e saem.
    function congelar(sim) {
        if (sim && !congeladas && !compacta && !aba.hidden) {
            congeladas = [];
            for (const celCab of cabLinha.children) {
                if (celCab.hidden) continue;
                congeladas.push([celCab, celCab.style.width]);
                celCab.style.width = celCab.getBoundingClientRect().width + 'px';
            }
            aba.classList.add('is-virtual');
        } else if (!sim && congeladas) {
            for (const [celCab, w] of congeladas) celCab.style.width = w;
            congeladas = null;
            aba.classList.remove('is-virtual');
        }
    }
    function aplicarJanela(ini, fim) {
        if (!espTopo) montarBase();
        if (ini !== ri || fim !== rf) {
            const faixa = (a, b) => Array.from({ length: Math.max(0, b - a) }, (_, k) => criarItem(a + k));
            if (fim <= ri || ini >= rf || ri === rf) {
                for (const no of nos) no.remove();
                nos = faixa(ini, fim);
                espTopo.after(...nos);
            } else {
                while (ri < ini) { nos.shift().remove(); ri++; }
                while (rf > fim) { nos.pop().remove(); rf--; }
                if (ini < ri) { const novos = faixa(ini, ri); espTopo.after(...novos); nos = novos.concat(nos); }
                if (fim > rf) { const novos = faixa(rf, fim); espBase.before(...novos); nos = nos.concat(novos); }
            }
            ri = ini;
            rf = fim;
        }
        alturaEspaco(espTopo, Math.ceil(ri / porFila));
        alturaEspaco(espBase, Math.ceil((linhas.length - rf) / porFila));
    }
    function atualizarContagem() {
        const total = temMais ? '-1' : String(linhas.length + 1);
        aba.setAttribute('aria-rowcount', total);
        for (const no of nos) if (compacta) no.setAttribute('aria-setsize', temMais ? '-1' : String(linhas.length));
    }
    function atualizarJanela() {
        if (paginas === 0) return;
        if (elegivel() && !passo) medir();
        const virtual = elegivel() && passo > 0;
        if (virtual) congelar(true);
        const [ini, fim] = janelaDesejada();
        aplicarJanela(ini, fim);
        if (!virtual) congelar(false);
        if (elegivel() && !passo) { medir(); if (passo) { congelar(true); const [a, b] = janelaDesejada(); aplicarJanela(a, b); } }
        atualizarContagem();
    }
    // Desenha de novo a janela inteira (troca de modo, de colunas ou de largura).
    function redesenhar() {
        congelar(false);
        passo = 0;
        porFila = 1;
        montarBase();
        if (paginas > 0) { atualizarJanela(); if (todos) sincronizarSelecao(); }
    }
    function focarIndice(i) {
        let k = i - ri;
        if (k >= 0 && k < nos.length) { nos[k].focus(); return; }
        if (!passo) return;
        const sc = rolavel();
        const alvo = alvoAtual();
        const y = alvo.getBoundingClientRect().top + Math.floor(i / porFila) * passo;
        const topo = sc ? sc.getBoundingClientRect().top : 0;
        const meio = (sc ? sc.clientHeight : window.innerHeight) / 2;
        if (sc) sc.scrollTop += y - topo - meio; else window.scrollBy(0, y - topo - meio);
        atualizarJanela();
        k = i - ri;
        if (k >= 0 && k < nos.length) nos[k].focus();
    }
    let rolagemEm = null;
    let quadro = 0;
    function aoRolar() {
        if (!el.isConnected) { desligarRolagem(); return; }
        if (quadro || linhas.length < VIRTUAL_A_PARTIR) return;
        quadro = requestAnimationFrame(() => { quadro = 0; atualizarJanela(); });
    }
    function desligarRolagem() {
        if (rolagemEm) rolagemEm.removeEventListener('scroll', aoRolar);
        rolagemEm = null;
    }
    function ligarRolagem() {
        if (!virtualizavel || !el.isConnected) return;
        const sc = rolavel() || window;
        if (rolagemEm === sc) return;
        desligarRolagem();
        rolagemEm = sc;
        sc.addEventListener('scroll', aoRolar, { passive: true });
    }

    // Estados: esqueleto, vazio, erro, carregando mais e fim
    function linhaEstado(conteudo, classe) {
        if (compacta) return h('div', { class: 'd2-tabela-estado' + (classe ? ' ' + classe : '') }, conteudo);
        return h('tr', { class: 'd2-tabela-estado' + (classe ? ' ' + classe : '') }, h('td', { colspan: String(totalColunas()) }, conteudo));
    }
    function alvoAtual() { return compacta ? cartoes : corpoTabela; }
    let estadoEl = null;
    function definirEstado(novo) {
        if (estadoEl) estadoEl.remove();
        estadoEl = novo;
        if (novo) alvoAtual().append(novo);
    }
    const estadoCarregandoMais = () => linhaEstado([h('span', { class: 'd2-spin', 'aria-hidden': 'true' }), t('d2.carregando_mais')]);
    function mostrarEsqueleto() {
        const alvo = alvoAtual();
        alvo.textContent = '';
        estadoEl = null;
        espTopo = null;
        espBase = null;
        nos = [];
        ri = 0;
        rf = 0;
        for (let i = 0; i < 6; i++) {
            if (compacta) alvo.append(h('div', { class: 'd2-cartao', 'aria-hidden': 'true' }, esqueleto(2)));
            else alvo.append(h('tr', { class: 'd2-tabela-esqueleto', 'aria-hidden': 'true' },
                Array.from({ length: totalColunas() }, () => h('td', {}, h('div', { class: 'd2-esqueleto-linha' })))));
        }
    }
    function estadoFinal() {
        if (erro) {
            if (!linhas.length) {
                definirEstado(linhaEstado(vazio({ chave: 'd2.erro_carregar', icone: 'erro', acao: { rotulo: 'd2.tentar_novamente', icone: 'atualizar', fn: () => recarregar() } })));
            } else {
                definirEstado(linhaEstado([h('span', { texto: t('d2.erro_carregar') + '. ' }),
                    h('button', { type: 'button', class: 'd2-link', texto: t('d2.tentar_novamente'), onclick: () => carregarPagina() })], 'is-erro'));
            }
        } else if (!linhas.length) {
            const v = opcoes.vazio || {};
            definirEstado(linhaEstado(vazio({ chave: v.chave || 'd2.nenhum_resultado', icone: v.icone || 'busca', acao: v.acao, texto: v.texto })));
        } else if (temMais) {
            definirEstado(linhaEstado(h('button', { type: 'button', class: 'd2-link', texto: t('d2.carregar_mais'), onclick: () => carregarPagina() })));
        } else if (paginas > 1) {
            definirEstado(linhaEstado(t('d2.fim_lista')));
        } else definirEstado(null);
    }

    // Carga por cursor (respostas atrasadas de pedidos antigos são descartadas)
    async function carregarPagina() {
        if (carregando || typeof carregar !== 'function') return;
        if (paginas > 0 && !temMais) return;
        carregando = true;
        erro = null;
        const meu = ++pedido;
        if (paginas === 0) mostrarEsqueleto();
        else definirEstado(estadoCarregandoMais());
        aba.setAttribute('aria-busy', 'true');
        cartoes.setAttribute('aria-busy', 'true');
        let r;
        try {
            r = await carregar({ cursor: paginas === 0 ? null : cursor, ordem, filtros, busca });
        } catch (e) {
            if (meu !== pedido) return;
            erro = e || true;
        }
        if (meu !== pedido) return;
        carregando = false;
        aba.setAttribute('aria-busy', 'false');
        cartoes.setAttribute('aria-busy', 'false');
        if (paginas === 0) { congelar(false); passo = 0; porFila = 1; montarBase(); }
        if (!erro) {
            const itens = (r && Array.isArray(r.itens)) ? r.itens : [];
            cursor = r ? (r.cursor_proximo ?? null) : null;
            temMais = cursor !== null && cursor !== undefined && cursor !== '' && itens.length > 0;
            paginas++;
            linhas = linhas.concat(itens);
            if (estadoEl) estadoEl.remove();
            estadoEl = null;
            atualizarJanela();
            if (todos) sincronizarSelecao();
        }
        estadoFinal();
        atualizarContagem();
        calcularTopo();
        if (temMais && !erro) requestAnimationFrame(verificarSentinela);
    }
    function recarregar() {
        pedido++;
        carregando = false;
        cursor = null;
        temMais = false;
        paginas = 0;
        linhas = [];
        erro = null;
        if (marcadas.size) { marcadas.clear(); sincronizarSelecao(); }
        return carregarPagina();
    }

    // Colunas escolhíveis: escondidas ficam no DOM (hidden), a escolha fica gravada neste navegador.
    function aplicarColunas(novas, gravar) {
        visiveis = new Set(novas);
        colunas.forEach((c, i) => { celsCab[i].hidden = !colunaVisivel(c); });
        if (gravar && escolhivel) gravarMemoria(memoria, gravar === 'padrao' ? null : [...visiveis]);
        if (paginas === 0) { if (carregando) mostrarEsqueleto(); }
        else { redesenhar(); if (carregando) definirEstado(estadoCarregandoMais()); else estadoFinal(); }
        if (typeof opcoes.aoMudarColunas === 'function') opcoes.aoMudarColunas([...visiveis]);
    }
    function escolherColunas(alvo) {
        if (!escolhivel) return null;
        const botao = alvo instanceof Element ? alvo
            : (compacta ? faixaFerramentas : cabLinha).querySelector('.d2-tabela-ferramenta');
        const livres = colunas.filter((c) => !c.fixa);
        // A última coluna visível não pode sair: a tabela nunca fica sem nenhuma.
        const travar = (botoes) => {
            const unica = visiveis.size <= 1;
            livres.forEach((c, k) => { if (botoes[k]) botoes[k].disabled = unica && colunaVisivel(c); });
        };
        const itens = livres.map((c) => ({
            rotulo: c.titulo, marcado: colunaVisivel(c), manterAberto: true,
            desativado: visiveis.size <= 1 && colunaVisivel(c),
            fn: (item, novo) => {
                if (!novo && visiveis.size <= 1) return false;
                const proximas = colunas.filter((x) => (x === c ? novo : colunaVisivel(x))).map((x) => x.chave);
                aplicarColunas(proximas, true);
                return true;
            },
            aoAlternar: travar,
        }));
        return menuContexto(botao, [{ grupo: 'd2.colunas' }, ...itens, 'separador',
            { icone: 'atualizar', rotulo: 'd2.colunas_restaurar', fn: () => aplicarColunas(padraoVisiveis(), 'padrao') }]);
    }

    // Exportação para planilha (CSV que abre direto no Excel e no LibreOffice): colunas visíveis,
    // na ordem e com os filtros da tela. Valor por c.exportar(linha), senão o texto da célula.
    let exportando = false;
    function valorExportado(c, l) {
        let v;
        if (typeof c.exportar === 'function') v = c.exportar(l);
        else if (typeof c.render === 'function') {
            const r = c.render(l);
            v = r instanceof Node ? r.textContent : r;
        } else v = l[c.chave];
        if (v === null || v === undefined) return '';
        return String(v).replace(/\s+/g, ' ').trim();
    }
    function montarCsv(itens, cols) {
        const sep = new Intl.NumberFormat(localidade()).format(1.5).includes(',') ? ';' : ',';
        const saida = [cols.map((c) => celulaCsv(txt(c.titulo), sep)).join(sep)];
        for (const l of itens) saida.push(cols.map((c) => celulaCsv(valorExportado(c, l), sep)).join(sep));
        return '\uFEFF' + saida.join('\r\n') + '\r\n';
    }
    function baixar(conteudo, nome) {
        const endereco = URL.createObjectURL(new Blob([conteudo], { type: 'text/csv;charset=utf-8' }));
        const a = h('a', { href: endereco, download: nome });
        a.hidden = true;
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(endereco), 1500);
    }
    async function exportar() {
        if (!exportavel || exportando) return;
        const cols = colunas.filter((c) => colunaVisivel(c) && c.exportar !== false);
        const pedidoExp = { ordem, filtros: { ...filtros }, busca, colunas: cols.map((c) => c.chave) };
        if (typeof confExportar.fn === 'function') return confExportar.fn(pedidoExp);
        exportando = true;
        let cancelado = false;
        const limite = confExportar.limite > 0 ? confExportar.limite : EXPORTAR_LIMITE;
        const p = progresso({
            titulo: 'd2.exportar_planilha', texto: t('d2.exportando'),
            janela: opcoes.janela || el.closest('.janela') || undefined,
            aoCancelar: () => { cancelado = true; },
        });
        try {
            let itens = [];
            let cortado = false;
            if (paginas > 0 && !temMais && !erro) itens = linhas.slice();
            else {
                let cursorAtual = null;
                for (;;) {
                    const r = await carregar({ cursor: cursorAtual, ordem: pedidoExp.ordem, filtros: pedidoExp.filtros, busca: pedidoExp.busca, exportacao: true });
                    if (cancelado) return;
                    const lote = r && Array.isArray(r.itens) ? r.itens : [];
                    itens = itens.concat(lote);
                    cursorAtual = r ? (r.cursor_proximo ?? null) : null;
                    p.atualizar(null, t('d2.exportando_linhas', { n: itens.length }));
                    if (itens.length >= limite) { cortado = itens.length > limite || (cursorAtual !== null && cursorAtual !== ''); itens = itens.slice(0, limite); break; }
                    if (!lote.length || cursorAtual === null || cursorAtual === undefined || cursorAtual === '') break;
                }
            }
            if (cancelado) return;
            if (!itens.length) { p.fechar(); toast(t('d2.exportar_vazio'), { tipo: 'info' }); return; }
            const base = confExportar.nome ? txt(confExportar.nome) : (opcoes.rotulo ? txt(opcoes.rotulo) : t('d2.exportar_nome'));
            const nome = (base.replace(/[\\/:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim() || t('d2.exportar_nome')) + ' ' + hojeIsoNoFuso(fusoEmpresa()) + '.csv';
            baixar(montarCsv(itens, cols), nome);
            p.concluir(t('d2.exportado', { n: itens.length }));
            if (cortado) toast(t('d2.exportar_limite', { n: limite }), { tipo: 'aviso' });
        } catch (e) {
            if (!cancelado) p.falhar('d2.exportar_falhou');
        } finally {
            exportando = false;
        }
    }

    // Cabeçalho fixo abaixo das barras fixas do contêiner que rola (ex.: barra da janela)
    function calcularTopo() {
        if (!el.isConnected) return;
        if (el.classList.contains('is-rolavel') || opcoes.topo !== undefined) {
            el.style.setProperty('--d2-tabela-topo', (opcoes.topo || 0) + 'px');
            return;
        }
        const sc = rolador(el);
        let soma = 0;
        if (sc) {
            for (const f of sc.children) {
                if (f.contains(el)) break;
                if (getComputedStyle(f).position === 'sticky') soma += f.getBoundingClientRect().height;
            }
        }
        el.style.setProperty('--d2-tabela-topo', Math.round(soma) + 'px');
    }

    // Carregar mais ao rolar
    let observador = null;
    function verificarSentinela() {
        if (!temMais || carregando || erro || !el.isConnected) return;
        const sc = rolavel();
        const r = sentinela.getBoundingClientRect();
        const limite = sc ? sc.getBoundingClientRect().bottom : window.innerHeight;
        if (r.top - limite < 320 && r.bottom > 0) carregarPagina();
    }
    function ligarObservador() {
        if (observador || !el.isConnected || typeof IntersectionObserver !== 'function') return;
        observador = new IntersectionObserver((entradas) => {
            if (entradas.some((e) => e.isIntersecting)) verificarSentinela();
        }, { root: rolavel(), rootMargin: '0px 0px 320px 0px' });
        observador.observe(sentinela);
    }

    // Troca entre tabela e cartões
    function aplicarModo() {
        aba.hidden = compacta;
        cartoes.hidden = !compacta;
        faixaFerramentas.hidden = !compacta || !(escolhivel || exportavel);
        el.classList.toggle('is-cartoes', compacta);
    }
    function aplicarCompacta(v) {
        const novo = Boolean(v);
        if (novo === compacta) return;
        congelar(false);
        compacta = novo;
        aplicarModo();
        corpoTabela.textContent = '';
        cartoes.textContent = '';
        estadoEl = null;
        espTopo = null;
        espBase = null;
        if (paginas === 0) { if (carregando) mostrarEsqueleto(); return; }
        redesenhar();
        if (carregando) definirEstado(estadoCarregandoMais()); else estadoFinal();
    }
    aplicarModo();
    if (typeof ResizeObserver === 'function') {
        const ro = new ResizeObserver((entradas) => {
            const w = entradas[0] ? entradas[0].contentRect.width : el.clientWidth;
            if (!el.isConnected) return;
            ligarObservador();
            ligarRolagem();
            calcularTopo();
            if (auto && w > 0 && w !== larguraVista && (w < 640) !== compacta) { larguraVista = w; aplicarCompacta(w < 640); return; }
            if (w > 0 && w !== larguraVista) {
                const antes = larguraVista;
                larguraVista = w;
                // Largura nova muda altura das linhas e cartões por fileira: mede de novo.
                if (antes > 0 && linhas.length >= VIRTUAL_A_PARTIR) redesenhar();
            } else if (linhas.length >= VIRTUAL_A_PARTIR) atualizarJanela();
        });
        ro.observe(el);
    }
    carregarPagina();
    return {
        el,
        recarregar,
        selecionados: () => [...marcadas.values()],
        limparSelecao,
        definirFiltros(novos, opc = {}) {
            filtros = novos ? { ...novos } : {};
            if (opc.busca !== undefined) busca = opc.busca;
            return recarregar();
        },
        definirBusca(texto) { busca = texto || ''; return recarregar(); },
        definirOrdem(o) { ordem = ordemDe(o) || ordemPadrao; marcarOrdem(); return recarregar(); },
        definirCompacta(v) {
            if (v === null || v === undefined) {
                auto = typeof cartao === 'function';
                larguraVista = el.clientWidth;
                aplicarCompacta(auto ? larguraVista > 0 && larguraVista < 640 : opcoes.compacta === true);
                return;
            }
            auto = false;
            aplicarCompacta(v);
        },
        linhas: () => linhas.slice(),
        // Colunas: escolherColunas(botao?) abre o menu; definirColunasVisiveis(null) volta ao padrão.
        escolherColunas,
        colunasVisiveis: () => colunas.filter(colunaVisivel).map((c) => c.chave),
        definirColunasVisiveis(chaves) {
            if (chaves === null || chaves === undefined) { aplicarColunas(padraoVisiveis(), 'padrao'); return; }
            const lista = (Array.isArray(chaves) ? chaves : []).filter((k) => colunas.some((c) => c.chave === k));
            for (const c of colunas) if (c.fixa && !lista.includes(c.chave)) lista.push(c.chave);
            if (lista.length) aplicarColunas(lista, true);
        },
        exportar,
    };
}

// Datas: sempre ISO (AAAA-MM-DD) por fora; exibição e digitação seguem o idioma.
const doisDig = (n) => String(n).padStart(2, '0');
const isoDe = (d) => `${d.getFullYear()}-${doisDig(d.getMonth() + 1)}-${doisDig(d.getDate())}`;
function dataDe(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
    if (!m) return null;
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return d.getMonth() === Number(m[2]) - 1 && d.getDate() === Number(m[3]) ? d : null;
}
const somarDias = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
// "Hoje" de negócio: calendário do fuso da empresa (contrato, seção 2), nunca o relógio local do navegador.
// Ordem: conf.hoje() (AAAA-MM-DD fornecido pela casca), depois conf.fuso (nome IANA ou função), depois o fuso padrão do backend.
const FUSO_PADRAO = 'America/Sao_Paulo';
const cacheFmtHoje = new Map();
function fusoEmpresa() {
    let f = typeof conf.fuso === 'function' ? conf.fuso() : conf.fuso;
    if (typeof f !== 'string' || f === '') f = FUSO_PADRAO;
    return f;
}
function hojeIsoNoFuso(fuso) {
    let fmt = cacheFmtHoje.get(fuso);
    if (!fmt) {
        try {
            fmt = new Intl.DateTimeFormat('en-US', { timeZone: fuso, year: 'numeric', month: '2-digit', day: '2-digit' });
        } catch (e) {
            if (fuso === FUSO_PADRAO) return null;
            return hojeIsoNoFuso(FUSO_PADRAO);
        }
        cacheFmtHoje.set(fuso, fmt);
    }
    const p = {};
    for (const x of fmt.formatToParts(new Date())) p[x.type] = x.value;
    return `${p.year}-${p.month}-${p.day}`;
}
function hojeData() {
    if (typeof conf.hoje === 'function') {
        try {
            const d = dataDe(conf.hoje());
            if (d) return d;
        } catch (e) { /* segue pelo fuso */ }
    }
    const d = dataDe(hojeIsoNoFuso(fusoEmpresa()));
    if (d) return d;
    const agora = new Date();
    return new Date(agora.getFullYear(), agora.getMonth(), agora.getDate());
}
function ordemData() {
    const partes = new Intl.DateTimeFormat(localidade(), { day: '2-digit', month: '2-digit', year: 'numeric' }).formatToParts(new Date(2026, 10, 22));
    return partes.filter((p) => p.type === 'day' || p.type === 'month' || p.type === 'year').map((p) => p.type);
}
function formatarData(iso) {
    const d = dataDe(iso);
    if (!d) return '';
    const v = { day: doisDig(d.getDate()), month: doisDig(d.getMonth() + 1), year: String(d.getFullYear()) };
    return ordemData().map((k) => v[k]).join('/');
}
// Lê o que foi digitado (só dígitos na ordem do idioma) e devolve ISO ou null.
function lerDataDigitada(texto) {
    const dig = String(texto || '').replace(/\D/g, '');
    if (dig.length !== 8) return null;
    const v = {};
    let i = 0;
    for (const k of ordemData()) { const n = k === 'year' ? 4 : 2; v[k] = dig.slice(i, i + n); i += n; }
    const iso = `${v.year}-${v.month}-${v.day}`;
    return dataDe(iso) ? iso : null;
}
function mascaraData(texto) {
    const dig = String(texto || '').replace(/\D/g, '').slice(0, 8);
    const tam = ordemData().map((k) => (k === 'year' ? 4 : 2));
    let resultado = '';
    let i = 0;
    for (let k = 0; k < tam.length && i < dig.length; k++) {
        resultado += dig.slice(i, i + tam[k]);
        i += tam[k];
        if (i < dig.length && k < tam.length - 1) resultado += '/';
    }
    return resultado;
}
function inicioSemana() {
    try {
        const l = new Intl.Locale(localidade());
        const info = l.getWeekInfo ? l.getWeekInfo() : l.weekInfo;
        if (info && info.firstDay) return info.firstDay % 7;
    } catch (e) { /* navegador sem weekInfo */ }
    return idioma() === 'es' ? 1 : 0;
}

// Calendário próprio. modo 'data' escolhe um dia; modo 'periodo' escolhe início e fim.
function calendario({ modo = 'data', valor, min, max, aoEscolher, aoAplicar, aoCancelar }) {
    const periodo = modo === 'periodo';
    const minD = dataDe(min);
    const maxD = dataDe(max);
    let inicio = periodo ? dataDe(valor && valor.inicio) : dataDe(valor);
    let fim = periodo ? dataDe(valor && valor.fim) : null;
    let previa = null;
    let foco = inicio || hojeData();
    let base = new Date(foco.getFullYear(), foco.getMonth(), 1);
    const nMeses = periodo && !celular() ? 2 : 1;
    const el = h('div', { class: 'd2-cal', role: 'dialog', 'aria-label': t(periodo ? 'd2.escolher_periodo' : 'd2.escolher_data') });
    const corpo = h('div', { class: 'd2-cal-corpo' });
    const meses = h('div', { class: 'd2-cal-meses' });
    const resumo = h('span', { class: 'd2-cal-resumo', 'aria-live': 'polite' });
    corpo.append(meses);
    const fmtMes = new Intl.DateTimeFormat(localidade(), { month: 'long', year: 'numeric' });
    const fmtSem = new Intl.DateTimeFormat(localidade(), { weekday: 'short' });
    const fmtLongo = new Intl.DateTimeFormat(localidade(), { dateStyle: 'full' });
    const primeiro = inicioSemana();
    const igual = (a, b) => a && b && a.getTime() === b.getTime();
    const fora = (d) => (minD && d < minD) || (maxD && d > maxD);
    if (periodo) {
        const hoje = hojeData();
        const atalhos = [
            ['d2.hoje', hoje, hoje],
            ['d2.ontem', somarDias(hoje, -1), somarDias(hoje, -1)],
            ['d2.ultimos_7', somarDias(hoje, -6), hoje],
            ['d2.ultimos_30', somarDias(hoje, -29), hoje],
            ['d2.este_mes', new Date(hoje.getFullYear(), hoje.getMonth(), 1), new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0)],
            ['d2.mes_passado', new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1), new Date(hoje.getFullYear(), hoje.getMonth(), 0)],
            ['d2.este_ano', new Date(hoje.getFullYear(), 0, 1), new Date(hoje.getFullYear(), 11, 31)],
        ];
        const lado = h('div', { class: 'd2-cal-atalhos' });
        for (const [chave, a, b] of atalhos) {
            const ativo = igual(a, inicio) && igual(b, fim);
            lado.append(h('button', {
                type: 'button', class: 'd2-cal-atalho' + (ativo ? ' is-ativo' : ''), texto: t(chave),
                onclick: () => { inicio = a; fim = b; aplicar(); },
            }));
        }
        el.append(lado);
        corpo.append(h('div', { class: 'd2-cal-rodape' }, resumo,
            h('button', { type: 'button', class: 'd2-btn d2-btn-sm d2-btn-fantasma', texto: t('d2.limpar'), onclick: () => { inicio = null; fim = null; aplicar(); } }),
            h('button', { type: 'button', class: 'd2-btn d2-btn-sm d2-btn-primary', texto: t('d2.aplicar'), onclick: () => aplicar() })));
    } else {
        corpo.append(h('div', { class: 'd2-cal-rodape' }, resumo,
            h('button', { type: 'button', class: 'd2-btn d2-btn-sm d2-btn-fantasma', texto: t('d2.hoje'), disabled: fora(hojeData()), onclick: () => escolher(hojeData()) })));
    }
    el.append(corpo);
    function aplicar() {
        if (inicio && !fim) fim = inicio;
        if (typeof aoAplicar === 'function') aoAplicar(inicio ? { inicio: isoDe(inicio), fim: isoDe(fim) } : { inicio: '', fim: '' });
    }
    function escolher(d) {
        if (fora(d)) return;
        if (!periodo) { inicio = d; if (typeof aoEscolher === 'function') aoEscolher(isoDe(d)); return; }
        if (!inicio || fim) { inicio = d; fim = null; }
        else if (d < inicio) { fim = inicio; inicio = d; }
        else fim = d;
        foco = d;
        desenhar();
    }
    function desenhar(focar) {
        meses.textContent = '';
        const fimVis = fim || (inicio && previa && !fim ? previa : null);
        const a = inicio && fimVis && fimVis < inicio ? fimVis : inicio;
        const b = inicio && fimVis && fimVis < inicio ? inicio : fimVis;
        const hojeRender = hojeData();
        for (let m = 0; m < nMeses; m++) {
            const mesRef = new Date(base.getFullYear(), base.getMonth() + m, 1);
            const titulo = h('div', { class: 'd2-cal-mes-titulo' }, h('span', { texto: inicialMaiuscula(fmtMes.format(mesRef)), 'aria-live': m === 0 ? 'polite' : null }));
            if (m === 0) titulo.append(h('button', { type: 'button', class: 'd2-cal-nav is-anterior', 'aria-label': t('d2.mes_anterior'), onclick: () => mudarMes(-1) }, icone('chevron-esquerda')));
            if (m === nMeses - 1) titulo.append(h('button', { type: 'button', class: 'd2-cal-nav is-proximo', 'aria-label': t('d2.mes_seguinte'), onclick: () => mudarMes(1) }, icone('chevron-direita')));
            const grade = h('div', { class: 'd2-cal-grade', role: 'grid' });
            for (let i = 0; i < 7; i++) {
                const dia = new Date(2026, 1, 1 + ((primeiro + i) % 7));
                grade.append(h('span', { class: 'd2-cal-sem', 'aria-hidden': 'true', texto: fmtSem.format(dia).replace('.', '').slice(0, 3) }));
            }
            const desloc = (mesRef.getDay() - primeiro + 7) % 7;
            const total = new Date(mesRef.getFullYear(), mesRef.getMonth() + 1, 0).getDate();
            for (let i = 0; i < desloc; i++) grade.append(h('span', { class: 'd2-cal-dia is-fora', 'aria-hidden': 'true' }));
            for (let n = 1; n <= total; n++) {
                const d = new Date(mesRef.getFullYear(), mesRef.getMonth(), n);
                const sel = igual(d, inicio) || igual(d, fim);
                const meio = a && b && d > a && d < b;
                const botao = h('button', {
                    type: 'button', class: 'd2-cal-dia' + (igual(d, hojeRender) ? ' is-hoje' : '') + (sel ? ' is-selecionado' : '') + (meio ? ' is-intervalo' : ''),
                    tabindex: igual(d, foco) ? '0' : '-1', 'aria-label': fmtLongo.format(d), 'aria-pressed': sel ? 'true' : 'false',
                    disabled: fora(d), texto: String(n), dados: { iso: isoDe(d) },
                });
                grade.append(botao);
            }
            meses.append(h('div', { class: 'd2-cal-mes' }, titulo, grade));
        }
        if (periodo) {
            if (inicio && !fim) resumo.textContent = t('d2.escolha_fim');
            else if (inicio) resumo.textContent = t('d2.periodo_de_ate', { inicio: formatarData(isoDe(inicio)), fim: formatarData(isoDe(fim)) });
            else resumo.textContent = '';
        }
        if (focar) {
            const b2 = meses.querySelector(`[data-iso="${isoDe(foco)}"]`);
            if (b2) b2.focus({ preventScroll: true });
        }
    }
    function mudarMes(n) {
        base = new Date(base.getFullYear(), base.getMonth() + n, 1);
        foco = new Date(base.getFullYear(), base.getMonth(), Math.min(foco.getDate(), new Date(base.getFullYear(), base.getMonth() + 1, 0).getDate()));
        desenhar();
    }
    meses.addEventListener('click', (evento) => {
        const b = evento.target.closest('.d2-cal-dia[data-iso]');
        if (b && !b.disabled) escolher(dataDe(b.dataset.iso));
    });
    meses.addEventListener('pointerover', (evento) => {
        if (!periodo || !inicio || fim) return;
        const b = evento.target.closest('.d2-cal-dia[data-iso]');
        const d = b ? dataDe(b.dataset.iso) : null;
        if (d && !igual(d, previa)) { previa = d; desenhar(); }
    });
    meses.addEventListener('keydown', (evento) => {
        const b = evento.target.closest('.d2-cal-dia[data-iso]');
        if (!b) return;
        const atual = dataDe(b.dataset.iso);
        const passos = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        let novo = null;
        if (passos[evento.key]) novo = somarDias(atual, passos[evento.key]);
        else if (evento.key === 'PageUp' || evento.key === 'PageDown') novo = new Date(atual.getFullYear(), atual.getMonth() + (evento.key === 'PageUp' ? -1 : 1), atual.getDate());
        else if (evento.key === 'Home') novo = somarDias(atual, -((atual.getDay() - primeiro + 7) % 7));
        else if (evento.key === 'End') novo = somarDias(atual, 6 - ((atual.getDay() - primeiro + 7) % 7));
        else if (evento.key === 'Enter' || evento.key === ' ') { evento.preventDefault(); escolher(atual); if (periodo) desenhar(true); return; }
        if (!novo) return;
        evento.preventDefault();
        foco = novo;
        if (periodo && inicio && !fim) previa = novo;
        const ultimo = new Date(base.getFullYear(), base.getMonth() + nMeses, 0);
        if (novo < base || novo > ultimo) base = new Date(novo.getFullYear(), novo.getMonth() - (novo > ultimo ? nMeses - 1 : 0), 1);
        desenhar(true);
    });
    el.addEventListener('keydown', (evento) => {
        if (evento.key === 'Escape' && typeof aoCancelar === 'function') { evento.preventDefault(); evento.stopPropagation(); aoCancelar(); }
    });
    desenhar();
    return { el, focar: () => { const b = meses.querySelector('.d2-cal-dia[tabindex="0"]'); if (b) b.focus({ preventScroll: true }); } };
}

// Lista flutuante de opções com busca (seleção, múltipla e país do telefone).
const normalizar = (s) => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
function abrirLista(alvo, { opcoes = [], multipla = false, selecionados, buscar, comBusca, aoEscolher, aoLimpar, aoFechar, larguraMin, idLista: idListaForcado, ariaDono }) {
    // idListaForcado: id estável do botão-combobox chamador (aria-controls); ariaDono: espelha
    // aria-activedescendant nele também, sem mudar o foco real (continua em entrada||lista).
    const idLista = idListaForcado || novoId('d2-lista');
    const raiz = h('div', { class: 'd2-lista' });
    const lista = h('div', { class: 'd2-lista-opcoes', role: 'listbox', id: idLista, tabindex: '-1', 'aria-multiselectable': multipla ? 'true' : null });
    const remoto = typeof buscar === 'function';
    const mostrarBusca = remoto || comBusca || opcoes.length > 8;
    let entrada = null;
    if (mostrarBusca) {
        entrada = h('input', {
            type: 'search', class: 'd2-control', placeholder: t('d2.buscar'), 'aria-label': t('d2.buscar'), autocomplete: 'off',
            role: 'combobox', 'aria-controls': idLista, 'aria-expanded': 'true', 'aria-autocomplete': 'list',
        });
        raiz.append(h('div', { class: 'd2-lista-busca' }, h('div', { class: 'd2-search' }, icone('busca'), entrada)));
    }
    raiz.append(lista);
    if (multipla || aoLimpar) {
        raiz.append(h('div', { class: 'd2-lista-rodape' },
            h('button', { type: 'button', class: 'd2-btn d2-btn-sm d2-btn-fantasma', texto: t('d2.limpar'), onclick: () => { if (aoLimpar) aoLimpar(); desenhar(visiveis); if (!multipla) f.fechar('limpar'); } }),
            multipla ? h('button', { type: 'button', class: 'd2-btn d2-btn-sm d2-btn-primary', texto: t('d2.ok'), onclick: () => f.fechar('ok') }) : null));
    }
    let visiveis = opcoes;
    let ativo = -1;
    let itens = [];
    function marcarAtivo(i, rolar = true) {
        itens.forEach((it, k) => it.classList.toggle('is-foco', k === i));
        ativo = i;
        const it = itens[i];
        const dono = entrada || lista;
        if (it) {
            dono.setAttribute('aria-activedescendant', it.id);
            if (ariaDono) ariaDono.setAttribute('aria-activedescendant', it.id);
            if (rolar) it.scrollIntoView({ block: 'nearest' });
        } else {
            dono.removeAttribute('aria-activedescendant');
            if (ariaDono) ariaDono.removeAttribute('aria-activedescendant');
        }
    }
    function desenhar(opcoesVisiveis, estado) {
        visiveis = opcoesVisiveis;
        lista.textContent = '';
        itens = [];
        if (estado) { lista.append(h('div', { class: 'd2-lista-nada', texto: estado })); return; }
        if (!opcoesVisiveis.length) { lista.append(h('div', { class: 'd2-lista-nada', texto: t('d2.nenhuma_opcao') })); return; }
        opcoesVisiveis.forEach((o, i) => {
            const sel = selecionados.has(String(o.valor));
            const it = h('div', {
                class: 'd2-opcao', role: 'option', id: `${idLista}-${i}`, 'aria-selected': sel ? 'true' : 'false',
                'aria-disabled': o.desativado ? 'true' : null,
            },
            multipla ? h('span', { class: 'd2-opcao-caixa', 'aria-hidden': 'true' }, sel ? icone('check') : null)
                : h('span', { class: 'd2-opcao-marca', 'aria-hidden': 'true' }, sel ? icone('check') : null),
            o.bandeira ? h('span', { class: 'd2-bandeira', 'aria-hidden': 'true', texto: o.bandeira }) : (o.icone ? icone(o.icone) : null),
            h('span', { class: 'd2-opcao-texto', texto: txt(o.rotulo) }),
            o.dica ? h('span', { class: 'd2-opcao-dica', texto: txt(o.dica) }) : null);
            it.addEventListener('click', () => escolher(i));
            it.addEventListener('pointermove', () => { if (ativo !== i) marcarAtivo(i, false); });
            itens.push(it);
            lista.append(it);
        });
        const iSel = opcoesVisiveis.findIndex((o) => selecionados.has(String(o.valor)));
        marcarAtivo(iSel >= 0 ? iSel : 0);
    }
    function escolher(i) {
        const o = visiveis[i];
        if (!o || o.desativado) return;
        aoEscolher(o);
        if (multipla) { const rolagem = lista.scrollTop; desenhar(visiveis); lista.scrollTop = rolagem; marcarAtivo(i, false); }
        else f.fechar('escolha');
    }
    let pedido = 0;
    let espera = 0;
    function filtrar() {
        const q = entrada ? entrada.value : '';
        if (!remoto) {
            const n = normalizar(q);
            desenhar(n ? opcoes.filter((o) => normalizar(txt(o.rotulo) + ' ' + (o.dica || '') + ' ' + (o.busca || '')).includes(n)) : opcoes);
            return;
        }
        clearTimeout(espera);
        const meu = ++pedido;
        desenhar([], t('d2.buscando'));
        espera = setTimeout(async () => {
            try {
                const r = await buscar(q);
                if (meu === pedido) desenhar(Array.isArray(r) ? r : []);
            } catch (e) { if (meu === pedido) desenhar([], t('d2.erro_carregar')); }
        }, q ? 250 : 0);
    }
    raiz.addEventListener('keydown', (evento) => {
        if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
            evento.preventDefault();
            if (!itens.length) return;
            marcarAtivo(evento.key === 'ArrowDown' ? Math.min(itens.length - 1, ativo + 1) : Math.max(0, ativo - 1));
        } else if (evento.key === 'Home' && !entrada) { evento.preventDefault(); marcarAtivo(0); }
        else if (evento.key === 'End' && !entrada) { evento.preventDefault(); marcarAtivo(itens.length - 1); }
        else if (evento.key === 'Enter' || (evento.key === ' ' && evento.target !== entrada)) { evento.preventDefault(); if (ativo >= 0) escolher(ativo); }
        else if (evento.key === 'Tab') f.fechar('tab');
    });
    if (entrada) entrada.addEventListener('input', filtrar);
    const f = abrirFlutuante(raiz, alvo, { classe: 'd2-popover', larguraMin: larguraMin || alvo.getBoundingClientRect().width, aoFechar });
    if (remoto) filtrar(); else desenhar(opcoes);
    f.reposicionar();
    (entrada || lista).focus({ preventScroll: true });
    return f;
}

// Documentos brasileiros: CPF e CNPJ (inclusive o CNPJ alfanumérico) com dígito verificador.
function cpfValido(d) {
    if (!/^\d{11}$/.test(d) || /^(\d)\1{10}$/.test(d)) return false;
    for (const n of [9, 10]) {
        let soma = 0;
        for (let i = 0; i < n; i++) soma += Number(d[i]) * (n + 1 - i);
        if ((soma * 10) % 11 % 10 !== Number(d[n])) return false;
    }
    return true;
}
function cnpjValido(c) {
    if (!/^[0-9A-Z]{12}\d{2}$/.test(c) || /^(\d)\1{13}$/.test(c)) return false;
    const pesos = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    for (const n of [12, 13]) {
        let soma = 0;
        for (let i = 0; i < n; i++) soma += (c.charCodeAt(i) - 48) * pesos[i + 13 - n];
        const r = soma % 11;
        if ((r < 2 ? 0 : 11 - r) !== Number(c[n])) return false;
    }
    return true;
}
function limparDocumento(v, aceitar) {
    let s = String(v || '').toUpperCase().replace(/[^0-9A-Z]/g, '');
    if (aceitar === 'cpf') return s.replace(/\D/g, '').slice(0, 11);
    s = s.slice(0, 14);
    return s.slice(0, 12) + s.slice(12).replace(/\D/g, '');
}
function mascaraDocumento(s, aceitar) {
    const cnpj = aceitar === 'cnpj' || /[A-Z]/.test(s) || s.length > 11;
    const grupos = cnpj ? [[2, ''], [3, '.'], [3, '.'], [4, '/'], [2, '-']] : [[3, ''], [3, '.'], [3, '.'], [2, '-']];
    let resultado = '';
    let i = 0;
    for (const [n, sep] of grupos) {
        if (i >= s.length) break;
        resultado += (i > 0 ? sep : '') + s.slice(i, i + n);
        i += n;
    }
    return resultado;
}

// Telefone: países atendidos, bandeira por indicador regional, regras do Brasil.
const PAISES_TELEFONE = [['BR', '55'], ['PY', '595'], ['AR', '54'], ['UY', '598'], ['BO', '591'], ['CL', '56'], ['PE', '51'], ['CO', '57'],
    ['EC', '593'], ['VE', '58'], ['MX', '52'], ['US', '1'], ['PT', '351'], ['ES', '34'], ['AO', '244'], ['MZ', '258']];
const TAMANHO_TELEFONE = { BR: [10, 11], US: [10, 10], PT: [9, 9], ES: [9, 9], AR: [10, 10], MX: [10, 10], CL: [9, 9], PY: [9, 9], UY: [8, 8], CO: [10, 10], PE: [9, 9], AO: [9, 9], MZ: [9, 9] };
const DDD_BR = new Set('11 12 13 14 15 16 17 18 19 21 22 24 27 28 31 32 33 34 35 37 38 41 42 43 44 45 46 47 48 49 51 53 54 55 61 62 63 64 65 66 67 68 69 71 73 74 75 77 79 81 82 83 84 85 86 87 88 89 91 92 93 94 95 96 97 98 99'.split(' '));
const bandeira = (iso) => String.fromCodePoint(...[...iso].map((c) => 0x1f1e6 + c.charCodeAt(0) - 65));
function nomePais(iso) {
    try { return new Intl.DisplayNames([localidade()], { type: 'region' }).of(iso) || iso; } catch (e) { return iso; }
}
function paisDoNumero(e164) {
    const d = String(e164 || '').replace(/\D/g, '');
    let melhor = null;
    for (const [iso, ddi] of PAISES_TELEFONE) if (d.startsWith(ddi) && (!melhor || ddi.length > melhor[1].length)) melhor = [iso, ddi];
    return melhor;
}
function mascaraTelefone(iso, d) {
    if (iso === 'BR') {
        if (d.length <= 2) return d.length ? '(' + d : '';
        const resto = d.slice(2);
        const corte = d.length === 11 ? 5 : 4;
        return `(${d.slice(0, 2)}) ` + (resto.length > corte ? resto.slice(0, corte) + '-' + resto.slice(corte) : resto);
    }
    if (iso === 'US') {
        if (d.length <= 3) return d;
        return `(${d.slice(0, 3)}) ` + (d.length > 6 ? d.slice(3, 6) + '-' + d.slice(6) : d.slice(3));
    }
    return d.replace(/(\d{3})(?=\d{2,})/g, '$1 ').trim();
}
function telefoneValido(iso, d) {
    if (iso === 'BR') {
        if (!DDD_BR.has(d.slice(0, 2))) return false;
        if (d.length === 11) return d[2] === '9';
        return d.length === 10 && /[2-5]/.test(d[2]);
    }
    if (iso === 'US') return d.length === 10 && /[2-9]/.test(d[0]);
    const [a, b] = TAMANHO_TELEFONE[iso] || [6, 12];
    return d.length >= a && d.length <= b;
}

// Números e dinheiro no formato do idioma
function separadores() {
    const partes = new Intl.NumberFormat(localidade()).formatToParts(12345.6);
    return { decimal: (partes.find((p) => p.type === 'decimal') || {}).value || ',', grupo: (partes.find((p) => p.type === 'group') || {}).value || '.' };
}
function lerNumero(texto) {
    const s = String(texto || '').trim();
    if (!s) return null;
    const { decimal, grupo } = separadores();
    const limpo = s.split(grupo).join('').replace(/\s/g, '').split(decimal).join('.');
    if (!/^-?\d*\.?\d+$|^-?\d+\.?$/.test(limpo)) return NaN;
    return Number(limpo);
}
const formatarNumero = (n, casas) => new Intl.NumberFormat(localidade(), { minimumFractionDigits: casas || 0, maximumFractionDigits: casas === undefined ? 6 : casas }).format(n);
// Símbolo curto em todos os idiomas (R$ e não BRL no espanhol): o sistema opera numa moeda só.
function formatoMoeda() {
    try { return new Intl.NumberFormat(localidade(), { style: 'currency', currency: conf.moeda, currencyDisplay: 'narrowSymbol' }); } catch (e) {
        return new Intl.NumberFormat(localidade(), { style: 'currency', currency: conf.moeda });
    }
}
function simboloMoeda() {
    try {
        const p = formatoMoeda().formatToParts(0).find((x) => x.type === 'currency');
        return p ? p.value : conf.moeda;
    } catch (e) { return conf.moeda; }
}
const formatarMoeda = (centavos) => formatoMoeda().format(centavos / 100);
function formatarTamanho(bytes) {
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let v = Number(bytes) || 0;
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return formatarNumero(i ? Math.round(v * 10) / 10 : v) + ' ' + u[i];
}

// Campo de formulário completo: rótulo, controle, erro vivo e dica.
// Devolve o elemento .d2-field; os métodos ficam em el.d2Campo (e também no próprio elemento).
const CONTROLES = {};
function campo(def = {}) {
    const tipo = CONTROLES[def.tipo] ? def.tipo : 'texto';
    const id = def.id || novoId('d2-campo');
    const el = h('div', { class: 'd2-field' + (def.classe ? ' ' + def.classe : ''), dados: { campo: def.nome || '', tipo } });
    const erroEl = h('div', { class: 'd2-field-erro', id: id + '-erro', 'aria-live': 'polite' });
    const dicaEl = def.dica ? h('div', { class: 'd2-field-hint', id: id + '-dica', texto: txt(def.dica) }) : null;
    let tocado = false;
    const ctx = { def, id, el, rotulo: txt(def.rotulo), mudou: () => mudou(), tocar: () => tocar() };
    const c = CONTROLES[tipo](ctx);
    if (tipo !== 'switch') {
        el.append(h('label', { class: 'd2-label', for: id, id: id + '-rotulo' }, h('span', { texto: ctx.rotulo }),
            def.obrigatorio ? h('span', { class: 'd2-obrigatorio', 'aria-hidden': 'true', texto: '*' })
                : (def.opcional ? h('span', { class: 'd2-opcional', texto: t('d2.opcional') }) : null)));
    }
    el.append(c.no, erroEl);
    if (c.extra) el.append(c.extra);
    if (dicaEl) el.append(dicaEl);
    c.foco.setAttribute('aria-describedby', [dicaEl && dicaEl.id, erroEl.id, c.descricao].filter(Boolean).join(' '));
    if (def.obrigatorio) c.foco.setAttribute('aria-required', 'true');
    const vazioDe = c.vazio || ((v) => v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length));
    function mensagem() {
        const v = c.ler();
        if (c.invalido) { const m = c.invalido(); if (m) return m; }
        if (vazioDe(v)) return def.obrigatorio ? t('d2.obrigatorio') : null;
        const m = c.checar ? c.checar(v) : null;
        if (m) return m;
        if (typeof def.validar === 'function') { const r = def.validar(v); if (r) return txt(r); }
        return null;
    }
    function mostrar(msg) {
        erroEl.textContent = '';
        if (msg) erroEl.append(icone('erro'), h('span', { texto: msg }));
        el.classList.toggle('is-invalid', Boolean(msg));
        if (msg) c.foco.setAttribute('aria-invalid', 'true'); else c.foco.removeAttribute('aria-invalid');
    }
    function avaliar(exibir) {
        const msg = mensagem();
        el.classList.toggle('is-valido', !msg && !vazioDe(c.ler()));
        if (exibir || tocado) mostrar(msg);
        else if (!msg) mostrar(null);
        return msg;
    }
    function mudou() {
        avaliar(false);
        const v = c.ler();
        if (typeof def.aoMudar === 'function') def.aoMudar(v, api);
        el.dispatchEvent(new CustomEvent('d2-mudou', { bubbles: true, detail: { nome: def.nome, valor: v } }));
    }
    function tocar() { tocado = true; avaliar(true); }
    el.addEventListener('focusout', (evento) => {
        if (evento.relatedTarget && el.contains(evento.relatedTarget)) return;
        if (c.aberto && c.aberto()) return;
        const v = c.ler();
        if (!tocado && vazioDe(v) && !(c.invalido && c.invalido())) return;
        tocar();
    });
    const api = {
        el,
        nome: def.nome,
        entrada: c.foco,
        valor: () => c.ler(),
        definir(v) { c.escrever(v); mostrar(null); avaliar(false); },
        validar() { tocado = true; return !avaliar(true); },
        erro(msg) {
            // Erro vindo do servidor vence a validação viva: o visto verde sai junto com a mensagem.
            tocado = Boolean(msg);
            if (msg) el.classList.remove('is-valido');
            mostrar(msg ? txt(msg) : null);
            if (!msg) avaliar(false);
        },
        limpar() { c.escrever(null); tocado = false; mostrar(null); el.classList.remove('is-valido'); },
        focar() { c.foco.focus(); },
        desativar(sim = true) { el.classList.toggle('is-desativado', Boolean(sim)); c.desativar(Boolean(sim)); },
    };
    if (def.valor !== undefined && def.valor !== null) { c.escrever(def.valor); avaliar(false); }
    if (def.desativado) api.desativar(true);
    if (c.api) for (const [k, f] of Object.entries(c.api)) api[k] = f;
    el.d2Campo = api;
    for (const [k, v] of Object.entries(api)) if (k !== 'el' && !(k in el)) el[k] = v;
    el.el = el;
    return el;
}

// Peças comuns dos controles
function controleBase(ctx, extra = {}) {
    const { def, id } = ctx;
    const controle = h('input', {
        id, name: def.nome || null, type: 'text', autocomplete: def.autocomplete || null,
        placeholder: def.placeholder ? txt(def.placeholder) : null, ...extra,
    });
    if (def.somenteLeitura) controle.readOnly = true;
    return controle;
}
function caixaEntrada(controle, { prefixo, sufixo, estado } = {}) {
    const caixa = h('div', { class: 'd2-entrada' });
    if (prefixo) caixa.append(prefixo instanceof Node ? prefixo : h('span', { class: 'd2-entrada-prefixo', 'aria-hidden': 'true', texto: txt(prefixo) }));
    caixa.append(controle);
    if (estado) caixa.append(h('span', { class: 'd2-entrada-estado', 'aria-hidden': 'true' }, icone('check')));
    if (sufixo) caixa.append(sufixo instanceof Node ? sufixo : h('span', { class: 'd2-entrada-sufixo', 'aria-hidden': 'true', texto: txt(sufixo) }));
    return caixa;
}
function desativarEm(no, controles) {
    return (sim) => {
        for (const i of controles) i.disabled = sim;
        if (no.classList.contains('d2-entrada')) no.classList.toggle('is-desativada', sim);
    };
}
function checarTamanho(def, v) {
    const n = String(v).length;
    if (def.minimo && n < def.minimo) return t('d2.min_caracteres', { n: def.minimo });
    if (def.maximo && n > def.maximo) return t('d2.max_caracteres', { n: def.maximo });
    return null;
}

CONTROLES.texto = (ctx) => {
    const { def } = ctx;
    const controle = controleBase(ctx, { maxlength: def.maximo || null, inputmode: def.inputmode || null });
    const no = def.prefixo || def.sufixo ? caixaEntrada(controle, { prefixo: def.prefixo, sufixo: def.sufixo }) : controle;
    if (no === controle) controle.classList.add('d2-control');
    controle.addEventListener('input', ctx.mudou);
    return {
        no, foco: controle, desativar: desativarEm(no, [controle]),
        ler: () => controle.value.trim(),
        escrever: (v) => { controle.value = v === null || v === undefined ? '' : String(v); },
        checar: (v) => checarTamanho(def, v),
    };
};
CONTROLES['texto-longo'] = (ctx) => {
    const { def, id } = ctx;
    const area = h('textarea', { class: 'd2-control', id, name: def.nome || null, rows: String(def.linhas || 4), maxlength: def.maximo || null, placeholder: def.placeholder ? txt(def.placeholder) : null });
    const contador = def.maximo ? h('div', { class: 'd2-field-contador', id: id + '-contador', 'aria-live': 'polite' }) : null;
    const contar = () => { if (contador) contador.textContent = `${formatarNumero(area.value.length)} / ${formatarNumero(def.maximo)}`; };
    area.addEventListener('input', () => { contar(); ctx.mudou(); });
    contar();
    return {
        no: area, foco: area, extra: contador, desativar: (s) => { area.disabled = s; },
        ler: () => area.value.trim(),
        escrever: (v) => { area.value = v === null || v === undefined ? '' : String(v); contar(); },
        checar: (v) => checarTamanho(def, v),
    };
};
const RE_EMAIL = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)*\.[a-z]{2,}$/i;
CONTROLES.email = (ctx) => {
    const controle = controleBase(ctx, { type: 'email', inputmode: 'email', autocomplete: ctx.def.autocomplete || 'email', spellcheck: 'false', autocapitalize: 'off' });
    const no = caixaEntrada(controle, { estado: true });
    controle.addEventListener('input', ctx.mudou);
    controle.addEventListener('blur', () => { controle.value = controle.value.trim().toLowerCase(); });
    return {
        no, foco: controle, desativar: desativarEm(no, [controle]),
        ler: () => controle.value.trim().toLowerCase(),
        escrever: (v) => { controle.value = v ? String(v) : ''; },
        checar: (v) => (RE_EMAIL.test(v) ? checarTamanho(ctx.def, v) : t('d2.email_invalido')),
    };
};
CONTROLES.senha = (ctx) => {
    const { def, id } = ctx;
    const controle = controleBase(ctx, { type: 'password', autocomplete: def.autocomplete || 'current-password', spellcheck: 'false', autocapitalize: 'off' });
    const ver = h('button', { type: 'button', class: 'd2-entrada-sufixo d2-iconbtn d2-iconbtn-fantasma', 'aria-pressed': 'false', 'aria-label': t('d2.mostrar_senha'), 'data-tooltip': t('d2.mostrar_senha'), 'aria-controls': id }, icone('olho'));
    const no = caixaEntrada(controle, { sufixo: ver });
    const avisoMaiusculas = h('div', { class: 'd2-field-caps', id: id + '-caps', 'aria-live': 'polite' });
    ver.addEventListener('click', () => {
        const mostrar = controle.type === 'password';
        controle.type = mostrar ? 'text' : 'password';
        ver.setAttribute('aria-pressed', String(mostrar));
        ver.setAttribute('aria-label', t(mostrar ? 'd2.ocultar_senha' : 'd2.mostrar_senha'));
        ver.setAttribute('data-tooltip', t(mostrar ? 'd2.ocultar_senha' : 'd2.mostrar_senha'));
        ver.replaceChildren(icone(mostrar ? 'olho-fechado' : 'olho'));
        controle.focus();
    });
    const verMaiusculas = (evento) => {
        const ligado = evento.getModifierState && evento.getModifierState('CapsLock');
        avisoMaiusculas.textContent = '';
        if (ligado) avisoMaiusculas.append(icone('alerta'), h('span', { texto: t('d2.caps_lock') }));
    };
    controle.addEventListener('keydown', verMaiusculas);
    controle.addEventListener('keyup', verMaiusculas);
    controle.addEventListener('blur', () => { avisoMaiusculas.textContent = ''; });
    controle.addEventListener('input', ctx.mudou);
    return {
        no, foco: controle, extra: avisoMaiusculas, descricao: avisoMaiusculas.id, desativar: desativarEm(no, [controle, ver]),
        ler: () => controle.value,
        escrever: (v) => { controle.value = v ? String(v) : ''; },
        checar: (v) => checarTamanho(def, v),
    };
};
CONTROLES.numero = (ctx) => {
    const { def } = ctx;
    const controle = controleBase(ctx, { inputmode: def.casas === 0 ? 'numeric' : 'decimal', autocomplete: 'off', class: 'is-direita' });
    const no = caixaEntrada(controle, { prefixo: def.prefixo, sufixo: def.sufixo });
    controle.addEventListener('input', () => {
        const { decimal, grupo } = separadores();
        const limpo = [...controle.value].filter((ch) => /[\d\s-]/.test(ch) || ch === decimal || ch === grupo).join('');
        if (limpo !== controle.value) controle.value = limpo;
        ctx.mudou();
    });
    controle.addEventListener('blur', () => { const n = lerNumero(controle.value); if (typeof n === 'number' && isFinite(n)) controle.value = formatarNumero(n, def.casas); });
    return {
        no, foco: controle, desativar: desativarEm(no, [controle]),
        ler: () => { const n = lerNumero(controle.value); return typeof n === 'number' && isFinite(n) ? n : null; },
        invalido: () => { const n = lerNumero(controle.value); return n !== null && !isFinite(n) ? t('d2.numero_invalido') : null; },
        escrever: (v) => { controle.value = typeof v === 'number' && isFinite(v) ? formatarNumero(v, def.casas) : ''; },
        checar: (v) => {
            if (def.casas === 0 && !Number.isInteger(v)) return t('d2.numero_invalido');
            if (def.min !== undefined && v < def.min) return t('d2.min_valor', { valor: formatarNumero(def.min) });
            if (def.max !== undefined && v > def.max) return t('d2.max_valor', { valor: formatarNumero(def.max) });
            return null;
        },
    };
};
// Dinheiro em centavos: digitação da direita para a esquerda, formato do idioma.
CONTROLES.dinheiro = (ctx) => {
    const { def } = ctx;
    // def.permitirNegativo: o sinal de menos digitado alterna o valor para negativo (saldos, ajustes).
    const negativo = Boolean(def.permitirNegativo);
    const controle = controleBase(ctx, { inputmode: negativo ? 'text' : 'numeric', autocomplete: 'off', class: 'is-direita d2-num' });
    const no = caixaEntrada(controle, { prefixo: simboloMoeda() });
    let centavos = null;
    const exibir = () => { controle.value = centavos === null ? '' : formatarNumero(centavos / 100, 2); };
    controle.addEventListener('input', () => {
        const bruto = controle.value;
        const dig = bruto.replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(0, 13);
        // Exibido negativo, o texto já traz um hífen; cada hífen digitado alterna o sinal.
        const ehNegativo = negativo && ((bruto.match(/-/g) || []).length % 2 === 1);
        centavos = dig ? Number(dig) * (ehNegativo ? -1 : 1) : null;
        exibir();
        controle.setSelectionRange(controle.value.length, controle.value.length);
        ctx.mudou();
    });
    return {
        no, foco: controle, desativar: desativarEm(no, [controle]),
        ler: () => centavos,
        escrever: (v) => { centavos = v === null || v === undefined || v === '' || !isFinite(Number(v)) ? null : Math.round(Number(v)); if (!negativo && centavos !== null) centavos = Math.abs(centavos); exibir(); },
        checar: (v) => {
            if (def.min !== undefined && v < def.min) return t('d2.min_valor', { valor: formatarMoeda(def.min) });
            if (def.max !== undefined && v > def.max) return t('d2.max_valor', { valor: formatarMoeda(def.max) });
            return null;
        },
    };
};
// Reaplica uma máscara mantendo o cursor depois do mesmo caractere significativo.
function remascarar(controle, formatar, significativo = /[0-9A-Za-z]/) {
    const pos = controle.selectionStart === null ? controle.value.length : controle.selectionStart;
    const antes = [...controle.value.slice(0, pos)].filter((ch) => significativo.test(ch)).length;
    const novo = formatar(controle.value);
    if (novo === controle.value) return;
    controle.value = novo;
    if (document.activeElement !== controle) return;
    let k = 0;
    let i = 0;
    while (i < novo.length && k < antes) { if (significativo.test(novo[i])) k++; i++; }
    controle.setSelectionRange(i, i);
}
CONTROLES.documento = (ctx) => {
    const { def } = ctx;
    const aceitar = ['cpf', 'cnpj'].includes(def.aceitar) ? def.aceitar : 'ambos';
    const controle = controleBase(ctx, { inputmode: aceitar === 'cpf' ? 'numeric' : 'text', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', class: 'd2-num' });
    const no = caixaEntrada(controle, { estado: true });
    const formatar = (texto) => mascaraDocumento(limparDocumento(texto, aceitar), aceitar);
    controle.addEventListener('input', () => { remascarar(controle, formatar); ctx.mudou(); });
    return {
        no, foco: controle, desativar: desativarEm(no, [controle]),
        ler: () => limparDocumento(controle.value, aceitar),
        escrever: (v) => { controle.value = v ? formatar(String(v)) : ''; },
        checar: (v) => {
            if (aceitar === 'cpf') return cpfValido(v) ? null : t('d2.cpf_invalido');
            if (aceitar === 'cnpj') return cnpjValido(v) ? null : t('d2.cnpj_invalido');
            if (v.length === 11 && /^\d+$/.test(v)) return cpfValido(v) ? null : t('d2.cpf_invalido');
            if (v.length === 14) return cnpjValido(v) ? null : t('d2.cnpj_invalido');
            return t('d2.documento_invalido');
        },
    };
};
// Telefone com país: o valor sai em E.164 (+5511987654321) ou vazio.
CONTROLES.telefone = (ctx) => {
    const { def } = ctx;
    let pais = PAISES_TELEFONE.find(([iso]) => iso === (def.pais || conf.paisTelefone)) || PAISES_TELEFONE[0];
    let lista = null;
    const controle = controleBase(ctx, { type: 'tel', inputmode: 'tel', autocomplete: def.autocomplete || 'tel-national', class: 'd2-num' });
    const bandeiraEl = h('span', { class: 'd2-bandeira', 'aria-hidden': 'true' });
    const ddi = h('span', { class: 'd2-num' });
    const botao = h('button', { type: 'button', class: 'd2-entrada-prefixo', 'aria-haspopup': 'listbox', 'aria-expanded': 'false', 'data-tooltip': t('d2.pais') }, bandeiraEl, ddi, icone('chevron-baixo'));
    const no = caixaEntrada(controle, { prefixo: botao, estado: true });
    const maximo = () => (TAMANHO_TELEFONE[pais[0]] || [6, 15])[1];
    const digitos = () => controle.value.replace(/\D/g, '').slice(0, maximo());
    function mostrarPais() {
        bandeiraEl.textContent = bandeira(pais[0]);
        ddi.textContent = '+' + pais[1];
        botao.setAttribute('aria-label', `${t('d2.pais')}: ${nomePais(pais[0])} +${pais[1]}`);
    }
    const formatar = (texto) => mascaraTelefone(pais[0], String(texto).replace(/\D/g, '').slice(0, maximo()));
    function lerCompleto(texto) {
        const achado = paisDoNumero(texto);
        if (!achado) return String(texto).replace(/\D/g, '');
        pais = achado;
        mostrarPais();
        return String(texto).replace(/\D/g, '').slice(achado[1].length);
    }
    controle.addEventListener('input', () => {
        if (controle.value.trim().startsWith('+')) controle.value = formatar(lerCompleto(controle.value));
        else remascarar(controle, formatar, /\d/);
        ctx.mudou();
    });
    botao.addEventListener('click', () => {
        if (lista) { lista.fechar('alternar'); return; }
        botao.setAttribute('aria-expanded', 'true');
        lista = abrirLista(botao, {
            opcoes: PAISES_TELEFONE.map(([iso, cod]) => ({ valor: iso, rotulo: nomePais(iso), dica: '+' + cod, bandeira: bandeira(iso), busca: cod + ' ' + iso })),
            selecionados: new Set([pais[0]]),
            comBusca: true,
            larguraMin: Math.max(260, no.getBoundingClientRect().width),
            aoEscolher: (o) => { pais = PAISES_TELEFONE.find(([iso]) => iso === o.valor); mostrarPais(); controle.value = formatar(controle.value); ctx.mudou(); },
            aoFechar: (motivo) => {
                lista = null;
                botao.setAttribute('aria-expanded', 'false');
                if (motivo === 'escolha') controle.focus();
                else if (motivo === 'esc') botao.focus();
            },
        });
    });
    mostrarPais();
    return {
        no, foco: controle, desativar: desativarEm(no, [controle, botao]), aberto: () => Boolean(lista),
        ler: () => { const d = digitos(); return d ? '+' + pais[1] + d : ''; },
        escrever: (v) => {
            const s = v ? String(v) : '';
            controle.value = s ? formatar(s.startsWith('+') ? lerCompleto(s) : s) : '';
        },
        checar: () => (telefoneValido(pais[0], digitos()) ? null : t('d2.telefone_invalido')),
    };
};
function checarIntervalo(def, iso) {
    if (def.min && iso < def.min) return t('d2.min_valor', { valor: formatarData(def.min) });
    if (def.max && iso > def.max) return t('d2.max_valor', { valor: formatarData(def.max) });
    return null;
}
// Data: digitação com máscara do idioma ou calendário próprio; o valor sai em ISO (AAAA-MM-DD).
CONTROLES.data = (ctx) => {
    const { def } = ctx;
    let flutuante = null;
    const controle = controleBase(ctx, { inputmode: 'numeric', autocomplete: def.autocomplete || 'off', class: 'd2-num' });
    const botao = h('button', {
        type: 'button', class: 'd2-entrada-sufixo d2-iconbtn d2-iconbtn-fantasma', 'aria-haspopup': 'dialog', 'aria-expanded': 'false',
        'aria-label': t('d2.escolher_data'), 'data-tooltip': t('d2.escolher_data'),
    }, icone('calendario'));
    const no = caixaEntrada(controle, { sufixo: botao });
    controle.addEventListener('input', () => { remascarar(controle, mascaraData, /\d/); ctx.mudou(); });
    function abrir() {
        if (flutuante) { flutuante.fechar('alternar'); return; }
        const cal = calendario({
            modo: 'data', valor: lerDataDigitada(controle.value), min: def.min, max: def.max,
            aoEscolher: (iso) => { controle.value = formatarData(iso); ctx.mudou(); if (flutuante) flutuante.fechar('escolha'); },
        });
        botao.setAttribute('aria-expanded', 'true');
        flutuante = abrirFlutuante(cal.el, no, {
            classe: 'd2-popover d2-popover-cal', alinhar: 'inicio',
            aoFechar: (motivo) => {
                flutuante = null;
                botao.setAttribute('aria-expanded', 'false');
                if (motivo === 'escolha') { controle.focus(); ctx.tocar(); } else if (motivo === 'esc') botao.focus();
            },
        });
        cal.focar();
    }
    botao.addEventListener('click', abrir);
    controle.addEventListener('keydown', (evento) => { if (evento.key === 'ArrowDown' && evento.altKey) { evento.preventDefault(); abrir(); } });
    return {
        no, foco: controle, desativar: desativarEm(no, [controle, botao]), aberto: () => Boolean(flutuante),
        ler: () => lerDataDigitada(controle.value),
        invalido: () => (controle.value.trim() && !lerDataDigitada(controle.value) ? t('d2.data_invalida') : null),
        escrever: (v) => { controle.value = v ? formatarData(v) : ''; },
        checar: (v) => checarIntervalo(def, v),
    };
};
// Período: um seletor que abre o calendário de dois meses com atalhos; valor { inicio, fim } em ISO.
CONTROLES.periodo = (ctx) => {
    const { def, id } = ctx;
    let valor = { inicio: '', fim: '' };
    let flutuante = null;
    const texto = h('span', { class: 'd2-seletor-texto' });
    const botao = h('button', { type: 'button', class: 'd2-seletor', id, 'aria-haspopup': 'dialog', 'aria-expanded': 'false' },
        icone('calendario'), texto);
    function mostrar() {
        const cheio = valor.inicio && valor.fim;
        texto.textContent = cheio ? t('d2.periodo_de_ate', { inicio: formatarData(valor.inicio), fim: formatarData(valor.fim) })
            : (def.placeholder ? txt(def.placeholder) : t('d2.escolher_periodo'));
        texto.classList.toggle('is-vazio', !cheio);
    }
    botao.addEventListener('click', () => {
        if (flutuante) { flutuante.fechar('alternar'); return; }
        const cal = calendario({
            modo: 'periodo', valor, min: def.min, max: def.max,
            aoAplicar: (v) => { valor = v; mostrar(); ctx.mudou(); if (flutuante) flutuante.fechar('escolha'); },
            aoCancelar: () => { if (flutuante) flutuante.fechar('esc'); },
        });
        botao.setAttribute('aria-expanded', 'true');
        botao.classList.add('is-aberto');
        flutuante = abrirFlutuante(cal.el, botao, {
            classe: 'd2-popover d2-popover-cal',
            aoFechar: (motivo) => {
                flutuante = null;
                botao.setAttribute('aria-expanded', 'false');
                botao.classList.remove('is-aberto');
                if (motivo === 'escolha' || motivo === 'esc') botao.focus();
                if (motivo === 'escolha') ctx.tocar();
            },
        });
        cal.focar();
    });
    mostrar();
    return {
        no: botao, foco: botao, desativar: (s) => { botao.disabled = s; }, aberto: () => Boolean(flutuante),
        ler: () => (valor.inicio && valor.fim ? { ...valor } : null),
        vazio: (v) => !v || !v.inicio || !v.fim,
        escrever: (v) => { valor = v && v.inicio && v.fim ? { inicio: v.inicio, fim: v.fim } : { inicio: '', fim: '' }; mostrar(); },
        checar: (v) => (v.fim < v.inicio ? t('d2.periodo_invalido') : checarIntervalo(def, v.inicio) || checarIntervalo(def, v.fim)),
    };
};
// Seleção única com lista própria (busca local ou remota). Opções: [{ valor, rotulo, dica, icone, desativado }].
function opcaoDe(opcoes, v) {
    if (v && typeof v === 'object' && 'valor' in v) return v;
    return opcoes.find((o) => String(o.valor) === String(v)) || { valor: v, rotulo: String(v) };
}
function seletorLista(ctx, multipla) {
    const { def, id } = ctx;
    let opcoes = Array.isArray(def.opcoes) ? def.opcoes : [];
    let lista = null;
    const escolhidos = new Map();
    // Rótulo do valor inicial quando as opções vêm da busca (def.valorRotulo: texto para o
    // def.valor, ou { valor: rótulo }), para o campo não mostrar o número do registro.
    function rotuloConhecido(v) {
        const r = def.valorRotulo;
        if (r === undefined || r === null || r === '') return null;
        if (typeof r === 'object') return r[String(v)] ?? null;
        return !multipla && String(v) === String(def.valor) ? r : null;
    }
    // Seleção única: papel combobox (padrão WAI-ARIA "select only combobox"), com id estável da
    // lista para aria-controls e aria-activedescendant espelhado enquanto navega pelas opções
    // (aria-required só é permitido em combobox/listbox, nunca no botão puro).
    const idListaPropria = novoId('d2-lista');
    const botao = h(multipla ? 'div' : 'button', {
        type: multipla ? null : 'button', class: 'd2-seletor', id, tabindex: multipla ? '0' : null, role: multipla ? 'button' : 'combobox',
        'aria-haspopup': 'listbox', 'aria-expanded': 'false', 'aria-controls': multipla ? null : idListaPropria,
    });
    function mostrar() {
        botao.textContent = '';
        const lista2 = [...escolhidos.values()];
        if (!lista2.length) { botao.append(h('span', { class: 'd2-seletor-texto is-vazio', texto: def.placeholder ? txt(def.placeholder) : t('d2.selecione') })); return; }
        if (!multipla) {
            const o = lista2[0];
            if (o.icone) botao.append(icone(o.icone));
            botao.append(h('span', { class: 'd2-seletor-texto', texto: txt(o.rotulo) }));
            return;
        }
        const chips = h('span', { class: 'd2-seletor-chips' });
        for (const o of lista2) {
            const nome = txt(o.rotulo);
            chips.append(h('span', { class: 'd2-chip' }, h('span', { texto: nome }),
                h('button', {
                    type: 'button', class: 'd2-chip-x', tabindex: '-1', 'aria-label': t('d2.remover_item', { item: nome }),
                    onclick: (evento) => { evento.stopPropagation(); escolhidos.delete(String(o.valor)); mostrar(); ctx.mudou(); ctx.tocar(); },
                }, icone('fechar'))));
        }
        botao.append(chips);
    }
    function abrir() {
        if (lista) { lista.fechar('alternar'); return; }
        if (botao.getAttribute('aria-disabled') === 'true') return;
        botao.setAttribute('aria-expanded', 'true');
        botao.classList.add('is-aberto');
        lista = abrirLista(botao, {
            opcoes, multipla, buscar: typeof def.buscar === 'function' ? def.buscar : null, comBusca: def.comBusca,
            selecionados: new Set(escolhidos.keys()),
            idLista: idListaPropria, ariaDono: multipla ? null : botao,
            aoEscolher: (o) => {
                const k = String(o.valor);
                if (multipla && escolhidos.has(k)) escolhidos.delete(k);
                else { if (!multipla) escolhidos.clear(); escolhidos.set(k, o); }
                mostrar();
                ctx.mudou();
            },
            aoLimpar: multipla || !def.obrigatorio ? () => { escolhidos.clear(); mostrar(); ctx.mudou(); } : null,
            aoFechar: (motivo) => {
                lista = null;
                botao.setAttribute('aria-expanded', 'false');
                botao.removeAttribute('aria-activedescendant');
                botao.classList.remove('is-aberto');
                if (motivo !== 'fora' && motivo !== 'tab') botao.focus();
                if (motivo !== 'alternar') ctx.tocar();
            },
        });
    }
    botao.addEventListener('click', abrir);
    botao.addEventListener('keydown', (evento) => {
        if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp' || (multipla && (evento.key === 'Enter' || evento.key === ' '))) { evento.preventDefault(); if (!lista) abrir(); }
        else if (multipla && (evento.key === 'Backspace' || evento.key === 'Delete') && escolhidos.size) {
            evento.preventDefault();
            escolhidos.delete([...escolhidos.keys()].pop());
            mostrar();
            ctx.mudou();
        }
    });
    mostrar();
    return {
        no: botao, foco: botao, aberto: () => Boolean(lista),
        desativar: (s) => { if (multipla) { botao.setAttribute('aria-disabled', String(s)); botao.tabIndex = s ? -1 : 0; botao.classList.toggle('is-desativado', s); } else botao.disabled = s; },
        ler: () => (multipla ? [...escolhidos.values()].map((o) => o.valor) : (escolhidos.size ? [...escolhidos.values()][0].valor : null)),
        escrever: (v) => {
            // O escolhido atual de mesmo valor (vindo da busca) guarda o rótulo que as opções não têm.
            const anteriores = new Map(escolhidos);
            escolhidos.clear();
            const lista2 = multipla ? (Array.isArray(v) ? v : []) : (v === null || v === undefined || v === '' ? [] : [v]);
            for (const item of lista2) {
                const o = opcaoDe(opcoes, item);
                const k = String(o.valor);
                if (opcoes.some((x) => String(x.valor) === k)) { escolhidos.set(k, o); continue; }
                const rot = rotuloConhecido(o.valor);
                const proprio = item && typeof item === 'object' && 'valor' in item;
                escolhidos.set(k, rot !== null ? { ...o, rotulo: String(rot) } : (proprio ? o : (anteriores.get(k) || o)));
            }
            mostrar();
        },
        api: {
            definirOpcoes(novas) {
                opcoes = Array.isArray(novas) ? novas : [];
                // Fora das novas opções, o escolhido continua com o rótulo que já tinha.
                for (const [k, o] of escolhidos) escolhidos.set(k, opcoes.find((x) => String(x.valor) === k) || o);
                mostrar();
            },
            opcaoEscolhida: () => (escolhidos.size ? [...escolhidos.values()][0] : null),
        },
    };
}
CONTROLES.selecao = (ctx) => seletorLista(ctx, false);
CONTROLES.multipla = (ctx) => seletorLista(ctx, true);
// Interruptor: o próprio rótulo fica ao lado do trilho; valor booleano.
CONTROLES.switch = (ctx) => {
    const { def, id } = ctx;
    const controle = h('input', { type: 'checkbox', role: 'switch', id, name: def.nome || null });
    const no = h('label', { class: 'd2-switch', for: id }, controle, h('span', { class: 'd2-switch-trilho', 'aria-hidden': 'true' }),
        h('span', { class: 'd2-switch-texto', texto: ctx.rotulo }),
        def.obrigatorio ? h('span', { class: 'd2-obrigatorio', 'aria-hidden': 'true', texto: '*' }) : null);
    controle.addEventListener('change', () => { ctx.mudou(); ctx.tocar(); });
    return {
        no, foco: controle,
        desativar: (s) => { controle.disabled = s; no.classList.toggle('is-desativado', s); },
        ler: () => controle.checked,
        vazio: (v) => !v,
        escrever: (v) => { controle.checked = Boolean(v); },
    };
};
// Arquivo: área de soltar com botão de escolha, lista dos escolhidos e conferência de tipo e tamanho.
function tipoAceito(arquivo, aceitar) {
    if (!aceitar) return true;
    const nome = arquivo.name.toLowerCase();
    const tipo = (arquivo.type || '').toLowerCase();
    return String(aceitar).split(',').map((x) => x.trim().toLowerCase()).filter(Boolean).some((a) => {
        if (a.startsWith('.')) return nome.endsWith(a);
        if (a.endsWith('/*')) return tipo.startsWith(a.slice(0, -1));
        return tipo === a;
    });
}
CONTROLES.arquivo = (ctx) => {
    const { def, id } = ctx;
    const multiplo = Boolean(def.multiplo);
    let arquivos = [];
    let recusa = null;
    const controle = h('input', { type: 'file', id, name: def.nome || null, tabindex: '-1', 'aria-hidden': 'true', multiple: multiplo || null, accept: def.aceitar || null });
    const rotuloBotao = t(multiplo ? 'd2.arquivos_escolher' : 'd2.arquivo_escolher');
    const botao = h('button', { type: 'button', class: 'd2-btn d2-btn-sm d2-btn-secundario', 'aria-label': `${ctx.rotulo}: ${rotuloBotao}` }, icone('anexo'), h('span', { texto: rotuloBotao }));
    const zona = h('div', { class: 'd2-arquivo' }, icone('subir'), h('span', { texto: t('d2.arquivo_soltar') }), botao, controle);
    const lista = h('ul', { class: 'd2-arquivo-lista' });
    const no = h('div', { class: 'd2-arquivo-campo' }, zona, lista);
    function desenhar() {
        lista.textContent = '';
        lista.hidden = !arquivos.length;
        for (const a of arquivos) {
            lista.append(h('li', { class: 'd2-arquivo-item' }, icone(/^image\//.test(a.type) ? 'imagem' : 'documento'),
                h('span', { class: 'd2-arquivo-nome', texto: a.name }),
                h('span', { class: 'd2-arquivo-tamanho', texto: formatarTamanho(a.size) }),
                h('button', {
                    type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm', 'aria-label': t('d2.arquivo_remover', { nome: a.name }),
                    'data-tooltip': t('d2.remover'),
                    onclick: () => { arquivos = arquivos.filter((x) => x !== a); recusa = null; desenhar(); ctx.mudou(); botao.focus(); },
                }, icone('lixeira'))));
        }
    }
    function adicionar(lista2) {
        recusa = null;
        const novos = [];
        for (const a of lista2) {
            if (def.tamanhoMaximo && a.size > def.tamanhoMaximo) recusa = t('d2.arquivo_grande', { tamanho: formatarTamanho(def.tamanhoMaximo) });
            else if (!tipoAceito(a, def.aceitar)) recusa = t('d2.arquivo_tipo');
            else novos.push(a);
        }
        if (novos.length) arquivos = multiplo ? arquivos.concat(novos.filter((n) => !arquivos.some((a) => a.name === n.name && a.size === n.size))) : [novos[0]];
        desenhar();
        ctx.mudou();
        ctx.tocar();
    }
    botao.addEventListener('click', () => controle.click());
    controle.addEventListener('change', () => { adicionar([...controle.files]); controle.value = ''; });
    let profundidade = 0;
    zona.addEventListener('dragenter', (evento) => { evento.preventDefault(); profundidade++; zona.classList.add('is-arrastando'); });
    zona.addEventListener('dragover', (evento) => { evento.preventDefault(); if (evento.dataTransfer) evento.dataTransfer.dropEffect = 'copy'; });
    zona.addEventListener('dragleave', () => { profundidade = Math.max(0, profundidade - 1); if (!profundidade) zona.classList.remove('is-arrastando'); });
    zona.addEventListener('drop', (evento) => {
        evento.preventDefault();
        profundidade = 0;
        zona.classList.remove('is-arrastando');
        if (!botao.disabled && evento.dataTransfer && evento.dataTransfer.files.length) adicionar([...evento.dataTransfer.files]);
    });
    desenhar();
    return {
        no, foco: botao,
        desativar: (s) => { botao.disabled = s; controle.disabled = s; zona.classList.toggle('is-desativado', s); lista.querySelectorAll('button').forEach((b) => { b.disabled = s; }); },
        ler: () => (multiplo ? [...arquivos] : arquivos[0] || null),
        invalido: () => recusa,
        escrever: (v) => { recusa = null; arquivos = v ? (Array.isArray(v) ? [...v] : [v]).filter((a) => a instanceof Blob) : []; desenhar(); },
    };
};

// Formulário: trabalha sobre os campos do D2UI e também sobre entradas comuns com name.
function raizForm(raiz) {
    if (raiz instanceof Node) return raiz;
    if (typeof raiz === 'string') return document.querySelector(raiz);
    if (raiz && raiz.el instanceof Node) return raiz.el;
    if (raiz && raiz.corpo instanceof Node) return raiz.corpo;
    return null;
}
function camposDe(raiz) {
    const r = raizForm(raiz);
    const mapa = new Map();
    if (!r) return mapa;
    const lista = r.matches && r.matches('.d2-field') ? [r] : [...r.querySelectorAll('.d2-field')];
    for (const el of lista) if (el.d2Campo && el.d2Campo.nome) mapa.set(el.d2Campo.nome, el.d2Campo);
    return mapa;
}
function entradasSoltas(raiz) {
    const r = raizForm(raiz);
    if (!r) return [];
    return [...r.querySelectorAll('input[name], select[name], textarea[name]')]
        .filter((i) => !i.closest('.d2-field') || !i.closest('.d2-field').d2Campo);
}
function lerSolta(i) {
    if (i.type === 'checkbox') return i.checked;
    if (i.type === 'number') return i.value === '' ? null : Number(i.value);
    if (i.type === 'file') return i.multiple ? [...i.files] : i.files[0] || null;
    return i.value.trim();
}
function mensagemDe(v) {
    if (Array.isArray(v)) return mensagemDe(v[0]);
    if (v && typeof v === 'object') return v.mensagem || v.chave || v.erro || '';
    return v ? String(v) : '';
}
const form = {
    campos: (raiz) => camposDe(raiz),
    serializar(raiz) {
        const dados = {};
        for (const i of entradasSoltas(raiz)) {
            if (i.type === 'radio') { if (i.checked) dados[i.name] = i.value; else if (!(i.name in dados)) dados[i.name] = null; continue; }
            if (i.disabled || i.type === 'submit' || i.type === 'button') continue;
            dados[i.name] = lerSolta(i);
        }
        for (const [nome, c] of camposDe(raiz)) dados[nome] = c.valor();
        return dados;
    },
    validar(raiz) {
        let primeiro = null;
        for (const c of camposDe(raiz).values()) if (!c.el.classList.contains('is-desativado') && !c.validar() && !primeiro) primeiro = c;
        if (primeiro) { primeiro.focar(); primeiro.el.scrollIntoView({ block: 'nearest', behavior: reduzido() ? 'auto' : 'smooth' }); }
        return !primeiro;
    },
    // Aplica os erros vindos da API ({ campo: mensagem }); devolve os nomes que não têm campo na tela.
    erros(raiz, erros) {
        const campos = camposDe(raiz);
        const sobra = [];
        let primeiro = null;
        for (const [nome, v] of Object.entries(erros || {})) {
            const c = campos.get(nome);
            const msg = mensagemDe(v);
            if (!c) { sobra.push(nome); continue; }
            c.erro(msg || 'd2.valor_invalido');
            if (!primeiro) primeiro = c;
        }
        if (primeiro) primeiro.focar();
        return sobra;
    },
    limpar(raiz) {
        for (const c of camposDe(raiz).values()) c.limpar();
        for (const i of entradasSoltas(raiz)) {
            if (i.type === 'checkbox' || i.type === 'radio') i.checked = false;
            else if (i.tagName === 'SELECT') i.selectedIndex = 0;
            else i.value = '';
        }
    },
};

// Contagem regressiva: <time> que mostra quanto falta (ou há quanto passou) para iso e se
// atualiza sozinho; para quando sai da página. opcoes: { aoZerar(), classe }.
const CONTAGEM_SEM_DOM_MS = 120000;
// Fora do DOM depois de vista (janela minimizada): confere de 5 em 5 s por até 15 min.
const CONTAGEM_FORA_INTERVALO_MS = 5000;
const CONTAGEM_FORA_MAXIMO_MS = 900000;
function textoDuracao(ms) {
    const s = Math.floor(ms / 1000);
    const d = Math.floor(s / 86400);
    const hr = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    const partes = [];
    if (d) partes.push(t('d2.contagem.dias', { n: d }));
    if (d && hr) partes.push(t('d2.contagem.horas', { n: hr }));
    if (!d && hr) partes.push(t('d2.contagem.horas', { n: hr }), t('d2.contagem.minutos', { n: m }));
    if (!d && !hr && m) partes.push(t('d2.contagem.minutos', { n: m }));
    if (!d && !hr && m < 5) partes.push(t('d2.contagem.segundos', { n: s % 60 }));
    return partes.join(' ');
}
function contagemRegressiva(iso, opcoes = {}) {
    const alvo = Date.parse(String(iso || ''));
    const el = h('time', { class: 'd2-contagem' + (opcoes.classe ? ' ' + opcoes.classe : '') });
    if (Number.isNaN(alvo)) { el.textContent = ''; return el; }
    el.setAttribute('datetime', new Date(alvo).toISOString());
    try {
        el.setAttribute('data-tooltip', new Intl.DateTimeFormat(localidade(), { dateStyle: 'full', timeStyle: 'short', timeZone: fusoEmpresa() }).format(alvo));
    } catch (e) { el.setAttribute('data-tooltip', new Date(alvo).toLocaleString(localidade())); }
    const criado = Date.now();
    let visto = false;
    let zerou = Date.now() >= alvo;
    let temporizador = 0;
    let saiu = 0;
    function passo() {
        if (el.isConnected) { visto = true; saiu = 0; }
        else if (!visto) { if (Date.now() - criado > CONTAGEM_SEM_DOM_MS) return; }
        else {
            // Saiu depois de vista: espera voltar e então recalcula (e dispara aoZerar, se passou da hora).
            if (!saiu) saiu = Date.now();
            if (Date.now() - saiu > CONTAGEM_FORA_MAXIMO_MS) return;
            temporizador = setTimeout(passo, CONTAGEM_FORA_INTERVALO_MS);
            return;
        }
        const resta = alvo - Date.now();
        const abs = Math.abs(resta);
        el.setAttribute('data-estado', resta > 0 ? 'futuro' : 'passado');
        if (abs < 1000) el.textContent = t('d2.contagem.agora');
        else el.textContent = t(resta > 0 ? 'd2.contagem.em' : 'd2.contagem.ha', { tempo: textoDuracao(abs) });
        if (!zerou && resta <= 0) {
            zerou = true;
            if (typeof opcoes.aoZerar === 'function') { try { opcoes.aoZerar(); } catch (e) { setTimeout(() => { throw e; }); } }
        }
        // Segundos à vista: a cada virada de segundo; senão, na virada do minuto.
        const unidade = abs < 300000 ? 1000 : 60000;
        const r = abs % unidade;
        temporizador = setTimeout(passo, Math.max(250, (resta > 0 ? (r || unidade) : unidade - r) + 20));
    }
    passo();
    el.parar = () => clearTimeout(temporizador);
    return el;
}

// Painel recolhível no celular: o cabeçalho vira um alternador que recolhe a seção; no
// computador nada muda. Recebe as opções de painel() (e o conteúdo) ou um painel pronto
// (elemento .d2-painel ou o objeto que painel() devolve). opcoes.recolhido: começa fechado.
function painelRecolhivel(opcoes = {}, ...conteudo) {
    let p;
    if (opcoes instanceof Element) p = { el: opcoes, cab: opcoes.querySelector(':scope > .d2-painel-cab'), corpo: opcoes.querySelector(':scope > .d2-painel-corpo') };
    else if (opcoes && opcoes.el instanceof Element) p = opcoes;
    else p = painel(opcoes, ...conteudo);
    const { el, cab, corpo } = p;
    if (!cab || !corpo) return p;
    // Já recolhível: devolve o controle existente, sem outro botão nem outro ouvinte.
    if (el.classList.contains('d2-recolhivel')) return el.d2Recolhivel || p;
    const config = opcoes instanceof Element || (opcoes && opcoes.el instanceof Element) ? (conteudo[0] || {}) : opcoes;
    if (!corpo.id) corpo.id = novoId('d2-recolhivel');
    el.classList.add('d2-recolhivel');
    const botao = h('button', { type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm d2-recolhivel-alternar', 'aria-controls': corpo.id }, icone('chevron-baixo'));
    cab.append(botao);
    function definir(recolhido) {
        el.classList.toggle('is-recolhido', recolhido);
        botao.setAttribute('aria-expanded', String(!recolhido));
        botao.setAttribute('aria-label', t(recolhido ? 'd2.recolhivel.expandir' : 'd2.recolhivel.recolher'));
    }
    definir(Boolean(config.recolhido));
    // No celular o cabeçalho inteiro alterna, menos os botões de ação dele.
    cab.addEventListener('click', (evento) => {
        if (!celular()) return;
        if (evento.target.closest('.d2-painel-acoes') || (evento.target.closest('button, a, input, select, textarea') && !botao.contains(evento.target))) return;
        definir(!el.classList.contains('is-recolhido'));
    });
    el.d2Recolhivel = { ...p, recolher: () => definir(true), expandir: () => definir(false), recolhido: () => el.classList.contains('is-recolhido') };
    return el.d2Recolhivel;
}

// Gráfico mínimo em SVG (barras ou linhas), sem biblioteca externa: grade, eixo com valores curtos, legenda,
// dica da casa em cada ponto e a tabela equivalente (sempre no DOM: o leitor de tela lê a tabela; a tela
// alterna com mostrarTabela). Cor só por tom semântico (ok, erro, aviso, info, neutro) ou pela ordem da série
// (acento da área, depois info, aviso e neutro), pelos tokens dos dois temas.
// opcoes: { tipo: 'barras' | 'linhas', rotulos: [texto], series: [{ nome, valores: [número | null], tom? }],
//           formatar(v) -> texto, formatarEixo(v) -> texto, horizontal, rotulo (nome acessível), classe }.
// Devolve { el, definir(novas opcoes), mostrarTabela(sim) }.
const TOM_SERIE = ['', 'info', 'aviso', 'neutro'];
const APELIDO_TOM = { positivo: 'ok', sucesso: 'ok', negativo: 'erro', perigo: 'erro', alerta: 'aviso' };
function svg(etiqueta, atributos = {}) {
    const e = document.createElementNS(SVG_NS, etiqueta);
    for (const [k, v] of Object.entries(atributos)) if (v !== null && v !== undefined) e.setAttribute(k, String(v));
    return e;
}
function passoBonito(bruto) {
    if (!(bruto > 0)) return 1;
    const p = Math.pow(10, Math.floor(Math.log10(bruto)));
    const f = bruto / p;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
}
function escalaDe(valores, marcas = 4) {
    const nums = valores.filter((v) => typeof v === 'number' && Number.isFinite(v));
    let min = Math.min(0, ...nums);
    let max = Math.max(0, ...nums);
    if (min === max) max = min + 1;
    const passo = passoBonito((max - min) / marcas);
    min = Math.floor(min / passo) * passo;
    max = Math.ceil(max / passo) * passo;
    const ticks = [];
    for (let v = min; v <= max + passo / 2; v += passo) ticks.push(Math.round(v * 1e6) / 1e6);
    return { min, max, ticks };
}
function grafico(opcoes = {}) {
    let o = { ...opcoes };
    const el = h('figure', { class: 'd2-grafico' + (o.classe ? ' ' + o.classe : '') });
    const area = h('div', { class: 'd2-grafico-area', role: 'img' });
    const legenda = h('div', { class: 'd2-grafico-legenda', 'aria-hidden': 'true' });
    const caixaTabela = h('div', { class: 'd2-grafico-tabela-caixa d2-oculto-leitor' });
    el.append(area, legenda, caixaTabela);
    let largura = 0;
    let tabelaVisivel = false;
    const fmt = (v) => (v === null || v === undefined ? '' : (typeof o.formatar === 'function' ? o.formatar(v) : formatarNumero(v)));
    const fmtEixo = (v) => (typeof o.formatarEixo === 'function' ? o.formatarEixo(v) : fmt(v));
    const tomDe = (s, i) => tomValido(APELIDO_TOM[s.tom] || s.tom) || TOM_SERIE[i % TOM_SERIE.length] || null;

    function tabela() {
        const series = o.series || [];
        const cab = h('tr', {}, h('th', { scope: 'col', texto: txt(o.rotuloEixo || '') }), ...series.map((s) => h('th', { scope: 'col', class: 'is-direita', texto: txt(s.nome || '') })));
        const corpo = (o.rotulos || []).map((r, i) => h('tr', {}, h('th', { scope: 'row', texto: String(r) }),
            ...series.map((s) => h('td', { class: 'is-direita d2-num', texto: fmt((s.valores || [])[i]) }))));
        caixaTabela.replaceChildren(h('table', { class: 'd2-tabela d2-grafico-tabela' }, o.rotulo ? h('caption', { class: 'd2-oculto-leitor', texto: txt(o.rotulo) }) : null,
            h('thead', {}, cab), h('tbody', {}, ...corpo)));
    }
    function desenharLegenda() {
        const series = o.series || [];
        legenda.replaceChildren();
        legenda.hidden = series.length < 2;
        series.forEach((s, i) => legenda.append(h('span', { class: 'd2-grafico-legenda-item', 'data-tom': tomDe(s, i) },
            h('span', { class: 'd2-grafico-amostra' + (o.tipo === 'linhas' ? ' is-linha' : '') }), h('span', { texto: txt(s.nome || '') }))));
    }
    function desenhar() {
        const rotulos = (o.rotulos || []).map(String);
        const series = o.series || [];
        const n = rotulos.length;
        const W = Math.max(280, largura || el.clientWidth || 640);
        area.setAttribute('aria-label', txt(o.rotulo || ''));
        area.replaceChildren();
        if (!n || !series.length) return;
        const todos = series.flatMap((s) => s.valores || []);
        const esc = escalaDe(todos);
        const fonte = 12;
        const larguraTexto = (s) => String(s).length * 6.6 + 4;
        if (o.horizontal) {
            const linha = 26;
            const rotW = Math.min(W * 0.38, Math.max(...rotulos.map(larguraTexto)) + 8);
            const valW = Math.max(...todos.map((v) => larguraTexto(fmt(v)))) + 10;
            const H = n * linha + 8;
            const x0 = rotW;
            const plotW = Math.max(40, W - rotW - valW);
            const s = svg('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H, class: 'd2-grafico-svg', 'aria-hidden': 'true', focusable: 'false' });
            const max = Math.max(1, ...todos.filter((v) => typeof v === 'number'));
            rotulos.forEach((r, i) => {
                const y = 4 + i * linha;
                const t1 = svg('text', { x: rotW - 8, y: y + linha / 2 + 4, 'text-anchor': 'end', class: 'd2-grafico-texto' });
                t1.textContent = r.length > 28 ? r.slice(0, 27) + '…' : r;
                s.append(t1);
                series.forEach((se, j) => {
                    const v = Number((se.valores || [])[i]) || 0;
                    const alt = (linha - 8) / series.length;
                    const w = Math.max(v > 0 ? 2 : 0, (v / max) * plotW);
                    const g = svg('g', { class: 'd2-grafico-serie', 'data-tom': tomDe(se, j) });
                    const ret = svg('rect', { x: x0, y: y + 4 + j * alt, width: w, height: Math.max(4, alt - 2), rx: 3, class: 'd2-grafico-barra',
                        'data-tooltip': r + ' · ' + txt(se.nome || '') + ': ' + fmt(v) });
                    g.append(ret);
                    s.append(g);
                    const tv = svg('text', { x: x0 + w + 6, y: y + 4 + j * alt + Math.max(4, alt - 2) / 2 + 4, class: 'd2-grafico-texto d2-grafico-valor' });
                    tv.textContent = fmt(v);
                    s.append(tv);
                });
            });
            area.append(s);
            return;
        }
        const H = Math.round(Math.min(320, Math.max(200, W * 0.36)));
        const esqW = Math.max(...esc.ticks.map((v) => larguraTexto(fmtEixo(v)))) + 8;
        const topo = 10;
        const baixo = 26;
        const plotW = W - esqW - 8;
        const plotH = H - topo - baixo;
        const yDe = (v) => topo + plotH - ((v - esc.min) / (esc.max - esc.min)) * plotH;
        const s = svg('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H, class: 'd2-grafico-svg', 'aria-hidden': 'true', focusable: 'false' });
        for (const v of esc.ticks) {
            const y = yDe(v);
            s.append(svg('line', { x1: esqW, x2: W - 8, y1: y, y2: y, class: 'd2-grafico-grade' + (v === 0 ? ' is-zero' : '') }));
            const t1 = svg('text', { x: esqW - 6, y: y + 4, 'text-anchor': 'end', class: 'd2-grafico-texto' });
            t1.textContent = fmtEixo(v);
            s.append(t1);
        }
        const faixa = plotW / n;
        const maxRot = Math.max(...rotulos.map(larguraTexto));
        const cada = Math.max(1, Math.ceil(maxRot / Math.max(1, faixa)));
        rotulos.forEach((r, i) => {
            if (i % cada !== 0 && i !== n - 1) return;
            if (i === n - 1 && i % cada !== 0 && (n - 1) % cada < cada * 0.6) return;
            const t1 = svg('text', { x: esqW + faixa * i + faixa / 2, y: H - 8, 'text-anchor': 'middle', class: 'd2-grafico-texto', 'font-size': fonte });
            t1.textContent = r;
            s.append(t1);
        });
        const zero = yDe(Math.max(esc.min, Math.min(esc.max, 0)));
        if (o.tipo === 'linhas') {
            series.forEach((se, j) => {
                const g = svg('g', { class: 'd2-grafico-serie', 'data-tom': tomDe(se, j) });
                const pts = [];
                (se.valores || []).forEach((v, i) => { if (typeof v === 'number') pts.push([esqW + faixa * i + faixa / 2, yDe(v), v, i]); });
                if (pts.length > 1) g.append(svg('polyline', { points: pts.map((p) => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' '), class: 'd2-grafico-linha' }));
                for (const [x, y, v, i] of pts) {
                    g.append(svg('circle', { cx: x, cy: y, r: n > 40 ? 2.5 : 4, class: 'd2-grafico-ponto', 'data-tooltip': rotulos[i] + ' · ' + txt(se.nome || '') + ': ' + fmt(v) }));
                }
                s.append(g);
            });
        } else {
            const ocupa = Math.min(faixa * 0.78, series.length * 34);
            const bw = ocupa / series.length;
            series.forEach((se, j) => {
                const g = svg('g', { class: 'd2-grafico-serie', 'data-tom': tomDe(se, j) });
                (se.valores || []).forEach((v, i) => {
                    if (typeof v !== 'number') return;
                    const x = esqW + faixa * i + (faixa - ocupa) / 2 + j * bw;
                    const y = Math.min(yDe(v), zero);
                    const alt = Math.max(v === 0 ? 0 : 1.5, Math.abs(yDe(v) - zero));
                    g.append(svg('rect', { x: x + 0.5, y, width: Math.max(1, bw - 1.5), height: alt, rx: Math.min(3, bw / 4), class: 'd2-grafico-barra',
                        'data-tooltip': rotulos[i] + ' · ' + txt(se.nome || '') + ': ' + fmt(v) }));
                    // Alvo maior que a barra baixa: a dica aparece passando o ponteiro pela coluna inteira.
                    if (alt < 8) g.append(svg('rect', { x, y: topo, width: bw, height: plotH, class: 'd2-grafico-alvo', 'data-tooltip': rotulos[i] + ' · ' + txt(se.nome || '') + ': ' + fmt(v) }));
                });
                s.append(g);
            });
        }
        area.append(s);
    }
    function definir(novas = {}) {
        o = { ...o, ...novas };
        desenhar();
        desenharLegenda();
        tabela();
    }
    function mostrarTabela(sim) {
        tabelaVisivel = Boolean(sim);
        caixaTabela.classList.toggle('d2-oculto-leitor', !tabelaVisivel);
        area.hidden = tabelaVisivel;
        legenda.classList.toggle('is-oculta', tabelaVisivel);
    }
    if (typeof ResizeObserver === 'function') {
        let quadro = 0;
        new ResizeObserver((entradas) => {
            const w = Math.round(entradas[0].contentRect.width);
            if (!w || Math.abs(w - largura) < 4) return;
            largura = w;
            cancelAnimationFrame(quadro);
            quadro = requestAnimationFrame(desenhar);
        }).observe(el);
    }
    definir();
    return { el, definir, mostrarTabela, tabelaVisivel: () => tabelaVisivel };
}

// Configuração: tradutor, idioma, caminho do sprite, moeda, país padrão do telefone e o "hoje" da empresa
// (fuso: nome IANA ou função que o devolve; hoje: função que devolve AAAA-MM-DD no fuso da empresa).
function configurar(o = {}) {
    for (const k of ['t', 'idioma', 'sprite', 'moeda', 'paisTelefone', 'fuso', 'hoje']) if (o[k] !== undefined) conf[k] = o[k];
    return D2UI;
}

const D2UI = {
    configurar, t,
    toast, modal, confirmar, confirm: confirmar, progresso,
    tabela, esqueleto, vazio, selo, avatar, iniciais, icone, abas, menuContexto,
    indicador, painel, painelRecolhivel, contagemRegressiva, grafico, areas: AREAS, tons: TONS,
    esconderDica, dica: { esconder: esconderDica },
    campo, form, atalhos, celulaCsv,
    formatar: { data: formatarData, moeda: formatarMoeda, numero: formatarNumero, tamanho: formatarTamanho },
    // Aceitam o valor limpo ou com máscara; o CNPJ, letras minúsculas ou maiúsculas.
    validar: { cpf: (v) => cpfValido(String(v || '').replace(/\D/g, '')), cnpj: (v) => cnpjValido(String(v || '').toUpperCase().replace(/[^0-9A-Z]/g, '')), email: (v) => RE_EMAIL.test(String(v || '').trim()) },
};

export { D2UI };
export default D2UI;
if (typeof window !== 'undefined') {
    window.D2UI = D2UI;
    window.showNotification = (mensagem, tipo, titulo) => toast(mensagem, { tipo, titulo });
}
// A abertura não tem texto: pede já a fonte principal (a mesma do preload do index.html),
// para ela estar pronta no primeiro texto e o preload ser aproveitado.
if (typeof document !== 'undefined' && document.fonts && typeof document.fonts.load === 'function') {
    document.fonts.load('400 1em "IBM Plex Sans"', 'Ravi Developer').catch(() => {});
}
