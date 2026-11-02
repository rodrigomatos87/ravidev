// Tema claro, escuro ou automático (segue o sistema). O resolvido vai para
// html[data-theme]; a preferência fica no navegador e, logado, no servidor.
import { Eventos } from './eventos.js';

const TEMAS = ['claro', 'escuro', 'auto'];
const CHAVE_LOCAL = 'ravidev.tema';
// A tela da IDE (views/ide/workspace.php) lê 'ravi-theme' com dark/light: espelhamos o resolvido lá.
const CHAVE_IDE = 'ravi-theme';
const consulta = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

let preferencia = 'auto';
let resolvido = 'claro';
let persistir = null;
let esperaTransicao = 0;

function lerLocal() {
    try {
        const v = localStorage.getItem(CHAVE_LOCAL);
        return TEMAS.includes(v) ? v : null;
    } catch (e) { return null; }
}

function gravarLocal(v) {
    try { localStorage.setItem(CHAVE_LOCAL, v); } catch (e) { /* navegador sem armazenamento */ }
}

function resolver(pref) {
    if (pref === 'claro' || pref === 'escuro') return pref;
    return consulta && consulta.matches ? 'escuro' : 'claro';
}

// A cor da barra do navegador no celular acompanha o fundo do tema.
function atualizarCorNavegador() {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    const cor = getComputedStyle(document.documentElement).getPropertyValue('--d2-bg').trim();
    if (cor) meta.setAttribute('content', cor);
}

function marcar(novo, suave) {
    const raiz = document.documentElement;
    if (suave) {
        raiz.classList.add('tema-trocando');
        clearTimeout(esperaTransicao);
        esperaTransicao = setTimeout(() => raiz.classList.remove('tema-trocando'), 360);
    }
    raiz.setAttribute('data-theme', novo);
    raiz.style.colorScheme = novo === 'escuro' ? 'dark' : 'light';
    try { localStorage.setItem(CHAVE_IDE, novo === 'escuro' ? 'dark' : 'light'); } catch (e) { /* sem armazenamento */ }
    atualizarCorNavegador();
}

function aplicar(pref, opcoes = {}) {
    const p = TEMAS.includes(pref) ? pref : 'auto';
    const anterior = resolvido;
    const preferenciaAnterior = preferencia;
    preferencia = p;
    resolvido = resolver(p);
    gravarLocal(p);
    marcar(resolvido, opcoes.suave !== false && anterior !== resolvido);
    if (anterior !== resolvido || preferenciaAnterior !== p) {
        Eventos.emitir('tema.trocado', { tema: resolvido, preferencia: p });
    }
    if (opcoes.salvar !== false && preferenciaAnterior !== p && typeof persistir === 'function') {
        Promise.resolve(persistir(p)).catch(() => { /* a troca na tela vale mesmo sem salvar */ });
    }
    return resolvido;
}

// Primeira aplicação, sem transição: preferência do navegador ou automático.
function iniciar() {
    preferencia = lerLocal() || 'auto';
    resolvido = resolver(preferencia);
    marcar(resolvido, false);
}

if (consulta) {
    const mudouSistema = () => {
        if (preferencia !== 'auto') return;
        const novo = resolver('auto');
        if (novo === resolvido) return;
        resolvido = novo;
        marcar(novo, true);
        Eventos.emitir('tema.trocado', { tema: novo, preferencia });
    };
    if (consulta.addEventListener) consulta.addEventListener('change', mudouSistema);
}

// Ordem do botão de tema na barra: automático, claro, escuro.
function proximo() {
    return { auto: 'claro', claro: 'escuro', escuro: 'auto' }[preferencia] || 'auto';
}

export const Tema = {
    aplicar,
    iniciar,
    atual: () => resolvido,
    preferencia: () => preferencia,
    proximo,
    lista: () => [...TEMAS],
    definirPersistencia: (fn) => { persistir = fn; },
};
export default Tema;
