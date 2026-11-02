// Gateway de API (admin): um módulo para as 5 telas (painel, histórico, análise, modelos e campos).
// A tela vem do data-tela do .gw-tela dentro da raiz; os dados do PHP chegam em iniciar(raiz, dados).
// Endpoints são os mesmos da tela antiga: AJAX onde já era AJAX; formulário com redirecionamento
// (e aviso do servidor) onde já era formulário.
import { chamar, D2UI } from '/assets/d2/js/casca.js';

const textos = window.RAVI_CASCA && window.RAVI_CASCA.textos ? window.RAVI_CASCA.textos : {};
const tx = (chave, padrao) => textos[chave] || padrao;

// Elemento simples: el('span', { class: 'x', texto: 'y' }, filho...)
function el(tag, attrs = {}, ...filhos) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'texto') n.textContent = String(v);
        else if (k === 'class') n.className = v;
        else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2), v);
        else n.setAttribute(k, v === true ? '' : String(v));
    }
    for (const f of filhos.flat()) {
        if (f === null || f === undefined || f === false) continue;
        n.append(f instanceof Node ? f : document.createTextNode(String(f)));
    }
    return n;
}
const selo = (texto, tom) => el('span', { class: 'd2-selo d2-selo-' + tom }, el('span', { class: 'd2-ponto' }), texto);
const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || (window.RAVI_CASCA && window.RAVI_CASCA.csrf) || '';

async function copiar(texto, botao) {
    try {
        if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(texto);
        else {
            const area = el('textarea', { class: 'd2-oculto-leitor', readonly: true });
            area.value = texto; document.body.append(area); area.select();
            document.execCommand('copy'); area.remove();
        }
        D2UI.toast(tx('gateway.copiado', 'Copiado.'), { tipo: 'ok' });
        if (botao) { botao.classList.add('is-copiado'); setTimeout(() => botao.classList.remove('is-copiado'), 1500); }
    } catch (e) {
        D2UI.toast(tx('gateway.copiar_falhou', 'Não foi possível copiar. Selecione o texto e copie à mão.'), { tipo: 'erro' });
    }
}

// Botão em carregamento enquanto a promessa corre (nunca espera sem retorno visual).
async function comCarregamento(botao, fn) {
    if (botao.classList.contains('d2-btn-carregando')) return;
    botao.classList.add('d2-btn-carregando'); botao.disabled = true; botao.setAttribute('aria-busy', 'true');
    try { return await fn(); } finally {
        botao.classList.remove('d2-btn-carregando'); botao.disabled = false; botao.removeAttribute('aria-busy');
    }
}

// Campo de formulário dentro de modal.
function campo({ id, rotulo, tipo = 'text', max, dica, valor = '', placeholder = '', obrigatorio = false, mono = false }) {
    const entrada = el('input', {
        class: 'd2-control' + (mono ? ' d2-mono' : ''), type: tipo, id, name: id, maxlength: max || null,
        placeholder: placeholder || null, autocomplete: 'off', spellcheck: 'false', required: obrigatorio || null,
        'aria-describedby': dica ? id + '-dica' : null,
    });
    entrada.value = valor;
    const caixa = el('div', { class: 'd2-field' },
        el('label', { class: 'd2-label', for: id, texto: rotulo }), entrada,
        dica ? el('span', { class: 'd2-field-hint', id: id + '-dica', texto: dica }) : null);
    return { caixa, entrada };
}

// Outra tela: a casca abre numa janela quando sabe; senão, navegação normal.
function irPara(url) {
    if (window.Casca && typeof window.Casca.abrir === 'function') window.Casca.abrir(url);
    else window.location.href = url;
}

// Limpeza da execução anterior na mesma raiz (re-execução da tela não duplica ouvintes).
const ativos = new WeakMap();

export function iniciar(raiz, dados) {
    if (ativos.has(raiz)) ativos.get(raiz)();
    const controle = new AbortController();
    const sinal = controle.signal;
    const gw = raiz.matches('.gw-tela') ? raiz : raiz.querySelector('.gw-tela');
    if (!gw) return undefined;
    const tela = gw.dataset.tela || '';
    dados = dados || {};
    // Endereço da tela: data-url da raiz (casca) ou, no modo página, o caminho atual.
    const urlDaTela = () => {
        const dono = raiz.closest('[data-url]');
        try { return dono ? new URL(dono.dataset.url, location.origin).pathname : location.pathname; } catch (e) { return location.pathname; }
    };

    // Envia um formulário (POST com redirecionamento): mantém o endpoint e o aviso do servidor.
    // O formulário nasce dentro da tela e sai por requestSubmit, para a casca interceptar.
    function enviarFormulario(acao, campos = {}) {
        const f = el('form', { method: 'POST', action: acao, hidden: true });
        f.append(el('input', { type: 'hidden', name: '_csrf_token', value: csrf() }));
        for (const [k, v] of Object.entries(campos)) {
            for (const valor of Array.isArray(v) ? v : [v]) f.append(el('input', { type: 'hidden', name: k, value: String(valor) }));
        }
        raiz.append(f);
        if (typeof f.requestSubmit === 'function') f.requestSubmit(); else f.submit();
        return new Promise(() => {}); // a página vai recarregar; o botão segue em carregamento
    }

    /* ---------------------------------------------------------------- painel */
    function telaPainel() {
        const tokens = dados.tokens || [];
        const licencas = dados.licencas || [];

        // Novo token: modal com o nome; o servidor cria e volta para a tela mostrando a chave uma vez.
        function novoToken() {
            const nome = campo({ id: 'gw-nome-token', rotulo: tx('gateway.nome', 'Nome'), max: 120, placeholder: tx('gateway.nome_ex', 'ex.: Sistema de e-mail'), obrigatorio: true,
                dica: tx('gateway.nome_dica', 'Para reconhecer o sistema que vai usar o token. A chave aparece uma única vez, logo depois de criar.') });
            D2UI.modal({
                titulo: tx('gateway.gerar_token', 'Gerar token'), icone: 'chave', corpo: el('div', { class: 'd2-pilha' }, nome.caixa), enterConfirma: true,
                botoes: [
                    { rotulo: tx('gateway.cancelar', 'Cancelar'), tipo: 'fantasma' },
                    { rotulo: tx('gateway.gerar', 'Gerar'), tipo: 'primario', icone: 'mais', fn: () => {
                        const v = nome.entrada.value.trim();
                        if (!v) { nome.entrada.focus(); D2UI.toast(tx('gateway.nome_obrigatorio', 'Informe o nome do token.'), { tipo: 'aviso' }); return false; }
                        return enviarFormulario('/admin/api/tokens', { name: v });
                    } },
                ],
            });
            setTimeout(() => nome.entrada.focus(), 50);
        }
        raiz.querySelectorAll('[data-acao="novo-token"]').forEach((b) => b.addEventListener('click', novoToken, { signal: sinal }));

        // Gráfico de consumo por dia: custo ou chamadas.
        const caixaGrafico = raiz.querySelector('#gw-grafico');
        if (caixaGrafico) {
            const serie = dados.serie || [];
            const rotulos = serie.map((d) => d.dia.slice(8, 10) + '/' + d.dia.slice(5, 7));
            const dinheiro = (v) => '$' + Number(v || 0).toFixed(2);
            const inteiro = (v) => Number(v || 0).toLocaleString('pt-BR');
            const opcoesDe = (medida) => medida === 'chamadas'
                ? { rotulos, series: [{ nome: tx('gateway.chamadas', 'Chamadas'), valores: serie.map((d) => d.chamadas), tom: 'info' }, { nome: tx('gateway.erros', 'Erros'), valores: serie.map((d) => d.erros), tom: 'erro' }], formatar: inteiro, formatarEixo: inteiro, rotulo: tx('gateway.chamadas_por_dia', 'Chamadas por dia') }
                : { rotulos, series: [{ nome: tx('gateway.custo_estimado', 'Custo estimado'), valores: serie.map((d) => Math.round(d.custo * 100) / 100), tom: 'ok' }], formatar: dinheiro, formatarEixo: dinheiro, rotulo: tx('gateway.custo_por_dia', 'Custo estimado por dia') };
            const grafico = D2UI.grafico({ tipo: 'barras', rotuloEixo: tx('gateway.dia', 'Dia'), ...opcoesDe('custo') });
            caixaGrafico.replaceChildren(grafico.el);
            raiz.querySelectorAll('[data-medida]').forEach((b) => b.addEventListener('click', () => {
                raiz.querySelectorAll('[data-medida]').forEach((x) => {
                    const sim = x === b;
                    x.setAttribute('aria-pressed', sim ? 'true' : 'false');
                    x.classList.toggle('d2-btn-secundario', sim); x.classList.toggle('d2-btn-fantasma', !sim);
                });
                grafico.definir(opcoesDe(b.dataset.medida));
            }, { signal: sinal }));
        }

        // Tabela de tokens.
        const caixaTokens = raiz.querySelector('#gw-tokens');
        if (caixaTokens) {
            const seletorLicenca = (l) => {
                const s = el('select', { class: 'd2-control gw-licenca', 'aria-label': tx('gateway.licenca_de', 'Licença do token') + ' ' + l.nome });
                s.append(el('option', { value: '0', texto: tx('gateway.herda', 'Herda o roteamento global') }));
                for (const li of licencas) s.append(el('option', { value: String(li.id), texto: li.nome }));
                s.value = String(l.licenca || 0);
                let anterior = s.value;
                s.addEventListener('change', async () => {
                    s.disabled = true;
                    try {
                        await chamar('/admin/api/tokens/' + l.id + '/license', { metodo: 'POST', formulario: { license_id: s.value } });
                        anterior = s.value; l.licenca = Number(s.value);
                        D2UI.toast(tx('gateway.licenca_salva', 'Licença do token salva.'), { tipo: 'ok' });
                    } catch (e) { s.value = anterior; D2UI.toast(e.message || tx('gateway.licenca_falhou', 'Falha ao salvar a licença do token.'), { tipo: 'erro' }); }
                    finally { s.disabled = false; }
                });
                return s;
            };
            const chaveIdentidade = (l) => {
                const caixa = el('input', { type: 'checkbox' });
                caixa.checked = !!l.identidade;
                caixa.addEventListener('change', async () => {
                    caixa.disabled = true;
                    try {
                        await chamar('/admin/api/tokens/' + l.id + '/identity', { metodo: 'POST', formulario: { enabled: caixa.checked ? '1' : '0' } });
                        l.identidade = caixa.checked;
                        D2UI.toast(tx('gateway.preferencia_salva', 'Preferência salva.'), { tipo: 'ok' });
                    } catch (e) { caixa.checked = !caixa.checked; D2UI.toast(e.message || tx('gateway.preferencia_falhou', 'Falha ao salvar a preferência.'), { tipo: 'erro' }); }
                    finally { caixa.disabled = false; }
                });
                const rotuloCampos = tx('gateway.campos', 'Campos') + (l.campos > 0 ? ' (' + l.campos + (l.campos_obrig > 0 ? ', ' + l.campos_obrig + ' ' + tx('gateway.obrig', 'obrig.') : '') + ')' : '');
                return el('div', { class: 'gw-identidade' },
                    el('label', { class: 'd2-switch', 'data-tooltip': tx('gateway.registrar_dica', 'Registrar o token e os IPs do servidor de origem em cada chamada') },
                        caixa, el('span', { class: 'd2-switch-trilho', 'aria-hidden': 'true' }), el('span', { class: 'd2-switch-texto', texto: tx('gateway.registrar', 'registrar') })),
                    el('a', { class: 'gw-link', href: '/admin/api/tokens/' + l.id + '/fields', 'data-tooltip': tx('gateway.campos_dica', 'Campos de identidade exigidos deste token'), texto: rotuloCampos }));
            };
            const ir = (url) => () => irPara(url);

            const tabela = D2UI.tabela({
                id: 'gw-tokens', rotulo: tx('gateway.tokens', 'Tokens'), densa: true, cartao: () => null,
                colunas: [
                    { chave: 'nome', titulo: tx('gateway.token', 'Token'), fixa: true, render: (l) => el('div', { class: 'gw-token-celula' + (l.ativo ? '' : ' is-revogado') },
                        el('strong', { texto: l.nome }), el('span', { class: 'gw-token-linha2' }, el('code', { class: 'd2-mono texto-mudo', texto: l.prefixo }),
                            l.ativo ? selo(tx('gateway.ativo', 'ativo'), 'ok') : selo(tx('gateway.revogado', 'revogado'), 'erro'))) },
                    { chave: 'licenca', titulo: tx('gateway.licenca', 'Licença'), render: seletorLicenca },
                    { chave: 'executor', titulo: tx('gateway.executor', 'Executor'), render: (l) => el('a', { class: 'd2-tag' + (l.executor ? ' d2-tag-brand' : ''), href: '/admin/api/tokens/' + l.id + '/models', 'data-tooltip': tx('gateway.configurar_modelos', 'Configurar em Modelos e execução'), texto: l.executor_rotulo }) },
                    { chave: 'identidade', titulo: tx('gateway.identidade', 'Identidade'), render: chaveIdentidade },
                    { chave: 'custo_hoje', titulo: tx('gateway.custo_hoje', 'Custo hoje'), alinhar: 'direita', render: (l) => el('span', { class: 'd2-num', texto: l.custo_hoje }) },
                    { chave: 'custo_7d', titulo: tx('gateway.custo_7d', 'Custo 7d'), alinhar: 'direita', render: (l) => el('span', { class: 'd2-num', texto: l.custo_7d }) },
                    { chave: 'custo_30d', titulo: tx('gateway.custo_30d', 'Custo 30d'), alinhar: 'direita', render: (l) => el('span', { class: 'd2-num', texto: l.custo_30d }) },
                    { chave: 'chamadas_30d', titulo: tx('gateway.chamadas_30d', 'Chamadas 30d'), alinhar: 'direita', render: (l) => el('span', { class: 'd2-num', texto: Number(l.chamadas_30d).toLocaleString('pt-BR') }) },
                    { chave: 'ultimo_uso', titulo: tx('gateway.ultimo_uso', 'Último uso'), render: (l) => el('span', { class: 'd2-num' + (l.ultimo_uso ? '' : ' texto-mudo'), texto: l.ultimo_uso || tx('gateway.nunca', 'nunca') }) },
                ],
                carregar: async () => ({ itens: tokens, cursor_proximo: null }),
                acoesLinha: [
                    { rotulo: tx('gateway.historico', 'Histórico'), icone: 'relogio', fn: (l) => ir('/admin/api/tokens/' + l.id + '/history')() },
                    { rotulo: tx('gateway.analise', 'Análise de uso'), icone: 'busca', fn: (l) => ir('/admin/api/tokens/' + l.id + '/suspeitas')() },
                    { rotulo: tx('gateway.modelos', 'Modelos'), icone: 'lista', fn: (l) => ir('/admin/api/tokens/' + l.id + '/models')() },
                    { rotulo: tx('gateway.campos', 'Campos'), icone: 'filtro', fn: (l) => ir('/admin/api/tokens/' + l.id + '/fields')() },
                    { rotulo: tx('gateway.revogar', 'Revogar'), icone: 'cadeado', perigo: true,
                        bloqueio: (l) => (l.ativo ? null : tx('gateway.ja_revogado', 'Token já revogado')),
                        fn: async (l) => {
                            const ok = await D2UI.confirmar({ titulo: tx('gateway.revogar_titulo', 'Revogar token'), perigo: true, palavra: l.nome, rotuloOk: tx('gateway.revogar', 'Revogar'),
                                texto: tx('gateway.revogar_texto', 'Os sistemas que usam este token param de funcionar. O histórico de consumo é mantido.') });
                            if (ok) enviarFormulario('/admin/api/tokens/' + l.id + '/revoke');
                        } },
                    { rotulo: tx('gateway.excluir', 'Excluir'), icone: 'lixeira', perigo: true,
                        fn: async (l) => {
                            const ok = await D2UI.confirmar({ titulo: tx('gateway.excluir_titulo', 'Excluir token'), perigo: true, palavra: l.nome, rotuloOk: tx('gateway.excluir', 'Excluir'),
                                texto: tx('gateway.excluir_texto', 'Exclui o token de vez, junto com o histórico de consumo dele. Esta ação não pode ser desfeita.') });
                            if (ok) enviarFormulario('/admin/api/tokens/' + l.id + '/delete');
                        } },
                ],
                vazio: { chave: tx('gateway.sem_tokens', 'Nenhum token ainda'), texto: tx('gateway.sem_tokens_texto', 'Gere o primeiro token para um sistema começar a usar o Gateway.'), icone: 'chave',
                    acao: { rotulo: tx('gateway.gerar_token', 'Gerar token'), icone: 'mais', tipo: 'primario', fn: novoToken } },
            });
            caixaTokens.replaceChildren(tabela.el);
        }

        // DeepSeek: testar e cadastrar/trocar/remover a chave.
        const testar = raiz.querySelector('#gw-ds-testar');
        if (testar) testar.addEventListener('click', () => comCarregamento(testar, async () => {
            try {
                const r = await chamar('/admin/api/deepseek/test', { metodo: 'POST', formulario: {} });
                const extra = r && r.resposta ? ' ' + tx('gateway.resposta', 'Resposta:') + ' ' + r.resposta : '';
                D2UI.toast(tx('gateway.ds_respondeu_em', 'DeepSeek respondeu em') + ' ' + ((r && r.ms) || 0) + ' ms (' + ((r && r.modelo) || 'deepseek') + ').' + extra, { tipo: 'ok' });
            } catch (e) { D2UI.toast(e.message || tx('gateway.teste_falhou', 'O teste falhou.'), { tipo: 'erro' }); }
        }), { signal: sinal });
        raiz.querySelectorAll('[data-acao="chave-deepseek"]').forEach((b) => b.addEventListener('click', () => {
            const temChave = !!dados.deepseek;
            const chave = campo({ id: 'gw-chave-ds', tipo: 'password', max: 120, mono: true,
                rotulo: tx('gateway.chave_ds', 'Chave da API DeepSeek'),
                placeholder: temChave ? tx('gateway.chave_nova', 'Nova chave (substitui a atual)') : 'sk-...',
                dica: temChave ? tx('gateway.chave_vazia_remove', 'Deixe em branco e salve para remover a chave atual.') : tx('gateway.chave_cifrada', 'A chave fica cifrada no servidor.') });
            D2UI.modal({
                titulo: temChave ? tx('gateway.trocar_chave', 'Trocar chave') : tx('gateway.salvar_chave', 'Salvar chave'), icone: 'chave', enterConfirma: true,
                corpo: el('div', { class: 'd2-pilha' }, chave.caixa),
                botoes: [
                    { rotulo: tx('gateway.cancelar', 'Cancelar'), tipo: 'fantasma' },
                    { rotulo: tx('gateway.salvar', 'Salvar'), tipo: 'primario', icone: 'check', fn: async () => {
                        const v = chave.entrada.value.trim();
                        if (v === '') {
                            if (!temChave) { chave.entrada.focus(); D2UI.toast(tx('gateway.informe_chave', 'Informe a chave.'), { tipo: 'aviso' }); return false; }
                            const ok = await D2UI.confirmar({ titulo: tx('gateway.remover_chave', 'Remover chave'), perigo: true, palavra: 'REMOVER', rotuloOk: tx('gateway.remover', 'Remover'),
                                texto: tx('gateway.remover_chave_texto', 'Salvar em branco remove a chave da DeepSeek. Tokens com regra DeepSeek passarão a falhar.') });
                            if (!ok) return false;
                        }
                        return enviarFormulario('/admin/api/deepseek/key', { key: v });
                    } },
                ],
            });
            setTimeout(() => chave.entrada.focus(), 50);
        }, { signal: sinal }));

        // Endereço do Gateway: salvar e verificar.
        const form = raiz.querySelector('#gw-form-endereco');
        if (form) {
            const entrada = raiz.querySelector('#gw-url');
            const salvar = raiz.querySelector('#gw-salvar-url');
            const verificar = raiz.querySelector('#gw-verificar');
            const resultado = raiz.querySelector('#gw-verificacao');
            const origemTxt = { painel: tx('gateway.origem_painel', 'definido aqui no painel'), env: tx('gateway.origem_env', 'definido na instalação'), padrao: tx('gateway.origem_padrao', 'padrão: api. + domínio do painel') };
            const aplicar = (url) => {
                const host = (() => { try { return new URL(url).host; } catch (e) { return ''; } })();
                raiz.querySelectorAll('[data-dominio]').forEach((n) => { n.textContent = host; });
                raiz.querySelectorAll('[data-endpoint]').forEach((n) => { n.textContent = url + '/v1/messages'; });
                const ep = raiz.querySelector('#gw-endpoint'); if (ep) ep.textContent = url + '/v1/messages';
            };
            const mostrar = (tom, texto) => {
                resultado.replaceChildren(el('div', { class: 'd2-faixa d2-faixa-' + tom }, D2UI.icone(tom === 'ok' ? 'check' : 'alerta'), el('div', { class: 'd2-faixa-texto', texto })));
            };
            form.addEventListener('submit', (ev) => {
                ev.preventDefault();
                comCarregamento(salvar, async () => {
                    try {
                        const r = await chamar('/admin/api/endereco', { metodo: 'POST', formulario: { url: entrada.value.trim() } });
                        entrada.value = r.painel || '';
                        raiz.querySelector('#gw-url-efetiva').textContent = r.url;
                        raiz.querySelector('#gw-endereco-origem').textContent = origemTxt[r.origem] || '';
                        aplicar(r.url);
                        resultado.replaceChildren();
                        D2UI.toast(r.painel ? tx('gateway.endereco_salvo', 'Endereço do Gateway salvo.') : tx('gateway.endereco_padrao', 'Endereço do Gateway voltou ao padrão da instalação.'), { tipo: 'ok' });
                    } catch (e) {
                        mostrar('erro', e.message);
                        entrada.focus();
                    }
                });
            }, { signal: sinal });
            verificar.addEventListener('click', () => comCarregamento(verificar, async () => {
                resultado.replaceChildren(el('p', { class: 'texto-mudo', texto: tx('gateway.verificando', 'Verificando o endereço...') }));
                try {
                    const url = entrada.value.trim();
                    const r = await chamar('/admin/api/endereco/verificar' + (url ? '?url=' + encodeURIComponent(url) : ''));
                    mostrar('ok', tx('gateway.respondendo', 'Gateway respondendo em') + ' ' + r.url + ' (' + r.ms + ' ms).');
                } catch (e) { mostrar('erro', e.message); }
            }), { signal: sinal });
        }
    }

    /* ---------------------------------------------------------------- modelos */
    function telaModelos() {
        const tid = gw.dataset.token;
        const radios = [...raiz.querySelectorAll('input[name="gw-backend"]')];
        let atual = (radios.find((r) => r.checked) || {}).value;
        radios.forEach((r) => r.addEventListener('change', async () => {
            const opcao = r.closest('.gw-opcao');
            radios.forEach((x) => { x.disabled = true; });
            opcao.classList.add('is-salvando');
            try {
                const resp = await chamar('/admin/api/tokens/' + tid + '/backend', { metodo: 'POST', formulario: { backend: r.value } });
                atual = r.value;
                D2UI.toast((resp && resp.message) || tx('gateway.executor_salvo', 'Executor salvo.'), { tipo: 'ok' });
            } catch (e) {
                const volta = radios.find((x) => x.value === atual); if (volta) volta.checked = true;
                D2UI.toast(e.message || tx('gateway.executor_falhou', 'Falha ao salvar o executor.'), { tipo: 'erro' });
            } finally {
                opcao.classList.remove('is-salvando');
                radios.forEach((x) => { x.disabled = x.closest('.gw-opcao').classList.contains('is-desativada'); });
            }
        }, { signal: sinal }));

        const restringir = raiz.querySelector('#gw-restringir');
        const lista = raiz.querySelector('#gw-lista-modelos');
        const dica = raiz.querySelector('#gw-modelos-dica');
        if (restringir && lista) restringir.addEventListener('change', () => {
            lista.querySelectorAll('input[type=checkbox]').forEach((c) => { c.disabled = !restringir.checked; });
            lista.classList.toggle('is-desligado', !restringir.checked);
            if (dica) dica.textContent = restringir.checked ? tx('gateway.restrito_dica', 'Restrito aos marcados.') : tx('gateway.livre_dica', 'Sem restrição: todos os modelos permitidos.');
        }, { signal: sinal });
        const form = raiz.querySelector('#gw-form-modelos');
        if (form) form.addEventListener('submit', () => {
            const b = form.querySelector('button[type=submit]');
            b.classList.add('d2-btn-carregando'); b.setAttribute('aria-busy', 'true');
        }, { signal: sinal });
    }

    /* ---------------------------------------------------------------- análise */
    function telaAnalise() {
        const num = (v) => el('span', { class: 'd2-num', texto: Number(v || 0).toLocaleString('pt-BR') });
        const destaque = (v, sim) => (sim ? el('strong', { class: 'gw-suspeito d2-num', texto: String(v) }) : num(v));
        const base = urlDaTela().replace(/\/suspeitas$/, '');
        const sem = raiz.querySelector('#gw-tabela-sem');
        if (sem) sem.replaceChildren(D2UI.tabela({
            id: 'gw-sem-ident', rotulo: tx('gateway.sem_ident_titulo', 'Chamadas sem identidade'), densa: true, cartao: () => null, escolherColunas: false,
            colunas: [
                { chave: 'ip', titulo: tx('gateway.ip_origem', 'IP de origem'), render: (l) => el('code', { class: 'd2-mono gw-suspeito', texto: l.ip }) },
                { chave: 'n', titulo: tx('gateway.chamadas', 'Chamadas'), alinhar: 'direita', render: (l) => num(l.n) },
                { chave: 'ultima', titulo: tx('gateway.ultima', 'Última'), render: (l) => el('span', { class: 'd2-num', texto: l.ultima }) },
            ],
            carregar: async () => ({ itens: dados.sem_ident || [], cursor_proximo: null }),
            vazio: { chave: tx('gateway.sem_ident_vazio', 'Nenhuma chamada sem identidade'), texto: tx('gateway.sem_ident_vazio_texto', 'Todas as chamadas deste token trazem identidade.'), icone: 'check',
                acao: { rotulo: tx('gateway.ver_historico', 'Ver histórico'), icone: 'relogio', fn: () => irPara(base + '/history') } },
        }).el);
        const por = raiz.querySelector('#gw-tabela-por');
        if (por) por.replaceChildren(D2UI.tabela({
            id: 'gw-por-ident', rotulo: tx('gateway.por_ident_titulo', 'Por identidade'), densa: true, cartao: () => null,
            colunas: [
                { chave: 'token', titulo: tx('gateway.token_ravi', 'Token Ravi'), fixa: true, render: (l) => el('code', { class: 'd2-mono' + (l.origens > 1 || l.confs > 1 ? ' gw-suspeito' : ''), texto: l.token }) },
                { chave: 'n', titulo: tx('gateway.chamadas', 'Chamadas'), alinhar: 'direita', render: (l) => num(l.n) },
                { chave: 'origens', titulo: tx('gateway.ips_origem', 'IPs origem'), alinhar: 'direita', render: (l) => destaque(l.origens, l.origens > 1) },
                { chave: 'confs', titulo: tx('gateway.conj_ips', 'Conj. de IPs'), alinhar: 'direita', render: (l) => destaque(l.confs, l.confs > 1) },
                { chave: 'ips', titulo: tx('gateway.origens_declarados', 'Origens / IPs declarados'), render: (l) => el('div', { class: 'gw-ips' },
                    el('span', { class: 'd2-mono', 'data-tooltip': tx('gateway.ips_origem', 'IPs origem'), texto: l.ips_origem }),
                    el('span', { class: 'd2-mono texto-mudo', 'data-tooltip': tx('gateway.ips_declarados', 'IPs declarados pelo servidor'), texto: l.ips_decl })) },
                { chave: 'ultima', titulo: tx('gateway.ultima', 'Última'), render: (l) => el('span', { class: 'd2-num', texto: l.ultima }) },
            ],
            carregar: async () => ({ itens: dados.por_ident || [], cursor_proximo: null }),
            vazio: { chave: tx('gateway.por_ident_vazio', 'Nenhuma chamada com identidade registrada'), texto: tx('gateway.por_ident_vazio_texto', 'Exija os campos de identidade para saber de onde vem cada chamada.'), icone: 'usuarios',
                acao: { rotulo: tx('gateway.campos', 'Campos'), icone: 'filtro', fn: () => irPara(base + '/fields') } },
        }).el);
    }

    /* ---------------------------------------------------------------- campos */
    function telaCampos() {
        const tid = dados.token || gw.dataset.token;
        function novoCampo() {
            const chave = campo({ id: 'gw-campo-chave', rotulo: tx('gateway.chave', 'Chave'), max: 64, mono: true, placeholder: tx('gateway.chave_ex', 'ex.: server_token'), obrigatorio: true,
                dica: tx('gateway.chave_dica', 'O cliente envia no header x-ravi-<chave> (sublinhado vira hífen).') });
            const rotulo = campo({ id: 'gw-campo-rotulo', rotulo: tx('gateway.rotulo', 'Rótulo'), max: 120, placeholder: tx('gateway.rotulo_ex', 'ex.: Token do servidor') });
            const obrig = el('input', { type: 'checkbox', id: 'gw-campo-obrig' }); obrig.checked = true;
            const atalho = (k, r) => el('button', { type: 'button', class: 'd2-btn d2-btn-sm d2-btn-secundario', onclick: () => { chave.entrada.value = k; rotulo.entrada.value = r; } }, D2UI.icone('mais'), el('span', { texto: r }));
            D2UI.modal({
                titulo: tx('gateway.adicionar_campo', 'Adicionar campo'), icone: 'filtro', enterConfirma: true,
                corpo: el('div', { class: 'd2-pilha' },
                    el('div', { class: 'gw-atalhos', role: 'group', 'aria-label': tx('gateway.atalhos', 'Atalhos') },
                        atalho('tokenravi', tx('gateway.atalho_token', 'Token Ravi (do sistema)')), atalho('server_ips', tx('gateway.atalho_ips', 'IPs do servidor'))),
                    chave.caixa, rotulo.caixa,
                    el('label', { class: 'd2-switch' }, obrig, el('span', { class: 'd2-switch-trilho', 'aria-hidden': 'true' }), el('span', { class: 'd2-switch-texto', texto: tx('gateway.obrigatorio', 'Obrigatório') }))),
                botoes: [
                    { rotulo: tx('gateway.cancelar', 'Cancelar'), tipo: 'fantasma' },
                    { rotulo: tx('gateway.adicionar', 'Adicionar'), tipo: 'primario', icone: 'mais', fn: () => {
                        const k = chave.entrada.value.trim();
                        if (!k) { chave.entrada.focus(); D2UI.toast(tx('gateway.informe_chave_campo', 'Informe a chave do campo.'), { tipo: 'aviso' }); return false; }
                        const campos = { field_key: k, label: rotulo.entrada.value.trim() };
                        if (obrig.checked) campos.required = '1';
                        return enviarFormulario('/admin/api/tokens/' + tid + '/fields', campos);
                    } },
                ],
            });
            setTimeout(() => chave.entrada.focus(), 50);
        }
        raiz.querySelectorAll('[data-acao="novo-campo"]').forEach((b) => b.addEventListener('click', novoCampo, { signal: sinal }));

        const caixa = raiz.querySelector('#gw-tabela-campos');
        if (!caixa) return;
        const obrigatorio = (l) => {
            const c = el('input', { type: 'checkbox' }); c.checked = !!l.obrigatorio;
            c.addEventListener('change', async () => {
                c.disabled = true;
                try {
                    const r = await chamar('/admin/api/fields/' + l.id + '/required', { metodo: 'POST', formulario: { required: c.checked ? '1' : '0' } });
                    l.obrigatorio = c.checked;
                    D2UI.toast((r && r.message) || tx('gateway.salvo', 'Salvo.'), { tipo: 'ok' });
                } catch (e) { c.checked = !c.checked; D2UI.toast(e.message || tx('gateway.falhou', 'Falha ao salvar.'), { tipo: 'erro' }); }
                finally { c.disabled = false; }
            });
            return el('label', { class: 'd2-switch' }, c, el('span', { class: 'd2-switch-trilho', 'aria-hidden': 'true' }),
                el('span', { class: 'd2-oculto-leitor', texto: tx('gateway.obrigatorio', 'Obrigatório') + ' ' + l.chave }));
        };
        caixa.replaceChildren(D2UI.tabela({
            id: 'gw-campos', rotulo: tx('gateway.campos_token', 'Campos deste token'), cartao: () => null, escolherColunas: false,
            colunas: [
                { chave: 'chave', titulo: tx('gateway.chave', 'Chave'), fixa: true, render: (l) => el('code', { class: 'd2-mono', texto: l.chave }) },
                { chave: 'cabecalho', titulo: tx('gateway.header', 'Header'), render: (l) => el('code', { class: 'd2-mono texto-mudo', texto: l.cabecalho }) },
                { chave: 'rotulo', titulo: tx('gateway.rotulo', 'Rótulo'), render: (l) => el('span', { texto: l.rotulo }) },
                { chave: 'obrigatorio', titulo: tx('gateway.obrigatorio', 'Obrigatório'), render: obrigatorio },
            ],
            carregar: async () => ({ itens: dados.campos || [], cursor_proximo: null }),
            acoesLinha: [
                { rotulo: tx('gateway.remover', 'Remover'), icone: 'lixeira', perigo: true, fn: async (l) => {
                    const ok = await D2UI.confirmar({ titulo: tx('gateway.remover_campo', 'Remover campo'), perigo: true, palavra: l.chave, rotuloOk: tx('gateway.remover', 'Remover'),
                        texto: tx('gateway.remover_campo_texto', 'O Gateway deixa de exigir e de registrar este campo nas chamadas deste token.') });
                    if (ok) enviarFormulario('/admin/api/fields/' + l.id + '/delete');
                } },
            ],
            vazio: { chave: tx('gateway.sem_campos', 'Nenhum campo definido'), texto: tx('gateway.sem_campos_texto', 'Adicione os campos que cada chamada deste token precisa trazer.'), icone: 'filtro',
                acao: { rotulo: tx('gateway.adicionar_campo', 'Adicionar campo'), icone: 'mais', tipo: 'primario', fn: novoCampo } },
        }).el);
    }

    // Botões de copiar: o alvo (seletor em data-copiar) é procurado dentro da tela.
    raiz.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-copiar]');
        if (!b || !raiz.contains(b)) return;
        const alvo = raiz.querySelector(b.dataset.copiar);
        if (alvo) copiar(alvo.textContent.trim(), b);
    }, { signal: sinal });

    if (tela === 'painel') telaPainel();
    else if (tela === 'modelos') telaModelos();
    else if (tela === 'analise') telaAnalise();
    else if (tela === 'campos') telaCampos();
    // 'historico' é só leitura: a página já vem pronta do servidor (os botões de copiar são ligados acima).

    const limpar = () => {
        controle.abort();
        if (ativos.get(raiz) === limpar) ativos.delete(raiz);
    };
    ativos.set(raiz, limpar);
    return limpar;
}
