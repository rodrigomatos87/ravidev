// Entrada da casca do painel do Ravi Developer (módulo ES, sem build). Monta a casca
// (casca/app.js) e expõe a API que os módulos das telas usam: t, chamar, D2UI, Tema,
// iniciarTela e window.Casca (abrir, recarregar, fechar, marcarEndereco, focada).
import { D2UI } from './d2ui.js';
import { Tema } from './tema.js';
import { t, chamar } from './casca/contexto.js';
import { iniciarTela } from './casca/telas.js';
import { Palco } from './casca/palco.js';
import { Janela } from './casca/janela.js';
import { App } from './casca/app.js';

// Janela de uma raiz de tela (ou de qualquer elemento dentro dela); aceita a própria janela.
function janelaDe(x) {
    if (x instanceof Janela) return x;
    if (x instanceof Element) return Palco.janelaDe(x);
    return null;
}

// Refaz o fetch da tela e reinicia os módulos dela, mantendo a rolagem.
function recarregar(raizOuJanela) {
    const j = janelaDe(raizOuJanela) || Palco.focada();
    if (!j) { location.reload(); return Promise.resolve(); }
    return Promise.resolve(j.remontar());
}

// Fecha a janela da tela; o painel (fundo) não fecha: volta ao início.
function fechar(raizOuJanela) {
    const j = janelaDe(raizOuJanela);
    if (!j || j.fundo) { location.href = '/dashboard'; return Promise.resolve(false); }
    return Promise.resolve(j.fechar());
}

// Atualiza a query do endereço da janela (ex.: { aba: 'uso' }) sem recarregar a tela.
// Valor vazio, null ou undefined tira o parâmetro.
function marcarEndereco(raiz, params = {}) {
    const j = janelaDe(raiz);
    const base = j ? j.params.url : (raiz && raiz.dataset && raiz.dataset.url) || location.pathname;
    const u = new URL(base, location.origin);
    for (const [k, v] of Object.entries(params || {})) {
        if (v === null || v === undefined || v === '') u.searchParams.delete(k);
        else u.searchParams.set(k, String(v));
    }
    const nova = u.pathname + u.search;
    const alvoRaiz = raiz instanceof Element ? (raiz.closest('[data-url]') || raiz) : null;
    if (alvoRaiz) alvoRaiz.dataset.url = nova;
    if (!j) { history.replaceState(history.state, '', nova); return nova; }
    if (j.fundo) { j.params.url = nova; return nova; }
    Palco.trocarUrl(j, nova);
    return nova;
}

const Casca = {
    t, chamar, D2UI, Tema, iniciarTela,
    abrir: (url, opcoes = {}) => Palco.abrir(url, opcoes),
    recarregar,
    fechar,
    marcarEndereco,
    focada: () => Palco.focada(),
};
window.Casca = Casca;

App.iniciar();

export { t, chamar, D2UI, Tema, iniciarTela, Casca };
export default Casca;
