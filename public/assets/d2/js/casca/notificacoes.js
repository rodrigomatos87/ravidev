// Central de notificações: selo no sino e painel com as tarefas do chat que terminaram
// (concluídas ou com falha). Fonte: /dashboard/notifications, relida a cada minuto.
// Lida = terminou antes da última vez que o painel foi aberto (ravi_notif_seen_at).
import { Eventos } from '../eventos.js';
import { D2UI } from '../d2ui.js';
import { t, formatar, chamar } from './contexto.js';
import { h, ic, celular } from './janela.js';
import { Palco } from './palco.js';

const CHAVE_VISTO = 'ravi_notif_seen_at';
const INTERVALO = 60000;
let sino = null;
let seloEl = null;
let ativo = false;
let itens = [];
let carregado = false;
let erro = false;
let painel = null;
let corpo = null;
let leitura = 0;
let relogio = 0;

function vistoEm() {
    try { return parseInt(localStorage.getItem(CHAVE_VISTO) || '0', 10) || 0; } catch (e) { return 0; }
}

function marcarVisto(ts) {
    try { localStorage.setItem(CHAVE_VISTO, String(ts)); } catch (e) { /* armazenamento indisponível */ }
}

const lida = (n) => (Number(n.at_ts) || 0) <= vistoEm();

function naoLidas() {
    return itens.filter((n) => !lida(n)).length;
}

function atualizarSelo() {
    if (!sino) return;
    const n = naoLidas();
    seloEl.textContent = n > 99 ? '99+' : String(n);
    seloEl.hidden = n <= 0;
    const rotulo = n > 0 ? t('casca.notificacoes.rotulo_qtd', { n }) : t('casca.notificacoes.titulo');
    sino.setAttribute('aria-label', rotulo);
    sino.setAttribute('data-tooltip', rotulo);
    Eventos.emitir('notificacoes.contagem', { naoLidas: n, tarefas: 0 });
}

async function carregar() {
    const r = await chamar('/dashboard/notifications');
    const anteriores = new Set(itens.map((n) => chaveDe(n)));
    const novos = (r && Array.isArray(r.items) ? r.items : []);
    const primeira = !carregado;
    itens = novos;
    carregado = true;
    erro = false;
    atualizarSelo();
    // Terminou agora: aviso discreto (só depois da primeira leitura, nunca para o histórico).
    if (!primeira && !painel) {
        for (const n of novos) if (!anteriores.has(chaveDe(n)) && !lida(n)) avisar(n);
    }
}

function chaveDe(n) {
    return String(n.at_ts || '') + '|' + String(n.url || '') + '|' + String(n.title || '');
}

function abrirUrl(url) {
    if (!url) return;
    Palco.abrir(url);
}

function avisar(n) {
    const ok = n.status === 'completed';
    D2UI.toast(n.title || '', {
        tipo: ok ? 'ok' : 'erro', titulo: t(ok ? 'casca.notificacoes.concluida' : 'casca.notificacoes.falhou'),
        acao: n.url ? { rotulo: t('casca.notificacoes.abrir'), fn: () => abrirUrl(n.url) } : null,
    });
}

function tempoEl(ts) {
    if (!ts) return null;
    const ms = Number(ts) * 1000;
    return h('time', { class: 'notif-tempo', datetime: new Date(ms).toISOString(), 'data-tooltip': formatar.dataHora(ms), texto: formatar.relativo(ms) });
}

function itemNotificacao(n) {
    const ok = n.status === 'completed';
    const naoLida = !lida(n);
    const estado = t(ok ? 'casca.notificacoes.concluida' : 'casca.notificacoes.falhou');
    const linha = [n.project, estado].filter(Boolean).join(' · ');
    return h('li', { class: 'notif-li' },
        h('button', {
            type: 'button', class: 'notif-item' + (naoLida ? ' is-nao-lida' : '') + (ok ? '' : ' is-falha'),
            'aria-label': (naoLida ? t('casca.notificacoes.nao_lida') + ': ' : '') + (n.title || '') + ', ' + estado,
            onclick: () => { if (n.url) { fecharPainel(false); abrirUrl(n.url); } },
        },
            h('span', { class: 'notif-icone', 'aria-hidden': 'true' }, ic(ok ? 'check' : 'alerta')),
            h('span', { class: 'notif-textos' },
                h('span', { class: 'notif-titulo-linha' }, h('strong', { class: 'notif-titulo', texto: n.title || '' })),
                h('span', { class: 'notif-texto', texto: linha }),
                !ok && n.error ? h('span', { class: 'notif-texto', texto: String(n.error) }) : null,
                tempoEl(n.at_ts)),
            naoLida ? h('span', { class: 'notif-ponto', 'aria-hidden': 'true' }) : null));
}

function desenhar() {
    if (!painel || !corpo) return;
    painel.querySelector('.notif-ler-todas').hidden = naoLidas() <= 0;
    corpo.textContent = '';
    if (!carregado && !erro) { corpo.append(D2UI.esqueleto(4)); return; }
    if (erro && !carregado) {
        corpo.append(D2UI.vazio({ chave: 'casca.notificacoes.erro', icone: 'erro', acao: { rotulo: t('casca.tentar_novamente'), fn: () => { erro = false; desenhar(); ler(); } } }));
        return;
    }
    if (!itens.length) {
        corpo.append(D2UI.vazio({ chave: 'casca.notificacoes.vazio', icone: 'sino', texto: t('casca.notificacoes.vazio_texto') }));
        return;
    }
    corpo.append(h('ul', { class: 'notif-lista' }, ...itens.map(itemNotificacao)));
}

function marcarTodas() {
    const maior = itens.reduce((m, n) => Math.max(m, Number(n.at_ts) || 0), Math.floor(Date.now() / 1000));
    marcarVisto(maior);
    atualizarSelo();
    desenhar();
}

function ler() {
    return carregar().then(desenhar).catch(() => { erro = true; desenhar(); });
}

function posicionar() {
    if (!painel || !sino) return;
    if (celular()) { painel.style.top = ''; painel.style.right = ''; return; }
    const r = sino.getBoundingClientRect();
    painel.style.top = Math.round(r.bottom + 8) + 'px';
    painel.style.right = Math.max(8, Math.round(document.documentElement.clientWidth - r.right - 6)) + 'px';
}

function foraDoPainel(ev) {
    if (painel && !painel.contains(ev.target) && !sino.contains(ev.target)) fecharPainel(false);
}

function escPainel(ev) {
    if (ev.key === 'Escape' && painel) { ev.preventDefault(); ev.stopPropagation(); fecharPainel(); }
}

export function abrirPainel() {
    if (painel) { desenhar(); return; }
    const fechar = h('button', { type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma', 'aria-label': t('casca.fechar'), 'data-tooltip': t('casca.fechar'), onclick: () => fecharPainel() }, ic('fechar'));
    const lerTodas = h('button', { type: 'button', class: 'd2-btn d2-btn-fantasma notif-ler-todas', onclick: marcarTodas }, ic('check'), t('casca.notificacoes.ler_todas'));
    corpo = h('div', { class: 'notif-corpo', id: 'notif-corpo' });
    painel = h('section', { class: 'notif-painel', role: 'dialog', 'aria-label': t('casca.notificacoes.titulo') },
        h('header', { class: 'notif-cab' }, h('h2', { class: 'notif-cab-titulo', texto: t('casca.notificacoes.titulo') }), lerTodas, fechar),
        corpo);
    document.body.append(painel);
    posicionar();
    requestAnimationFrame(() => painel && painel.classList.add('is-aberto'));
    sino.setAttribute('aria-expanded', 'true');
    document.addEventListener('pointerdown', foraDoPainel, true);
    document.addEventListener('keydown', escPainel, true);
    window.addEventListener('resize', posicionar);
    desenhar();
    ler();
    clearInterval(relogio);
    relogio = setInterval(desenhar, 60000);
    Eventos.emitir('notificacoes.aberta', {});
}

export function fecharPainel(devolverFoco = true) {
    if (!painel) return;
    const el = painel;
    painel = null;
    corpo = null;
    clearInterval(relogio);
    document.removeEventListener('pointerdown', foraDoPainel, true);
    document.removeEventListener('keydown', escPainel, true);
    window.removeEventListener('resize', posicionar);
    el.classList.remove('is-aberto');
    el.classList.add('is-saindo');
    setTimeout(() => el.remove(), 180);
    if (sino) {
        sino.setAttribute('aria-expanded', 'false');
        if (devolverFoco) sino.focus({ preventScroll: true });
    }
}

// Liga a central ao botão do sino (a casca cria o botão com um selo dentro).
export function montar(botao) {
    sino = botao;
    seloEl = botao.querySelector('.casca-selo') || h('span', { class: 'casca-selo', hidden: '' });
    if (!seloEl.isConnected) botao.append(seloEl);
    botao.setAttribute('aria-haspopup', 'dialog');
    botao.setAttribute('aria-expanded', 'false');
    botao.addEventListener('click', () => (painel ? fecharPainel() : abrirPainel()));
    atualizarSelo();
}

export function iniciar() {
    if (ativo) return;
    ativo = true;
    carregar().catch(() => { /* o selo aparece na próxima leitura */ });
    leitura = setInterval(() => { if (!document.hidden) carregar().then(desenhar).catch(() => {}); }, INTERVALO);
}

export function encerrar() {
    ativo = false;
    clearInterval(leitura);
    fecharPainel(false);
    itens = [];
    carregado = false;
    atualizarSelo();
}

export const Notificacoes = { montar, iniciar, encerrar, abrirPainel, fecharPainel, naoLidas, ativo: () => ativo };
export default Notificacoes;
