// Hub do projeto: ações do cabeçalho, importação de .zip, acompanhamento do processamento
// e as abas (visão geral e conversas vêm prontas do PHP; as demais são montadas aqui).
import {
    tx, aplicarCores, abrirIde, mostrarAvisoGuardado, enviarComProgresso,
    dadosPagina, el, chamar, D2UI,
} from './projeto-comum.js';
import { montarArquivos } from './projeto-arquivos.js';
import { montarCompartilhar } from './projeto-compartilhar.js';
import { montarGit } from './projeto-git.js';
import { montarMemorias } from './projeto-memorias.js';
import { montarServidores } from './projeto-servidores.js';
import { montarLigados } from './projeto-ligados.js';

const dados = dadosPagina();
const uuid = String(dados.uuid || '');
const base = '/projects/' + encodeURIComponent(uuid);
const contexto = { dados, uuid, base };

aplicarCores();
mostrarAvisoGuardado();

// ── Ações do cabeçalho ────────────────────────────────────────────────
const botaoIde = document.getElementById('acao-ide');
if (botaoIde) botaoIde.addEventListener('click', () => abrirIde(uuid, botaoIde));

const botaoConfig = document.getElementById('acao-config');
if (botaoConfig) {
    botaoConfig.setAttribute('aria-haspopup', 'menu');
    botaoConfig.addEventListener('click', () => {
        D2UI.menuContexto(botaoConfig, [
            { rotulo: tx('projeto.editar', 'Editar projeto'), icone: 'editar', fn: editarProjeto },
            { rotulo: tx('projeto.clonar', 'Clonar projeto'), icone: 'duplicar', fn: clonarProjeto },
            dados.processando ? null : { rotulo: tx('projeto.importar_zip', 'Importar (.zip)'), icone: 'subir', fn: escolherZip },
            dados.processando ? null : { rotulo: tx('projeto.terminal', 'Terminal'), icone: 'computador', fn: () => { window.location = base + '/terminal'; } },
            'separador',
            dados.arquivado
                ? { rotulo: tx('projeto.restaurar', 'Restaurar projeto'), icone: 'restaurar', fn: () => arquivar(false) }
                : { rotulo: tx('projeto.arquivar', 'Arquivar projeto'), icone: 'exportar', fn: () => arquivar(true) },
            { rotulo: tx('projeto.excluir', 'Excluir projeto'), icone: 'lixeira', perigo: true, fn: excluirProjeto },
        ]);
    });
}

// Edição com ícone, cor e tipo (mesmo PUT /api/projects que o editor antigo usava).
// As opções são as mesmas da tela de criação; a cor gravada é o hex da matiz escolhida.
const corDaMatiz = (m) => {
    const hex = getComputedStyle(document.documentElement).getPropertyValue('--d2-matiz-' + m + '-icone').trim();
    return /^#[0-9a-fA-F]{6}$/.test(hex) ? hex.toLowerCase() : '';
};
function editarProjeto() {
    const icones = Array.isArray(dados.icones) ? dados.icones : [];
    const matizes = Array.isArray(dados.matizes) ? dados.matizes : [];
    const corAtual = String(dados.cor || '').toLowerCase();
    const nomeGrupo = 'editar-' + Math.random().toString(36).slice(2, 8);

    const nome = D2UI.campo({ tipo: 'texto', nome: 'name', rotulo: tx('projetos.nome', 'Nome do projeto'), obrigatorio: true, maximo: 200, valor: dados.nome, validar: (v) => (String(v).length < 2 ? tx('projetos.nome_curto', 'Dê um nome ao projeto (mínimo 2 letras).') : null) });
    const descricao = D2UI.campo({ tipo: 'texto-longo', nome: 'description', rotulo: tx('projetos.descricao', 'Descrição'), opcional: true, maximo: 1000, linhas: 3, valor: dados.descricao });
    const tipo = D2UI.campo({ tipo: 'selecao', nome: 'type', rotulo: tx('projetos.tipo', 'Tipo de projeto'), opcoes: dados.tipos || [], obrigatorio: true, valor: dados.tipo || 'web' });

    const listaIcones = icones.some((i) => i.valor === dados.icone) || !dados.icone ? icones : [{ valor: dados.icone, rotulo: tx('projetos.icone_atual', 'Atual') }, ...icones];
    const gradeIcones = el('div', { class: 'projeto-icones' });
    listaIcones.forEach((i, n) => {
        const r = el('input', { type: 'radio', name: nomeGrupo + '-icone', value: i.valor, 'aria-label': i.rotulo });
        r.checked = i.valor === dados.icone || (!dados.icone && n === 0);
        gradeIcones.append(el('label', { class: 'projeto-icone-opcao', 'data-tooltip': i.rotulo }, r, el('span', { 'aria-hidden': 'true', texto: i.valor })));
    });

    const gradeCores = el('div', { class: 'projeto-cores' });
    const opcaoCor = (valor, rotulo, amostra, marcado) => {
        const r = el('input', { type: 'radio', name: nomeGrupo + '-cor', value: valor, 'aria-label': rotulo });
        r.checked = marcado;
        gradeCores.append(el('label', { class: 'projeto-cor-opcao', 'data-tooltip': rotulo }, r, amostra));
    };
    let algumaMarcada = !corAtual;
    opcaoCor('', tx('projetos.cor_padrao', 'Padrão'), el('span', { class: 'projeto-cor-amostra is-padrao', 'aria-hidden': 'true' }), !corAtual);
    const comMatiz = [];
    for (const m of matizes) {
        const hex = corDaMatiz(m.valor);
        const marcado = Boolean(corAtual) && hex === corAtual;
        if (marcado) algumaMarcada = true;
        comMatiz.push([m, hex, marcado]);
    }
    if (!algumaMarcada) {
        // Cor gravada fora da paleta (projeto antigo): continua disponível como "Atual".
        opcaoCor('atual', tx('projetos.cor_atual', 'Cor atual'), el('span', { class: 'projeto-cor-amostra is-atual', 'data-cor': corAtual, 'aria-hidden': 'true' }), true);
    }
    for (const [m, , marcado] of comMatiz) {
        opcaoCor(m.valor, m.rotulo, el('span', { class: 'projeto-cor-amostra matiz-' + m.valor, 'aria-hidden': 'true' }), marcado);
    }
    aplicarCores(gradeCores);

    const corpo = el('div', { class: 'form-grade' },
        el('div', { class: 'is-cheio' }, nome),
        el('div', { class: 'is-cheio' }, descricao),
        el('div', { class: 'is-cheio' }, tipo),
        el('fieldset', { class: 'projeto-escolha is-cheio' }, el('legend', { class: 'd2-label', texto: tx('projetos.icone', 'Ícone') }), gradeIcones),
        el('fieldset', { class: 'projeto-escolha is-cheio' }, el('legend', { class: 'd2-label', texto: tx('projetos.cor', 'Cor') }), gradeCores),
    );
    D2UI.modal({
        titulo: tx('projeto.editar', 'Editar projeto'), icone: 'editar', corpo, largura: 600,
        botoes: [
            { rotulo: tx('projeto.cancelar', 'Cancelar') },
            {
                rotulo: tx('projeto.salvar', 'Salvar'), tipo: 'primario', icone: 'check',
                fn: async () => {
                    if (!nome.validar() || !descricao.validar()) return false;
                    const ic = corpo.querySelector('input[name="' + nomeGrupo + '-icone"]:checked');
                    const mz = corpo.querySelector('input[name="' + nomeGrupo + '-cor"]:checked');
                    const m = mz ? mz.value : '';
                    const cor = m === 'atual' ? corAtual : (m ? corDaMatiz(m) : '');
                    try {
                        await chamar('/api/projects/' + encodeURIComponent(uuid), {
                            metodo: 'PUT',
                            dados: { name: nome.valor(), description: descricao.valor(), type: tipo.valor() || 'web', icon: ic ? ic.value : (dados.icone || ''), color: cor },
                        });
                        D2UI.toast(tx('projeto.salvo', 'Projeto atualizado.'), { tipo: 'ok' });
                        setTimeout(() => window.location.reload(), 500);
                        return true;
                    } catch (e) {
                        const erros = e.dados && e.dados.errors;
                        if (erros && erros.name) nome.erro([].concat(erros.name)[0]);
                        D2UI.toast(e.message || tx('projeto.erro_salvar', 'Não foi possível salvar.'), { tipo: 'erro' });
                        return false;
                    }
                },
            },
        ],
    });
}

async function clonarProjeto() {
    const ok = await D2UI.confirmar({
        titulo: tx('projeto.clonar', 'Clonar projeto'),
        texto: tx('projeto.clonar_texto', 'Clonar este projeto? A cópia recebe os arquivos do projeto, mas NÃO o GitHub nem os servidores remotos.'),
        rotuloOk: tx('projeto.clonar_ok', 'Clonar'),
    });
    if (!ok) return;
    const p = D2UI.progresso({ titulo: tx('projeto.clonando', 'Clonando projeto') });
    try {
        const d = await chamar(base + '/clone', { metodo: 'POST', formulario: {} });
        if (d && d.ok && d.redirect) { p.concluir(); window.location = d.redirect; return; }
        p.falhar((d && d.error) || tx('projeto.clonar_falhou', 'Falha ao clonar.'));
    } catch (e) {
        p.falhar(e.message || tx('projeto.clonar_falhou', 'Falha ao clonar.'));
    }
}

async function arquivar(sim) {
    const ok = await D2UI.confirmar({
        titulo: sim ? tx('projeto.arquivar', 'Arquivar projeto') : tx('projeto.restaurar', 'Restaurar projeto'),
        texto: sim
            ? tx('projeto.arquivar_texto', 'O projeto sai da lista principal e fica em Arquivados. Você pode restaurar quando quiser.')
            : tx('projeto.restaurar_texto', 'O projeto volta para a lista principal.'),
        rotuloOk: sim ? tx('projeto.arquivar_ok', 'Arquivar') : tx('projeto.restaurar_ok', 'Restaurar'),
    });
    if (!ok) return;
    try {
        await chamar(base + (sim ? '/archive' : '/restore'), { metodo: 'POST', formulario: {} });
        D2UI.toast(sim ? tx('projeto.arquivado', 'Projeto arquivado') : tx('projeto.restaurado', 'Projeto restaurado'), { tipo: 'ok' });
        setTimeout(() => window.location.reload(), 500);
    } catch (e) {
        D2UI.toast(e.message || tx('projeto.erro_generico', 'Não foi possível concluir.'), { tipo: 'erro' });
    }
}

async function excluirProjeto() {
    const ok = await D2UI.confirmar({
        titulo: tx('projeto.excluir', 'Excluir projeto'),
        texto: tx('projeto.excluir_texto', 'O projeto, as conversas e os arquivos deixam de existir. Esta ação não pode ser desfeita.'),
        perigo: true,
        palavra: dados.nome,
        rotuloOk: tx('projeto.excluir_ok', 'Excluir'),
    });
    if (!ok) return;
    try {
        await chamar('/api/projects/' + encodeURIComponent(uuid), { metodo: 'DELETE' });
        D2UI.toast(tx('projeto.excluido', 'Projeto excluído'), { tipo: 'ok' });
        setTimeout(() => { window.location = '/dashboard'; }, 500);
    } catch (e) {
        D2UI.toast(e.message || tx('projeto.erro_excluir', 'Não foi possível excluir o projeto.'), { tipo: 'erro' });
    }
}

// ── Importação de .zip ────────────────────────────────────────────────
const entradaZip = document.getElementById('entrada-zip');
function escolherZip() { if (entradaZip) entradaZip.click(); }
if (entradaZip) {
    entradaZip.addEventListener('change', () => {
        const f = entradaZip.files && entradaZip.files[0];
        entradaZip.value = '';
        if (f) prepararImportacao(f);
    });
}
document.querySelectorAll('[data-importar-zip]').forEach((b) => b.addEventListener('click', escolherZip));

const zona = document.getElementById('zona-importar');
if (zona) {
    zona.addEventListener('click', escolherZip);
    zona.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); escolherZip(); } });
    ['dragenter', 'dragover'].forEach((t) => zona.addEventListener(t, (ev) => { ev.preventDefault(); zona.classList.add('is-arrastando'); }));
    zona.addEventListener('dragleave', (ev) => { if (!zona.contains(ev.relatedTarget)) zona.classList.remove('is-arrastando'); });
    zona.addEventListener('drop', (ev) => {
        ev.preventDefault();
        zona.classList.remove('is-arrastando');
        const f = ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0];
        if (f) prepararImportacao(f);
    });
}

function prepararImportacao(arquivo) {
    if (!/\.zip$/i.test(arquivo.name || '')) {
        D2UI.toast(tx('projeto.so_zip', 'Envie um arquivo .zip'), { tipo: 'erro' });
        return;
    }
    const instrucoes = D2UI.campo({
        tipo: 'texto-longo', nome: 'instructions', rotulo: tx('projeto.instrucoes', 'Objetivo para a IA'), opcional: true, linhas: 4,
        placeholder: tx('projeto.instrucoes_exemplo', 'Ex.: use este projeto como MODELO/REFERÊNCIA para construir um projeto NOVO do zero que faça algo parecido, apenas se baseando no que ele faz e já tem. Por enquanto, organize e analise o projeto enviado.'),
        dica: tx('projeto.instrucoes_dica', 'A IA descompacta, analisa e prepara o projeto, e registra esse objetivo para usar quando você começar a conversar. Deixe em branco para só importar e analisar.'),
    });
    const corpo = el('div', { class: 'projeto-modal-pilha' },
        el('div', { class: 'projeto-arquivo-escolhido' }, D2UI.icone('documento'),
            el('strong', { texto: arquivo.name }), el('span', { class: 'texto-mudo', texto: D2UI.formatar.tamanho(arquivo.size) })),
        instrucoes);
    D2UI.modal({
        titulo: tx('projeto.importar_titulo', 'Importar um projeto pronto'), icone: 'subir', corpo, largura: 600,
        botoes: [
            { rotulo: tx('projeto.cancelar', 'Cancelar') },
            { rotulo: tx('projeto.importar', 'Importar'), tipo: 'primario', icone: 'subir', fn: () => { enviarZip(arquivo, instrucoes.valor()); } },
        ],
    });
}

async function enviarZip(arquivo, instrucoes) {
    const fd = new FormData();
    fd.append('archive', arquivo);
    fd.append('_token', (window.RAVI_CASCA && window.RAVI_CASCA.csrf) || '');
    if (instrucoes) fd.append('instructions', instrucoes);
    const p = D2UI.progresso({ titulo: tx('projeto.importando', 'Importando projeto'), texto: tx('projeto.enviando', 'Enviando') + ' ' + arquivo.name });
    try {
        await enviarComProgresso(base + '/upload', fd, (pct) => p.atualizar(pct));
        p.concluir(tx('projeto.recebido', 'Recebido. Processando…'));
        setTimeout(() => window.location.reload(), 900);
    } catch (e) {
        p.falhar(e.message || tx('projeto.importar_falhou', 'Falha ao importar.'));
    }
}

// ── Acompanhamento do processamento da importação ─────────────────────
if (dados.processando) {
    const lista = document.getElementById('etapas-importacao');
    const consultar = async () => {
        let espera = 2500;
        try {
            const d = await chamar(base + '/import/status');
            const imp = (d && d.import) || {};
            if (lista && Array.isArray(imp.stages)) {
                lista.textContent = '';
                for (const st of imp.stages) {
                    lista.append(el('li', { class: 'projeto-etapa is-' + (st.state || 'pending') },
                        el('span', { class: 'projeto-etapa-marca', 'aria-hidden': 'true' }), el('span', { texto: st.label || st.key || '' })));
                }
            }
            if (imp.status === 'done' || imp.status === 'failed') { window.location.reload(); return; }
        } catch (e) {
            espera = 4000;
        }
        setTimeout(consultar, espera);
    };
    setTimeout(consultar, 2500);
}

// ── Abas ──────────────────────────────────────────────────────────────
const secao = (id) => {
    const s = document.querySelector('.projeto-secao[data-aba="' + id + '"]');
    if (!s) return null;
    s.hidden = false;
    return s;
};
const lista = [
    { id: 'visao', rotulo: tx('projeto.aba_visao', 'Visão geral'), icone: 'painel', conteudo: () => secao('visao') },
    { id: 'conversas', rotulo: tx('projeto.aba_conversas', 'Conversas'), icone: 'atendimento', contador: (dados.contagens && dados.contagens.conversas) || 0, conteudo: () => secao('conversas') },
];
if (!dados.processando) {
    lista.push({ id: 'arquivos', rotulo: tx('projeto.aba_arquivos', 'Arquivos'), icone: 'grade', conteudo: () => montarArquivos(contexto) });
    lista.push({ id: 'compartilhar', rotulo: tx('projeto.aba_compartilhar', 'Compartilhar'), icone: 'usuarios', contador: (dados.contagens && dados.contagens.membros) || null, conteudo: () => montarCompartilhar(contexto) });
    lista.push({ id: 'git', rotulo: 'Git', icone: 'plataforma', conteudo: () => montarGit(contexto) });
    if (dados.contexto) {
        lista.push({ id: 'memorias', rotulo: tx('projeto.aba_memorias', 'Memórias'), icone: 'copiloto', conteudo: () => montarMemorias(contexto) });
        lista.push({ id: 'servidores', rotulo: tx('projeto.aba_servidores', 'Servidores'), icone: 'rede', conteudo: () => montarServidores(contexto) });
    }
    if (dados.ligados) {
        lista.push({ id: 'ligados', rotulo: tx('projeto.aba_ligados', 'Projetos ligados'), icone: 'anexo', conteudo: () => montarLigados(contexto) });
    }
}
const pedida = (window.location.hash || '').replace('#', '');
const caixaAbas = document.getElementById('projeto-abas');
const abas = D2UI.abas(lista, {
    ativa: lista.some((a) => a.id === pedida) ? pedida : 'visao',
    rotulo: tx('projeto.secoes', 'Seções do projeto'),
    classe: 'projeto-abas-d2',
    aoTrocar: (id) => { history.replaceState(null, '', id === 'visao' ? window.location.pathname + window.location.search : '#' + id); },
});
caixaAbas.textContent = '';
caixaAbas.removeAttribute('aria-busy');
caixaAbas.append(abas);
window.addEventListener('hashchange', () => {
    const id = (window.location.hash || '').replace('#', '') || 'visao';
    const botao = abas.querySelector('[role="tab"][data-aba="' + id + '"]');
    if (botao && botao.getAttribute('aria-selected') !== 'true') botao.click();
});

