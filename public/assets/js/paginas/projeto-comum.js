// Peças comuns das telas de projeto (lista, criação, hub e terminal).
import { chamar, D2UI, Tema } from '/assets/d2/js/casca.js';

const textos = (window.RAVI_CASCA && window.RAVI_CASCA.textos) || {};
export const tx = (chave, padrao) => textos[chave] || padrao;

// Cor gravada no projeto (hex vindo do banco) vira variável CSS; o CSS mistura com tokens.
export function aplicarCores(raiz = document) {
    raiz.querySelectorAll('[data-cor]').forEach((el) => {
        const cor = String(el.getAttribute('data-cor') || '').trim();
        if (/^#[0-9a-fA-F]{6}$/.test(cor)) el.style.setProperty('--projeto-cor', cor);
    });
}

// Liga o botão em modo de espera e devolve a função que desfaz.
export function carregando(botao) {
    if (!botao) return () => {};
    botao.classList.add('d2-btn-carregando');
    botao.setAttribute('aria-busy', 'true');
    botao.disabled = true;
    return () => { botao.classList.remove('d2-btn-carregando'); botao.removeAttribute('aria-busy'); botao.disabled = false; };
}

// Abre a IDE do projeto com o tema atual da tela (mesmo caminho da tela antiga).
export async function abrirIde(uuid, botao) {
    const soltar = carregando(botao);
    try {
        const d = await chamar('/projects/' + encodeURIComponent(uuid) + '/ide/start', {
            metodo: 'POST', formulario: { theme: Tema.atual() === 'escuro' ? 'dark' : 'light' },
        });
        if (d && d.ok) { window.location = '/ide?active=' + encodeURIComponent(uuid); return; }
        soltar();
        D2UI.toast((d && d.error) || tx('projeto.ide_falhou', 'Não foi possível abrir a IDE agora.'), { tipo: 'erro', titulo: tx('projeto.ide', 'IDE') });
    } catch (e) {
        soltar();
        D2UI.toast(e.message || tx('projeto.ide_falhou', 'Não foi possível abrir a IDE agora.'), { tipo: 'erro', titulo: tx('projeto.ide', 'IDE') });
    }
}

// Aviso deixado por uma tela para a próxima (ex.: falha no envio do .zip logo após criar).
const CHAVE_AVISO = 'ravidev.projeto.aviso';
export function guardarAviso(texto, tipo = 'info') {
    try { sessionStorage.setItem(CHAVE_AVISO, JSON.stringify({ texto, tipo })); } catch (e) { /* sem armazenamento */ }
}
export function mostrarAvisoGuardado() {
    try {
        const v = sessionStorage.getItem(CHAVE_AVISO);
        if (!v) return;
        sessionStorage.removeItem(CHAVE_AVISO);
        const a = JSON.parse(v);
        if (a && a.texto) D2UI.toast(a.texto, { tipo: a.tipo || 'info' });
    } catch (e) { /* ignora */ }
}

// Envio de arquivo com progresso real (fetch não informa o progresso do upload).
export function enviarComProgresso(url, dados, aoProgresso) {
    return new Promise((resolver, rejeitar) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('X-CSRF-Token', (window.RAVI_CASCA && window.RAVI_CASCA.csrf) || '');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = (ev) => { if (ev.lengthComputable && aoProgresso) aoProgresso(Math.round((ev.loaded / ev.total) * 100)); };
        xhr.onload = () => {
            let json = null;
            try { json = JSON.parse(xhr.responseText); } catch (e) { /* sem JSON */ }
            const d = json && json.data !== undefined ? json.data : json;
            if (xhr.status >= 200 && xhr.status < 300 && d && d.ok !== false && !(json && json.success === false)) resolver(d);
            else rejeitar(new Error((d && d.error) || (json && (json.message || json.error)) || tx('casca.erro_generico', 'Não foi possível concluir.')));
        };
        xhr.onerror = () => rejeitar(new Error(tx('projeto.erro_rede', 'Falha de conexão. Tente de novo.')));
        xhr.send(dados);
    });
}

export function dadosPagina() {
    const el = document.getElementById('dados-pagina');
    if (!el) return {};
    try { return JSON.parse(el.textContent || '{}'); } catch (e) { return {}; }
}

// Criação de elementos sem innerHTML.
export function el(tag, atributos = {}, ...filhos) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(atributos || {})) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') n.className = v;
        else if (k === 'texto') n.textContent = v;
        else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2), v);
        else n.setAttribute(k, v === true ? '' : String(v));
    }
    for (const f of filhos.flat()) {
        if (f === null || f === undefined || f === false) continue;
        n.append(f instanceof Node ? f : document.createTextNode(String(f)));
    }
    return n;
}

export function botao(rotulo, { icone = null, tipo = 'secundario', tamanho = 'sm', dica = null, aoClicar = null, rotuloAria = null } = {}) {
    const classes = ['d2-btn', tamanho ? 'd2-btn-' + tamanho : '', 'd2-btn-' + tipo].filter(Boolean).join(' ');
    const b = el('button', { type: 'button', class: classes, 'data-tooltip': dica, 'aria-label': rotuloAria || (!rotulo ? dica : null) },
        icone ? D2UI.icone(icone) : null, rotulo ? el('span', { texto: rotulo }) : null);
    if (aoClicar) b.addEventListener('click', aoClicar);
    return b;
}

export function interruptor(rotulo, ligado, aoMudar, { desativado = false, dica = null } = {}) {
    const entrada = el('input', { type: 'checkbox', role: 'switch' });
    entrada.checked = Boolean(ligado);
    entrada.disabled = desativado;
    const no = el('label', { class: 'd2-switch' + (desativado ? ' is-desativado' : ''), 'data-tooltip': dica },
        entrada, el('span', { class: 'd2-switch-trilho', 'aria-hidden': 'true' }), rotulo ? el('span', { class: 'd2-switch-texto', texto: rotulo }) : null);
    if (!rotulo) entrada.setAttribute('aria-label', dica || '');
    entrada.addEventListener('change', () => aoMudar(entrada.checked, entrada));
    return no;
}

export { chamar, D2UI, Tema };
