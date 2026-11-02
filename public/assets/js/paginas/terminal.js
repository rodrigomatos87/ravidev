// Terminal do projeto: xterm (carregado antes, em window.Terminal e window.FitAddon) ligado
// ao WebSocket da casa. Mesmo protocolo da tela anterior: auth, term_start, term_input,
// term_resize, term_output, term_exit e error.
import { tx, aplicarCores, dadosPagina, D2UI, novaVida, recarregarTela } from './projeto-comum.js';

// Cores do terminal a partir dos tokens do tema atual.
function corDe(nome, padrao) {
    const v = getComputedStyle(document.documentElement).getPropertyValue(nome).trim();
    return v || padrao;
}

export async function iniciar(raiz, dadosTela) {
    const vida = novaVida(raiz, 'terminal');
    const { signal } = vida;
    aplicarCores(raiz);
    const dados = dadosPagina(raiz, dadosTela);
    const caixaEstado = raiz.querySelector('#terminal-estado');
    const host = raiz.querySelector('#terminal');

    if (!window.Terminal || !window.FitAddon || !host) {
        estadoEm(caixaEstado, tx('terminal.indisponivel', 'Indisponível'), 'erro');
        if (host) {
            host.textContent = '';
            host.append(D2UI.vazio({ chave: tx('terminal.nao_carregou', 'O terminal não carregou'), texto: tx('terminal.nao_carregou_texto', 'Recarregue a página para tentar de novo.'), icone: 'erro', tipo: 'erro', acao: { rotulo: tx('terminal.recarregar', 'Recarregar'), icone: 'atualizar', fn: () => recarregarTela(raiz) } }));
        }
        return () => vida.abort();
    }
    const desligar = ligarTerminal({ raiz, host, caixaEstado, dados, signal });
    signal.addEventListener('abort', desligar);
    return () => vida.abort();
}

function estadoEm(caixaEstado, texto, tom = 'neutro', pulsando = false) {
    if (!caixaEstado) return;
    caixaEstado.textContent = '';
    caixaEstado.append(D2UI.selo(texto, tom, { pulsando }));
}

function temaTerminal(host) {
    const fundo = corDe('--d2-bg-subtle', 'Canvas');
    host.style.setProperty('--terminal-fundo', fundo);
    return {
        background: fundo,
        foreground: corDe('--d2-text', 'CanvasText'),
        cursor: corDe('--d2-acento', 'CanvasText'),
        cursorAccent: fundo,
        selectionBackground: corDe('--d2-acento-fundo', 'Highlight'),
        black: corDe('--d2-text-faint', 'GrayText'),
        red: corDe('--d2-erro', 'red'), green: corDe('--d2-ok', 'green'), yellow: corDe('--d2-aviso', 'orange'),
        blue: corDe('--d2-matiz-azul', 'blue'), magenta: corDe('--d2-matiz-magenta', 'purple'), cyan: corDe('--d2-matiz-ciano', 'teal'),
        white: corDe('--d2-text-muted', 'GrayText'),
        brightBlack: corDe('--d2-text-muted', 'GrayText'),
        brightRed: corDe('--d2-erro', 'red'), brightGreen: corDe('--d2-ok', 'green'), brightYellow: corDe('--d2-aviso', 'orange'),
        brightBlue: corDe('--d2-matiz-azul-texto', 'blue'), brightMagenta: corDe('--d2-matiz-magenta-texto', 'purple'), brightCyan: corDe('--d2-matiz-ciano-texto', 'teal'),
        brightWhite: corDe('--d2-text', 'CanvasText'),
    };
}

// Liga o xterm ao WebSocket da casa; devolve a função que derruba tudo (socket, observadores, xterm).
function ligarTerminal({ raiz, host, caixaEstado, dados, signal }) {
    const estado = (texto, tom, pulsando) => estadoEm(caixaEstado, texto, tom, pulsando);
    let encerrado = false;
    const term = new window.Terminal({
        cursorBlink: true,
        fontFamily: corDe('--d2-font-mono', 'monospace'),
        fontSize: 13,
        scrollback: 5000,
        theme: temaTerminal(host),
    });
    const fit = new window.FitAddon.FitAddon();
    term.loadAddon(fit);
    term.open(host);
    const ajustar = () => { try { fit.fit(); } catch (e) { /* sem tamanho ainda */ } };
    ajustar();

    // Troca de tema na barra: repinta o terminal.
    const observarTema = new MutationObserver(() => { term.options.theme = temaTerminal(host); });
    observarTema.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    let ws = null;
    let iniciado = false;
    const codificador = new TextEncoder();
    const enviar = (obj) => { if (ws && ws.readyState === 1) ws.send(JSON.stringify(obj)); };
    const paraBase64 = (bytes) => { let s = ''; for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]); return btoa(s); };
    const deBase64 = (b64) => { const bin = atob(b64); const arr = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i); return arr; };
    const enviarTamanho = () => { if (iniciado) enviar({ type: 'term_resize', cols: term.cols, rows: term.rows }); };

    let espera;
    const observar = new ResizeObserver(() => {
        ajustar();
        clearTimeout(espera);
        espera = setTimeout(enviarTamanho, 180);
    });
    observar.observe(host);

    function enderecoWs() {
        return (window.location.protocol === 'https:' ? 'wss' : 'ws') + '://' + window.location.host + '/ws';
    }
    function conectar() {
        if (encerrado) return;
        iniciado = false;
        estado(tx('terminal.conectando', 'Conectando…'), 'neutro', true);
        const meta = document.querySelector('meta[name="ws-token"]');
        try {
            ws = new WebSocket(enderecoWs());
        } catch (e) {
            estado(tx('terminal.erro_conexao', 'Erro de conexão'), 'erro');
            return;
        }
        ws.onopen = () => enviar({ type: 'auth', ws_token: meta ? meta.getAttribute('content') : '' });
        ws.onmessage = (ev) => {
            let d;
            try { d = JSON.parse(ev.data); } catch (e) { return; }
            switch (d.type) {
                case 'authenticated':
                    enviar({ type: 'term_start', project_id: dados.uuid, cols: term.cols, rows: term.rows });
                    break;
                case 'term_started':
                    iniciado = true;
                    estado(tx('terminal.conectado', 'Conectado'), 'ok');
                    term.focus();
                    break;
                case 'term_output':
                    if (d.data) term.write(deBase64(d.data));
                    break;
                case 'term_exit':
                    iniciado = false;
                    estado(tx('terminal.encerrada', 'Sessão encerrada'), 'aviso');
                    term.write('\r\n\x1b[33m[' + tx('terminal.encerrada_minusc', 'sessão encerrada') + ']\x1b[0m\r\n');
                    break;
                case 'error':
                    estado(tx('terminal.erro', 'Erro'), 'erro');
                    D2UI.toast(d.message || tx('terminal.erro_abrir', 'Não foi possível abrir o terminal agora.'), { tipo: 'erro', titulo: tx('terminal.titulo', 'Terminal') });
                    term.write('\r\n\x1b[31m' + (d.message || tx('terminal.erro', 'Erro')) + '\x1b[0m\r\n');
                    break;
                default:
                    break;
            }
        };
        ws.onclose = () => {
            if (encerrado) return;
            if (iniciado) estado(tx('terminal.desconectado', 'Desconectado'), 'erro');
            iniciado = false;
        };
        ws.onerror = () => {
            if (encerrado) return;
            estado(tx('terminal.erro_conexao', 'Erro de conexão'), 'erro');
            D2UI.toast(tx('terminal.erro_conexao_texto', 'Não foi possível conectar ao terminal. Use Reconectar para tentar de novo.'), { tipo: 'erro', titulo: tx('terminal.titulo', 'Terminal') });
        };
    }

    term.onData((texto) => { if (iniciado) enviar({ type: 'term_input', data: paraBase64(codificador.encode(texto)) }); });

    const reconectar = raiz.querySelector('#terminal-reconectar');
    if (reconectar) reconectar.addEventListener('click', () => {
        try { if (ws) ws.close(); } catch (e) { /* já fechado */ }
        term.reset();
        conectar();
    }, { signal });

    conectar();

    return () => {
        encerrado = true;
        clearTimeout(espera);
        observar.disconnect();
        observarTema.disconnect();
        if (ws) {
            ws.onopen = null; ws.onmessage = null; ws.onclose = null; ws.onerror = null;
            try { ws.close(); } catch (e) { /* já fechado */ }
            ws = null;
        }
        try { term.dispose(); } catch (e) { /* já destruído */ }
        host.textContent = '';
    };
}
