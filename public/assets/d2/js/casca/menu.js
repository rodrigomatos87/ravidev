// Menu lateral do painel: árvore montada a partir do menu que o servidor entrega
// (RAVI_CASCA.menu, já filtrado por permissão e traduzido), com busca interna, grupos,
// favoritos, selos e modo recolhido.
import { Eventos } from '../eventos.js';
import { D2UI } from '../d2ui.js';
import { t, cfg, chamar, Preferencias, appNome } from './contexto.js';
import { Palco } from './palco.js';
import { h, ic, celular, registrarArea } from './janela.js';

// Itens do servidor: { url, pagina, titulo, icone, area }. O id do item é o endereço.
const dadosMenu = cfg.menu || {};
const doServidor = (i, extra = {}) => ({
    id: i.url, url: i.url, icone: i.icone || 'grade', area: i.area || null, rotulo: i.titulo || i.url, ...extra,
});

// Grupo Projetos: a lista, cada projeto, o novo projeto e (administrador) a troca entre
// os próprios projetos e os de todos os usuários.
function grupoProjetos(itemLista) {
    const projetos = Array.isArray(dadosMenu.projetos) ? dadosMenu.projetos : [];
    const area = (itemLista && itemLista.area) || 'projetos';
    const itensGrupo = [
        { id: '/projects', url: '/projects', icone: 'lista', area, rotulo: t('menu.projetos.todos') },
        ...projetos.map((p) => ({
            id: '/projects/' + p.uuid, url: '/projects/' + p.uuid, icone: 'documento', area,
            rotulo: p.nome || t('menu.projetos.sem_nome'), dono: p.dono || null, trabalhando: Boolean(p.trabalhando),
            projeto: true,
        })),
        { id: '/projects/create', url: '/projects/create', icone: 'mais', area, rotulo: t('menu.projetos.novo') },
    ];
    if (cfg.usuario && cfg.usuario.admin) {
        itensGrupo.push({
            id: 'acao:todos-projetos', icone: 'usuarios', area, acao: alternarTodosProjetos, semFavorito: true,
            rotulo: t(dadosMenu.todos ? 'menu.projetos.so_meus' : 'menu.projetos.de_todos'),
        });
    }
    return { grupo: 'projetos', icone: (itemLista && itemLista.icone) || 'lista', area, rotulo: (itemLista && itemLista.titulo) || t('menu.grupo.projetos'), itens: itensGrupo };
}

function construirArvore() {
    const arvore = [];
    const secoes = Array.isArray(dadosMenu.secoes) ? dadosMenu.secoes : [];
    secoes.forEach((sec, k) => {
        const lista = Array.isArray(sec.itens) ? sec.itens : [];
        if (k === 0) {
            // Navegação: itens no topo, com Projetos como grupo no lugar da lista.
            for (const i of lista) {
                if (i.url === '/projects') arvore.push(grupoProjetos(i));
                else arvore.push(doServidor(i));
            }
            return;
        }
        if (!lista.length) return;
        const area = (lista[0] && lista[0].area) || 'configuracoes';
        arvore.push({
            grupo: 'secao' + k, icone: k === 1 ? 'plataforma' : 'grade', area, rotulo: sec.titulo || '',
            itens: lista.map((i) => doServidor(i)),
        });
    });
    return arvore;
}

export const ARVORE = construirArvore();

async function alternarTodosProjetos() {
    try {
        await chamar('/projects/all-users', { metodo: 'POST', formulario: { on: dadosMenu.todos ? '0' : '1' } });
        location.reload();
    } catch (e) {
        D2UI.toast(e.mensagem || t('casca.erro_generico'), { tipo: 'erro' });
    }
}

// Lista plana de itens, com o grupo de cada um (busca e favoritos usam).
export function itens() {
    const saida = [];
    for (const n of ARVORE) {
        if (n.itens) for (const i of n.itens) saida.push({ ...i, grupo: n.grupo, grupoRotulo: n.rotulo });
        else saida.push({ ...n, grupo: null, grupoRotulo: null });
    }
    return saida;
}

export function item(id) {
    return itens().find((i) => i.id === id) || null;
}

// Item do menu que corresponde a um endereço: o de caminho mais longo que é prefixo dele.
export function itemDoEndereco(url) {
    const caminho = String(url || '').split('?')[0].replace(/\/+$/, '') || '/';
    let melhor = null;
    for (const i of itens()) {
        if (!i.url) continue;
        const base = i.url.split('?')[0].replace(/\/+$/, '');
        if (caminho === base || caminho.startsWith(base + '/')) {
            if (!melhor || base.length > melhor.url.split('?')[0].replace(/\/+$/, '').length) melhor = i;
        }
    }
    return melhor;
}

// Área do sistema que uma janela representa, pelo item do menu do endereço dela.
export function areaDoEndereco(modulo, params = {}) {
    const i = itemDoEndereco(params && params.url);
    return i ? i.area : null;
}
registrarArea(areaDoEndereco);

export function visivel() {
    return true;
}

export function abrirItem(i, opcoes = {}) {
    if (!i) return null;
    if (typeof i.acao === 'function') return i.acao();
    return Palco.abrir(i.url, { ...opcoes });
}

// Texto sem acento e em minúsculas, para comparar buscas.
export function normalizar(s) {
    return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
}

const CHAVE_RECOLHIDO = 'ravidev.menu.recolhido';
const CHAVE_GRUPOS = 'ravidev.menu.grupos';
const selos = new Map();
let raiz = null;
let lista = null;
let campoBusca = null;
let termo = '';
let ativo = null;
let aoEscolher = null;
let flutuante = null;
const cancelar = [];

function lerJson(chave, padrao) {
    try { const v = JSON.parse(localStorage.getItem(chave)); return v === null ? padrao : v; } catch (e) { return padrao; }
}

function gravarJson(chave, valor) {
    try { localStorage.setItem(chave, JSON.stringify(valor)); } catch (e) { /* armazenamento indisponível */ }
}

// Grupos abertos: preferência do usuário no servidor, com espelho local.
function gruposAbertos() {
    const p = Preferencias.obter().grupos_abertos;
    if (Array.isArray(p)) return [...p];
    const v = lerJson(CHAVE_GRUPOS, null);
    return Array.isArray(v) ? v : ['projetos', 'secao1'];
}

function favoritos() {
    const f = Preferencias.obter().favoritos;
    return Array.isArray(f) ? f.filter((id) => item(id)) : [];
}

// iPad em retrato (760 a 1024 px): o menu nasce recolhido para o palco ter largura,
// com preferência própria (recolher ou abrir na horizontal não muda a do retrato).
const MQ_RETRATO = '(min-width: 760px) and (max-width: 1024px) and (orientation: portrait)';
const CHAVE_RECOLHIDO_RETRATO = CHAVE_RECOLHIDO + '.retrato';
const retrato = () => window.matchMedia(MQ_RETRATO).matches;
function preferenciaRecolhido() {
    if (retrato()) return Boolean(lerJson(CHAVE_RECOLHIDO_RETRATO, true));
    const p = Preferencias.obter().menu_recolhido;
    if (p === '1' || p === 1 || p === true) return true;
    if (p === '0' || p === 0 || p === false) return false;
    return Boolean(lerJson(CHAVE_RECOLHIDO, false));
}

export function recolhido() {
    return !celular() && preferenciaRecolhido();
}

function rotulo(i) {
    return i.rotulo || '';
}

function combina(i) {
    if (!termo) return true;
    const alvo = normalizar(rotulo(i) + ' ' + (i.grupoRotulo || '') + ' ' + (i.dono || ''));
    return normalizar(termo).split(/\s+/).every((p) => alvo.includes(p));
}

// Item ativo: o de endereço mais próximo do endereço da janela em foco; sem janela, o Início.
function eAtivo(i) {
    if (!i.url) return false;
    if (!ativo) return i.url === '/dashboard';
    const alvo = itemDoEndereco(ativo.params && ativo.params.url);
    return Boolean(alvo && alvo.id === i.id);
}

function seloEl(id) {
    const i = item(id);
    if (i && i.trabalhando) {
        return h('span', { class: 'menu-selo is-vivo', role: 'img', 'aria-label': t('menu.projetos.trabalhando'), 'data-tooltip': t('menu.projetos.trabalhando') });
    }
    const v = selos.get(id);
    if (!v) return null;
    const texto = typeof v === 'number' ? (v > 99 ? '99+' : String(v)) : String(v);
    return h('span', { class: 'menu-selo', texto, 'aria-label': t('menu.selo', { n: typeof v === 'number' ? v : 1 }) });
}

function itemEl(i, { comGrupo = false } = {}) {
    const fav = favoritos().includes(i.id);
    const nome = rotulo(i);
    const botao = h('button', {
        type: 'button', class: 'menu-item' + (eAtivo(i) ? ' is-ativo' : ''), 'data-id': i.id,
        'aria-current': eAtivo(i) ? 'page' : null,
        'data-tooltip': recolhido() ? nome : null,
        onclick: () => escolher(i),
    },
        h('span', { class: 'd2-pastilha menu-item-pastilha', 'aria-hidden': 'true' }, ic(i.icone, 'menu-item-icone')),
        h('span', { class: 'menu-item-texto' },
            h('span', { class: 'menu-item-nome', texto: nome }),
            comGrupo && i.grupoRotulo ? h('span', { class: 'menu-item-grupo', texto: i.grupoRotulo }) : (i.dono ? h('span', { class: 'menu-item-grupo', texto: i.dono }) : null)),
        seloEl(i.id));
    const estrela = i.semFavorito ? null : h('button', {
        type: 'button', class: 'menu-fav' + (fav ? ' is-favorito' : ''), 'aria-pressed': fav ? 'true' : 'false',
        'aria-label': t(fav ? 'menu.favorito.remover' : 'menu.favorito.adicionar', { nome }),
        'data-tooltip': fav ? 'menu.favorito.remover_curto' : 'menu.favorito.adicionar_curto',
        onclick: (ev) => { ev.stopPropagation(); alternarFavorito(i.id); },
    }, ic(fav ? 'estrela-cheia' : 'estrela'));
    return h('li', { class: 'menu-li', 'data-area': i.area || null }, botao, estrela);
}

function grupoEl(g, abertos) {
    const filhos = g.itens.filter(visivel);
    if (!filhos.length) return null;
    const aberto = abertos.includes(g.grupo);
    const temAtivo = filhos.some(eAtivo);
    const somaSelos = filhos.reduce((s, i) => s + (typeof selos.get(i.id) === 'number' ? selos.get(i.id) : 0), 0);
    const idLista = 'menu-g-' + g.grupo;
    const btn = h('button', {
        type: 'button', class: 'menu-grupo-btn' + (temAtivo ? ' tem-ativo' : ''),
        'aria-expanded': recolhido() ? null : String(aberto), 'aria-controls': recolhido() ? null : idLista,
        'aria-haspopup': recolhido() ? 'menu' : null,
        'data-tooltip': recolhido() ? g.rotulo : null,
        onclick: (ev) => (recolhido() ? abrirFlutuante(g, ev.currentTarget) : alternarGrupo(g.grupo)),
    },
        h('span', { class: 'd2-pastilha menu-item-pastilha', 'aria-hidden': 'true' }, ic(g.icone, 'menu-item-icone')),
        h('span', { class: 'menu-item-texto' }, h('span', { class: 'menu-item-nome', texto: g.rotulo })),
        somaSelos && (!aberto || recolhido()) ? h('span', { class: 'menu-selo is-resumo', texto: somaSelos > 99 ? '99+' : String(somaSelos) }) : null,
        ic('chevron-baixo', 'menu-grupo-seta'));
    const ul = h('ul', { class: 'menu-grupo-itens', id: idLista, hidden: !aberto || recolhido() ? '' : null },
        ...filhos.map((i) => itemEl(i)));
    return h('li', { class: 'menu-grupo' + (aberto ? ' is-aberto' : ''), 'data-grupo': g.grupo, 'data-area': g.area || null }, btn, ul);
}

// Menu recolhido: a lista do grupo abre ao lado do trilho, alinhada ao ícone, sem
// cobrir os outros ícones. O componente posiciona embaixo do alvo; aqui a posição é
// refeita depois dele (mesmo quadro) na abertura, na rolagem e no redimensionamento.
function abrirFlutuante(g, alvo) {
    const filhos = g.itens.filter(visivel);
    flutuante = D2UI.menuContexto(alvo, [
        { grupo: g.rotulo },
        ...filhos.map((i) => ({ icone: i.icone, rotulo: rotulo(i), fn: () => escolher(i) })),
    ]);
    if (!flutuante || !flutuante.el) return;
    const el = flutuante.el;
    el.classList.add('menu-flutuante');
    const lateral = () => {
        if (!el.isConnected || !alvo.isConnected) { soltar(); return; }
        const r = alvo.getBoundingClientRect();
        const m = 8;
        const alt = el.offsetHeight;
        const larg = el.offsetWidth;
        const vw = document.documentElement.clientWidth;
        el.classList.remove('is-acima');
        el.style.left = Math.round(Math.min(r.right + m, vw - larg - m)) + 'px';
        el.style.top = Math.round(Math.max(m, Math.min(r.top - 4, window.innerHeight - alt - m))) + 'px';
    };
    let quadro = 0;
    const agendar = () => { cancelAnimationFrame(quadro); quadro = requestAnimationFrame(lateral); };
    const soltar = () => {
        cancelAnimationFrame(quadro);
        window.removeEventListener('scroll', agendar, true);
        window.removeEventListener('resize', agendar);
    };
    lateral();
    setTimeout(() => {
        if (!el.isConnected) return;
        window.addEventListener('scroll', agendar, true);
        window.addEventListener('resize', agendar);
    }, 0);
}

function desenhar() {
    if (!lista) return;
    const foco = document.activeElement && lista.contains(document.activeElement) ? document.activeElement.dataset.id || document.activeElement.closest('[data-grupo]')?.dataset.grupo : null;
    lista.textContent = '';
    raiz.classList.toggle('is-recolhido', recolhido());
    if (termo) {
        const achados = itens().filter((i) => visivel(i) && combina(i));
        if (!achados.length) {
            lista.append(h('li', { class: 'menu-vazio' }, ic('busca'), h('span', { texto: t('menu.busca.vazio', { termo }) })));
        } else {
            lista.append(...achados.map((i) => itemEl(i, { comGrupo: true })));
        }
        requestAnimationFrame(marcarCortados);
        return;
    }
    const favs = favoritos().map(item).filter((i) => i && visivel(i));
    if (favs.length && !recolhido()) {
        lista.append(h('li', { class: 'menu-secao', texto: t('menu.favoritos') }), ...favs.map((i) => itemEl(i, { comGrupo: true })));
        lista.append(h('li', { class: 'menu-secao', texto: t('menu.navegacao') }));
    }
    const abertos = gruposAbertos();
    for (const n of ARVORE) {
        const el = n.itens ? grupoEl(n, abertos) : (visivel(n) ? itemEl(n) : null);
        if (el) lista.append(el);
    }
    if (foco) {
        const alvo = lista.querySelector(`[data-id="${CSS.escape(foco)}"]`) || lista.querySelector(`[data-grupo="${CSS.escape(foco)}"] > .menu-grupo-btn`);
        if (alvo) alvo.focus({ preventScroll: true });
    }
    requestAnimationFrame(marcarCortados);
}

// Nome cortado pela largura do menu ganha a dica com o nome inteiro.
function marcarCortados() {
    if (!lista || recolhido()) return;
    for (const n of lista.querySelectorAll('.menu-item-nome')) {
        const b = n.closest('.menu-item');
        if (b && n.scrollWidth > n.clientWidth + 1) b.setAttribute('data-tooltip', n.textContent);
    }
}

function alternarGrupo(id) {
    const abertos = gruposAbertos();
    const i = abertos.indexOf(id);
    if (i >= 0) abertos.splice(i, 1);
    else abertos.push(id);
    gravarJson(CHAVE_GRUPOS, abertos);
    Preferencias.salvar('grupos_abertos', abertos).catch(() => {});
    desenhar();
}

async function alternarFavorito(id) {
    const atual = favoritos();
    const eraFavorito = atual.includes(id);
    const nova = eraFavorito ? atual.filter((x) => x !== id) : [...atual, id];
    try {
        await Preferencias.salvarFavoritos(nova);
        D2UI.toast(t(eraFavorito ? 'menu.favorito.removido' : 'menu.favorito.adicionado', { nome: rotulo(item(id) || {}) }), { tipo: 'ok', duracao: 2400 });
    } catch (e) {
        Preferencias.obter().favoritos = atual;
        D2UI.toast(e.mensagem || t('casca.erro.inesperado'), { tipo: 'erro' });
    }
    desenhar();
}

function escolher(i) {
    if (termo && campoBusca) {
        termo = '';
        campoBusca.value = '';
        desenhar();
    }
    abrirItem(i);
    if (aoEscolher) aoEscolher(i);
}

function marcarAtivo() {
    if (!lista) return;
    for (const b of lista.querySelectorAll('.menu-item')) {
        const i = item(b.dataset.id);
        const sim = Boolean(i && eAtivo(i));
        b.classList.toggle('is-ativo', sim);
        if (sim) b.setAttribute('aria-current', 'page');
        else b.removeAttribute('aria-current');
    }
    for (const g of lista.querySelectorAll('.menu-grupo')) {
        const n = ARVORE.find((x) => x.grupo === g.dataset.grupo);
        const btn = g.querySelector('.menu-grupo-btn');
        if (n && btn) btn.classList.toggle('tem-ativo', n.itens.some((i) => visivel(i) && eAtivo(i)));
    }
}

// Setas percorrem o menu como uma lista; Home e End vão às pontas.
function teclado(ev) {
    const alvos = [...lista.querySelectorAll('.menu-item, .menu-grupo-btn')].filter((b) => b.offsetParent !== null);
    const i = alvos.indexOf(document.activeElement);
    let novo = null;
    if (ev.key === 'ArrowDown') novo = alvos[Math.min(alvos.length - 1, i + 1)];
    else if (ev.key === 'ArrowUp') novo = i <= 0 ? campoBusca : alvos[i - 1];
    else if (ev.key === 'Home') novo = alvos[0];
    else if (ev.key === 'End') novo = alvos[alvos.length - 1];
    else if ((ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') && document.activeElement.classList.contains('menu-grupo-btn') && !recolhido()) {
        const g = document.activeElement.closest('.menu-grupo').dataset.grupo;
        const aberto = gruposAbertos().includes(g);
        if ((ev.key === 'ArrowRight') !== aberto) alternarGrupo(g);
        ev.preventDefault();
        return;
    }
    if (novo) {
        ev.preventDefault();
        novo.focus();
    }
}

function tecladoBusca(ev) {
    if (ev.key === 'ArrowDown') {
        const primeiro = lista.querySelector('.menu-item, .menu-grupo-btn');
        if (primeiro) { ev.preventDefault(); primeiro.focus(); }
    } else if (ev.key === 'Enter') {
        const primeiro = termo ? lista.querySelector('.menu-item') : null;
        if (primeiro) { ev.preventDefault(); primeiro.click(); }
    } else if (ev.key === 'Escape' && campoBusca.value) {
        ev.preventDefault();
        ev.stopPropagation();
        campoBusca.value = '';
        termo = '';
        desenhar();
    }
}

export function alternarRecolhido(valor) {
    const novo = valor === undefined ? !preferenciaRecolhido() : Boolean(valor);
    gravarJson(retrato() ? CHAVE_RECOLHIDO_RETRATO : CHAVE_RECOLHIDO, novo);
    if (!retrato()) Preferencias.salvar('menu_recolhido', novo ? '1' : '0').catch(() => {});
    aplicarRecolhido();
}

function aplicarRecolhido() {
    const r = preferenciaRecolhido();
    document.documentElement.classList.toggle('menu-recolhido', r);
    desenhar();
    rotularRecolher();
    Eventos.emitir('menu.recolhido', { recolhido: r });
}

let btnRecolher = null;
function rotularRecolher() {
    if (!btnRecolher) return;
    const r = recolhido();
    const chave = r ? 'menu.expandir' : 'menu.recolher';
    btnRecolher.setAttribute('aria-label', t(chave));
    btnRecolher.setAttribute('data-tooltip', chave);
    btnRecolher.setAttribute('aria-expanded', String(!r));
}

// Monta o menu dentro de elMenu. opcoes.aoEscolher: chamado depois de abrir um item
// (o celular fecha a gaveta).
export function montar(elMenu, opcoes = {}) {
    desmontar();
    raiz = elMenu;
    aoEscolher = opcoes.aoEscolher || null;
    raiz.textContent = '';
    raiz.classList.add('menu');
    raiz.setAttribute('aria-label', t('menu.rotulo'));
    document.documentElement.classList.toggle('menu-recolhido', preferenciaRecolhido());
    btnRecolher = h('button', { type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma menu-recolher', onclick: () => alternarRecolhido() }, ic('chevron-esquerda'));
    const marca = h('button', {
        type: 'button', class: 'menu-marca', 'aria-label': t('menu.ir_painel'), 'data-tooltip': t('menu.ir_painel'),
        onclick: () => { Palco.mostrarPainel(); if (aoEscolher) aoEscolher(null); },
    }, h('img', { class: 'menu-marca-icone', src: '/assets/images/logo.svg', alt: '', width: 30, height: 30, decoding: 'async' }),
    h('span', { class: 'menu-marca-nome' }, h('span', { class: 'menu-marca-texto', texto: appNome() })));
    campoBusca = h('input', {
        type: 'search', class: 'menu-busca-campo', placeholder: t('menu.busca.indicacao'), 'aria-label': t('menu.busca.rotulo'),
        autocomplete: 'off', spellcheck: 'false', enterkeyhint: 'go',
        oninput: () => { termo = campoBusca.value.trim(); desenhar(); },
        onkeydown: tecladoBusca,
    });
    const busca = h('div', { class: 'menu-busca', role: 'search' }, ic('busca', 'menu-busca-icone'), campoBusca);
    lista = h('ul', { class: 'menu-lista', onkeydown: teclado });
    const rolagem = h('div', { class: 'menu-rolagem' }, lista);
    raiz.append(h('div', { class: 'menu-topo' }, marca, btnRecolher), busca, rolagem);
    rotularRecolher();
    desenhar();
    cancelar.push(
        Eventos.ouvir('janela.foco', ({ janela }) => { ativo = janela && !janela.fundo ? janela : null; marcarAtivo(); }),
        Eventos.ouvir('palco.painel', () => { ativo = null; marcarAtivo(); }),
        Eventos.ouvir('janela.endereco', ({ janela }) => { if (janela && janela === ativo) marcarAtivo(); }),
        Eventos.ouvir('idioma.trocado', () => {
            raiz.setAttribute('aria-label', t('menu.rotulo'));
            campoBusca.placeholder = t('menu.busca.indicacao');
            campoBusca.setAttribute('aria-label', t('menu.busca.rotulo'));
            marca.setAttribute('aria-label', t('menu.ir_painel'));
            marca.setAttribute('data-tooltip', t('menu.ir_painel'));
            rotularRecolher();
            desenhar();
        }),
        Eventos.ouvir('palco.modo', () => { desenhar(); rotularRecolher(); }),
    );
    // Girar o iPad troca entre as duas preferências.
    const mq = window.matchMedia(MQ_RETRATO);
    const aoGirar = () => aplicarRecolhido();
    mq.addEventListener('change', aoGirar);
    cancelar.push(() => mq.removeEventListener('change', aoGirar));
}

export function desmontar() {
    while (cancelar.length) cancelar.pop()();
    if (flutuante && flutuante.fechar) flutuante.fechar();
    flutuante = null;
    raiz = null;
    lista = null;
    campoBusca = null;
    termo = '';
    ativo = null;
}

// Selo de um item (número ou texto curto); 0, null ou vazio remove.
export function selo(id, valor) {
    if (!valor) selos.delete(id);
    else selos.set(id, valor);
    desenhar();
}

export function focarBusca() {
    if (!campoBusca) return;
    if (recolhido()) alternarRecolhido(false);
    campoBusca.focus();
    campoBusca.select();
}

export const Menu = {
    ARVORE, itens, item, itemDoEndereco, visivel, abrirItem, normalizar,
    montar, desmontar, redesenhar: desenhar, selo, focarBusca, recolhido, alternarRecolhido,
};
export default Menu;
