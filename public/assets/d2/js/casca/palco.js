// Palco: área de trabalho onde as janelas abrem por cima do painel principal.
// Foco e empilhamento, limite de janelas, encaixe em metades e quartos,
// minimizar com liberação do DOM, disposição salva e disposições nomeadas.
import { Eventos } from '../eventos.js';
import { D2UI } from '../d2ui.js';
import { t, Idioma, Preferencias, cfg, appNome } from './contexto.js';
import { Janela, h, ic, celular, movimentoReduzido, esconderDicas } from './janela.js';
import { montarEndereco } from './roteador.js';
import { Telas } from './telas.js';

const LIMITE = 12;
const MARGEM = 8;
const GRADE_MIN = { largura: 300, altura: 220 };
const RE_GRADE = /^grade-(\d)-(\d)-(\d)-(\d)$/;
const BORDA_ENCAIXE = 16;
const LIBERAR_MINIMIZADA_MS = 10 * 60 * 1000;
const MAX_NOMEADAS = 10;

let raiz = null;
let bandeja = null;
let previa = null;
let fundo = null;
let lista = [];
let focadaAtual = null;
let contadorAbertura = 0;
let restaurando = false;
let esperaSalvar = 0;
let modoCelular = false;
let independente = false;

// Endereço de tela: caminho e query no próprio domínio (sem hash). Fora disso, nada abre.
export function normalizarUrl(url) {
    try {
        const u = new URL(String(url || ''), location.origin);
        if (u.origin !== location.origin) return null;
        return u.pathname + u.search;
    } catch (e) {
        return null;
    }
}

// O painel inicial é o fundo do palco, não uma janela.
export function ePainel(url) {
    const u = String(url || '').split('?')[0].replace(/\/+$/, '');
    return u === '' || u === '/dashboard';
}

// Páginas com layout próprio (IDE, chat, preview): a casca navega de verdade.
export function paginaCheia(url) {
    const u = String(url || '');
    return (cfg.paginasCheias || []).some((p) => {
        const base = p.replace(/\/+$/, '');
        return u === base || u.startsWith(base + '/') || u.startsWith(base + '?');
    });
}

function abertas() {
    return lista.filter((j) => !j.fechada);
}

function visiveis() {
    return lista.filter((j) => !j.fechada && !j.minimizada);
}

function tamanhoArea() {
    if (!raiz) return { largura: window.innerWidth, altura: window.innerHeight };
    return { largura: raiz.clientWidth || window.innerWidth, altura: raiz.clientHeight || window.innerHeight };
}

function retArea() {
    return raiz ? raiz.getBoundingClientRect() : new DOMRect(0, 0, window.innerWidth, window.innerHeight);
}

// Metades lado a lado só quando cada uma tem largura de trabalho; num palco estreito
// (tablet em pé, menu aberto) as zonas de coluna viram faixas na largura inteira.
const LARGURA_METADE = 420;
function cabemMetades(a = tamanhoArea()) {
    return Math.floor((a.largura - 3 * MARGEM) / 2) >= LARGURA_METADE;
}

function retZona(zona) {
    const a = tamanhoArea();
    const m = MARGEM;
    const meiaL = Math.floor((a.largura - 3 * m) / 2);
    const meiaA = Math.floor((a.altura - 3 * m) / 2);
    const cheiaA = a.altura - 2 * m;
    const cheiaL = a.largura - 2 * m;
    const grade = RE_GRADE.exec(zona || '');
    if (grade) return retCelula(a, ...grade.slice(1).map(Number));
    if (!cabemMetades(a)) {
        switch (zona) {
            // Lado a lado num palco estreito (tablet): uma faixa em cima e outra embaixo,
            // nunca as duas empilhadas no mesmo lugar.
            case 'esquerda': case 'sup-esq': case 'sup-dir': return { x: m, y: m, largura: cheiaL, altura: meiaA };
            case 'direita': case 'inf-esq': case 'inf-dir': return { x: m, y: 2 * m + meiaA, largura: cheiaL, altura: a.altura - 3 * m - meiaA };
            default: break;
        }
    }
    switch (zona) {
        case 'esquerda': return { x: m, y: m, largura: meiaL, altura: cheiaA };
        case 'direita': return { x: 2 * m + meiaL, y: m, largura: a.largura - 3 * m - meiaL, altura: cheiaA };
        case 'sup-esq': return { x: m, y: m, largura: meiaL, altura: meiaA };
        case 'sup-dir': return { x: 2 * m + meiaL, y: m, largura: a.largura - 3 * m - meiaL, altura: meiaA };
        case 'inf-esq': return { x: m, y: 2 * m + meiaA, largura: meiaL, altura: a.altura - 3 * m - meiaA };
        case 'inf-dir': return { x: 2 * m + meiaL, y: 2 * m + meiaA, largura: a.largura - 3 * m - meiaL, altura: a.altura - 3 * m - meiaA };
        default: return { x: 0, y: 0, largura: a.largura, altura: a.altura };
    }
}

// Zona de encaixe sob o ponteiro durante o arrasto (bordas e cantos do palco).
function zonaEncaixe(x, y) {
    const r = retArea();
    const canto = Math.min(120, r.height / 4);
    const esq = x <= r.left + BORDA_ENCAIXE;
    const dir = x >= r.right - BORDA_ENCAIXE;
    const topo = y <= r.top + BORDA_ENCAIXE;
    if (esq) return y <= r.top + canto ? 'sup-esq' : (y >= r.bottom - canto ? 'inf-esq' : 'esquerda');
    if (dir) return y <= r.top + canto ? 'sup-dir' : (y >= r.bottom - canto ? 'inf-dir' : 'direita');
    if (topo) {
        if (x <= r.left + canto) return 'sup-esq';
        if (x >= r.right - canto) return 'sup-dir';
        return 'max';
    }
    return null;
}

function mostrarEncaixe(zona) {
    if (!previa) return;
    if (!zona) {
        previa.classList.remove('is-visivel');
        return;
    }
    const r = retZona(zona);
    previa.style.width = r.largura + 'px';
    previa.style.height = r.altura + 'px';
    previa.style.transform = `translate3d(${r.x}px, ${r.y}px, 0)`;
    previa.classList.add('is-visivel');
}

// Empilhamento: ordem da lista; as fixadas ficam sempre por cima das demais.
function reempilhar() {
    const normais = lista.filter((j) => !j.fixa);
    const fixas = lista.filter((j) => j.fixa);
    normais.forEach((j, i) => { j.el.style.zIndex = String(10 + i); });
    fixas.forEach((j, i) => { j.el.style.zIndex = String(60 + i); });
}

function tituloDocumento() {
    const nome = appNome();
    const j = focadaAtual && !focadaAtual.fechada && !focadaAtual.minimizada ? focadaAtual : null;
    document.title = j && j.textoTitulo ? `${j.textoTitulo} · ${nome}` : nome;
}

function focar(j, opcoes = {}) {
    if (!j || j.fechada) return;
    if (j.fundo) {
        if (focadaAtual && focadaAtual.el) focadaAtual.el.classList.remove('is-focada');
        focadaAtual = null;
        tituloDocumento();
        Eventos.emitir('janela.foco', { janela: null });
        return;
    }
    if (j.minimizada) restaurarJanela(j);
    const mudou = focadaAtual !== j;
    lista = lista.filter((x) => x !== j);
    lista.push(j);
    reempilhar();
    for (const x of lista) x.el.classList.toggle('is-focada', x === j);
    focadaAtual = j;
    if (!opcoes.semFoco && !j.el.contains(document.activeElement)) j.el.focus({ preventScroll: true });
    tituloDocumento();
    if (mudou) Eventos.emitir('janela.foco', { janela: j });
}

function focarTopo() {
    const v = visiveis();
    const topo = v[v.length - 1];
    if (topo) focar(topo);
    else {
        focadaAtual = null;
        tituloDocumento();
        Eventos.emitir('janela.foco', { janela: null });
        if (fundo && fundo.el.isConnected) fundo.corpo.focus({ preventScroll: true });
    }
}

// Alterna entre as janelas (Ctrl+Tab e Alt+J); com voltar, na ordem inversa.
function alternar(voltar = false) {
    const ordem = abertas();
    if (!ordem.length) return;
    if (voltar) {
        focar(ordem[0]);
        return;
    }
    const atual = ordem[ordem.length - 1];
    const proxima = ordem.length > 1 ? ordem[ordem.length - 2] : atual;
    if (proxima.minimizada || proxima !== atual) {
        lista = lista.filter((x) => x !== atual);
        lista.unshift(atual);
    }
    focar(proxima);
}

function janelaMudou(j) {
    if (j.fundo) return;
    atualizarBandeja();
    if (j === focadaAtual) tituloDocumento();
    Eventos.emitir('palco.janela', { janela: j });
}

// Barra das minimizadas: botão por janela, com ícone e título.
function atualizarBandeja() {
    if (!bandeja) return;
    const min = lista.filter((j) => j.minimizada && !j.fechada);
    const itens = min.map((j) => {
        const restaurar = h('button', {
            type: 'button', class: 'palco-bandeja-item', dados: { janela: j.id },
            'aria-label': t('casca.bandeja.restaurar', { titulo: j.textoTitulo }), 'data-tooltip': j.textoSubtitulo ? `${j.textoTitulo}: ${j.textoSubtitulo}` : j.textoTitulo,
            onclick: () => focar(j),
        }, ic(j.nomeIcone), h('span', { class: 'palco-bandeja-texto', texto: j.textoTitulo }));
        const fechar = h('button', {
            type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma d2-iconbtn-sm palco-bandeja-x',
            'aria-label': t('casca.bandeja.fechar', { titulo: j.textoTitulo }), 'data-tooltip': t('casca.janela.fechar'),
            onclick: () => j.fechar(),
        }, ic('fechar'));
        return h('li', { class: 'palco-bandeja-li', 'data-area': j.el.getAttribute('data-area') }, restaurar, fechar);
    });
    bandeja.lista.replaceChildren(...itens);
    bandeja.el.classList.toggle('is-visivel', itens.length > 0);
    bandeja.el.setAttribute('aria-hidden', itens.length ? 'false' : 'true');
    Eventos.emitir('palco.lista', { total: abertas().length, minimizadas: min.length });
}

function itemBandeja(j) {
    return bandeja ? bandeja.lista.querySelector(`[data-janela="${j.id}"]`) : null;
}

function minimizar(j) {
    if (!j || j.fechada || j.minimizada || j.fundo) return;
    esconderDicas();
    j.minimizada = true;
    j.el.classList.remove('is-focada');
    atualizarBandeja();
    const alvo = itemBandeja(j);
    const sair = () => {
        j.el.classList.remove('is-minimizando');
        j.el.style.translate = '';
        j.el.style.scale = '';
        if (j.minimizada) j.el.remove();
    };
    if (alvo && !modoCelular && !movimentoReduzido() && j.el.isConnected) {
        const de = j.el.getBoundingClientRect();
        const para = alvo.getBoundingClientRect();
        j.el.classList.add('is-minimizando');
        j.el.style.transformOrigin = '0 0';
        j.el.style.translate = `${Math.round(para.left - de.left)}px ${Math.round(para.top - de.top)}px`;
        j.el.style.scale = `${Math.max(0.05, para.width / de.width)} ${Math.max(0.05, para.height / de.height)}`;
        setTimeout(sair, 240);
    } else {
        sair();
    }
    clearTimeout(j.esperaLiberar);
    j.esperaLiberar = setTimeout(() => liberar(j), LIBERAR_MINIMIZADA_MS);
    if (focadaAtual === j) focarTopo();
    agendarSalvar();
}

// Minimizada há muito tempo e sem nada por salvar: solta o conteúdo da memória.
function liberar(j) {
    if (!j.minimizada || j.fechada || j.guardas.size) return;
    j.limpar();
    j.corpo.replaceChildren();
    j.precisaRemontar = true;
}

function restaurarJanela(j) {
    if (!j.minimizada) return;
    clearTimeout(j.esperaLiberar);
    j.minimizada = false;
    const alvo = itemBandeja(j);
    const origem = alvo ? alvo.getBoundingClientRect() : null;
    raiz.append(j.el);
    if (!modoCelular) j.pintar(j.ret);
    if (origem && !modoCelular && !movimentoReduzido()) {
        const para = j.el.getBoundingClientRect();
        j.el.style.transformOrigin = '0 0';
        j.el.style.translate = `${Math.round(origem.left - para.left)}px ${Math.round(origem.top - para.top)}px`;
        j.el.style.scale = `${Math.max(0.05, origem.width / para.width)} ${Math.max(0.05, origem.height / para.height)}`;
        j.el.getBoundingClientRect();
        j.el.classList.add('is-restaurando');
        j.el.style.translate = '';
        j.el.style.scale = '';
        setTimeout(() => j.el.classList.remove('is-restaurando'), 260);
    }
    if (j.precisaRemontar) {
        j.precisaRemontar = false;
        j.montar();
    }
    atualizarBandeja();
    agendarSalvar();
}

function removerJanela(j, opcoes = {}) {
    if (j.fechada) return;
    clearTimeout(j.esperaLiberar);
    const eraFocada = focadaAtual === j;
    lista = lista.filter((x) => x !== j);
    j.fechada = true;
    if (eraFocada) focadaAtual = null;
    const terminar = () => j.destruir();
    // Movimento reduzido também passa pelo is-saindo: o CSS tira o deslocamento e deixa o esmaecimento.
    if (opcoes.semAnimar || j.minimizada || !j.el.isConnected) terminar();
    else {
        j.el.classList.add('is-saindo');
        setTimeout(terminar, movimentoReduzido() ? 130 : 170);
    }
    atualizarBandeja();
    reempilhar();
    if (eraFocada && !opcoes.semFocar) focarTopo();
    Eventos.emitir('janela.fechada', { janela: j });
    agendarSalvar();
}

function encaixar(j, zona) {
    if (!j || j.fundo || !zona) return;
    esconderDicas();
    if (j.minimizada) restaurarJanela(j);
    if (!j.zona && !j.maximizada) j.retAntes = { ...j.ret };
    // Maximizar a partir de um encaixe guarda o encaixe: restaurar volta a ele.
    if (zona === 'max') { if (j.zona && j.zona !== 'max') j.zonaAntes = j.zona; } else j.zonaAntes = null;
    j.zona = zona;
    j.marcarMaximizada(zona === 'max');
    j.aplicarRet(retZona(zona), { animar: true, limitar: false });
    agendarSalvar();
}

function alternarMaximizar(j) {
    if (!j || j.fundo) return;
    esconderDicas();
    if (j.maximizada) {
        const zonaAntes = j.zonaAntes;
        if (zonaAntes && zonaAntes !== 'max') {
            j.zona = null;
            encaixar(j, zonaAntes);
            focar(j);
            return;
        }
        j.zona = null;
        j.marcarMaximizada(false);
        j.aplicarRet(j.limitarDentro(j.retAntes || retPadrao(j.def)), { animar: true, limitar: false });
        agendarSalvar();
    } else {
        encaixar(j, 'max');
    }
    focar(j);
}

function ladoALado(a, b) {
    if (!a || !b || a === b || a.fechada || b.fechada) return;
    if (modoCelular) {
        focar(b);
        return;
    }
    encaixar(a, 'esquerda');
    encaixar(b, 'direita');
    focar(a, { semFoco: true });
    focar(b);
}

// Célula da grade: "grade-<posição na linha>-<células na linha>-<linha>-<linhas>".
// Fica gravada na janela como zona, então a grade se refaz quando o palco muda de tamanho.
function retCelula(a, i, n, lin, linhas) {
    const m = MARGEM;
    // Grade gravada num palco mais largo: sem espaço para as colunas, cada célula
    // ocupa a linha inteira em vez de espremer a tela abaixo do mínimo.
    if (n > 1 && Math.floor((a.largura - (n + 1) * m) / n) < GRADE_MIN.largura) { i = 0; n = 1; }
    const larg = Math.floor((a.largura - (n + 1) * m) / n);
    const alt = Math.floor((a.altura - (linhas + 1) * m) / linhas);
    const x = m + i * (larg + m);
    const y = m + lin * (alt + m);
    return {
        x, y,
        largura: i === n - 1 ? a.largura - m - x : larg,
        altura: lin === linhas - 1 ? a.altura - m - y : alt,
    };
}

// Em grade: toda janela visível ganha uma célula. O que não cabe com folga
// (células menores que GRADE_MIN) vai para a barra, começando pelas menos usadas.
function organizarQuartos() {
    if (modoCelular) return;
    let v = visiveis();
    if (!v.length) return;
    const a = tamanhoArea();
    const m = MARGEM;
    const maxCols = Math.max(1, Math.floor((a.largura - m) / (GRADE_MIN.largura + m)));
    const maxLins = Math.max(1, Math.floor((a.altura - m) / (GRADE_MIN.altura + m)));
    const sobra = v.length - maxCols * maxLins;
    if (sobra > 0) {
        const fora = v.filter((j) => !j.fixa).slice(0, sobra);
        fora.forEach((j) => minimizar(j));
        v = visiveis();
    }
    const n = v.length;
    if (n === 1) return encaixar(v[0], 'max');
    if (n === 2 && cabemMetades(a)) return ladoALado(v[0], v[1]);
    if (n === 3 && cabemMetades(a) && maxLins >= 2) {
        encaixar(v[0], 'esquerda');
        encaixar(v[1], 'sup-dir');
        encaixar(v[2], 'inf-dir');
        return;
    }
    let cols = Math.min(maxCols, Math.ceil(Math.sqrt(n)));
    if (Math.ceil(n / cols) > maxLins) cols = Math.min(maxCols, Math.ceil(n / maxLins));
    const linhas = Math.ceil(n / cols);
    v.forEach((j, k) => {
        const lin = Math.floor(k / cols);
        const naLinha = lin === linhas - 1 ? n - lin * cols : cols;
        encaixar(j, `grade-${k - lin * cols}-${naLinha}-${lin}-${linhas}`);
    });
}

// Cascata: da janela de baixo para a de cima, um degrau fixo de 32 px a partir do
// canto superior esquerdo, sem depender da altura de cada tela. Cada janela encolhe o
// necessário para caber a partir do seu degrau (o degrau nunca é sacrificado); passada
// a oitava, a série recomeça um pouco à direita para nenhuma cobrir outra por inteiro.
const DEGRAU_CASCATA = 32;
const MAX_DEGRAUS = 8;
function organizarCascata() {
    if (modoCelular) return;
    const v = visiveis();
    if (!v.length) return;
    const a = tamanhoArea();
    const degraus = (Math.min(v.length, MAX_DEGRAUS) - 1) * DEGRAU_CASCATA;
    v.forEach((j, k) => {
        const passo = (k % MAX_DEGRAUS) * DEGRAU_CASCATA;
        const serie = Math.floor(k / MAX_DEGRAUS) * DEGRAU_CASCATA * 2;
        const x = MARGEM + passo + serie;
        const y = MARGEM + passo;
        const minL = Math.min(j.minimo.largura, a.largura - 2 * MARGEM);
        const minA = Math.min(j.minimo.altura, a.altura - 2 * MARGEM);
        const largura = Math.max(minL, Math.min(j.def.largura || 760, a.largura - 2 * MARGEM - degraus - serie));
        const altura = Math.max(minA, Math.min(j.def.altura || 560, a.altura - 2 * MARGEM - degraus));
        j.zona = null;
        j.zonaAntes = null;
        j.marcarMaximizada(false);
        j.aplicarRet({
            x: Math.max(MARGEM, Math.min(a.largura - largura - MARGEM, x)),
            y: Math.max(MARGEM, Math.min(a.altura - altura - MARGEM, y)),
            largura: Math.round(largura), altura: Math.round(altura),
        }, { animar: true });
    });
    agendarSalvar();
}

function minimizarTodas() {
    for (const j of visiveis()) if (!j.fixa) minimizar(j);
}

async function fecharOutras(manter) {
    for (const j of abertas()) if (j !== manter) await j.fechar();
}

async function fecharTodas() {
    for (const j of abertas()) await j.fechar();
}

// Tamanho padrão da tela, em cascata: a nova janela desce um degrau (32 px) sobre a
// última aberta na mesma região; sem espaço para descer, recomeça do ponto base.
// Nunca cai exatamente sobre outra janela livre.
function retPadrao(def = {}, tamanho = null) {
    const a = tamanhoArea();
    // Reserva espaço para alguns degraus: a janela encolhe (até o mínimo da tela) para a
    // cascata descer na diagonal mesmo numa área pequena.
    const folga = DEGRAU_CASCATA * 3;
    const minL = Math.min(def.minLargura || 320, a.largura - 2 * MARGEM);
    const minA = Math.min(def.minAltura || 240, a.altura - 2 * MARGEM);
    const largura = Math.round(Math.max(minL, Math.min((tamanho && tamanho.largura) || def.largura || 760, a.largura - 2 * MARGEM - folga)));
    const altura = Math.round(Math.max(minA, Math.min((tamanho && tamanho.altura) || def.altura || 560, a.altura - 2 * MARGEM - folga)));
    // Sem folga para a margem, a janela encosta na borda em vez de passar do palco.
    const maxX = Math.max(0, Math.min(Math.max(MARGEM, a.largura - largura - MARGEM), a.largura - largura));
    const maxY = Math.max(0, Math.min(Math.max(MARGEM, a.altura - altura - MARGEM), a.altura - altura));
    const cabe = (x, y) => x <= maxX && y <= maxY;
    const baseX = Math.round(Math.max(MARGEM, Math.min(maxX, (a.largura - largura) / 2 - 84)));
    const baseY = Math.round(Math.max(MARGEM, Math.min(maxY, (a.altura - altura) / 2 - 56)));
    const regiao = DEGRAU_CASCATA * MAX_DEGRAUS;
    const livres = visiveis().filter((j) => !j.zona && !j.maximizada && !j.fundo);
    const vizinhas = livres
        .filter((j) => Math.abs(j.ret.x - baseX) <= regiao && Math.abs(j.ret.y - baseY) <= regiao)
        .sort((p, q) => (p.ordemAbertura || 0) - (q.ordemAbertura || 0));
    const ultima = vizinhas[vizinhas.length - 1];
    let x = baseX;
    let y = baseY;
    if (ultima) {
        x = Math.round(ultima.ret.x) + DEGRAU_CASCATA;
        y = Math.round(ultima.ret.y) + DEGRAU_CASCATA;
        if (!cabe(x, y)) { x = baseX; y = baseY; }
    }
    const ocupado = (px, py) => livres.some((j) => Math.abs(j.ret.x - px) < 4 && Math.abs(j.ret.y - py) < 4);
    for (let i = 0; i < LIMITE && ocupado(x, y); i++) {
        if (cabe(x + DEGRAU_CASCATA, y + DEGRAU_CASCATA)) { x += DEGRAU_CASCATA; y += DEGRAU_CASCATA; }
        else { x = Math.min(maxX, baseX + 14 * (i + 1)); y = baseY; }
    }
    return { x: Math.min(x, maxX), y: Math.min(y, maxY), largura, altura };
}

function avisoLimite() {
    const antiga = abertas().find((j) => !j.fixa);
    D2UI.toast(t('casca.palco.limite', { n: LIMITE }), {
        tipo: 'aviso',
        titulo: t('casca.palco.limite_titulo'),
        acao: antiga ? { rotulo: t('casca.palco.fechar_antiga', { titulo: antiga.textoTitulo }), fn: () => antiga.fechar() } : null,
    });
}

// Abre a tela do endereço numa janela; se já houver janela com o mesmo endereço, só foca.
// opcoes: aoLado, substituir (janela), tamanho {largura, altura}, ret, semFocar,
// semHistorico, semMontar e aguardar (montagem).
async function abrir(endereco, opcoes = {}) {
    if (!raiz) return null;
    const url = normalizarUrl(endereco);
    if (!url) return null;
    if (ePainel(url)) {
        mostrarPainel();
        return fundo;
    }
    if (paginaCheia(url)) {
        location.href = url;
        return null;
    }
    const origem = opcoes.origem || focadaAtual;
    const def = Telas.definicao(url);
    const p = { url };
    const chave = 'tela/url/' + url;
    const substituir = opcoes.substituir && !opcoes.substituir.fechada && lista.includes(opcoes.substituir) ? opcoes.substituir : null;
    const existente = abertas().find((j) => j.chave() === chave);
    if (existente) {
        if (substituir && substituir !== existente) removerJanela(substituir, { semFocar: true });
        if (!opcoes.semFocar) focar(existente);
        if (opcoes.recarregar) existente.remontar();
        return existente;
    }
    if (!substituir && abertas().length >= LIMITE) {
        avisoLimite();
        return null;
    }
    const j = new Janela(Palco, { modulo: 'tela', tela: 'url', params: p, def });
    j.ordemAbertura = ++contadorAbertura;
    if (opcoes.ret) j.ret = { ...opcoes.ret };
    else if (substituir) {
        j.ret = { ...substituir.ret };
        j.zona = substituir.zona;
        j.retAntes = substituir.retAntes;
        j.zonaAntes = substituir.zonaAntes;
    } else {
        j.ret = retPadrao(def, opcoes.tamanho);
    }
    if (opcoes.inicial) j.inicial = opcoes.inicial;
    lista.push(j);
    raiz.append(j.el);
    j.aplicarRet(j.ret, { limitar: !j.zona });
    if (substituir) {
        j.marcarMaximizada(substituir.maximizada);
        if (substituir.fixa) j.alternarFixa(true);
        removerJanela(substituir, { semAnimar: true, semFocar: true });
    } else if (!restaurando) {
        j.el.classList.add('is-entrando');
        requestAnimationFrame(() => requestAnimationFrame(() => j.el.classList.remove('is-entrando')));
    }
    Eventos.emitir('janela.aberta', { janela: j, historico: !opcoes.semHistorico && !restaurando });
    if (!opcoes.semFocar) focar(j);
    else reempilhar();
    const largo = tamanhoArea().largura >= 2 * Math.max(j.minimo.largura, LARGURA_METADE) + 3 * MARGEM;
    if (opcoes.aoLado && !substituir && !modoCelular && largo && origem && !origem.fundo && !origem.fechada && origem !== j) {
        ladoALado(origem, j);
    }
    atualizarBandeja();
    agendarSalvar();
    if (opcoes.semMontar) j.precisaRemontar = true;
    else if (opcoes.aguardar === false) j.montar();
    else await j.montar();
    return j;
}

// A tela da janela navegou para outro endereço (link interno, resposta de formulário):
// a janela passa a ser a do endereço novo, no mesmo lugar.
function trocarUrl(j, endereco) {
    const url = normalizarUrl(endereco);
    if (!j || j.fundo || j.fechada || !url || url === j.params.url) return;
    j.params = { ...j.params, url };
    janelaMudou(j);
    Eventos.emitir('janela.endereco', { janela: j });
    agendarSalvar();
}

// O painel é o fundo do palco: mostrar o painel é limpar a mesa.
function mostrarPainel() {
    if (!raiz) return;
    for (const j of visiveis()) minimizar(j);
    if (fundo) {
        focar(fundo);
        fundo.corpo.scrollTo({ top: 0, behavior: movimentoReduzido() ? 'auto' : 'smooth' });
        fundo.corpo.focus({ preventScroll: true });
    }
    Eventos.emitir('palco.painel', {});
}

// Fundo: a tela do painel inicial (/dashboard), montada como as janelas.
async function montarFundo(inicial = null) {
    if (fundo) {
        fundo.destruir();
        fundo = null;
    }
    const def = Telas.definicao('/dashboard');
    fundo = new Janela(Palco, { modulo: 'tela', tela: 'url', params: { url: '/dashboard' }, def, fundo: true });
    if (inicial) fundo.inicial = inicial;
    if (!raiz) return;
    raiz.prepend(fundo.el);
    await fundo.montar();
}

// Disposição: janelas na ordem de empilhamento, com retângulo em frações do
// palco (vale para qualquer tamanho de tela).
const fracao = (v, total) => Math.round((v / Math.max(1, total)) * 10000) / 10000;

function retFracoes(r, a) {
    return [fracao(r.x, a.largura), fracao(r.y, a.altura), fracao(r.largura, a.largura), fracao(r.altura, a.altura)];
}

// Ao reabrir uma disposição, cada janela volta inteira dentro da área, mesmo
// que tenha sido guardada parcialmente fora dela ou numa tela maior.
function retDeFracoes(v, a) {
    if (!Array.isArray(v) || v.length !== 4 || !v.every((n) => typeof n === 'number' && Number.isFinite(n))) return null;
    const largura = Math.min(Math.max(0, v[2] * a.largura), a.largura);
    const altura = Math.min(Math.max(0, v[3] * a.altura), a.altura);
    return {
        x: Math.min(Math.max(0, v[0] * a.largura), Math.max(0, a.largura - largura)),
        y: Math.min(Math.max(0, v[1] * a.altura), Math.max(0, a.altura - altura)),
        largura,
        altura,
    };
}

// Título trocado pelo módulo (ex.: nome do cliente) vai junto na disposição, para a
// janela que volta minimizada (sem montar) não aparecer na barra com o título genérico.
const RE_CHAVE = /^[a-z0-9_]+(\.[a-z0-9_]+)+$/;
function tituloGravavel(j) {
    const k = j.chaveTitulo;
    if (k && j.textoTitulo === k.texto) {
        const vars = varsSeguras(k.vars);
        return vars ? { chave: k.chave, vars } : { chave: k.chave };
    }
    if (j.textoTitulo && j.textoTitulo !== j.tituloDaDef) return { texto: j.textoTitulo.slice(0, 200) };
    return null;
}
function varsSeguras(vars) {
    if (!vars || typeof vars !== 'object') return null;
    const saida = {};
    for (const [k, v] of Object.entries(vars).slice(0, 10)) {
        if (!/^[a-z0-9_]{1,30}$/i.test(k)) continue;
        if (typeof v === 'number' && Number.isFinite(v)) saida[k] = v;
        else if (typeof v === 'string') saida[k] = v.slice(0, 200);
    }
    return Object.keys(saida).length ? saida : null;
}
function aplicarTituloGravado(j, titulo) {
    if (!titulo || typeof titulo !== 'object') return;
    if (typeof titulo.chave === 'string' && RE_CHAVE.test(titulo.chave) && Idioma.existe(titulo.chave)) {
        j.tituloPorChave(titulo.chave, varsSeguras(titulo.vars) || undefined);
    } else if (typeof titulo.texto === 'string' && titulo.texto.trim()) {
        j.titulo(titulo.texto.slice(0, 200));
    }
}

function disposicao() {
    const a = tamanhoArea();
    const js = abertas();
    return {
        v: 1,
        janelas: js.map((j) => {
            const item = { params: { url: String(j.params.url || '').slice(0, 400) }, ret: retFracoes(j.ret, a) };
            if (j.zona) item.zona = j.zona;
            if (j.retAntes) item.antes = retFracoes(j.retAntes, a);
            if (j.zona === 'max' && j.zonaAntes) item.zona_antes = j.zonaAntes;
            if (j.minimizada) item.minimizada = true;
            if (j.fixa) item.fixa = true;
            const titulo = tituloGravavel(j);
            if (titulo) item.titulo = titulo;
            return item;
        }),
        foco: focadaAtual ? js.indexOf(focadaAtual) : -1,
    };
}

const ZONAS = ['esquerda', 'direita', 'sup-esq', 'sup-dir', 'inf-esq', 'inf-dir', 'max'];

async function restaurar(d, opcoes = {}) {
    if (!raiz || !d || !Array.isArray(d.janelas)) return [];
    restaurando = true;
    const criadas = [];
    let descartadas = 0;
    try {
        if (opcoes.fecharAtuais !== false) for (const j of abertas()) removerJanela(j, { semAnimar: true, semFocar: true });
        const a = tamanhoArea();
        // O foco gravado é a posição na lista gravada; item inválido pulado não pode deslocá-lo.
        const porPosicao = new Map();
        for (const [posicao, item] of d.janelas.slice(0, LIMITE).entries()) {
            const bruta = item && item.params && typeof item.params.url === 'string' && item.params.url.startsWith('/') ? item.params.url : '';
            const url = bruta ? normalizarUrl(bruta) : null;
            if (!url || ePainel(url) || paginaCheia(url)) { descartadas++; continue; }
            const ret = retDeFracoes(item.ret, a);
            const j = await abrir(url, {
                ret: ret || undefined, semFocar: true, aguardar: false, semMontar: Boolean(item.minimizada),
            });
            if (!j || j.fundo) continue;
            j.retAntes = retDeFracoes(item.antes, a);
            if (ZONAS.includes(item.zona) || RE_GRADE.test(String(item.zona || ''))) {
                j.zona = item.zona;
                j.marcarMaximizada(item.zona === 'max');
                j.aplicarRet(retZona(item.zona), { limitar: false });
                const za = String(item.zona_antes || '');
                if (item.zona === 'max' && za !== 'max' && (ZONAS.includes(za) || RE_GRADE.test(za))) j.zonaAntes = za;
            }
            if (item.fixa) j.alternarFixa(true);
            if (item.minimizada) {
                aplicarTituloGravado(j, item.titulo);
                j.minimizada = true;
                j.el.remove();
                j.esperaLiberar = 0;
            }
            criadas.push(j);
            porPosicao.set(posicao, j);
        }
        const alvo = Number.isInteger(d.foco) ? porPosicao.get(d.foco) : null;
        if (alvo && !alvo.minimizada) focar(alvo);
        else focarTopo();
        atualizarBandeja();
    } finally {
        restaurando = false;
    }
    // A disposição salva deixa de levar a janela indisponível (as nomeadas ficam como foram gravadas).
    if (descartadas) agendarSalvar();
    return criadas;
}

// Gravação adiada: várias mudanças seguidas viram uma só. salvarPendente marca que
// há mudança ainda não enviada (o forçar ao sair só grava nesse caso, sem duplicar).
let salvarPendente = false;
function agendarSalvar() {
    if (restaurando || independente || modoCelular || !raiz) return;
    clearTimeout(esperaSalvar);
    salvarPendente = true;
    esperaSalvar = setTimeout(() => { gravarAgora(); }, 900);
}

async function gravarAgora() {
    if (!salvarPendente || !raiz) return;
    clearTimeout(esperaSalvar);
    salvarPendente = false;
    const d = disposicao();
    try {
        await Preferencias.salvarDisposicao('palco', d);
        Eventos.emitir('palco.disposicao', { disposicao: d });
    } catch (e) { /* tenta de novo na próxima mudança */ }
}

// Página saindo (recarregar, fechar a aba, navegar): o palco atual vai por keepalive.
function gravarAoSair() {
    if (!salvarPendente || !raiz) return;
    clearTimeout(esperaSalvar);
    salvarPendente = false;
    Preferencias.salvarAoSair('disposicao', disposicao());
}

function nomeadas() {
    const n = Preferencias.obter().disposicoes;
    return n && typeof n === 'object' && !Array.isArray(n) ? n : {};
}

// Nomeadas partem sempre da cópia relida do servidor: outra aba pode ter salvo ou
// excluído uma enquanto esta estava aberta.
const soObjeto = (v) => (v && typeof v === 'object' && !Array.isArray(v) ? v : {});
async function salvarNomeada(nome) {
    const limpo = String(nome || '').trim().slice(0, 40);
    if (!limpo) return false;
    const d = disposicao();
    await Preferencias.salvarDisposicao('nomeadas', (atual) => {
        const n = { ...soObjeto(atual) };
        if (!n[limpo] && Object.keys(n).length >= MAX_NOMEADAS) throw new Error('limite');
        n[limpo] = d;
        return n;
    });
    return true;
}

async function aplicarNomeada(nome) {
    const d = nomeadas()[nome];
    if (!d) return;
    await restaurar(d);
    agendarSalvar();
}

async function excluirNomeada(nome) {
    await Preferencias.salvarDisposicao('nomeadas', (atual) => {
        const n = { ...soObjeto(atual) };
        delete n[nome];
        return Object.keys(n).length ? n : null;
    });
}

// Menu de organização (barra do topo): arranjos e disposições nomeadas.
function menuOrganizar(alvo) {
    const nomes = Object.keys(nomeadas()).sort((a, b) => a.localeCompare(b));
    const temJanelas = abertas().length > 0;
    D2UI.menuContexto(alvo, [
        { grupo: t('casca.organizar.arranjo') },
        { icone: 'grade', rotulo: t('casca.organizar.quartos'), desativado: visiveis().length < 2, fn: () => organizarQuartos() },
        { icone: 'duplicar', rotulo: t('casca.organizar.cascata'), desativado: !visiveis().length, fn: () => organizarCascata() },
        { icone: 'minimizar', rotulo: t('casca.organizar.minimizar_todas'), desativado: !visiveis().length, fn: () => minimizarTodas() },
        { icone: 'fechar', rotulo: t('casca.organizar.fechar_todas'), desativado: !temJanelas, fn: () => confirmarFecharTodas() },
        { grupo: t('casca.organizar.disposicoes') },
        ...nomes.map((n) => ({ icone: 'grade', rotulo: n, fn: () => aplicarNomeada(n) })),
        { icone: 'lado-a-lado', rotulo: t('casca.organizar.salvar_atual'), desativado: !temJanelas, fn: () => dialogoSalvarNomeada() },
        nomes.length ? { icone: 'engrenagem', rotulo: t('casca.organizar.gerenciar'), fn: () => dialogoGerenciar() } : null,
    ]);
}

async function confirmarFecharTodas() {
    const ok = await D2UI.confirmar({
        titulo: t('casca.organizar.fechar_todas_titulo'),
        texto: t('casca.organizar.fechar_todas_texto', { n: abertas().length }),
        rotuloOk: t('casca.organizar.fechar_todas'),
    });
    if (ok) fecharTodas();
}

function dialogoSalvarNomeada() {
    const campo = D2UI.campo({
        nome: 'nome', rotulo: t('casca.organizar.nome'), obrigatorio: true, maximo: 40,
        dica: t('casca.organizar.nome_dica'), placeholder: t('casca.organizar.nome_exemplo'),
    });
    // Campo único: Enter salva, como num formulário.
    campo.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Enter' || ev.isComposing || !ev.target.matches('input')) return;
        ev.preventDefault();
        const salvar = m.botoes[m.botoes.length - 1];
        if (!salvar.disabled) salvar.click();
    });
    const m = D2UI.modal({
        titulo: t('casca.organizar.salvar_titulo'), icone: 'grade', largura: 440, corpo: campo,
        botoes: [
            { rotulo: t('casca.cancelar'), tipo: 'fantasma' },
            {
                rotulo: t('casca.salvar'), tipo: 'primary', fn: async () => {
                    if (!campo.validar()) return false;
                    try {
                        await salvarNomeada(campo.valor());
                        D2UI.toast(t('casca.organizar.salva', { nome: campo.valor() }), { tipo: 'ok' });
                        return true;
                    } catch (e) {
                        campo.erro(e && e.message === 'limite' ? t('casca.organizar.limite', { n: MAX_NOMEADAS }) : (e.mensagem || t('casca.erro.inesperado')));
                        return false;
                    }
                },
            },
        ],
    });
}

function dialogoGerenciar() {
    const lista = h('ul', { class: 'palco-nomeadas' });
    const desenhar = () => {
        const nomes = Object.keys(nomeadas()).sort((a, b) => a.localeCompare(b));
        if (!nomes.length) {
            lista.replaceChildren(D2UI.vazio({ chave: 'casca.organizar.nenhuma', icone: 'grade' }));
            return;
        }
        lista.replaceChildren(...nomes.map((n) => {
            const qtd = (nomeadas()[n].janelas || []).length;
            return h('li', { class: 'palco-nomeada' },
                h('span', { class: 'palco-nomeada-icone', 'aria-hidden': 'true' }, ic('grade')),
                h('span', { class: 'palco-nomeada-texto' },
                    h('strong', { texto: n }), h('small', { texto: t('casca.organizar.qtd_janelas', { n: qtd }) })),
                h('button', {
                    type: 'button', class: 'd2-btn d2-btn-fantasma d2-btn-sm', onclick: () => { m.fechar(); aplicarNomeada(n); },
                }, ic('check'), h('span', { texto: t('casca.organizar.aplicar') })),
                h('button', {
                    type: 'button', class: 'd2-iconbtn d2-iconbtn-fantasma', 'aria-label': t('casca.organizar.excluir', { nome: n }),
                    'data-tooltip': t('casca.organizar.excluir', { nome: n }),
                    onclick: async (ev) => {
                        ev.currentTarget.disabled = true;
                        const pos = [...lista.querySelectorAll('.palco-nomeada')].indexOf(ev.currentTarget.closest('.palco-nomeada'));
                        try { await excluirNomeada(n); } catch (e) { D2UI.toast(e.mensagem || t('casca.erro.inesperado'), { tipo: 'erro' }); }
                        desenhar();
                        // O foco segue para a linha que ocupou o lugar, ou para Fechar quando a lista esvazia.
                        const lixeiras = lista.querySelectorAll('.palco-nomeada .d2-iconbtn');
                        const proximo = lixeiras[Math.min(pos, lixeiras.length - 1)] || m.botoes[0];
                        if (proximo) proximo.focus({ preventScroll: true });
                    },
                }, ic('lixeira')));
        }));
    };
    desenhar();
    const m = D2UI.modal({ titulo: t('casca.organizar.gerenciar'), icone: 'grade', largura: 520, corpo: lista, botoes: [{ rotulo: t('casca.fechar'), tipo: 'secundario' }] });
}

// Celular: as janelas viram folhas empilhadas em tela cheia.
function aplicarModo() {
    if (!raiz) return;
    const novo = celular();
    raiz.classList.toggle('is-celular', novo);
    if (novo === modoCelular) return;
    modoCelular = novo;
    for (const j of abertas()) {
        if (novo) {
            j.el.style.transform = '';
            j.el.style.width = '';
            j.el.style.height = '';
        } else {
            j.aplicarRet(j.zona ? retZona(j.zona) : j.ret, { limitar: !j.zona });
        }
    }
    Eventos.emitir('palco.modo', { celular: novo });
}

// A área útil mudou (janela do navegador, menu): encaixadas e maximizadas
// recalculam a zona; as livres cabem inteiras na área nova e guardam o lugar pedido pelo
// usuário, para voltar a ele quando a área crescer de novo.
const mesmoRet = (a, b) => Boolean(a && b) && a.x === b.x && a.y === b.y && a.largura === b.largura && a.altura === b.altura;
function reajustar() {
    if (!raiz) return;
    aplicarModo();
    if (modoCelular) return;
    const animar = false;
    for (const j of abertas()) {
        const opcoes = { animar: animar && !j.minimizada, limitar: false };
        if (j.zona) { j.aplicarRet(retZona(j.zona), opcoes); continue; }
        // Se o usuário mexeu na janela depois do último ajuste, o lugar dele passa a ser o atual.
        const pedido = j.retLivre && mesmoRet(j.ret, j.retAjustado) ? j.retLivre : j.ret;
        const cabe = j.limitarDentro(pedido);
        if (!mesmoRet(cabe, j.ret)) j.aplicarRet(cabe, opcoes);
        if (mesmoRet(cabe, pedido)) { j.retLivre = null; j.retAjustado = null; } else { j.retLivre = { ...pedido }; j.retAjustado = { ...j.ret }; }
    }
}

// Troca de idioma: cada janela remonta no lugar (as minimizadas, ao voltar).
// A remontagem não mexe na mesa: um campo que se foca sozinho ao montar não traz
// a janela dele para a frente, e no fim a ordem, a janela focada e o foco do
// teclado voltam a ser os de antes da troca.
let remontando = false;

// Foco que chega a uma janela de trás sem o usuário ter agido (campo que se foca
// sozinho quando os dados do módulo chegam) não traz a janela para a frente. Logo
// depois da troca de idioma, esse foco ainda volta para onde estava.
let ultimaInteracao = 0;
let protegerAte = 0;
let focoProtegido = null;
const marcarInteracao = () => { ultimaInteracao = Date.now(); };
function focoAlheio() {
    if (remontando) return true;
    const agora = Date.now();
    if (agora - ultimaInteracao < 800) return false;
    if (agora < protegerAte && focoProtegido && focoProtegido.isConnected) {
        const alvo = focoProtegido;
        requestAnimationFrame(() => { if (alvo.isConnected) alvo.focus({ preventScroll: true }); });
    }
    return true;
}

// Antes de trocar o idioma, cada janela com edição pendente pergunta (a de cima
// primeiro). Guarda sem nada pendente responde na hora e a mesa não mexe; a que
// devolve uma pergunta (promessa) traz a janela para a frente, restaurando a
// minimizada, para a pergunta aparecer sobre ela.
async function guardaIdioma() {
    const comGuarda = [...lista].reverse().filter((j) => !j.fechada && j.guardas.size);
    for (const j of comGuarda) {
        for (const g of [...j.guardas]) {
            try {
                let r = g({ motivo: 'idioma' });
                if (r && typeof r.then === 'function') {
                    if (j.minimizada) restaurarJanela(j);
                    focar(j);
                    r = await r;
                }
                if (r === false) return false;
            } catch (e) { return false; }
        }
    }
    return true;
}
async function remontarTodas() {
    const ordem = [...lista];
    const focada = focadaAtual;
    const ativo = document.activeElement;
    const estavaNaJanela = Boolean(focada && ativo && focada.el.contains(ativo));
    const esperas = [];
    remontando = true;
    try {
        for (const j of abertas()) {
            if (j.minimizada) {
                j.limpar();
                j.corpo.replaceChildren();
                j.precisaRemontar = true;
                j.retraduzirControles();
                j.retraduzirTitulo();
            } else {
                esperas.push(j.remontar());
            }
        }
        if (fundo) esperas.push(fundo.remontar());
        atualizarBandeja();
        await Promise.allSettled(esperas);
    } finally {
        remontando = false;
    }
    lista = [...ordem.filter((j) => lista.includes(j)), ...lista.filter((j) => !ordem.includes(j))];
    reempilhar();
    // Títulos já no idioma novo: quem mostra o nome da janela refaz o seu.
    Eventos.emitir('palco.retraduzido', {});
    if (!focada || focada.fechada || focada.minimizada) return;
    focadaAtual = focada;
    for (const x of lista) x.el.classList.toggle('is-focada', x === focada);
    tituloDocumento();
    const agora = document.activeElement;
    const roubado = agora && agora !== ativo && lista.some((x) => x.el.contains(agora));
    if (estavaNaJanela) {
        if (ativo.isConnected) ativo.focus({ preventScroll: true });
        else if (!focada.el.contains(agora)) focada.el.focus({ preventScroll: true });
    } else if (roubado) {
        if (ativo && ativo.isConnected && ativo !== document.body) ativo.focus({ preventScroll: true });
        else agora.blur();
    }
    focoProtegido = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
    protegerAte = Date.now() + 2500;
}

let cancelarIdioma = null;
let observadorArea = null;
let consultaCelular = null;

// Liga o palco aos elementos da casca. opcoes.independente: aba aberta a partir
// de uma janela, que não grava disposição para não atropelar a aba principal.
function iniciar(elPalco, elBandeja, opcoes = {}) {
    raiz = elPalco;
    Telas.definirNavegador({ abrir, trocarUrl, recarregarFundo: () => (fundo ? fundo.remontar() : null), janelaDe, focada: () => focadaAtual });
    independente = Boolean(opcoes.independente);
    bandeja = elBandeja ? { el: elBandeja, lista: elBandeja.querySelector('.palco-bandeja-lista') || elBandeja } : null;
    previa = h('div', { class: 'palco-encaixe', 'aria-hidden': 'true' });
    raiz.append(previa);
    modoCelular = !celular();
    aplicarModo();
    if (window.ResizeObserver) {
        let quadro = 0;
        observadorArea = new ResizeObserver(() => {
            cancelAnimationFrame(quadro);
            quadro = requestAnimationFrame(reajustar);
        });
        observadorArea.observe(raiz);
    }
    consultaCelular = window.matchMedia('(max-width: 759.98px)');
    consultaCelular.addEventListener('change', aplicarModo);
    cancelarIdioma = Eventos.ouvir('idioma.trocado', () => remontarTodas());
    document.addEventListener('pointerdown', marcarInteracao, true);
    document.addEventListener('keydown', marcarInteracao, true);
    Idioma.definirGuarda(guardaIdioma);
    window.addEventListener('pagehide', gravarAoSair);
    return montarFundo(opcoes.inicial || null);
}

// Saída do sistema: tudo é desmontado, nada fica na memória da tela.
function encerrar() {
    clearTimeout(esperaSalvar);
    salvarPendente = false;
    window.removeEventListener('pagehide', gravarAoSair);
    for (const j of [...lista]) j.destruir();
    lista = [];
    focadaAtual = null;
    if (fundo) fundo.destruir();
    fundo = null;
    if (observadorArea) observadorArea.disconnect();
    if (consultaCelular) consultaCelular.removeEventListener('change', aplicarModo);
    if (cancelarIdioma) cancelarIdioma();
    document.removeEventListener('pointerdown', marcarInteracao, true);
    document.removeEventListener('keydown', marcarInteracao, true);
    Idioma.definirGuarda(null);
    if (previa) previa.remove();
    raiz = null;
    bandeja = null;
    previa = null;
    document.title = appNome();
}

function enderecoDe(j) {
    return montarEndereco(j.params && j.params.url);
}

// Janela que contém o elemento (a raiz de uma tela, um botão dentro dela); o fundo também conta.
function janelaDe(el) {
    if (!el || !(el instanceof Element)) return null;
    const sec = el.closest('[data-janela]');
    if (!sec) return null;
    if (fundo && sec === fundo.el) return fundo;
    return lista.find((j) => j.el === sec) || null;
}

export const Palco = {
    limite: LIMITE,
    abrir,
    trocarUrl,
    janelaDe,
    recarregarFundo: () => (fundo ? fundo.remontar() : null),
    focada: () => focadaAtual,
    focoAlheio,
    janelas: () => abertas(),
    fechar: (j) => (j ? j.fechar() : false),
    disposicao,
    restaurar,
    ladoALado,
    minimizarTodas,
    // Usados pela casca e pelas janelas.
    iniciar,
    encerrar,
    focar,
    alternar,
    minimizar,
    restaurarJanela,
    alternarMaximizar,
    encaixar,
    organizarQuartos,
    organizarCascata,
    fecharOutras,
    fecharTodas,
    confirmarFecharTodas,
    mostrarPainel,
    menuOrganizar,
    nomeadas,
    salvarNomeada,
    aplicarNomeada,
    excluirNomeada,
    removerJanela,
    reempilhar,
    agendarSalvar,
    gravarAgora,
    janelaMudou,
    enderecoDe,
    tamanhoArea,
    retArea,
    zonaEncaixe,
    mostrarEncaixe,
    modoCelular: () => modoCelular,
    fundo: () => fundo,
    ativo: () => Boolean(raiz),
    idiomaAtual: () => Idioma.atual(),
};
export default Palco;
