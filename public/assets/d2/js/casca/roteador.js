// Endereço da tela: #/<caminho>?query (o endereço real da tela no painel). A janela focada
// fica no endereço (recarregar volta ao mesmo lugar) e abrir janela cria entrada no histórico.
import { Eventos } from '../eventos.js';
import { Palco } from './palco.js';

export function montarEndereco(url) {
    const u = String(url || '');
    return u.startsWith('/') ? '#' + u : '#/';
}

// Lê o endereço. Devolve null se for inválido, { vazio: true } para a mesa.
export function lerEndereco(hash) {
    const bruto = String(hash || '').replace(/^#/, '');
    if (!bruto || bruto === '/') return { vazio: true };
    if (!bruto.startsWith('/') || bruto.startsWith('//') || bruto.length > 600) return null;
    try {
        const u = new URL(bruto, location.origin);
        if (u.origin !== location.origin) return null;
        return { url: u.pathname + u.search };
    } catch (e) {
        return null;
    }
}

let ultimo = '';
let ligado = false;
const cancelar = [];

function hashAtual() {
    return location.hash || '#/';
}

function escrever(alvo, empilhar) {
    if (hashAtual() === alvo) return;
    const url = location.pathname + location.search + alvo;
    if (empilhar) history.pushState({ ravidev: 1 }, '', url);
    else history.replaceState(history.state, '', url);
    ultimo = alvo;
}

async function aoMudar() {
    const alvo = hashAtual();
    if (alvo === ultimo) return;
    ultimo = alvo;
    const r = lerEndereco(alvo);
    if (!r) { corrigir(alvo); return; }
    // No celular, voltar fecha a folha de cima antes de mostrar o destino.
    const topo = Palco.focada();
    if (Palco.modoCelular() && topo && topo.endereco() !== alvo) {
        await topo.fechar();
    }
    if (r.vazio) {
        if (!Palco.modoCelular()) Palco.mostrarPainel();
        return;
    }
    const j = await Palco.abrir(r.url, { semHistorico: true });
    if (!j) corrigir(alvo);
}

// Endereço que não abre nada: a barra volta a mostrar a janela em foco, sem deixar um
// endereço morto no histórico.
function corrigir(alvo) {
    if (hashAtual() !== alvo) return;
    const f = Palco.focada();
    escrever(f && !f.fundo ? f.endereco() : '#/', false);
}

function ligar() {
    if (ligado) return;
    ligado = true;
    ultimo = hashAtual();
    window.addEventListener('hashchange', aoMudar);
    window.addEventListener('popstate', aoMudar);
    cancelar.push(Eventos.ouvir('janela.aberta', ({ janela, historico }) => {
        if (janela && !janela.fundo) escrever(janela.endereco(), Boolean(historico));
    }));
    cancelar.push(Eventos.ouvir('janela.foco', ({ janela }) => {
        escrever(janela ? janela.endereco() : '#/', false);
    }));
    // A tela da janela navegou (link interno, formulário): o endereço acompanha.
    cancelar.push(Eventos.ouvir('janela.endereco', ({ janela }) => {
        if (janela && janela === Palco.focada()) escrever(janela.endereco(), false);
    }));
}

function desligar() {
    ligado = false;
    window.removeEventListener('hashchange', aoMudar);
    window.removeEventListener('popstate', aoMudar);
    while (cancelar.length) cancelar.pop()();
}

export const Roteador = {
    // Lido uma vez no arranque: o endereço pedido na barra.
    capturar: () => lerEndereco(hashAtual()),
    ligar,
    desligar,
    ir: (alvo) => { location.hash = alvo; },
    limpar: () => escrever('#/', false),
    ler: lerEndereco,
    montar: montarEndereco,
};
export default Roteador;
