// Chat do Dev autônomo: liga o D2UI e o tema, instancia o RaviChat (chat.js, clássico com defer,
// já carregado quando este módulo roda) e cuida das ações da tela que antes eram script inline.
import './chat-casca.js';
import { D2UI, Tema } from '/assets/d2/js/casca.js';

const raiz = document.documentElement;
const $ = (id) => document.getElementById(id);

function lerJson(id) {
    const el = $(id);
    if (!el) return {};
    try { return JSON.parse(el.textContent || '{}') || {}; } catch (e) { return {}; }
}

const cfg = lerJson('dados-pagina');
window.CHAT_CONFIG = cfg;
const projectId = cfg.projectId || '';
const conversationId = cfg.conversationId || '';
const container = document.querySelector('.chat-container');

// ---------- Tema: auto, claro, escuro (o tema.js espelha 'ravi-theme' para a IDE) ----------
const btnTema = $('theme-toggle');
const rotuloTema = { auto: 'Tema automático', claro: 'Tema claro', escuro: 'Tema escuro' };
function marcarTema() {
    const pref = Tema.preferencia();
    raiz.setAttribute('data-tema-pref', pref);
    if (btnTema) {
        btnTema.setAttribute('data-tooltip', rotuloTema[pref] || 'Tema');
        btnTema.setAttribute('aria-label', rotuloTema[pref] || 'Tema');
    }
}
if (btnTema) btnTema.addEventListener('click', () => { Tema.aplicar(Tema.proximo()); marcarTema(); });
marcarTema();

// ---------- Estado da conexão em tempo real: selo discreto no cabeçalho ----------
const seloConexao = $('chat-conexao');
let temporizadorConexao = null;
function mostrarConexao(estado) {
    if (!seloConexao) return;
    clearTimeout(temporizadorConexao);
    if (estado === 'online') {
        seloConexao.hidden = true;
        seloConexao.replaceChildren();
        return;
    }
    // Espera um instante antes de mostrar: uma queda breve (reinício do servidor) não pisca na tela.
    temporizadorConexao = setTimeout(() => {
        seloConexao.replaceChildren();
        seloConexao.className = 'chat-conexao is-' + (estado === 'error' ? 'parada' : 'tentando');
        const ponto = document.createElement('span');
        ponto.className = 'chat-conexao-ponto';
        ponto.setAttribute('aria-hidden', 'true');
        const texto = document.createElement('span');
        texto.textContent = estado === 'error' ? 'Sem conexão em tempo real' : 'Reconectando…';
        seloConexao.append(ponto, texto);
        if (estado === 'error') {
            const tentar = document.createElement('button');
            tentar.type = 'button';
            tentar.className = 'chat-conexao-tentar';
            tentar.textContent = 'Tentar de novo';
            tentar.addEventListener('click', () => {
                const chat = window.raviChat;
                if (!chat) return;
                chat.reconnectAttempts = 0;
                mostrarConexao('reconnecting');
                chat.connect();
            });
            seloConexao.append(tentar);
        }
        seloConexao.hidden = false;
    }, estado === 'error' ? 0 : 1500);
}

// ---------- Cliente do chat ----------
// URL do WebSocket derivada do navegador (funciona por domínio ou por IP), como antes.
const loc = window.location;
const wsUrl = loc.protocol === 'https:' ? ('wss://' + loc.host + '/ws') : ('ws://' + loc.hostname + ':8081');

if (window.RaviChat) {
    window.raviChat = new window.RaviChat({
        wsUrl,
        conversationUuid: conversationId || null,
        delivered: cfg.delivered || false,
        previewable: cfg.previewable !== false,
        zip: cfg.zip || '',
        demo: cfg.demo || null,
        projectUuid: projectId || null,
        conversationStatus: cfg.status || '',
        onStatusChange: mostrarConexao,
    });
}
const chat = () => window.raviChat;

// ---------- Painel de arquivos ----------
const ft = $('files-toggle');
const fc = $('files-close');
if (ft && container) ft.addEventListener('click', () => container.classList.toggle('files-open'));
if (fc && container) fc.addEventListener('click', () => container.classList.remove('files-open'));

// ---------- Modal de código (fecha só pelo X; avisa se houver edição não salva) ----------
const cmArea = $('code-modal-area');
$('code-modal-close')?.addEventListener('click', () => chat()?.closeFileModal());
$('code-modal-save')?.addEventListener('click', () => chat()?.saveFileModal());
$('code-modal-download')?.addEventListener('click', () => chat()?.downloadFileModal());
if (cmArea) cmArea.addEventListener('input', () => chat()?.markModalDirty());

// ---------- Prompts de exemplo preenchem o campo ----------
document.querySelectorAll('.example-prompt').forEach((btn) => {
    btn.addEventListener('click', () => {
        const input = $('message-input');
        if (input) { input.value = btn.getAttribute('data-prompt') || btn.textContent.trim(); input.focus(); }
    });
});

// ---------- Nova conversa ----------
async function novaConversa(botao) {
    if (!window.api) return;
    botao?.classList.add('d2-btn-carregando');
    if (botao) botao.disabled = true;
    try {
        const resp = await window.api.createConversation(projectId, { title: 'Nova conversa' });
        const c = (resp && resp.conversation) || (resp && resp.data && resp.data.conversation) || (resp && resp.data) || resp || {};
        const uuid = c.uuid || (resp && resp.uuid);
        window.location.href = uuid ? ('/chat/' + projectId + '/' + uuid) : ('/chat/' + projectId);
    } catch (e) {
        botao?.classList.remove('d2-btn-carregando');
        if (botao) botao.disabled = false;
        D2UI.toast('Erro ao criar conversa.', { tipo: 'erro' });
    }
}
$('new-conversation-btn')?.addEventListener('click', (e) => novaConversa(e.currentTarget));
document.querySelectorAll('[data-acao="nova-conversa"]').forEach((b) => b.addEventListener('click', () => novaConversa(b)));

// ---------- Busca de conversas (filtro local) ----------
$('conversation-search')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.conversation-item').forEach((item) => {
        item.style.display = (item.textContent || '').toLowerCase().indexOf(q) >= 0 ? '' : 'none';
    });
});

// ---------- Excluir conversa ----------
function confirmarExclusao(nome) {
    return D2UI.confirmar({
        titulo: 'Excluir conversa',
        texto: 'Excluir "' + nome + '"? As mensagens desta conversa serão apagadas e esta ação não pode ser desfeita.',
        perigo: true,
        palavra: 'excluir',
        rotuloOk: 'Excluir',
    });
}
$('conversations-list')?.addEventListener('click', async (e) => {
    const del = e.target.closest('.conv-delete');
    if (!del) return;
    e.preventDefault(); e.stopPropagation();
    const uuid = del.getAttribute('data-del');
    const item = del.closest('.conversation-item');
    const nomeEl = item ? item.querySelector('.conversation-title') : null;
    const nome = (nomeEl ? nomeEl.textContent : '').trim() || 'esta conversa';
    if (!(await confirmarExclusao(nome))) return;
    try {
        await window.api.deleteConversation(uuid);
        if (uuid === conversationId) { window.location.href = '/chat/' + projectId; return; }
        if (item) item.remove();
        D2UI.toast('Conversa excluída.', { tipo: 'ok' });
    } catch (err) { D2UI.toast('Erro ao excluir a conversa.', { tipo: 'erro' }); }
});

async function excluirAtual() {
    if (!conversationId) { D2UI.toast('Conversa ainda não iniciada.', { tipo: 'info' }); return; }
    const titulo = ($('chat-title')?.textContent || '').trim() || 'esta conversa';
    if (!(await confirmarExclusao(titulo))) return;
    try {
        await window.api.deleteConversation(conversationId);
        window.location.href = '/chat/' + projectId;
    } catch (e) { D2UI.toast('Erro ao excluir a conversa.', { tipo: 'erro' }); }
}

// ---------- Renomear (edição no próprio título, sem prompt nativo) ----------
const titleEl = $('chat-title');
function renomearNoTitulo() {
    if (!conversationId) { D2UI.toast('Conversa ainda não iniciada.', { tipo: 'info' }); return; }
    if (!titleEl || titleEl.dataset.editing === '1') return;
    const original = titleEl.textContent.trim();
    titleEl.dataset.editing = '1';
    titleEl.contentEditable = 'true';
    titleEl.classList.add('editing');
    titleEl.focus();
    const range = document.createRange(); range.selectNodeContents(titleEl);
    const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);

    function terminar(salvar) {
        titleEl.contentEditable = 'false';
        titleEl.classList.remove('editing');
        delete titleEl.dataset.editing;
        titleEl.removeEventListener('keydown', aoTeclar);
        titleEl.removeEventListener('blur', aoSair);
        const novo = titleEl.textContent.replace(/\s+/g, ' ').trim().slice(0, 60);
        if (!salvar || !novo || novo === original) { titleEl.textContent = original; return; }
        titleEl.textContent = novo;
        window.api.request('PUT', '/api/conversations/' + conversationId, { title: novo })
            .then(() => {
                D2UI.toast('Conversa renomeada.', { tipo: 'ok' });
                const it = document.querySelector('.conversation-item[data-id="' + conversationId + '"] .conversation-title');
                if (it) it.textContent = novo;
            })
            .catch(() => { D2UI.toast('Erro ao renomear.', { tipo: 'erro' }); titleEl.textContent = original; });
    }
    function aoTeclar(e) {
        if (e.key === 'Enter') { e.preventDefault(); terminar(true); }
        else if (e.key === 'Escape') { e.preventDefault(); terminar(false); }
    }
    function aoSair() { terminar(true); }
    titleEl.addEventListener('keydown', aoTeclar);
    titleEl.addEventListener('blur', aoSair);
}
if (titleEl) titleEl.addEventListener('dblclick', renomearNoTitulo);

// ---------- Menu da conversa ----------
const btnMenu = $('chat-settings');
if (btnMenu) btnMenu.addEventListener('click', () => {
    D2UI.menuContexto(btnMenu, [
        { icone: 'editar', rotulo: 'Renomear conversa', fn: renomearNoTitulo },
        { icone: 'lixeira', rotulo: 'Excluir conversa', fn: excluirAtual, perigo: true },
        'separador',
        { icone: 'painel', rotulo: 'Voltar ao painel', fn: () => { window.location.href = '/dashboard'; } },
    ], { alinhar: 'fim' });
});

// ---------- Delegação: copiar mensagem, copiar código, abrir entrada e saída das ferramentas ----------
document.addEventListener('click', (e) => {
    const copiarMsg = e.target.closest('.msg-copiar');
    if (copiarMsg) {
        const msg = copiarMsg.closest('.message');
        const texto = msg ? (msg.querySelector('.message-text')?.innerText || '') : '';
        chat()?.copyToClipboard(texto.trim(), copiarMsg);
        D2UI.toast('Mensagem copiada.', { tipo: 'ok', duracao: 1800 });
        return;
    }
    const copiarCodigo = e.target.closest('.code-copy-btn');
    if (copiarCodigo) {
        const code = copiarCodigo.previousElementSibling;
        chat()?.copyToClipboard(code ? code.textContent : '', copiarCodigo);
        D2UI.toast('Código copiado.', { tipo: 'ok', duracao: 1800 });
        return;
    }
    const alternar = e.target.closest('.tool-toggle');
    if (alternar && alternar.parentElement) alternar.parentElement.classList.toggle('expanded');
});
