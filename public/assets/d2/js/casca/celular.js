// Celular (abaixo de 760 px): menu em gaveta, janelas como folhas empilhadas,
// barra inferior, gesto de voltar pela borda esquerda e teclado virtual sem quebrar a tela.
import { Eventos } from '../eventos.js';
import { t } from './contexto.js';
import { Palco } from './palco.js';
import { D2UI } from '../d2ui.js';
import { h, ic, celular, movimentoReduzido } from './janela.js';

const BORDA_GESTO = 24;
const LIMIAR_GESTO = 90;
let opcoes = {};
let menuEl = null;
let veuEl = null;
let barraEl = null;
let principalEl = null;
let botoes = {};
let gavetaAberta = false;
let focoAntes = null;
const cancelar = [];

// Área de cada aba (cores.md, 3.8): o painel veste a dele; "janelas" veste a da janela em
// foco (atualizarBarra); as demais ficam na marca. Trocar de aba some com a dica aberta.
const AREA_ABA = { painel: 'painel' };
const esconderDica = () => { if (D2UI.esconderDica) D2UI.esconderDica(); };

function botaoBarra(id, icone, chave, fn) {
    const b = h('button', {
        type: 'button', class: 'barra-inferior-btn', 'data-acao': id, 'data-area': AREA_ABA[id] || null,
        onclick: (ev) => { esconderDica(); fn(ev); },
    },
        h('span', { class: 'barra-inferior-icone' }, ic(icone), h('span', { class: 'casca-selo', hidden: '' })),
        h('span', { class: 'barra-inferior-rotulo', texto: t(chave) }));
    b.dataset.chave = chave;
    return b;
}

function montarBarra() {
    barraEl.textContent = '';
    botoes = {
        painel: botaoBarra('painel', 'painel', 'casca.barra.painel', () => { fecharGaveta(); Palco.mostrarPainel(); }),
        busca: botaoBarra('busca', 'busca', 'casca.barra.buscar', () => { fecharGaveta(); if (opcoes.aoBusca) opcoes.aoBusca(); }),
        janelas: botaoBarra('janelas', 'lado-a-lado', 'casca.barra.janelas', (ev) => { fecharGaveta(); listarJanelas(ev.currentTarget); }),
        avisos: botaoBarra('avisos', 'sino', 'casca.barra.avisos', () => { fecharGaveta(); if (opcoes.aoNotificacoes) opcoes.aoNotificacoes(); }),
        menu: botaoBarra('menu', 'menu', 'casca.barra.menu', () => (gavetaAberta ? fecharGaveta() : abrirGaveta())),
    };
    botoes.janelas.querySelector('.casca-selo').classList.add('is-info');
    botoes.menu.setAttribute('aria-expanded', 'false');
    botoes.menu.setAttribute('aria-controls', menuEl.id || '');
    barraEl.setAttribute('aria-label', t('casca.barra.rotulo'));
    barraEl.append(...Object.values(botoes));
    atualizarBarra();
}

function seloDe(botao, n) {
    const s = botao.querySelector('.casca-selo');
    s.textContent = n > 99 ? '99+' : String(n);
    s.hidden = !n;
}

let avisosAtuais = 0;
export function atualizarBarra(dados = {}) {
    if (typeof dados.avisos === 'number') avisosAtuais = dados.avisos;
    if (!botoes.janelas) return;
    const total = Palco.janelas().filter((j) => !j.fundo).length;
    seloDe(botoes.janelas, total);
    seloDe(botoes.avisos, avisosAtuais);
    const focada = Palco.focada();
    botoes.painel.classList.toggle('is-ativo', !focada && !gavetaAberta);
    botoes.janelas.classList.toggle('is-ativo', Boolean(focada) && !gavetaAberta);
    const area = focada && focada.el ? focada.el.getAttribute('data-area') : null;
    if (area) botoes.janelas.setAttribute('data-area', area); else botoes.janelas.removeAttribute('data-area');
    botoes.menu.classList.toggle('is-ativo', gavetaAberta);
}

function listarJanelas(alvo) {
    const lista = Palco.janelas().filter((j) => !j.fundo);
    if (!lista.length) {
        D2UI.toast(t('casca.barra.sem_janelas'), { tipo: 'info', duracao: 2400 });
        return;
    }
    D2UI.menuContexto(alvo, [
        { grupo: t('casca.barra.janelas_abertas', { n: lista.length }) },
        ...lista.slice().reverse().map((j) => ({ icone: j.nomeIcone, rotulo: j.textoTitulo, fn: () => Palco.focar(j) })),
        'separador',
        { icone: 'fechar', rotulo: t('casca.organizar.fechar_todas'), perigo: true, fn: () => Palco.confirmarFecharTodas() },
    ]);
}

export function abrirGaveta() {
    if (!celular() || gavetaAberta) return;
    gavetaAberta = true;
    focoAntes = document.activeElement;
    document.documentElement.classList.add('gaveta-aberta');
    menuEl.classList.add('is-aberto');
    veuEl.hidden = false;
    requestAnimationFrame(() => veuEl.classList.add('is-visivel'));
    if (principalEl) principalEl.inert = true;
    if (barraEl) barraEl.inert = false;
    botoes.menu && botoes.menu.setAttribute('aria-expanded', 'true');
    const primeiro = menuEl.querySelector('.menu-item.is-ativo') || menuEl.querySelector('.menu-busca-campo');
    if (primeiro) setTimeout(() => primeiro.focus({ preventScroll: true }), 60);
    atualizarBarra();
    Eventos.emitir('gaveta.aberta', {});
}

export function fecharGaveta(devolverFoco = false) {
    if (!gavetaAberta) return;
    gavetaAberta = false;
    document.documentElement.classList.remove('gaveta-aberta');
    menuEl.classList.remove('is-aberto');
    menuEl.style.transform = '';
    veuEl.classList.remove('is-visivel');
    setTimeout(() => { if (!gavetaAberta) veuEl.hidden = true; }, movimentoReduzido() ? 0 : 260);
    if (principalEl) principalEl.inert = false;
    botoes.menu && botoes.menu.setAttribute('aria-expanded', 'false');
    if (devolverFoco && focoAntes && focoAntes.isConnected) focoAntes.focus({ preventScroll: true });
    atualizarBarra();
}

// Arrastar a gaveta para a esquerda fecha.
function gestoGaveta() {
    let inicio = null;
    menuEl.addEventListener('pointerdown', (ev) => {
        if (!gavetaAberta || ev.pointerType === 'mouse') return;
        inicio = { x: ev.clientX, y: ev.clientY, dx: 0, decidido: false, horizontal: false };
    });
    menuEl.addEventListener('pointermove', (ev) => {
        if (!inicio) return;
        const dx = ev.clientX - inicio.x;
        const dy = ev.clientY - inicio.y;
        if (!inicio.decidido && Math.hypot(dx, dy) > 8) { inicio.decidido = true; inicio.horizontal = Math.abs(dx) > Math.abs(dy) && dx < 0; }
        if (!inicio.horizontal) return;
        inicio.dx = Math.min(0, dx);
        menuEl.style.transition = 'none';
        menuEl.style.transform = `translate3d(${inicio.dx}px, 0, 0)`;
    });
    const soltar = () => {
        if (!inicio) return;
        const fechar = inicio.horizontal && inicio.dx < -70;
        menuEl.style.transition = '';
        menuEl.style.transform = '';
        inicio = null;
        if (fechar) fecharGaveta();
    };
    menuEl.addEventListener('pointerup', soltar);
    menuEl.addEventListener('pointercancel', soltar);
}

// Gesto de voltar: da borda esquerda para a direita fecha a folha de cima
// (ou abre o menu, se não houver folha aberta).
function gestoVoltar(palcoEl) {
    let g = null;
    palcoEl.addEventListener('pointerdown', (ev) => {
        if (!celular() || ev.pointerType === 'mouse' || ev.clientX > BORDA_GESTO || gavetaAberta) return;
        const j = Palco.focada();
        g = { x: ev.clientX, y: ev.clientY, dx: 0, j, decidido: false, horizontal: false };
    }, true);
    palcoEl.addEventListener('pointermove', (ev) => {
        if (!g) return;
        const dx = ev.clientX - g.x;
        const dy = ev.clientY - g.y;
        if (!g.decidido && Math.hypot(dx, dy) > 10) { g.decidido = true; g.horizontal = dx > 0 && Math.abs(dx) > Math.abs(dy) * 1.2; }
        if (!g.horizontal) return;
        ev.preventDefault();
        g.dx = Math.max(0, dx);
        if (g.j && g.j.el) {
            g.j.el.style.transition = 'none';
            g.j.el.style.transform = `translate3d(${g.dx}px, 0, 0)`;
        }
    }, { passive: false });
    const soltar = () => {
        if (!g) return;
        const { j, dx, horizontal } = g;
        g = null;
        if (j && j.el) { j.el.style.transition = ''; j.el.style.transform = ''; }
        if (!horizontal || dx < LIMIAR_GESTO) return;
        if (j) j.fechar();
        else abrirGaveta();
    };
    palcoEl.addEventListener('pointerup', soltar, true);
    palcoEl.addEventListener('pointercancel', soltar, true);
}

// Teclado virtual: a altura visível vira variável CSS e a barra inferior se esconde.
function acompanharTeclado() {
    const vv = window.visualViewport;
    if (!vv) return;
    const medir = () => {
        const oculto = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
        const aberto = celular() && oculto > 120;
        document.documentElement.style.setProperty('--casca-altura-visivel', Math.round(vv.height) + 'px');
        document.documentElement.classList.toggle('teclado-aberto', aberto);
    };
    vv.addEventListener('resize', medir);
    vv.addEventListener('scroll', medir);
    cancelar.push(() => { vv.removeEventListener('resize', medir); vv.removeEventListener('scroll', medir); });
    medir();
}

function escGaveta(ev) {
    if (ev.key === 'Escape' && gavetaAberta) { ev.preventDefault(); ev.stopPropagation(); fecharGaveta(true); }
}

// elementos: { menu, veu, barra, principal, palco }; o: { aoBusca, aoNotificacoes }.
export function iniciar(elementos, o = {}) {
    encerrar();
    opcoes = o;
    menuEl = elementos.menu;
    veuEl = elementos.veu;
    barraEl = elementos.barra;
    principalEl = elementos.principal;
    veuEl.hidden = true;
    veuEl.addEventListener('click', () => fecharGaveta(true));
    montarBarra();
    gestoGaveta();
    gestoVoltar(elementos.palco);
    if (elementos.palco) {
        elementos.palco.addEventListener('scroll', esconderDica, { capture: true, passive: true });
        cancelar.push(() => elementos.palco.removeEventListener('scroll', esconderDica, { capture: true }));
    }
    acompanharTeclado();
    document.addEventListener('keydown', escGaveta, true);
    const mq = window.matchMedia('(max-width: 759.98px)');
    const aoMudar = () => { if (!mq.matches) fecharGaveta(); atualizarBarra(); };
    mq.addEventListener('change', aoMudar);
    cancelar.push(
        () => mq.removeEventListener('change', aoMudar),
        () => document.removeEventListener('keydown', escGaveta, true),
        Eventos.ouvir('janela.aberta', () => atualizarBarra()),
        Eventos.ouvir('janela.fechada', () => atualizarBarra()),
        Eventos.ouvir('janela.foco', () => atualizarBarra()),
        Eventos.ouvir('palco.lista', () => atualizarBarra()),
        Eventos.ouvir('idioma.trocado', () => montarBarra()),
    );
}

export function encerrar() {
    fecharGaveta();
    while (cancelar.length) cancelar.pop()();
}

export const Celular = { iniciar, encerrar, abrirGaveta, fecharGaveta, atualizarBarra, gavetaAberta: () => gavetaAberta, ativo: celular };
export default Celular;
