// Peças comuns das telas de licenças (Claude Code e ChatGPT): textos, formulário em modal,
// teste com detalhe, exclusão com palavra, formulários com espera e cópia.
// Tudo recebe a raiz da tela (consultas relativas a ela) e o sinal de um AbortController:
// abortar o sinal desliga os ouvintes (limpeza ao fechar a janela ou ao reiniciar a tela).
import { chamar, D2UI } from '/assets/d2/js/casca.js';

// Envia um formulário da tela: requestSubmit dispara o evento submit (a casca intercepta os que
// estão dentro da tela); no modo página vira o envio normal do navegador.
export function enviarForm(form) {
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else form.submit();
}

// Texto da página com troca de :marcadores (as chaves vêm do PHP, já traduzidas).
export function criarTexto(textos) {
    return (chave, troca = {}) => {
        let s = String((textos || {})[chave] ?? chave);
        for (const [k, v] of Object.entries(troca)) s = s.split(':' + k).join(String(v));
        return s;
    };
}

// Elemento simples sem innerHTML (nada de dado do servidor virar HTML).
export function el(etiqueta, atributos = {}, ...filhos) {
    const e = document.createElement(etiqueta);
    for (const [k, v] of Object.entries(atributos || {})) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') e.className = v;
        else if (k === 'texto') e.textContent = v;
        else if (k === 'style') e.setAttribute('style', v);
        else e.setAttribute(k, v === true ? '' : String(v));
    }
    for (const f of filhos.flat()) if (f !== null && f !== undefined && f !== false) e.append(f instanceof Node ? f : String(f));
    return e;
}

// Botão em espera enquanto a promessa corre.
export async function comEspera(botao, tarefa) {
    botao.disabled = true;
    botao.classList.add('d2-btn-carregando');
    botao.setAttribute('aria-busy', 'true');
    try { return await tarefa(); } finally {
        botao.disabled = false;
        botao.classList.remove('d2-btn-carregando');
        botao.removeAttribute('aria-busy');
    }
}

// Formulários POST comuns (ativar, roteamento...): mostra espera no botão até a página recarregar.
export function ligarFormulariosComEspera(raiz, sinal) {
    raiz.querySelectorAll('form[data-carregando]').forEach((form) => {
        form.addEventListener('submit', (ev) => {
            const botao = ev.submitter || form.querySelector('[type="submit"]');
            if (!botao) return;
            // adia para não tirar o botão do envio antes de o navegador montar os dados
            setTimeout(() => { botao.disabled = true; botao.classList.add('d2-btn-carregando'); botao.setAttribute('aria-busy', 'true'); }, 0);
        }, { signal: sinal });
    });
}

// Abre o <template> do formulário num modal. preencher(form) ajusta ação e valores.
// O formulário fica no modal (fora da tela), então segue com o envio nativo do navegador.
export function abrirFormulario(raiz, { modeloId, titulo, rotuloOk, rotuloCancelar, preencher, icone = 'chave', largura = 620 }) {
    const modelo = raiz.querySelector('#' + modeloId);
    if (!modelo) return;
    const corpo = modelo.content.cloneNode(true);
    const form = corpo.querySelector('form');
    if (typeof preencher === 'function') preencher(form);
    D2UI.modal({
        titulo, icone, largura, corpo,
        botoes: [
            { rotulo: rotuloCancelar, tipo: 'secundario' },
            {
                rotulo: rotuloOk, tipo: 'primario',
                fn: () => {
                    if (!form.reportValidity()) return false;
                    form.submit();
                    return new Promise(() => {}); // a página recarrega; o botão fica em espera
                },
            },
        ],
    });
}

// Exclusão: formulários com data-excluir pedem a palavra antes de enviar.
export function ligarExclusao(raiz, sinal, { titulo, texto, rotuloOk, palavra = 'EXCLUIR' }) {
    raiz.querySelectorAll('form[data-excluir]').forEach((form) => {
        form.addEventListener('submit', async (ev) => {
            if (form.dataset.confirmado === '1') return;
            ev.preventDefault();
            const ok = await D2UI.confirmar({
                titulo, perigo: true, palavra, rotuloOk, icone: 'lixeira',
                texto: texto.split(':nome').join(form.dataset.excluir || ''),
            });
            if (!ok) return;
            form.dataset.confirmado = '1';
            const botao = form.querySelector('[type="submit"]');
            if (botao) { botao.disabled = true; botao.classList.add('d2-btn-carregando'); }
            enviarForm(form);
        }, { signal: sinal });
    });
}

// Teste de autenticação: toast no sucesso; na falha, modal com o detalhe devolvido pelo servidor.
export async function testarLicenca(botao, url, tx) {
    await comEspera(botao, async () => {
        try {
            const r = await chamar(url, { metodo: 'POST', formulario: {} });
            if (r && r.ok) { D2UI.toast(tx('teste_ok'), { tipo: 'ok' }); return; }
            const detalhe = (r && r.detail) ? String(r.detail) : '';
            if (!detalhe) { D2UI.toast(tx('teste_falha'), { tipo: 'erro' }); return; }
            D2UI.modal({
                titulo: tx('teste_falha'), icone: 'alerta', perigo: true, largura: 560,
                corpo: el('div', { class: 'licencas-teste' },
                    el('p', { class: 'texto-mudo', texto: tx('teste_detalhe') }),
                    el('pre', { class: 'codigo licencas-teste-detalhe', texto: detalhe })),
                botoes: [{ rotulo: tx('fechar'), tipo: 'primario' }],
            });
        } catch (e) {
            D2UI.toast(e && e.message ? e.message : tx('teste_erro'), { tipo: 'erro' });
        }
    });
}

// Seletor que salva sozinho ao mudar: trava enquanto salva e volta ao valor anterior se falhar.
export function ligarSeletorQueSalva(sel, salvar, sinal) {
    let anterior = sel.value;
    sel.addEventListener('change', async () => {
        sel.disabled = true;
        sel.setAttribute('aria-busy', 'true');
        try {
            await salvar(sel.value);
            anterior = sel.value;
        } catch (e) {
            sel.value = anterior;
            D2UI.toast(e && e.message ? e.message : String(e), { tipo: 'erro' });
        } finally {
            sel.disabled = false;
            sel.removeAttribute('aria-busy');
        }
    }, { signal: sinal });
}

// Botões "copiar" (delegação no contêiner: a tela ou o corpo de um modal).
export function ligarCopiar(conteiner, tx, sinal) {
    conteiner.addEventListener('click', async (ev) => {
        const b = ev.target.closest('[data-copiar]');
        if (!b) return;
        try {
            await navigator.clipboard.writeText(b.dataset.copiar || '');
            D2UI.toast(tx('copiado'), { tipo: 'ok' });
        } catch (e) {
            D2UI.toast(tx('copiar_falhou'), { tipo: 'aviso' });
        }
    }, sinal ? { signal: sinal } : undefined);
}

// Ajuda vinda de um <template>: abre num modal só de leitura (os botões de copiar ligados no corpo).
export function abrirAjuda(raiz, modeloId, titulo, rotuloFechar, tx) {
    const modelo = raiz.querySelector('#' + modeloId);
    if (!modelo) return;
    const corpo = document.createElement('div');
    corpo.append(modelo.content.cloneNode(true));
    if (tx) ligarCopiar(corpo, tx);
    D2UI.modal({ titulo, icone: 'info', largura: 620, corpo, botoes: [{ rotulo: rotuloFechar, tipo: 'primario' }] });
}

export { chamar, D2UI };
