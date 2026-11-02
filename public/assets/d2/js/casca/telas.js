// Telas do painel dentro das janelas: dado um endereço, a janela busca a tela no servidor
// (cabeçalho X-Ravi-Janela: 1, resposta em JSON com título, área, ícone, HTML, estilos e
// scripts), injeta o HTML, carrega os estilos e inicia os módulos da tela. Links e
// formulários dentro da tela continuam na casca: abrem janela ou re-renderizam a própria.
import { Eventos } from '../eventos.js';
import { D2UI } from '../d2ui.js';
import { t, cfg } from './contexto.js';
import { h } from './janela.js';

const TAMANHO_PADRAO = { largura: 1040, altura: 680, minLargura: 360, minAltura: 260 };

// Quem abre e troca janelas (o palco registra; evita importação circular).
let navegador = { abrir: () => null, trocarUrl: () => {}, recarregarFundo: () => {}, janelaDe: () => null, focada: () => null };
function definirNavegador(n) {
    if (n && typeof n === 'object') navegador = { ...navegador, ...n };
}

// Estilos da tela: um <link> por arquivo, carregado uma vez; a falta não impede a tela.
const estilosCarregados = new Map();
function carregarEstilo(href) {
    const chave = String(href || '').split('?')[0];
    if (!chave) return Promise.resolve();
    if (estilosCarregados.has(chave)) return estilosCarregados.get(chave);
    const ja = [...document.querySelectorAll('link[rel="stylesheet"]')].some((l) => (l.getAttribute('href') || '').split('?')[0] === chave);
    if (ja) {
        estilosCarregados.set(chave, Promise.resolve());
        return estilosCarregados.get(chave);
    }
    const p = new Promise((resolver) => {
        const link = h('link', { rel: 'stylesheet', href });
        const fim = () => { clearTimeout(limite); resolver(); };
        const limite = setTimeout(fim, 2500);
        link.addEventListener('load', fim, { once: true });
        link.addEventListener('error', () => { link.remove(); estilosCarregados.delete(chave); fim(); }, { once: true });
        document.head.append(link);
    });
    estilosCarregados.set(chave, p);
    return p;
}

// Módulos da tela: convenção `export function iniciar(raiz, dados)` (dados = JSON de
// #dados-pagina dentro da raiz). Pode devolver uma função de limpeza, chamada quando a
// tela some (fechar, recarregar). Módulo sem iniciar() é legado: roda sozinho ao ser importado.
export async function iniciarTela(raiz, scripts) {
    const limpezas = [];
    const dadosEl = raiz.querySelector('#dados-pagina');
    let dados = null;
    if (dadosEl) { try { dados = JSON.parse(dadosEl.textContent || 'null'); } catch (e) { dados = null; } }
    for (const url of scripts || []) {
        try {
            const m = await import(url);
            if (typeof m.iniciar === 'function') {
                const r = await m.iniciar(raiz, dados);
                if (typeof r === 'function') limpezas.push(r);
            }
        } catch (e) { console.error('tela: módulo falhou', url, e); }
    }
    return () => { for (const f of limpezas) { try { f(); } catch (e) { /* limpeza nunca derruba */ } } };
}

// Endereço interno de tela (mesmo domínio), resolvido contra o endereço da janela.
function resolver(href, base) {
    try {
        const u = new URL(href, location.origin + (base || '/'));
        if (u.origin !== location.origin) return null;
        return u;
    } catch (e) {
        return null;
    }
}

// Busca a tela. Resposta que não é JSON é página inteira: a sessão caiu (login) ou o
// endereço tem layout próprio. Nos dois casos a casca dá lugar à página de verdade.
async function buscar(url, { metodo = 'GET', corpo = null, sinal = null } = {}) {
    const r = await fetch(url, {
        method: metodo, body: corpo, credentials: 'same-origin', signal: sinal || undefined,
        headers: { 'X-Ravi-Janela': '1', Accept: 'text/html' },
    });
    const tipo = r.headers.get('content-type') || '';
    const final = new URL(r.url || url, location.origin);
    if (!tipo.includes('application/json')) {
        const erro = new Error('pagina');
        erro.pagina = final.pathname + final.search;
        erro.status = r.status;
        throw erro;
    }
    let dados = null;
    try { dados = await r.json(); } catch (e) { dados = null; }
    if (!dados || typeof dados.html !== 'string') {
        const erro = new Error((dados && (dados.message || dados.error)) || t('casca.erro_generico'));
        erro.status = r.status;
        erro.dados = dados;
        erro.mensagem = erro.message;
        throw erro;
    }
    return { dados, url: final.pathname + final.search, status: r.status };
}

function mostrarFlash(lista) {
    for (const f of lista || []) if (f && f.texto) D2UI.toast(f.texto, { tipo: f.tipo || 'info' });
}

// Põe a tela na janela: cabeçalho da janela, área, HTML, estilos e módulos.
async function aplicar(janela, dados, url, n) {
    if (!janela.fundo) {
        if (dados.titulo) janela.titulo(dados.titulo);
        janela.subtitulo(dados.subtitulo || '');
        if (dados.icone) janela.icone(dados.icone);
    }
    if (dados.area) janela.el.setAttribute('data-area', dados.area);
    await Promise.all((dados.estilos || []).map(carregarEstilo));
    if (n !== janela.montagem || janela.fechada) return;
    const pagina = h('div', { class: 'pagina' + (dados.larga ? ' is-larga' : ''), 'data-url': url });
    // HTML gerado pelo servidor (views do painel, com escape próprio): a mesma marcação da página inteira.
    pagina.innerHTML = dados.html;
    janela.corpo.replaceChildren(pagina);
    janela.raizTela = pagina;
    janela.corpo.scrollTop = 0;
    mostrarFlash(dados.flash);
    const limpar = await iniciarTela(pagina, dados.scripts || []);
    if (n !== janela.montagem || janela.fechada) { limpar(); return; }
    janela.aoFechar(limpar);
    Eventos.emitir('tela.montada', { janela, url });
}

// Página inteira no lugar da casca (sessão caída, layout próprio).
function irParaPagina(erro) {
    if (erro && erro.pagina) location.href = erro.pagina;
    else location.reload();
}

// A tela pediu outro endereço (link, resposta de formulário): mesma janela no lugar novo.
async function seguir(janela, resposta, n) {
    const { dados, url } = resposta;
    if (n !== janela.montagem || janela.fechada) return;
    if (!janela.fundo && url !== janela.params.url) {
        if (eCheia(url.split('?')[0])) { location.href = url; return; }
        const painel = url.split('?')[0].replace(/\/+$/, '') === '/dashboard';
        if (painel) {
            // A ação terminou no painel (excluir, por exemplo): a janela fecha e o painel se atualiza.
            mostrarFlash(dados.flash);
            await janela.fechar({ forcar: true });
            navegador.recarregarFundo();
            return;
        }
        navegador.trocarUrl(janela, url);
    }
    janela.limpar();
    await aplicar(janela, dados, url, n);
}

// Envio de formulário da tela pela janela: mesmos campos, resposta (redirecionamento e
// flash) re-renderiza a janela.
async function enviar(janela, form, botao) {
    const base = janela.params.url || '/dashboard';
    const acao = resolver(form.getAttribute('action') || base, base);
    if (!acao) return;
    const metodo = String((botao && botao.getAttribute('formmethod')) || form.getAttribute('method') || 'GET').toUpperCase();
    const dados = new FormData(form);
    if (botao && botao.name) dados.append(botao.name, botao.value);
    const alvo = acao.pathname + acao.search;
    if (metodo === 'GET') {
        const q = new URLSearchParams();
        for (const [k, v] of dados) if (typeof v === 'string') q.append(k, v);
        const s = q.toString();
        navegar(janela, acao.pathname + (s ? '?' + s : ''));
        return;
    }
    const n = ++janela.montagem;
    janela.ocupado(true);
    const marcar = (sim) => {
        if (!botao) return;
        botao.classList.toggle('d2-btn-carregando', sim);
        botao.disabled = sim;
        botao.toggleAttribute('aria-busy', sim);
    };
    marcar(true);
    try {
        const resposta = await buscar(alvo, { metodo: 'POST', corpo: dados });
        janela.ocupado(false);
        marcar(false);
        // Modal que disparou o envio (formulário, progresso) não fica aberto girando.
        D2UI.fecharModais();
        await seguir(janela, resposta, n);
    } catch (erro) {
        janela.ocupado(false);
        marcar(false);
        D2UI.fecharModais();
        if (erro.message === 'pagina') { irParaPagina(erro); return; }
        D2UI.toast(erro.mensagem || t('casca.erro_generico'), { tipo: 'erro' });
    }
}

// Link interno clicado dentro da tela: outra tela abre em outra janela; a mesma tela com
// outra query (filtro, página da lista) troca no lugar.
function navegar(janela, endereco, { novaJanela = false } = {}) {
    const base = janela.params.url || '/dashboard';
    const u = resolver(endereco, base);
    if (!u) return;
    const url = u.pathname + u.search;
    const atual = new URL(base, location.origin);
    if (!novaJanela && !janela.fundo && u.pathname === atual.pathname && url !== base) {
        navegador.trocarUrl(janela, url);
        janela.remontar();
        return;
    }
    navegador.abrir(url, { origem: janela });
}

// Endereço que sai da casca (layout próprio ou troca de conta): navegação de verdade.
function eCheia(pathname) {
    return (cfg.paginasCheias || []).some((p) => pathname === p.replace(/\/+$/, '') || pathname.startsWith(p.replace(/\/+$/, '') + '/'));
}

// Formulário que a casca envia pela janela, ou null se ele segue com o envio do navegador.
function formularioDaCasca(form, janela) {
    if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-casca-ignorar') || form.hasAttribute('data-pagina-cheia')) return false;
    if (form.target && form.target !== '_self') return false;
    const base = janela.params.url || '/dashboard';
    const acao = resolver(form.getAttribute('action') || base, base);
    return Boolean(acao) && !eCheia(acao.pathname);
}

// form.submit() não dispara o evento de envio: o de um modal do D2UI (fora da tela) ou o
// chamado direto numa tela passa por aqui e segue pela janela de onde veio (a do formulário
// ou, no modal, a janela em foco). O resto (página cheia, sem janela) é o envio nativo.
const submitNativo = HTMLFormElement.prototype.submit;
HTMLFormElement.prototype.submit = function submit() {
    let janela = null;
    try {
        janela = navegador.janelaDe(this);
        if (!janela && this.closest('.d2-modal-veu')) janela = navegador.focada();
        if (janela && !formularioDaCasca(this, janela)) janela = null;
    } catch (e) { janela = null; }
    if (!janela) { submitNativo.call(this); return; }
    enviar(janela, this, null);
};

// Liga os links e os formulários da tela à casca (um ouvinte por corpo de janela).
function ligar(janela) {
    if (janela.telaLigada) return;
    janela.telaLigada = true;
    janela.corpo.addEventListener('click', (ev) => {
        if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.altKey) return;
        const a = ev.target instanceof Element ? ev.target.closest('a[href]') : null;
        if (!a || !janela.corpo.contains(a)) return;
        const href = a.getAttribute('href') || '';
        if (!href || href.startsWith('#') || /^(javascript|mailto|tel):/i.test(href)) return;
        if (a.hasAttribute('download') || (a.target && a.target !== '_self') || a.hasAttribute('data-casca-ignorar')) return;
        const u = resolver(href, janela.params.url);
        if (!u) return;
        // Arquivos (download, exportação) e rotas de API seguem pelo navegador.
        if (/\.(zip|csv|pdf|txt|json|png|jpe?g|svg|gz|tar)$/i.test(u.pathname) || u.pathname.startsWith('/api/')) return;
        ev.preventDefault();
        navegar(janela, u.pathname + u.search, { novaJanela: ev.shiftKey });
    });
    janela.corpo.addEventListener('submit', (ev) => {
        if (ev.defaultPrevented) return;
        const form = ev.target;
        if (!formularioDaCasca(form, janela)) return;
        ev.preventDefault();
        enviar(janela, form, ev.submitter || null);
    });
}

// Monta a tela na janela (primeira vez, recarregar, voltar de minimizada).
async function montar(janela) {
    const n = janela.montagem;
    const url = janela.params.url || '/dashboard';
    ligar(janela);
    // Tela que já veio no HTML da página (a primeira aberta): aproveita sem buscar de novo.
    const inicial = janela.inicial;
    janela.inicial = null;
    if (inicial && inicial.dados && inicial.el) {
        const dados = inicial.dados;
        if (!janela.fundo) {
            if (dados.titulo) janela.titulo(dados.titulo);
            janela.subtitulo(dados.subtitulo || '');
            if (dados.icone) janela.icone(dados.icone);
        }
        if (dados.area) janela.el.setAttribute('data-area', dados.area);
        const pagina = inicial.el;
        pagina.hidden = false;
        pagina.removeAttribute('id');
        pagina.className = 'pagina' + (dados.larga ? ' is-larga' : '');
        janela.corpo.replaceChildren(pagina);
        janela.raizTela = pagina;
        mostrarFlash(dados.flash);
        const limpar = await iniciarTela(pagina, dados.scripts || []);
        if (n !== janela.montagem || janela.fechada) { limpar(); return; }
        janela.aoFechar(limpar);
        return;
    }
    let resposta;
    try {
        resposta = await buscar(url);
    } catch (erro) {
        if (erro.message === 'pagina') { irParaPagina(erro); return; }
        throw erro;
    }
    await seguir(janela, resposta, n);
}

// Definição da tela de um endereço (o palco usa para abrir e restaurar janelas).
function definicao(url) {
    return {
        ...TAMANHO_PADRAO,
        icone: 'grade',
        identidade: (p) => p.url,
        montar: (janela) => montar(janela),
    };
}

export const Telas = { definicao, definirNavegador, iniciarTela, carregarEstilo };
export default Telas;
