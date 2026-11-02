// Novo projeto: escolhas de origem, cor e repositório; envio nativo ou, com .zip, criação + envio com progresso.
import { chamar, D2UI, tx, carregando, enviarComProgresso, guardarAviso, dadosPagina } from './projeto-comum.js';

const dados = dadosPagina();
const form = document.getElementById('form-criar');
const nome = document.getElementById('criar-nome');
const nomeErro = document.getElementById('criar-nome-erro');
const origemValor = document.getElementById('criar-origem-valor');
const corValor = document.getElementById('criar-cor-valor');
const caixaClonar = document.getElementById('caixa-clonar');
const caixaNovo = document.getElementById('caixa-novo-repo');
const caixaArquivo = document.getElementById('caixa-arquivo');
const caixaInstrucoes = document.getElementById('caixa-instrucoes');
const instrucoes = document.getElementById('criar-instrucoes');
const repo = document.getElementById('criar-repo');
const repoErro = document.getElementById('criar-repo-erro');
const repoNome = document.getElementById('criar-repo-nome');
const enviar = document.getElementById('criar-enviar');

// Campo do .zip (só para projeto em branco).
const campoArquivo = D2UI.campo({
    tipo: 'arquivo', nome: 'archive', rotulo: tx('projetos.importar_zip', 'Importar projeto existente (.zip)'), opcional: true,
    aceitar: '.zip', tamanhoMaximo: 100 * 1024 * 1024,
    dica: tx('projetos.importar_zip_dica', 'Até 100 MB. A IA analisa o conteúdo e prepara o ambiente.'),
    aoMudar: () => atualizarInstrucoes(),
});
document.getElementById('campo-arquivo').append(campoArquivo.el);

function origemAtual() {
    const r = form.querySelector('input[name="origem"]:checked');
    return r ? r.value : '';
}

function ligarCaixa(caixa, ligada) {
    if (!caixa) return;
    caixa.hidden = !ligada;
    caixa.querySelectorAll('input, select, textarea').forEach((c) => { if (c.name) c.disabled = !ligada; });
}

function atualizarInstrucoes() {
    const o = origemAtual();
    caixaInstrucoes.hidden = !(o === 'git' || (o === '' && campoArquivo.valor()));
}

let reposCarregados = false;
async function carregarRepos() {
    if (reposCarregados || !repo) return;
    reposCarregados = true;
    repo.disabled = true;
    repo.replaceChildren(new Option(tx('projetos.repos_carregando', 'Carregando repositórios...'), ''));
    try {
        const d = await chamar('/settings/github/repos');
        if (!d || !d.ok) throw new Error((d && d.error) || tx('projetos.repos_falhou', 'Não foi possível listar seus repositórios.'));
        const lista = d.repos || [];
        repo.replaceChildren(new Option(lista.length ? tx('projetos.repo_escolha', 'Escolha um repositório') : tx('projetos.repos_vazio', 'Nenhum repositório encontrado'), ''));
        lista.forEach((r) => repo.append(new Option((r.private ? '🔒 ' : '') + r.full_name, r.full_name)));
    } catch (e) {
        reposCarregados = false;
        repo.replaceChildren(new Option(tx('projetos.repos_falhou', 'Não foi possível listar seus repositórios.'), ''));
        D2UI.toast(e.message, { tipo: 'erro' });
    } finally {
        repo.disabled = origemAtual() !== 'git';
    }
}

function atualizarOrigem() {
    const o = origemAtual();
    origemValor.value = o;
    ligarCaixa(caixaClonar, o === 'git');
    ligarCaixa(caixaNovo, o === 'github_new');
    caixaArquivo.hidden = o !== '';
    if (o !== '') campoArquivo.limpar();
    if (o === 'git') carregarRepos();
    if (o === 'github_new') sugerirRepo();
    atualizarInstrucoes();
}

// Nome do repositório novo acompanha o nome do projeto até ser editado (mesma regra da tela antiga).
let repoEditado = false;
const slug = (s) => String(s || '').toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 90);
function sugerirRepo() { if (repoNome && !repoEditado) repoNome.value = slug(nome.value); }
if (repoNome) repoNome.addEventListener('input', () => { repoEditado = repoNome.value.trim() !== ''; });

form.querySelectorAll('input[name="origem"]').forEach((r) => r.addEventListener('change', atualizarOrigem));

// Cor: a amostra escolhida vira o hex da matiz (o mesmo nos dois temas).
form.querySelectorAll('input[name="matiz"]').forEach((r) => r.addEventListener('change', () => {
    const m = r.value;
    if (!m) { corValor.value = ''; corValor.disabled = true; return; }
    const hex = getComputedStyle(document.documentElement).getPropertyValue('--d2-matiz-' + m + '-icone').trim();
    corValor.value = /^#[0-9a-fA-F]{6}$/.test(hex) ? hex.toLowerCase() : '';
    corValor.disabled = !corValor.value;
}));

function erroNome(msg) {
    nomeErro.replaceChildren();
    const campo = nome.closest('.d2-field');
    if (msg) { nomeErro.append(D2UI.icone('erro'), document.createTextNode(msg)); nome.setAttribute('aria-invalid', 'true'); }
    else nome.removeAttribute('aria-invalid');
    campo.classList.toggle('is-invalid', Boolean(msg));
}
nome.addEventListener('input', () => { if (nome.value.trim().length >= 2) erroNome(null); sugerirRepo(); });

function validar() {
    let ok = true;
    if (nome.value.trim().length < 2) { erroNome(tx('projetos.nome_curto', 'Dê um nome ao projeto (mínimo 2 letras).')); ok = false; }
    if (origemAtual() === 'git' && repo && !repo.value) {
        repoErro.replaceChildren(D2UI.icone('erro'), document.createTextNode(tx('projetos.repo_obrigatorio', 'Selecione um repositório para clonar.')));
        repo.closest('.d2-field').classList.add('is-invalid');
        ok = false;
    } else if (repoErro) { repoErro.replaceChildren(); repo.closest('.d2-field').classList.remove('is-invalid'); }
    if (!campoArquivo.validar()) ok = false;
    if (!ok) {
        const primeiro = form.querySelector('.is-invalid .d2-control, .is-invalid input');
        if (primeiro) primeiro.focus();
    }
    return ok;
}
if (repo) repo.addEventListener('change', () => { if (repo.value) { repoErro.replaceChildren(); repo.closest('.d2-field').classList.remove('is-invalid'); } });

// Com .zip: cria o projeto e envia o arquivo com progresso. Sem .zip: envio nativo (o servidor redireciona com aviso).
async function criarComArquivo(arquivo) {
    const soltar = carregando(enviar);
    const campos = {};
    new FormData(form).forEach((v, k) => { if (k !== '_token' && k !== 'origem' && k !== 'matiz' && k !== 'instructions') campos[k] = v; });
    let uuid = null;
    try {
        const d = await chamar('/projects', { metodo: 'POST', dados: campos });
        uuid = d && (d.uuid || d.id);
        if (!uuid) throw new Error(tx('casca.erro_generico', 'Não foi possível concluir.'));
    } catch (e) {
        soltar();
        const erros = e.dados && e.dados.errors;
        if (erros && erros.name) erroNome(Array.isArray(erros.name) ? erros.name[0] : erros.name);
        D2UI.toast(e.message || tx('projetos.criar_falhou', 'Não foi possível criar o projeto.'), { tipo: 'erro' });
        return;
    }
    const p = D2UI.progresso({ titulo: tx('projetos.enviando_zip', 'Enviando o projeto'), texto: arquivo.name });
    const fd = new FormData();
    fd.append('archive', arquivo);
    fd.append('_token', (window.RAVI_CASCA && window.RAVI_CASCA.csrf) || '');
    fd.append('instructions', instrucoes.value.trim());
    try {
        await enviarComProgresso('/projects/' + encodeURIComponent(uuid) + '/upload', fd, (pct) => p.atualizar(pct, arquivo.name));
        p.concluir(tx('projetos.zip_enviado', 'Arquivo enviado. Preparando o projeto...'));
        guardarAviso(tx('projetos.criado_zip', 'Projeto criado. A IA está processando o arquivo enviado.'), 'ok');
        setTimeout(() => { window.location = '/projects/' + encodeURIComponent(uuid); }, 700);
    } catch (e) {
        p.fechar();
        guardarAviso(tx('projetos.zip_falhou', 'O projeto foi criado, mas o envio do arquivo falhou: ') + e.message + ' ' + tx('projetos.zip_tentar', 'Você pode importar o .zip de novo pelas configurações do projeto.'), 'erro');
        window.location = '/projects/' + encodeURIComponent(uuid);
    }
}

form.addEventListener('submit', (ev) => {
    if (!validar()) { ev.preventDefault(); return; }
    const arquivo = origemAtual() === '' ? campoArquivo.valor() : null;
    if (arquivo) { ev.preventDefault(); criarComArquivo(arquivo); return; }
    carregando(enviar);
    // O botão desativado não impede o envio já em curso; evita só o duplo clique.
});

if (dados.github) atualizarOrigem();
nome.focus();
