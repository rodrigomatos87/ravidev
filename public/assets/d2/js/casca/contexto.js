// Contexto da casca do Ravi Developer: configuração injetada pelo layout (window.RAVI_CASCA),
// tradutor, idioma, formatação, preferências do usuário no servidor e a chamada JSON da casa.
// Faz o papel de idioma, sessão e API da casca, sem login próprio: a sessão
// é a do PHP e o dicionário já chega pronto no HTML.
import { Eventos } from '../eventos.js';

export const cfg = window.RAVI_CASCA || {};
const textos = cfg.textos || {};

export const IDIOMAS = ['pt', 'en', 'es'];
const LOCALIDADES = { pt: 'pt-BR', en: 'en-US', es: 'es-ES' };
const atual = IDIOMAS.includes(cfg.idioma) ? cfg.idioma : 'pt';

function localidade() {
    return LOCALIDADES[atual] || 'pt-BR';
}

const cacheFormatos = new Map();
function formato(tipo, opcoes) {
    const chave = tipo + '|' + JSON.stringify(opcoes);
    let f = cacheFormatos.get(chave);
    if (!f) {
        f = tipo === 'numero' ? new Intl.NumberFormat(localidade(), opcoes)
            : tipo === 'relativo' ? new Intl.RelativeTimeFormat(localidade(), opcoes)
                : new Intl.DateTimeFormat(localidade(), opcoes);
        cacheFormatos.set(chave, f);
    }
    return f;
}

function numero(n, casas) {
    const v = Number(n);
    if (!Number.isFinite(v)) return '';
    const o = casas === undefined ? { maximumFractionDigits: 2 } : { minimumFractionDigits: casas, maximumFractionDigits: casas };
    return formato('numero', { ...o, useGrouping: 'always' }).format(v);
}

function interpolar(texto, params) {
    if (!params) return texto;
    return texto.replace(/\{(\w+)\}/g, (m, k) => {
        const v = params[k];
        if (v === undefined || v === null) return m;
        if (typeof v === 'number' && Number.isFinite(v)) return numero(v);
        return String(v);
    });
}

// t('chave', {param}): plural em "chave.plural" quando n != 1; sem tradução, devolve a chave.
export function t(chave, params) {
    if (typeof chave !== 'string' || !chave) return '';
    let c = chave;
    if (params && typeof params.n === 'number' && params.n !== 1 && textos[chave + '.plural'] !== undefined) c = chave + '.plural';
    const s = textos[c];
    if (typeof s !== 'string') return chave;
    return interpolar(s, params);
}

function existe(chave) {
    return typeof textos[chave] === 'string';
}

// Trocar o idioma grava a preferência no servidor e recarrega: os textos do PHP (telas e menu)
// vêm traduzidos do servidor, então só a página nova fica inteira no idioma escolhido.
let guarda = null;
async function trocar(codigo) {
    const c = String(codigo || '').slice(0, 2).toLowerCase();
    if (!IDIOMAS.includes(c) || c === atual) return atual;
    if (guarda && (await guarda(c)) === false) return atual;
    await Preferencias.salvar('idioma', c);
    try { localStorage.setItem('ravidev.idioma', c); } catch (e) { /* sem armazenamento */ }
    Eventos.emitir('idioma.trocando', { codigo: c });
    location.reload();
    return c;
}

export const Idioma = {
    atual: () => atual,
    localidade,
    lista: () => [...IDIOMAS],
    existe,
    trocar,
    definirGuarda: (fn) => { guarda = typeof fn === 'function' ? fn : null; },
};

// Formatação no idioma atual (fuso do navegador).
function instante(valor) {
    let d = null;
    if (valor instanceof Date) d = valor;
    else if (typeof valor === 'number') d = new Date(valor);
    else if (typeof valor === 'string' && valor) d = new Date(valor);
    return d && !Number.isNaN(d.getTime()) ? d : null;
}

function data(valor) {
    const d = instante(valor);
    return d ? formato('data', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d) : '';
}

function dataHora(valor) {
    const d = instante(valor);
    return d ? formato('data', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(d) : '';
}

function isoLocal(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function hoje() {
    return isoLocal(new Date());
}

function relativo(valor) {
    const d = instante(valor);
    if (!d) return '';
    const rtf = formato('relativo', { numeric: 'auto' });
    const segundos = Math.round((d.getTime() - Date.now()) / 1000);
    const abs = Math.abs(segundos);
    if (abs < 45) return rtf.format(0, 'second');
    if (abs < 3600) return rtf.format(Math.round(segundos / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(segundos / 3600), 'hour');
    const ms = (iso) => { const [y, m, dd] = iso.split('-').map(Number); return Date.UTC(y, m - 1, dd); };
    const dias = Math.round((ms(isoLocal(d)) - ms(hoje())) / 86400000);
    if (Math.abs(dias) < 7) return rtf.format(dias, 'day');
    return data(d);
}

// Nome visível de uma tecla (no Mac, Ctrl vira ⌘ e Alt vira ⌥).
const NO_MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad|iPod/i.test((navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '');
const SIMBOLOS_MAC = { ctrl: '⌘', alt: '⌥', shift: '⇧' };
function tecla(nome) {
    const n = String(nome || '').trim();
    const baixo = n.toLowerCase();
    if (NO_MAC && SIMBOLOS_MAC[baixo]) return SIMBOLOS_MAC[baixo];
    const chave = 'casca.tecla.' + (baixo === 'escape' ? 'esc' : baixo);
    if (existe(chave)) return t(chave);
    return n.length === 1 ? n.toUpperCase() : n.charAt(0).toUpperCase() + n.slice(1);
}

function atalho(combo) {
    return String(combo || '').split('+').filter(Boolean).map(tecla).join(NO_MAC ? '' : '+');
}

export const formatar = { numero, data, dataHora, relativo, hoje, tecla, atalho };

// Chamada JSON da casa: CSRF no cabeçalho, erro vira exceção com a mensagem do servidor.
export async function chamar(url, { metodo = 'GET', dados = null, formulario = null, sinal = null } = {}) {
    const cabecalhos = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': cfg.csrf || '', Accept: 'application/json' };
    let corpo = null;
    if (formulario) {
        corpo = formulario instanceof FormData ? formulario : new URLSearchParams(formulario);
        if (!(corpo instanceof FormData)) cabecalhos['Content-Type'] = 'application/x-www-form-urlencoded';
    } else if (dados !== null) {
        cabecalhos['Content-Type'] = 'application/json';
        corpo = JSON.stringify(dados);
    }
    const r = await fetch(url, { method: metodo, headers: cabecalhos, body: corpo, credentials: 'same-origin', signal: sinal || undefined });
    let json = null;
    try { json = await r.json(); } catch (e) { /* sem corpo JSON */ }
    if (!r.ok || (json && json.success === false)) {
        const msg = (json && (json.message || json.error)) || t('casca.erro_generico');
        const erro = new Error(msg);
        erro.status = r.status;
        erro.dados = json;
        erro.mensagem = msg;
        throw erro;
    }
    return json && json.data !== undefined ? json.data : json;
}

// Envio que sobrevive ao fechamento da aba (disposição do palco ao sair).
export function enviarAoSair(url, dados) {
    try {
        fetch(url, {
            method: 'POST', keepalive: true, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf || '', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            body: JSON.stringify(dados),
        }).catch(() => {});
        return true;
    } catch (e) {
        return false;
    }
}

// Preferências do usuário (servidor, com espelho local para a próxima abertura ser imediata).
const prefs = cfg.preferencias && typeof cfg.preferencias === 'object' && !Array.isArray(cfg.preferencias) ? cfg.preferencias : {};
const CHAVE_DISPOSICAO = { palco: 'disposicao', nomeadas: 'disposicoes' };

async function salvar(chave, valor) {
    prefs[chave] = valor;
    try { localStorage.setItem('ravidev.pref.' + chave, JSON.stringify(valor)); } catch (e) { /* sem armazenamento */ }
    await chamar('/conta/preferencia', { metodo: 'POST', dados: { chave, valor } });
    return valor;
}

async function salvarDisposicao(tipo, valorOuFn) {
    const chave = CHAVE_DISPOSICAO[tipo] || 'disposicao';
    const atualValor = prefs[chave];
    const nova = typeof valorOuFn === 'function' ? valorOuFn(atualValor) : valorOuFn;
    try {
        return await salvar(chave, nova);
    } catch (e) {
        prefs[chave] = atualValor;
        throw e;
    }
}

export const Preferencias = {
    obter: () => prefs,
    salvar,
    salvarDisposicao,
    salvarFavoritos: (lista) => salvar('favoritos', lista),
    // Gravação no fechamento da aba (keepalive), sem esperar resposta.
    salvarAoSair: (chave, valor) => {
        prefs[chave] = valor;
        return enviarAoSair('/conta/preferencia', { chave, valor });
    },
};

// Usuário logado, como o layout entregou.
export const Usuario = {
    dados: () => cfg.usuario || {},
    nome: () => (cfg.usuario && cfg.usuario.nome) || '',
    admin: () => Boolean(cfg.usuario && cfg.usuario.admin),
};

export const appNome = () => cfg.appNome || 'Ravi Developer';
export const versao = () => cfg.versao || '';

// Antes de sair da página: callbacks síncronos (gravar disposição pendente).
const aoSair = new Set();
window.addEventListener('pagehide', () => { for (const fn of aoSair) { try { fn(); } catch (e) { /* nunca trava a saída */ } } });
export function antesDeSair(fn) {
    aoSair.add(fn);
    return () => aoSair.delete(fn);
}
