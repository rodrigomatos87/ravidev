// Casca do painel do Ravi Developer: liga o D2UI (textos, sprite), o tema, o menu lateral
// (recolher no computador, gaveta no celular) e as notificações da barra. Módulo ES, sem build.
import { D2UI } from './d2ui.js';
import { Tema } from './tema.js';

const raiz = document.documentElement;
const cfg = window.RAVI_CASCA || {};
const textos = cfg.textos || {};

// Tradutor do D2UI: dicionário injetado pelo PHP (idioma ativo), plural por {n} e variáveis entre chaves.
function t(chave, params) {
    let s = textos[chave];
    if (params && typeof params.n === 'number' && params.n !== 1 && textos[chave + '.plural']) s = textos[chave + '.plural'];
    if (typeof s !== 'string') return chave;
    if (params) for (const [k, v] of Object.entries(params)) s = s.split('{' + k + '}').join(String(v));
    return s;
}

D2UI.configurar({ t, idioma: cfg.idioma || 'pt', sprite: cfg.sprite || '/assets/d2/icones/sprite.svg' });

// Tema: o tema-inicial.js já pintou; aqui só sincroniza o estado e o botão da barra.
Tema.iniciar();
raiz.setAttribute('data-tema-pref', Tema.preferencia());
const btnTema = document.getElementById('casca-tema');
if (btnTema) {
    const rotulo = { auto: 'Tema automático', claro: 'Tema claro', escuro: 'Tema escuro' };
    const marcar = () => {
        raiz.setAttribute('data-tema-pref', Tema.preferencia());
        btnTema.setAttribute('data-tooltip', rotulo[Tema.preferencia()] || 'Tema');
        btnTema.setAttribute('aria-label', rotulo[Tema.preferencia()] || 'Tema');
    };
    btnTema.addEventListener('click', () => { Tema.aplicar(Tema.proximo()); marcar(); });
    marcar();
}

// Menu lateral: recolhido aos ícones no computador (lembrado), gaveta no celular.
const menu = document.getElementById('casca-menu');
const veu = document.getElementById('casca-veu');
const CHAVE_MENU = 'ravidev.menu';
const celular = () => window.matchMedia('(max-width: 759.98px)').matches;
try { if (localStorage.getItem(CHAVE_MENU) === 'recolhido') raiz.classList.add('menu-recolhido'); } catch (e) { /* sem armazenamento */ }
const btnRecolher = document.getElementById('menu-recolher');
if (btnRecolher) btnRecolher.addEventListener('click', () => {
    const recolhido = raiz.classList.toggle('menu-recolhido');
    try { localStorage.setItem(CHAVE_MENU, recolhido ? 'recolhido' : 'aberto'); } catch (e) { /* idem */ }
});
function abrirGaveta(sim) {
    if (!menu) return;
    menu.classList.toggle('is-aberto', sim);
    if (veu) veu.classList.toggle('is-visivel', sim);
    raiz.classList.toggle('gaveta-aberta', sim);
    const btn = document.getElementById('casca-topo-menu');
    if (btn) btn.setAttribute('aria-expanded', sim ? 'true' : 'false');
    if (sim) { const foco = menu.querySelector('.menu-item.is-ativo, .menu-item'); if (foco) foco.focus({ preventScroll: true }); }
}
const btnGaveta = document.getElementById('casca-topo-menu');
if (btnGaveta) btnGaveta.addEventListener('click', () => abrirGaveta(!menu.classList.contains('is-aberto')));
if (veu) veu.addEventListener('click', () => abrirGaveta(false));
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && menu && menu.classList.contains('is-aberto')) abrirGaveta(false); });
if (menu) menu.addEventListener('click', (e) => { if (celular() && e.target.closest('a.menu-item')) abrirGaveta(false); });

// Mensagens flash do PHP viram toasts do d2.
for (const f of cfg.flash || []) D2UI.toast(f.texto, { tipo: f.tipo });

// Sair: formulário oculto com CSRF.
const btnSair = document.getElementById('menu-sair');
if (btnSair) btnSair.addEventListener('click', () => { const f = document.getElementById('form-sair'); if (f) f.submit(); });

// Administrador: alterna entre os próprios projetos e os de todos os usuários (menu lateral).
const btnTodos = document.getElementById('menu-todos-projetos');
if (btnTodos) btnTodos.addEventListener('click', async () => {
    const ligar = btnTodos.getAttribute('data-on') === '1' ? '0' : '1';
    btnTodos.disabled = true;
    try {
        const d = await chamar('/projects/all-users', { metodo: 'POST', formulario: { on: ligar } });
        if (d && d.ok !== false) { location.reload(); return; }
        throw new Error((d && d.error) || '');
    } catch (e) {
        btnTodos.disabled = false;
        D2UI.toast(e.message || (cfg.textos && cfg.textos['casca.erro_generico']) || 'Não foi possível trocar a visão.', { tipo: 'erro' });
    }
});

// Notificações da barra: tarefas concluídas ou com falha (mesma fonte do painel antigo).
(function notificacoes() {
    const btn = document.getElementById('casca-sino');
    const caixa = document.getElementById('casca-notif');
    const lista = document.getElementById('casca-notif-lista');
    const selo = document.getElementById('casca-notif-selo');
    if (!btn || !caixa || !lista) return;
    const CHAVE = 'ravi_notif_seen_at';
    const vistoEm = () => parseInt(localStorage.getItem(CHAVE) || '0', 10) || 0;
    const h = (tag, props = {}, ...filhos) => {
        const el = document.createElement(tag);
        for (const [k, v] of Object.entries(props)) { if (k === 'texto') el.textContent = v; else if (v !== null && v !== undefined) el.setAttribute(k, v); }
        for (const f of filhos) if (f) el.append(f);
        return el;
    };
    const ha = (ts) => {
        if (!ts) return '';
        const s = Math.max(0, Math.floor(Date.now() / 1000 - ts));
        if (s < 60) return t('d2.contagem.agora');
        if (s < 3600) return t('d2.contagem.ha', { tempo: t('d2.contagem.minutos', { n: Math.floor(s / 60) }) });
        if (s < 86400) return t('d2.contagem.ha', { tempo: t('d2.contagem.horas', { n: Math.floor(s / 3600) }) });
        return t('d2.contagem.ha', { tempo: t('d2.contagem.dias', { n: Math.floor(s / 86400) }) });
    };
    let itens = [];
    function desenhar() {
        lista.replaceChildren();
        if (!itens.length) { lista.append(h('div', { class: 'notif-vazio', texto: cfg.textoSemNotificacoes || 'Nenhuma tarefa recente.' })); return; }
        for (const n of itens) {
            const ok = n.status === 'completed';
            const sub = [n.project, ok ? (cfg.textoConcluida || 'Concluída') : (cfg.textoFalhou || 'Falhou'), ha(n.at_ts)].filter(Boolean).join(' · ');
            const corpo = h('span', { class: 'notif-item-corpo' }, h('span', { class: 'notif-item-titulo', texto: n.title || '' }), h('span', { class: 'notif-item-sub', texto: sub }));
            lista.append(h(n.url ? 'a' : 'div', { class: 'notif-item' + (ok ? '' : ' is-falha'), href: n.url || null }, h('span', { class: 'notif-item-ponto' }), corpo));
        }
    }
    function selar() {
        const n = itens.filter((i) => (i.at_ts || 0) > vistoEm()).length;
        if (!selo) return;
        selo.hidden = n === 0;
        selo.textContent = n > 9 ? '9+' : String(n);
    }
    function carregar() {
        return fetch('/dashboard/notifications', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((res) => { const d = res && res.data ? res.data : res; itens = (d && d.items) || []; desenhar(); selar(); })
            .catch(() => {});
    }
    const fechar = () => { caixa.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (!caixa.hidden) { fechar(); return; }
        caixa.hidden = false; btn.setAttribute('aria-expanded', 'true');
        carregar().then(() => { if (itens.length) localStorage.setItem(CHAVE, String(Math.floor(Date.now() / 1000))); selar(); });
    });
    document.addEventListener('click', (e) => { if (!caixa.contains(e.target) && e.target !== btn) fechar(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') fechar(); });
    carregar();
    setInterval(carregar, 60000);
}());

// Chamada JSON da casa: CSRF no cabeçalho, erro vira exceção com a mensagem do servidor.
export async function chamar(url, { metodo = 'GET', dados = null, formulario = null } = {}) {
    const cabecalhos = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': cfg.csrf || '', Accept: 'application/json' };
    let corpo = null;
    if (formulario) { corpo = formulario instanceof FormData ? formulario : new URLSearchParams(formulario); if (!(corpo instanceof FormData)) cabecalhos['Content-Type'] = 'application/x-www-form-urlencoded'; }
    else if (dados !== null) { cabecalhos['Content-Type'] = 'application/json'; corpo = JSON.stringify(dados); }
    const r = await fetch(url, { method: metodo, headers: cabecalhos, body: corpo, credentials: 'same-origin' });
    let json = null;
    try { json = await r.json(); } catch (e) { /* sem corpo JSON */ }
    if (!r.ok || (json && json.success === false)) {
        const msg = (json && (json.message || json.error)) || (cfg.textoErroGenerico || 'Não foi possível concluir.');
        const erro = new Error(msg); erro.status = r.status; erro.dados = json; throw erro;
    }
    return json && json.data !== undefined ? json.data : json;
}

window.Casca = { t, chamar, abrirGaveta, D2UI, Tema };
export { t, D2UI, Tema };
